<?php
/**
 * Enrolment sync handler — pull external data → enrol/unenrol Moodle users.
 *
 * Expected mapped fields:
 *   - username or email (key field to identify the user)
 *   - course_idnumber or course_shortname (to identify the course)
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_external_api_sync\sync;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/enrollib.php');

use local_external_api_sync\api\response_parser;

class enrolment_sync {

    private $endpoint;
    private $mappings;
    private $test_mode;
    private $stats   = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
    private $errors  = [];

    public function __construct($endpoint, array $mappings) {
        $this->endpoint  = $endpoint;
        $this->mappings  = $mappings;
        $this->test_mode = (bool) get_config('local_external_api_sync', 'test_mode');
    }

    public function process(array $records): array {
        foreach ($records as $record) {
            $this->stats['processed']++;
            try {
                $this->process_record($record);
            } catch (\Throwable $e) {
                $this->stats['failed']++;
                $this->errors[] = ['record' => json_encode(array_slice($record, 0, 5)), 'error' => $e->getMessage()];
            }
        }
        return $this->stats;
    }

    public function get_errors(): array { return $this->errors; }

    private function process_record(array $record): void {
        global $DB;

        $mapped = response_parser::map_record($record, $this->mappings);

        // Resolve user.
        $user = null;
        if (!empty($mapped['username'])) {
            $user = $DB->get_record('user', ['username' => $mapped['username'], 'deleted' => 0]);
        } elseif (!empty($mapped['email'])) {
            $user = $DB->get_record('user', ['email' => $mapped['email'], 'deleted' => 0]);
        } elseif (!empty($mapped['idnumber'])) {
            $user = $DB->get_record('user', ['idnumber' => $mapped['idnumber'], 'deleted' => 0]);
        }

        if (!$user) {
            $this->stats['skipped']++;
            $this->errors[] = ['record' => json_encode(array_slice($record, 0, 3)), 'error' => 'User not found'];
            return;
        }

        // Resolve course.
        $course = null;
        if (!empty($mapped['course_idnumber'])) {
            $course = $DB->get_record('course', ['idnumber' => $mapped['course_idnumber']]);
        } elseif (!empty($mapped['course_shortname'])) {
            $course = $DB->get_record('course', ['shortname' => $mapped['course_shortname']]);
        }

        if (!$course) {
            $this->stats['skipped']++;
            $this->errors[] = ['record' => json_encode(array_slice($record, 0, 3)), 'error' => 'Course not found'];
            return;
        }

        if ($this->test_mode) {
            mtrace("  [TEST MODE] Would {$this->endpoint->sync_action} user {$user->username} in course {$course->shortname}");
            $this->stats['created']++;
            return;
        }

        $enrol_plugin = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);

        if (!$instance) {
            $this->stats['skipped']++;
            $this->errors[] = ['record' => json_encode(array_slice($record, 0, 3)), 'error' => 'No manual enrolment instance for course'];
            return;
        }

        if ($this->endpoint->sync_action === 'unenrol') {
            $enrol_plugin->unenrol_user($instance, $user->id);
            $this->stats['updated']++;
        } else {
            $role_id = $mapped['role_id'] ?? null;
            if (!$role_id) {
                $role = $DB->get_record('role', ['shortname' => 'student']);
                $role_id = $role ? $role->id : null;
            }
            $enrol_plugin->enrol_user($instance, $user->id, $role_id);
            $this->stats['created']++;
        }
    }
}
