<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Scheduled task: orchestrates all enabled endpoint syncs.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_external_api_sync\task;

defined('MOODLE_INTERNAL') || die();

use local_external_api_sync\api\client;
use local_external_api_sync\sync\user_sync;
use local_external_api_sync\sync\enrolment_sync;
use local_external_api_sync\sync\push_sync;
use local_external_api_sync\sync\calendar_sync;
use local_external_api_sync\util\crypto;

/**
 * Runs every 15 minutes. For each enabled endpoint, checks whether
 * its cron schedule indicates it should run, then executes the sync.
 */
class sync_task extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('taskname', 'local_external_api_sync');
    }

    public function execute(): void {
        global $DB;

        mtrace('External API Sync: starting run at ' . userdate(time()));

        // Get all enabled endpoints with their connection.
        $sql = "SELECT e.*, c.name AS connection_name, c.auth_type, c.base_url,
                       c.token_url, c.client_id, c.client_secret, c.oauth_scope,
                       c.api_key, c.api_key_header, c.api_key_location, c.api_key_param,
                       c.basic_username, c.basic_password, c.bearer_token
                  FROM {ext_api_endpoints} e
                  JOIN {ext_api_connections} c ON c.id = e.connection_id
                 WHERE e.enabled = 1 AND c.enabled = 1
              ORDER BY e.id ASC";

        $endpoints = $DB->get_records_sql($sql);

        if (empty($endpoints)) {
            mtrace('External API Sync: no enabled endpoints found.');
            return;
        }

        $now = time();

        foreach ($endpoints as $endpoint) {
            // Parent-only endpoints are triggered by their child — never run directly.
            if (!empty($endpoint->is_parent_only)) {
                mtrace("  Skipping [{$endpoint->name}] — parent-only endpoint, runs via child.");
                continue;
            }

            // Check if this endpoint is due to run based on its schedule.
            if (!$this->is_due($endpoint, $now)) {
                mtrace("  Skipping [{$endpoint->name}] — not due yet.");
                continue;
            }

            mtrace("  Running endpoint: [{$endpoint->name}] (connection: {$endpoint->connection_name})");

            // Decrypt credentials.
            $connection = $this->extract_connection($endpoint);
            $connection = crypto::decrypt_connection($connection);

            // Get field mappings.
            $mappings = array_values($DB->get_records(
                'ext_api_field_mappings',
                ['endpoint_id' => $endpoint->id],
                'sortorder ASC'
            ));

            $start_time = microtime(true);
            $log = $this->run_endpoint($endpoint, $connection, $mappings);
            $log['duration'] = (int) (microtime(true) - $start_time);

            // Write log record.
            $log_id = $this->write_log($endpoint->id, (int)$endpoint->connection_id, $now, $log);

            // Update endpoint last_run and status.
            $DB->update_record('ext_api_endpoints', (object) [
                'id'          => $endpoint->id,
                'last_run'    => $now,
                'last_status' => $log['status'],
                'timemodified' => $now,
            ]);

            // Send error email if needed.
            if (!empty($endpoint->error_email) && !empty($log['errors'])) {
                $this->send_error_email($endpoint, $connection, $log, $now);
                $DB->set_field('ext_api_sync_log', 'error_email_sent', 1, ['id' => $log_id]);
            }

            mtrace(sprintf(
                '  Done [%s]: fetched=%d created=%d updated=%d skipped=%d failed=%d status=%s duration=%ds',
                $endpoint->name,
                $log['fetched'],
                $log['created'],
                $log['updated'],
                $log['skipped'],
                $log['failed'],
                $log['status'],
                $log['duration']
            ));
        }

        // Purge old log entries if retention is configured.
        $this->purge_old_logs();

        mtrace('External API Sync: completed.');
    }

    /**
     * Run a single endpoint sync and return stats.
     *
     * @param object $endpoint   Endpoint record
     * @param object $connection Decrypted connection record
     * @param array  $mappings   Field mapping records
     * @return array Stats + status
     */
    private function run_endpoint(object $endpoint, object $connection, array $mappings): array {
        $stats = [
            'fetched'  => 0,
            'created'  => 0,
            'updated'  => 0,
            'skipped'  => 0,
            'failed'   => 0,
            'errors'   => [],
            'status'   => 'success',
            'duration' => 0,
        ];

        try {
            // Parent-child enumeration — fire parent first, iterate IDs through child.
            if (!empty($endpoint->parent_endpoint_id)) {
                global $DB;
                $parent = $DB->get_record('ext_api_endpoints',
                    ['id' => $endpoint->parent_endpoint_id], '*', MUST_EXIST);

                mtrace("  Endpoint [{$endpoint->name}] depends on parent [{$parent->name}] — running enumeration.");

                $runner = new \local_external_api_sync\sync\parent_child_runner();
                $result = $runner->run($endpoint, $parent, $connection, $mappings);

                $stats['fetched']  = $result['ids_fetched'] ?? 0;
                $stats['created']  = $result['created']     ?? 0;
                $stats['updated']  = $result['updated']     ?? 0;
                $stats['skipped']  = $result['skipped']     ?? 0;
                $stats['failed']   = $result['errors']      ?? 0;
                $stats['errors']   = $result['error_list']  ?? [];

                if ($stats['failed'] > 0 && $stats['created'] === 0 && $stats['updated'] === 0) {
                    $stats['status'] = 'error';
                } elseif ($stats['failed'] > 0) {
                    $stats['status'] = 'partial';
                }

                return $stats;
            }

            // Teams calendar sync is self-contained — it queries Moodle DB
            // and Graph directly, bypassing the standard pull/push HTTP client.
            if ($endpoint->entity_type === 'teams_calendar') {
                $syncer = new calendar_sync($endpoint, $connection);
                $result = $syncer->process();
                $stats['errors'] = $syncer->get_errors();

            } elseif ($endpoint->direction === 'push') {
                $http_client = new client($connection, $endpoint);
                // Push: send Moodle data outward.
                $pusher = new push_sync($endpoint, $mappings, $http_client);
                $result = $pusher->process();
            } else {
                $http_client = new client($connection, $endpoint);
                $records         = $http_client->fetch_all();
                $stats['fetched'] = count($records);

                if (empty($records)) {
                    mtrace("  No records returned for [{$endpoint->name}].");
                    return $stats;
                }

                mtrace("  Fetched {$stats['fetched']} records.");

                switch ($endpoint->entity_type) {
                    case 'user':
                        $syncer = new user_sync($endpoint, $mappings);
                        $result = $syncer->process($records);
                        break;
                    case 'enrolment':
                        $syncer = new enrolment_sync($endpoint, $mappings);
                        $result = $syncer->process($records);
                        break;
                    case 'raw':
                    default:
                        // Raw mode: just log what was fetched, no Moodle writes.
                        mtrace("  Raw mode: {$stats['fetched']} records fetched, no Moodle writes.");
                        return $stats;
                }
            }

            // Merge results.
            $stats['created'] = $result['created'] ?? 0;
            $stats['updated'] = $result['updated'] ?? 0;
            $stats['skipped'] = $result['skipped'] ?? 0;
            $stats['failed']  = $result['failed'] ?? 0;
            $stats['errors']  = $result['errors'] ?? [];

        } catch (\Throwable $e) {
            $stats['failed']++;
            $stats['errors'][] = [
                'record' => 'endpoint',
                'error'  => $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')',
            ];
            mtrace('  ERROR: ' . $e->getMessage());
        }

        // Determine overall status.
        if ($stats['failed'] > 0 && $stats['created'] === 0 && $stats['updated'] === 0) {
            $stats['status'] = 'error';
        } elseif ($stats['failed'] > 0) {
            $stats['status'] = 'partial';
        } else {
            $stats['status'] = 'success';
        }

        return $stats;
    }

    /**
     * Check whether an endpoint is due to run based on its cron schedule.
     * Uses a simple 15-minute window check.
     *
     * @param object $endpoint
     * @param int    $now      Current unix timestamp
     * @return bool
     */
    private function is_due(object $endpoint, int $now): bool {
        if (empty($endpoint->schedule)) {
            return true; // No schedule means always run.
        }

        $last_run = (int) ($endpoint->last_run ?? 0);

        // Parse the cron expression to find the minimum interval.
        // This is a simplified approach — evaluates whether enough time
        // has passed since last run based on the schedule's hour field.
        try {
            $parts = explode(' ', trim($endpoint->schedule));
            if (count($parts) < 5) {
                return true;
            }

            [$minute, $hour, $day, $month, $dow] = $parts;

            // Calculate the interval in seconds from the schedule.
            $interval = $this->schedule_to_min_interval($minute, $hour);

            // If we've never run, or enough time has passed.
            return $last_run === 0 || ($now - $last_run) >= $interval;

        } catch (\Throwable $e) {
            return true; // On parse error, just run it.
        }
    }

    /**
     * Estimate minimum interval in seconds from minute/hour cron fields.
     *
     * @param string $minute
     * @param string $hour
     * @return int Seconds
     */
    private function schedule_to_min_interval(string $minute, string $hour): int {
        if ($minute === '*' && $hour === '*') {
            return 60; // Every minute.
        }
        if (str_starts_with($minute, '*/')) {
            $n = (int) substr($minute, 2);
            return $n * 60;
        }
        if ($hour === '*') {
            return 60 * 60; // Hourly.
        }
        if (str_starts_with($hour, '*/')) {
            $n = (int) substr($hour, 2);
            return $n * 3600;
        }
        // Specific hour means once daily minimum.
        return 24 * 3600;
    }

    /**
     * Extract a connection-shaped object from the joined endpoint record.
     *
     * @param object $endpoint Record from the JOIN query
     * @return object
     */
    private function extract_connection(object $endpoint): object {
        return (object) [
            'auth_type'       => $endpoint->auth_type,
            'base_url'        => $endpoint->base_url,
            'token_url'       => $endpoint->token_url ?? '',
            'client_id'       => $endpoint->client_id ?? '',
            'client_secret'   => $endpoint->client_secret ?? '',
            'oauth_scope'     => $endpoint->oauth_scope ?? '',
            'api_key'         => $endpoint->api_key ?? '',
            'api_key_header'  => $endpoint->api_key_header ?? 'X-API-Key',
            'api_key_location' => $endpoint->api_key_location ?? 'header',
            'api_key_param'   => $endpoint->api_key_param ?? 'api_key',
            'basic_username'  => $endpoint->basic_username ?? '',
            'basic_password'  => $endpoint->basic_password ?? '',
            'bearer_token'    => $endpoint->bearer_token ?? '',
        ];
    }

    /**
     * Write a sync log record to the database.
     *
     * @param int   $endpoint_id
     * @param int   $run_time
     * @param array $stats
     * @return int  Inserted log record ID
     */
    private function write_log(int $endpoint_id, int $connection_id, int $run_time, array $stats): int {
        global $DB;

        return $DB->insert_record('ext_api_sync_log', (object) [
            'endpoint_id'      => $endpoint_id,
            'connection_id'    => $connection_id,
            'run_time'         => $run_time,
            'duration_seconds' => $stats['duration'] ?? 0,
            'records_fetched'  => $stats['fetched'] ?? 0,
            'records_created'  => $stats['created'] ?? 0,
            'records_updated'  => $stats['updated'] ?? 0,
            'records_skipped'  => $stats['skipped'] ?? 0,
            'records_failed'   => $stats['failed'] ?? 0,
            'status'           => $stats['status'] ?? 'success',
            'error_details'    => !empty($stats['errors'])
                ? json_encode($stats['errors']) : null,
            'error_email_sent' => 0,
        ]);
    }

    /**
     * Send an error report email to configured addresses.
     *
     * @param object $endpoint
     * @param object $connection
     * @param array  $stats
     * @param int    $run_time
     */
    private function send_error_email(object $endpoint, object $connection,
            array $stats, int $run_time): void {

        $addresses = array_filter(array_map('trim', explode(',', $endpoint->error_email)));
        if (empty($addresses)) {
            return;
        }

        $error_lines = array_map(function ($e) {
            return '  [' . ($e['record'] ?? '?') . '] ' . ($e['error'] ?? '');
        }, $stats['errors']);

        $a = (object) [
            'endpoint'   => $endpoint->name,
            'connection' => $endpoint->connection_name ?? '',
            'runtime'    => userdate($run_time),
            'fetched'    => $stats['fetched'] ?? 0,
            'created'    => $stats['created'] ?? 0,
            'updated'    => $stats['updated'] ?? 0,
            'skipped'    => $stats['skipped'] ?? 0,
            'failed'     => $stats['failed'] ?? 0,
            'errors'     => implode("\n", $error_lines),
            'date'       => date('Y-m-d'),
        ];

        $subject = get_string('errorreport_subject', 'local_external_api_sync', $a);
        $body    = get_string('errorreport_body',    'local_external_api_sync', $a);

        // Build a noreply user object for the from address.
        $noreply = \core_user::get_noreply_user();

        foreach ($addresses as $address) {
            $to        = new \stdClass();
            $to->email = $address;
            $to->name  = $address;
            $to->id    = -1; // Fake ID for external recipient.
            $to->mailformat = FORMAT_PLAIN;
            $to->maildisplay = 0;

            email_to_user($to, $noreply, $subject, $body);
        }
    }

    /**
     * Delete log records older than the configured retention period.
     */
    private function purge_old_logs(): void {
        global $DB;

        $days = (int) get_config('local_external_api_sync', 'log_retention_days');
        if ($days <= 0) {
            return; // Keep indefinitely.
        }

        $cutoff = time() - ($days * DAYSECS);
        $DB->delete_records_select('ext_api_sync_log', 'run_time < :cutoff', ['cutoff' => $cutoff]);
    }
}
