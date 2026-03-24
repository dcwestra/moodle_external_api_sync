<?php
/**
 * Push sync handler — read Moodle data → POST to external API.
 *
 * For push endpoints, field mappings work in reverse:
 *   internal_field = Moodle field/alias to read (see supported fields per entity below)
 *   external_field = key name in the outgoing JSON payload (dot notation supported)
 *
 * Supported entity types:
 *   user                — active Moodle user profile fields + custom profile fields
 *   course_completion   — one row per user+course: completion status, date, grade
 *   activity_completion — one row per user+course module: completion status + grade
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_external_api_sync\sync;

defined('MOODLE_INTERNAL') || die();

use local_external_api_sync\api\client;
use local_external_api_sync\api\response_parser;

class push_sync {

    private $endpoint;
    private $mappings;
    private $http_client;
    private $test_mode;
    private $stats  = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
    private $errors = [];

    public function __construct($endpoint, array $mappings, client $http_client) {
        $this->endpoint    = $endpoint;
        $this->mappings    = $mappings;
        $this->http_client = $http_client;
        $this->test_mode   = (bool) get_config('local_external_api_sync', 'test_mode');
    }

    /**
     * Dispatch to the correct push method based on entity_type.
     */
    public function process(): array {
        switch ($this->endpoint->entity_type) {
            case 'course_completion':
                return $this->push_course_completions();
            case 'activity_completion':
                return $this->push_activity_completions();
            case 'user':
            default:
                return $this->push_users();
        }
    }

    public function get_errors(): array { return $this->errors; }

    // -----------------------------------------------------------------------
    // Entity: user
    // -----------------------------------------------------------------------

    /**
     * Push active Moodle user profiles to the external API.
     *
     * Available internal_field values:
     *   Any standard mdl_user column: username, email, firstname, lastname,
     *   idnumber, department, institution, phone1, phone2, city, country,
     *   lang, suspended, timecreated, timemodified, lastaccess
     *   profile_field_{shortname} — custom profile fields
     */
    private function push_users(): array {
        global $DB;

        $users = $DB->get_records('user', ['deleted' => 0, 'suspended' => 0], '', '*');
        $payload = [];

        foreach ($users as $user) {
            $this->stats['processed']++;
            try {
                $row = $this->build_user_row($user);
                if (!empty($row)) {
                    $payload[] = $row;
                }
            } catch (\Throwable $e) {
                $this->stats['failed']++;
                $this->errors[] = ['record' => "userid:{$user->id}", 'error' => $e->getMessage()];
            }
        }

        return $this->send_payload($payload, 'user');
    }

    // -----------------------------------------------------------------------
    // Entity: course_completion
    // -----------------------------------------------------------------------

    /**
     * Push course completion records to the external API.
     * One row per enrolled user per course.
     *
     * Available internal_field values:
     *   -- User fields --
     *   username, email, firstname, lastname, idnumber, department, institution
     *   profile_field_{shortname}
     *
     *   -- Course fields --
     *   course_id          mdl_course.id
     *   course_shortname   mdl_course.shortname
     *   course_fullname    mdl_course.fullname
     *   course_idnumber    mdl_course.idnumber
     *   course_category    mdl_course_categories.name
     *
     *   -- Completion fields --
     *   completion_status      'complete' | 'incomplete' | 'complete_pass' | 'complete_fail'
     *   completion_status_raw  raw integer (0=incomplete 1=complete 2=pass 3=fail)
     *   completion_date        Unix timestamp (null if incomplete)
     *   completion_date_iso    ISO 8601 string (null if incomplete)
     *   enrolled_date          Unix timestamp of enrolment
     *   time_started           Unix timestamp of first access (null if never)
     *
     *   -- Grade fields --
     *   final_grade            numeric final grade (null if not graded)
     *   final_grade_max        maximum possible grade
     *   final_grade_percent    0-100 (null if not graded)
     *   pass_grade             minimum passing grade on the course
     *   passed                 1 | 0 | null
     */
    private function push_course_completions(): array {
        global $DB;

        $sql = "SELECT
                    cc.id             AS completion_id,
                    cc.userid,
                    cc.course         AS courseid,
                    cc.timeenrolled   AS enrolled_date,
                    cc.timestarted    AS time_started,
                    cc.timecompleted  AS completion_timestamp,
                    cc.status         AS completion_status_raw,

                    u.username, u.email, u.firstname, u.lastname,
                    u.idnumber, u.department, u.institution,

                    c.shortname       AS course_shortname,
                    c.fullname        AS course_fullname,
                    c.idnumber        AS course_idnumber,

                    cat.name          AS course_category,

                    gg.finalgrade     AS final_grade,
                    gi.grademax       AS final_grade_max,
                    gi.gradepass      AS pass_grade

                FROM {course_completions} cc
                JOIN {user} u
                    ON u.id = cc.userid AND u.deleted = 0 AND u.suspended = 0
                JOIN {course} c
                    ON c.id = cc.course
                JOIN {course_categories} cat
                    ON cat.id = c.category
                LEFT JOIN {grade_items} gi
                    ON gi.courseid = cc.course AND gi.itemtype = 'course'
                LEFT JOIN {grade_grades} gg
                    ON gg.itemid = gi.id AND gg.userid = cc.userid
            ORDER BY cc.course ASC, cc.userid ASC";

        $records = $DB->get_records_sql($sql);

        if (empty($records)) {
            mtrace('  push_sync [course_completion]: no records found.');
            return $this->stats;
        }

        mtrace('  push_sync [course_completion]: building payload for ' . count($records) . ' records.');

        $payload = [];

        foreach ($records as $record) {
            $this->stats['processed']++;
            try {
                $row = $this->build_completion_row($record);
                if (!empty($row)) {
                    $payload[] = $row;
                }
            } catch (\Throwable $e) {
                $this->stats['failed']++;
                $this->errors[] = [
                    'record' => "userid:{$record->userid} course:{$record->courseid}",
                    'error'  => $e->getMessage(),
                ];
            }
        }

        return $this->send_payload($payload, 'course_completion');
    }

    // -----------------------------------------------------------------------
    // Entity: activity_completion
    // -----------------------------------------------------------------------

    /**
     * Push activity (module) level completion records to the external API.
     * One row per user per course module.
     *
     * Available internal_field values:
     *   -- User / course fields (same as course_completion) --
     *
     *   -- Activity fields --
     *   activity_id            mdl_course_modules.id
     *   activity_name          display name of the activity
     *   activity_type          module type e.g. quiz, scorm, lesson, page
     *   activity_idnumber      mdl_course_modules.idnumber
     *
     *   -- Completion / grade fields --
     *   completion_status, completion_status_raw, completion_date, completion_date_iso
     *   activity_grade, activity_grade_max, activity_grade_percent,
     *   activity_pass_grade, activity_passed
     */
    private function push_activity_completions(): array {
        global $DB;

        $sql = "SELECT
                    cmc.id              AS completion_id,
                    cmc.userid,
                    cmc.coursemoduleid  AS activity_id,
                    cmc.completionstate AS completion_status_raw,
                    cmc.timemodified    AS completion_timestamp,

                    cm.course           AS courseid,
                    cm.idnumber         AS activity_idnumber,
                    m.name              AS activity_type,

                    u.username, u.email, u.firstname, u.lastname,
                    u.idnumber, u.department, u.institution,

                    c.shortname         AS course_shortname,
                    c.fullname          AS course_fullname,
                    c.idnumber          AS course_idnumber,

                    cat.name            AS course_category,

                    gg.finalgrade       AS activity_grade,
                    gi.grademax         AS activity_grade_max,
                    gi.gradepass        AS activity_pass_grade

                FROM {course_modules_completion} cmc
                JOIN {course_modules} cm
                    ON cm.id = cmc.coursemoduleid
                JOIN {modules} m
                    ON m.id = cm.module
                JOIN {user} u
                    ON u.id = cmc.userid AND u.deleted = 0 AND u.suspended = 0
                JOIN {course} c
                    ON c.id = cm.course
                JOIN {course_categories} cat
                    ON cat.id = c.category
                LEFT JOIN {grade_items} gi
                    ON gi.courseid = cm.course
                    AND gi.itemtype = 'mod'
                    AND gi.itemmodule = m.name
                    AND gi.iteminstance = cm.instance
                LEFT JOIN {grade_grades} gg
                    ON gg.itemid = gi.id AND gg.userid = cmc.userid
            ORDER BY cm.course ASC, cmc.userid ASC, cmc.coursemoduleid ASC";

        $records = $DB->get_records_sql($sql);

        if (empty($records)) {
            mtrace('  push_sync [activity_completion]: no records found.');
            return $this->stats;
        }

        $activity_names = $this->fetch_activity_names($records);

        mtrace('  push_sync [activity_completion]: building payload for ' . count($records) . ' records.');

        $payload = [];

        foreach ($records as $record) {
            $this->stats['processed']++;
            try {
                $record->activity_name = $activity_names[(int)$record->activity_id] ?? '';
                $row = $this->build_activity_row($record);
                if (!empty($row)) {
                    $payload[] = $row;
                }
            } catch (\Throwable $e) {
                $this->stats['failed']++;
                $this->errors[] = [
                    'record' => "userid:{$record->userid} activity:{$record->activity_id}",
                    'error'  => $e->getMessage(),
                ];
            }
        }

        return $this->send_payload($payload, 'activity_completion');
    }

    // -----------------------------------------------------------------------
    // Row builders
    // -----------------------------------------------------------------------

    private function build_user_row(object $user): array {
        global $DB;
        $row = [];

        foreach ($this->mappings as $mapping) {
            if (empty($mapping->enabled)) continue;

            $internal = $mapping->internal_field;
            $external = $mapping->external_field;

            if (isset($user->$internal)) {
                $value = $user->$internal;
            } elseif (strpos($internal, 'profile_field_') === 0) {
                $value = $this->get_profile_field($user->id, $internal);
            } else {
                $value = $mapping->default_value ?? null;
            }

            $value = $this->apply_transform($value, $mapping->transform ?? 'none');
            $this->set_nested($row, $external, $value);
        }

        return $row;
    }

    private function build_completion_row(object $record): array {
        $row = [];

        $status_map = [0 => 'incomplete', 1 => 'complete', 2 => 'complete_pass', 3 => 'complete_fail'];
        $status_raw = (int) $record->completion_status_raw;
        $comp_date  = !empty($record->completion_timestamp) ? (int) $record->completion_timestamp : null;
        $grade      = $record->final_grade !== null ? (float) $record->final_grade : null;
        $grade_max  = $record->final_grade_max !== null ? (float) $record->final_grade_max : null;
        $pass_grade = $record->pass_grade !== null ? (float) $record->pass_grade : null;
        $grade_pct  = ($grade !== null && $grade_max > 0) ? round(($grade / $grade_max) * 100, 2) : null;
        $passed     = ($grade !== null && $pass_grade !== null) ? ($grade >= $pass_grade ? 1 : 0) : null;

        $virtual = [
            'completion_status'     => $status_map[$status_raw] ?? 'incomplete',
            'completion_status_raw' => $status_raw,
            'completion_date'       => $comp_date,
            'completion_date_iso'   => $comp_date ? date('c', $comp_date) : null,
            'enrolled_date'         => !empty($record->enrolled_date) ? (int) $record->enrolled_date : null,
            'time_started'          => !empty($record->time_started) ? (int) $record->time_started : null,
            'course_id'             => (int) $record->courseid,
            'course_shortname'      => $record->course_shortname,
            'course_fullname'       => $record->course_fullname,
            'course_idnumber'       => $record->course_idnumber,
            'course_category'       => $record->course_category,
            'final_grade'           => $grade,
            'final_grade_max'       => $grade_max,
            'final_grade_percent'   => $grade_pct,
            'pass_grade'            => $pass_grade,
            'passed'                => $passed,
        ];

        foreach ($this->mappings as $mapping) {
            if (empty($mapping->enabled)) continue;

            $internal = $mapping->internal_field;
            $external = $mapping->external_field;

            if (array_key_exists($internal, $virtual)) {
                $value = $virtual[$internal];
            } elseif (isset($record->$internal)) {
                $value = $record->$internal;
            } elseif (strpos($internal, 'profile_field_') === 0) {
                $value = $this->get_profile_field($record->userid, $internal);
            } else {
                $value = $mapping->default_value ?? null;
            }

            $value = $this->apply_transform($value, $mapping->transform ?? 'none');
            $this->set_nested($row, $external, $value);
        }

        return $row;
    }

    private function build_activity_row(object $record): array {
        $row = [];

        $status_map  = [0 => 'incomplete', 1 => 'complete', 2 => 'complete_pass', 3 => 'complete_fail'];
        $status_raw  = (int) $record->completion_status_raw;
        $comp_date   = !empty($record->completion_timestamp) ? (int) $record->completion_timestamp : null;
        $act_grade   = $record->activity_grade !== null ? (float) $record->activity_grade : null;
        $act_max     = $record->activity_grade_max !== null ? (float) $record->activity_grade_max : null;
        $act_pass    = $record->activity_pass_grade !== null ? (float) $record->activity_pass_grade : null;
        $act_pct     = ($act_grade !== null && $act_max > 0) ? round(($act_grade / $act_max) * 100, 2) : null;
        $act_passed  = ($act_grade !== null && $act_pass !== null) ? ($act_grade >= $act_pass ? 1 : 0) : null;

        $virtual = [
            'completion_status'      => $status_map[$status_raw] ?? 'incomplete',
            'completion_status_raw'  => $status_raw,
            'completion_date'        => $comp_date,
            'completion_date_iso'    => $comp_date ? date('c', $comp_date) : null,
            'course_id'              => (int) $record->courseid,
            'course_shortname'       => $record->course_shortname,
            'course_fullname'        => $record->course_fullname,
            'course_idnumber'        => $record->course_idnumber,
            'course_category'        => $record->course_category,
            'activity_id'            => (int) $record->activity_id,
            'activity_name'          => $record->activity_name,
            'activity_type'          => $record->activity_type,
            'activity_idnumber'      => $record->activity_idnumber,
            'activity_grade'         => $act_grade,
            'activity_grade_max'     => $act_max,
            'activity_grade_percent' => $act_pct,
            'activity_pass_grade'    => $act_pass,
            'activity_passed'        => $act_passed,
        ];

        foreach ($this->mappings as $mapping) {
            if (empty($mapping->enabled)) continue;

            $internal = $mapping->internal_field;
            $external = $mapping->external_field;

            if (array_key_exists($internal, $virtual)) {
                $value = $virtual[$internal];
            } elseif (isset($record->$internal)) {
                $value = $record->$internal;
            } elseif (strpos($internal, 'profile_field_') === 0) {
                $value = $this->get_profile_field($record->userid, $internal);
            } else {
                $value = $mapping->default_value ?? null;
            }

            $value = $this->apply_transform($value, $mapping->transform ?? 'none');
            $this->set_nested($row, $external, $value);
        }

        return $row;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Fetch activity display names for a set of completion records in bulk.
     * Groups by module type to avoid N+1 queries.
     *
     * @param array $records Indexed by completion_id
     * @return array [coursemoduleid => name]
     */
    private function fetch_activity_names(array $records): array {
        global $DB;

        $by_type = [];
        foreach ($records as $record) {
            $by_type[$record->activity_type][] = (int) $record->activity_id;
        }

        $names = [];

        foreach ($by_type as $type => $cmids) {
            try {
                $placeholders = implode(',', array_fill(0, count($cmids), '?'));
                $sql = "SELECT cm.id AS cmid, act.name
                          FROM {{$type}} act
                          JOIN {course_modules} cm
                            ON cm.instance = act.id
                           AND cm.module = (SELECT id FROM {modules} WHERE name = ?)
                         WHERE cm.id IN ($placeholders)";

                $rows = $DB->get_records_sql($sql, array_merge([$type], $cmids));
                foreach ($rows as $row) {
                    $names[(int) $row->cmid] = $row->name;
                }
            } catch (\Throwable $e) {
                mtrace("  push_sync: could not fetch names for module type '{$type}': " . $e->getMessage());
            }
        }

        return $names;
    }

    private function get_profile_field(int $userid, string $internal_field) {
        global $DB;

        $shortname = str_replace('profile_field_', '', $internal_field);
        $field_rec = $DB->get_record('user_info_field', ['shortname' => $shortname]);

        if (!$field_rec) {
            return null;
        }

        $data_rec = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $field_rec->id]);
        return $data_rec ? $data_rec->data : null;
    }

    private function apply_transform($value, string $transform) {
        if (empty($transform) || $transform === 'none') {
            return $value;
        }
        return response_parser::apply_transform($value, $transform);
    }

    private function set_nested(array &$array, string $key, $value): void {
        $keys    = explode('.', $key);
        $current = &$array;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $current[$k] = $value;
            } else {
                if (!isset($current[$k]) || !is_array($current[$k])) {
                    $current[$k] = [];
                }
                $current = &$current[$k];
            }
        }
    }

    /**
     * Send payload batch to the external endpoint.
     *
     * @param array  $payload
     * @param string $label   For mtrace messages
     * @return array Stats
     */
    private function send_payload(array $payload, string $label = 'records'): array {
        if (empty($payload)) {
            mtrace("  push_sync [{$label}]: nothing to push.");
            return $this->stats;
        }

        if ($this->test_mode) {
            mtrace("  [TEST MODE] Would push " . count($payload) . " {$label} records to {$this->endpoint->path}");
            $this->stats['created'] = count($payload);
            return $this->stats;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->http_client->push($body);
        $this->stats['created'] = count($payload);

        mtrace("  push_sync [{$label}]: pushed " . count($payload) . " records.");

        return $this->stats;
    }
}
