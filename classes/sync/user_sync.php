<?php
/**
 * User sync handler — pull external API data → create/update Moodle users.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_external_api_sync\sync;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

use local_external_api_sync\api\response_parser;

class user_sync {

    /** @var object Endpoint record */
    private $endpoint;

    /** @var array Field mapping records */
    private $mappings;

    /** @var array Stats counters */
    private $stats = [
        'processed' => 0,
        'created'   => 0,
        'updated'   => 0,
        'skipped'   => 0,
        'failed'    => 0,
    ];

    /** @var array Per-record error log */
    private $errors = [];

    /** @var bool Test mode — no writes */
    private $test_mode = false;

    public function __construct($endpoint, array $mappings) {
        $this->endpoint   = $endpoint;
        $this->mappings   = $mappings;
        $this->test_mode  = (bool) get_config('local_external_api_sync', 'test_mode');
    }

    /**
     * Process all records from the API response.
     *
     * @param array $records Array of raw API records
     * @return array Stats array
     */
    public function process(array $records): array {
        foreach ($records as $record) {
            $this->stats['processed']++;
            try {
                $this->process_record($record);
            } catch (\Throwable $e) {
                $this->stats['failed']++;
                $this->errors[] = [
                    'record' => json_encode(array_slice($record, 0, 5)), // Truncate for log safety.
                    'error'  => $e->getMessage(),
                ];
            }
        }

        return $this->stats;
    }

    public function get_errors(): array {
        return $this->errors;
    }

    /**
     * Process a single API record — map fields, find/create/update Moodle user.
     */
    private function process_record(array $record): void {
        global $DB;

        // Map API fields to Moodle fields.
        $mapped = response_parser::map_record($record, $this->mappings);

        if (empty($mapped)) {
            $this->stats['skipped']++;
            return;
        }

        // Find the key field to look up existing user.
        $key_field = $this->get_key_field_value($mapped);
        if ($key_field === null) {
            $this->stats['skipped']++;
            $this->errors[] = ['record' => json_encode(array_slice($record, 0, 3)), 'error' => 'Key field value is empty'];
            return;
        }

        // Separate standard user fields from custom profile fields.
        [$user_fields, $profile_fields] = $this->split_fields($mapped);

        // Look up existing user.
        $existing_user = $this->find_user($key_field['field'], $key_field['value']);

        if ($this->test_mode) {
            // Test mode: log what would happen but don't write.
            mtrace('  [TEST MODE] Would ' . ($existing_user ? 'update' : 'create') . ' user: ' . ($key_field['value'] ?? ''));
            $existing_user ? $this->stats['updated']++ : $this->stats['created']++;
            return;
        }

        $action = $this->endpoint->sync_action ?? 'create_update';

        switch ($action) {
            case 'suspend':
                if ($existing_user) {
                    if (!$existing_user->suspended) {
                        $this->suspend_user($existing_user);
                        $this->stats['updated']++;
                    } else {
                        $this->stats['skipped']++;
                    }
                } else {
                    $this->stats['skipped']++;
                }
                break;

            case 'create_update':
            default:
                if ($existing_user) {
                    $this->update_user($existing_user, $user_fields, $profile_fields);
                    $this->stats['updated']++;
                } else {
                    $this->create_user($user_fields, $profile_fields);
                    $this->stats['created']++;
                }
                break;
        }
    }

    /**
     * Find which mapping is the key field and return its current value.
     */
    private function get_key_field_value(array $mapped): ?array {
        foreach ($this->mappings as $mapping) {
            if (!empty($mapping->is_key_field) && !empty($mapping->enabled)) {
                $field = $mapping->internal_field;
                $value = $mapped[$field] ?? null;
                if ($value !== null && $value !== '') {
                    return ['field' => $field, 'value' => $value];
                }
            }
        }
        return null;
    }

    /**
     * Look up a Moodle user by a given field (username, email, idnumber).
     */
    private function find_user(string $field, string $value): ?object {
        global $DB;

        // Standard user table fields we can search by.
        $searchable = ['username', 'email', 'idnumber'];

        if (in_array($field, $searchable)) {
            return $DB->get_record('user', [$field => $value, 'deleted' => 0]) ?: null;
        }

        // Custom profile field search.
        if (strpos($field, 'profile_field_') === 0) {
            $shortname = str_replace('profile_field_', '', $field);
            $field_record = $DB->get_record('user_info_field', ['shortname' => $shortname]);
            if ($field_record) {
                $data_record = $DB->get_record('user_info_data', [
                    'fieldid' => $field_record->id,
                    'data'    => $value,
                ]);
                if ($data_record) {
                    return $DB->get_record('user', ['id' => $data_record->userid, 'deleted' => 0]) ?: null;
                }
            }
        }

        return null;
    }

    /**
     * Create a new Moodle user from mapped data.
     */
    /**
     * Suspend a Moodle user — sets suspended = 1, disabling login.
     * Preserves all data. Fires user_updated event.
     */
    private function suspend_user(object $user): void {
        global $DB;
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        \core\event\user_updated::create_from_userid($user->id)->trigger();
    }

    private function create_user(array $user_fields, array $profile_fields): void {
        global $CFG;

        $user = new \stdClass();

        // Required defaults.
        $user->confirmed  = 1;
        $user->mnethostid = $CFG->mnet_localhost_id;
        $user->auth       = 'oauth2'; // SSO users — set to match your auth method.
        $user->timecreated = time();
        $user->timemodified = time();

        // Apply mapped standard fields.
        foreach ($user_fields as $field => $value) {
            if ($value !== null) {
                $user->$field = $value;
            }
        }

        // Ensure required fields have values.
        if (empty($user->username) && !empty($user->email)) {
            $user->username = strtolower($user->email);
        }
        if (empty($user->password)) {
            $user->password = AUTH_PASSWORD_NOT_CACHED;
        }
        if (empty($user->lang)) {
            $user->lang = $CFG->lang ?? 'en';
        }

        $user->id = user_create_user($user, false, false);

        // Save custom profile fields.
        if (!empty($profile_fields)) {
            $this->save_profile_fields($user->id, $profile_fields);
        }
    }

    /**
     * Update an existing Moodle user with mapped data.
     */
    private function update_user(object $existing, array $user_fields, array $profile_fields): void {
        $user = clone $existing;
        $user->timemodified = time();

        $changed = false;
        foreach ($user_fields as $field => $value) {
            // Dayforce is source of truth — write API value if it is non-null
            // AND differs from what is currently in Moodle. Skipping identical
            // values avoids unnecessary DB writes across 8,000+ users nightly.
            if ($value === null) {
                continue; // Not returned by API — leave Moodle value untouched.
            }
            $existing_val = isset($existing->$field) ? (string)$existing->$field : '';
            if ($existing_val !== (string)$value) {
                $user->$field = $value;
                $changed = true;
            }
        }

        if ($changed) {
            user_update_user($user, false, false);
        }

        // Always re-save profile fields in case they changed.
        if (!empty($profile_fields)) {
            $this->save_profile_fields($existing->id, $profile_fields);
        }
    }

    /**
     * Save custom user profile field values.
     */
    private function save_profile_fields(int $userid, array $profile_fields): void {
        global $DB;

        foreach ($profile_fields as $field_key => $value) {
            // Skip null values — a null means the field was not present in the
            // API response, not that it should be cleared.
            if ($value === null) {
                continue;
            }

            $shortname = str_replace('profile_field_', '', $field_key);
            $field_record = $DB->get_record('user_info_field', ['shortname' => $shortname]);

            if (!$field_record) {
                continue; // Field doesn't exist in Moodle — skip.
            }

            $existing = $DB->get_record('user_info_data', [
                'userid'  => $userid,
                'fieldid' => $field_record->id,
            ]);

            // Dayforce is source of truth — write API value only if it differs.
            // Avoids unnecessary DB writes for unchanged profile fields.
            if ($existing) {
                if ((string)$existing->data === (string)$value) {
                    continue; // No change — skip write.
                }
                $existing->data = (string) $value;
                $DB->update_record('user_info_data', $existing);
            } else {
                $data = new \stdClass();
                $data->userid  = $userid;
                $data->fieldid = $field_record->id;
                $data->data    = (string) $value;
                $DB->insert_record('user_info_data', $data);
            }
        }
    }

    /**
     * Split mapped fields into standard user fields and profile_ fields.
     *
     * @return array [standard_fields, profile_fields]
     */
    private function split_fields(array $mapped): array {
        $standard_fields = [
            'username', 'email', 'firstname', 'lastname', 'idnumber',
            'phone1', 'phone2', 'department', 'institution', 'city',
            'country', 'lang', 'suspended', 'password', 'auth',
            'alternatename', 'address', 'description',
        ];

        $user_fields    = [];
        $profile_fields = [];

        foreach ($mapped as $field => $value) {
            if (in_array($field, $standard_fields)) {
                $user_fields[$field] = $value;
            } else {
                // Anything else is treated as a custom profile field.
                // Ensure it has the profile_field_ prefix.
                $key = strpos($field, 'profile_field_') === 0 ? $field : 'profile_field_' . $field;
                $profile_fields[$key] = $value;
            }
        }

        return [$user_fields, $profile_fields];
    }
}
