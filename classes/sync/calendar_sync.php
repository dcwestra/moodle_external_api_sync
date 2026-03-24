<?php
/**
 * Teams Calendar Sync — syncs enrolled Moodle users as attendees on a
 * Microsoft Teams meeting via the Graph Calendar API.
 *
 * Flow per course:
 *   1. Find all mod_msteams activities in the course.
 *   2. For each meeting, resolve the Graph event ID by searching the
 *      service account's calendar (matched by Teams join URL).
 *   3. GET current attendees from Graph.
 *   4. GET enrolled user emails from Moodle.
 *   5. Diff: add any enrolled emails not yet in the attendees list.
 *   6. PATCH the Graph event with the merged attendee list.
 *
 * Configuration (stored on the endpoint record via query_params JSON):
 *   - service_account_upn : UPN of the shared Teams organiser account
 *                           e.g. "training@eyecarepartners.com"
 *
 * The connection must use auth_type = oauth2 pointed at:
 *   base_url   : https://graph.microsoft.com
 *   token_url  : https://login.microsoftonline.com/{tenant_id}/oauth2/v2.0/token
 *   scope      : https://graph.microsoft.com/.default
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_external_api_sync\sync;

defined('MOODLE_INTERNAL') || die();

use local_external_api_sync\api\auth\oauth2;

class calendar_sync {

    /** @var object Endpoint record */
    private $endpoint;

    /** @var object Decrypted connection record */
    private $connection;

    /** @var string Service account UPN from endpoint query_params */
    private $service_account_upn;

    /** @var bool Test mode — no Graph writes */
    private $test_mode;

    /** @var array Stats */
    private $stats = [
        'processed' => 0,
        'created'   => 0,
        'updated'   => 0,
        'skipped'   => 0,
        'failed'    => 0,
    ];

    /** @var array Per-record errors */
    private $errors = [];

    /** @var oauth2 Auth handler */
    private $auth;

    public function __construct(object $endpoint, object $connection) {
        $this->endpoint  = $endpoint;
        $this->connection = $connection;
        $this->test_mode = (bool) get_config('local_external_api_sync', 'test_mode');
        $this->auth      = new oauth2($connection);

        // Read service account UPN from endpoint's query_params JSON field.
        $params = json_decode($endpoint->query_params ?? '{}', true) ?? [];
        $this->service_account_upn = trim($params['service_account_upn'] ?? '');
    }

    /**
     * Run the sync for all courses that have mod_msteams activities.
     *
     * @return array Stats
     */
    public function process(): array {
        global $DB;

        if (empty($this->service_account_upn)) {
            throw new \moodle_exception('errorhttp', 'local_external_api_sync', '',
                'calendar_sync: service_account_upn is not configured in endpoint query_params.');
        }

        // Find all enabled msteams activities with a future (or recent) meeting.
        $sql = "SELECT m.id, m.course, m.name, m.meetingurl, m.starttime, m.duration
                  FROM {msteams} m
                  JOIN {course} c ON c.id = m.course
                 WHERE c.visible = 1
                   AND m.meetingurl IS NOT NULL
                   AND m.meetingurl <> ''
              ORDER BY m.course ASC, m.starttime ASC";

        $meetings = $DB->get_records_sql($sql);

        if (empty($meetings)) {
            mtrace('  calendar_sync: no mod_msteams activities found.');
            return $this->stats;
        }

        mtrace('  calendar_sync: found ' . count($meetings) . ' Teams meeting(s) to process.');

        foreach ($meetings as $meeting) {
            $this->stats['processed']++;
            try {
                $this->sync_meeting($meeting);
            } catch (\Throwable $e) {
                $this->stats['failed']++;
                $this->errors[] = [
                    'record' => "meeting:{$meeting->id} course:{$meeting->course}",
                    'error'  => $e->getMessage(),
                ];
                mtrace('  calendar_sync ERROR [meeting ' . $meeting->id . ']: ' . $e->getMessage());
            }
        }

        return $this->stats;
    }

    public function get_errors(): array {
        return $this->errors;
    }

    // -----------------------------------------------------------------------
    // Private — per-meeting sync
    // -----------------------------------------------------------------------

    /**
     * Sync one Teams meeting: diff Moodle enrolments vs Graph attendees, PATCH if needed.
     */
    private function sync_meeting(object $meeting): void {

        // 1. Resolve Graph event ID from the Teams join URL.
        $graph_event = $this->find_graph_event($meeting->meetingurl, $meeting->starttime);

        if (!$graph_event) {
            $this->stats['skipped']++;
            $this->errors[] = [
                'record' => "meeting:{$meeting->id}",
                'error'  => 'Could not find matching Graph calendar event for join URL: ' . $meeting->meetingurl,
            ];
            mtrace('    Skipped meeting ' . $meeting->id . ': no matching Graph event found.');
            return;
        }

        $event_id = $graph_event['id'];

        // 2. Get current attendees from Graph.
        $current_attendee_emails = $this->extract_attendee_emails($graph_event['attendees'] ?? []);

        // 3. Get enrolled user emails from Moodle.
        $enrolled_emails = $this->get_enrolled_emails($meeting->course);

        if (empty($enrolled_emails)) {
            $this->stats['skipped']++;
            mtrace('    Skipped meeting ' . $meeting->id . ': no enrolled users found.');
            return;
        }

        // 4. Diff — emails to add (enrolled but not yet attendees).
        $emails_to_add = array_diff(
            array_map('strtolower', $enrolled_emails),
            array_map('strtolower', $current_attendee_emails)
        );

        if (empty($emails_to_add)) {
            $this->stats['skipped']++;
            mtrace('    Meeting ' . $meeting->id . ': all ' . count($enrolled_emails) . ' enrolled users already attendees. No update needed.');
            return;
        }

        mtrace('    Meeting ' . $meeting->id . ': adding ' . count($emails_to_add) . ' new attendee(s).');

        if ($this->test_mode) {
            mtrace('    [TEST MODE] Would PATCH event ' . $event_id . ' with: ' . implode(', ', $emails_to_add));
            $this->stats['updated']++;
            return;
        }

        // 5. Build merged attendees list (existing + new).
        $merged_attendees = $this->build_merged_attendees(
            $graph_event['attendees'] ?? [],
            $emails_to_add
        );

        // 6. PATCH the Graph event.
        $this->patch_event_attendees($event_id, $merged_attendees);
        $this->stats['updated']++;

        mtrace('    Meeting ' . $meeting->id . ': successfully updated attendees.');
    }

    // -----------------------------------------------------------------------
    // Private — Graph API calls
    // -----------------------------------------------------------------------

    /**
     * Find a Graph calendar event by matching the Teams join URL in the event body/location.
     * Falls back to matching by start time if multiple candidates are returned.
     *
     * @param string   $join_url   Teams join URL from mod_msteams
     * @param int|null $starttime  Unix timestamp of meeting start (optional but improves accuracy)
     * @return array|null Graph event object, or null if not found
     */
    private function find_graph_event(string $join_url, ?int $starttime): ?array {
        // Search the service account's calendar for events containing the join URL.
        // We filter by isOnlineMeeting=true and optionally by start time window.
        $filter_parts = ['isOnlineMeeting eq true'];

        if (!empty($starttime)) {
            // Search within a ±24h window around the stored start time.
            $from = date('Y-m-d\TH:i:s', $starttime - 86400);
            $to   = date('Y-m-d\TH:i:s', $starttime + 86400);
            $filter_parts[] = "start/dateTime ge '{$from}'";
            $filter_parts[] = "start/dateTime le '{$to}'";
        }

        $filter   = implode(' and ', $filter_parts);
        $select   = 'id,subject,start,end,attendees,onlineMeeting,body,location';
        $url      = $this->graph_url(
            "/users/{$this->service_account_upn}/events",
            [
                '$filter' => $filter,
                '$select' => $select,
                '$top'    => '50',
            ]
        );

        $response = $this->graph_get($url);
        $events   = $response['value'] ?? [];

        // Match by join URL substring in onlineMeeting.joinUrl, body, or location.
        // Normalise the join URL for comparison (strip tracking params).
        $normalised_join = $this->normalise_join_url($join_url);

        foreach ($events as $event) {
            // Check onlineMeeting.joinUrl first (most reliable).
            $event_join = $event['onlineMeeting']['joinUrl'] ?? '';
            if (!empty($event_join) && $this->normalise_join_url($event_join) === $normalised_join) {
                return $event;
            }

            // Fall back: check if join URL appears in the event body HTML.
            $body_content = $event['body']['content'] ?? '';
            if (!empty($body_content) && strpos($body_content, $normalised_join) !== false) {
                return $event;
            }

            // Also check location displayName / locationUri.
            $location_uri = $event['location']['locationUri'] ?? '';
            if (!empty($location_uri) && $this->normalise_join_url($location_uri) === $normalised_join) {
                return $event;
            }
        }

        return null;
    }

    /**
     * PATCH a Graph calendar event with a new attendees list.
     *
     * @param string $event_id         Graph event ID
     * @param array  $merged_attendees Full attendees array (existing + new)
     */
    private function patch_event_attendees(string $event_id, array $merged_attendees): void {
        $url  = $this->graph_url("/users/{$this->service_account_upn}/events/{$event_id}");
        $body = json_encode(['attendees' => $merged_attendees], JSON_UNESCAPED_UNICODE);

        $this->graph_request('PATCH', $url, $body);
    }

    // -----------------------------------------------------------------------
    // Private — Moodle DB helpers
    // -----------------------------------------------------------------------

    /**
     * Get email addresses of all active enrolled users in a course.
     *
     * @param int $courseid
     * @return string[] Array of email addresses
     */
    private function get_enrolled_emails(int $courseid): array {
        global $DB;

        $sql = "SELECT DISTINCT u.email
                  FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
                 WHERE u.deleted = 0
                   AND u.suspended = 0
                   AND u.email IS NOT NULL
                   AND u.email <> ''
                   AND ue.status = 0";

        $records = $DB->get_records_sql($sql, ['courseid' => $courseid]);
        return array_column(array_values($records), 'email');
    }

    // -----------------------------------------------------------------------
    // Private — attendee list helpers
    // -----------------------------------------------------------------------

    /**
     * Extract email addresses from a Graph attendees array.
     *
     * @param array $attendees Graph attendees array
     * @return string[]
     */
    private function extract_attendee_emails(array $attendees): array {
        $emails = [];
        foreach ($attendees as $attendee) {
            $email = $attendee['emailAddress']['address'] ?? '';
            if (!empty($email)) {
                $emails[] = strtolower($email);
            }
        }
        return $emails;
    }

    /**
     * Build a merged Graph attendees array: keep existing entries intact,
     * append new ones as 'required' attendees.
     *
     * @param array    $existing_attendees  Current Graph attendees array
     * @param string[] $emails_to_add       New email addresses to add
     * @return array   Merged attendees array ready for PATCH
     */
    private function build_merged_attendees(array $existing_attendees, array $emails_to_add): array {
        $merged = $existing_attendees; // Preserve existing type/status/response.

        foreach ($emails_to_add as $email) {
            $merged[] = [
                'emailAddress' => [
                    'address' => $email,
                ],
                'type' => 'required',
            ];
        }

        return $merged;
    }

    /**
     * Strip query string from a Teams join URL for comparison.
     * Teams URLs often have tracking params that differ between contexts.
     *
     * @param string $url
     * @return string Base URL without query string
     */
    private function normalise_join_url(string $url): string {
        $parsed = parse_url(trim($url));
        $base   = ($parsed['scheme'] ?? 'https') . '://'
                . ($parsed['host'] ?? '')
                . ($parsed['path'] ?? '');
        return rtrim($base, '/');
    }

    // -----------------------------------------------------------------------
    // Private — HTTP helpers
    // -----------------------------------------------------------------------

    /**
     * Build a Graph API URL with optional query parameters.
     *
     * @param string $path        e.g. /users/upn@example.com/events
     * @param array  $query_params
     * @return string
     */
    private function graph_url(string $path, array $query_params = []): string {
        $base = rtrim($this->connection->base_url, '/'); // https://graph.microsoft.com
        $url  = $base . '/v1.0' . $path;

        if (!empty($query_params)) {
            $url .= '?' . http_build_query($query_params);
        }

        return $url;
    }

    /**
     * Perform a GET request against the Graph API.
     *
     * @param string $url
     * @return array Decoded JSON response
     */
    private function graph_get(string $url): array {
        return $this->graph_request('GET', $url);
    }

    /**
     * Perform an authenticated HTTP request against the Graph API.
     *
     * @param string      $method  GET | PATCH
     * @param string      $url
     * @param string|null $body    JSON body for PATCH
     * @return array Decoded JSON response
     * @throws \moodle_exception on HTTP error
     */
    private function graph_request(string $method, string $url, ?string $body = null): array {
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setopt([
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT'        => 30,
            'CURLOPT_FOLLOWLOCATION' => true,
        ]);

        $headers = [
            'Authorization: Bearer ' . $this->auth->get_token(),
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        $curl->setHeader($headers);

        $method = strtoupper($method);

        switch ($method) {
            case 'GET':
                $raw = $curl->get($url);
                break;
            case 'PATCH':
                $curl->setopt(['CURLOPT_CUSTOMREQUEST' => 'PATCH']);
                $raw = $curl->post($url, $body ?? '{}');
                break;
            default:
                throw new \moodle_exception('errorhttp', 'local_external_api_sync', '',
                    "calendar_sync: unsupported method {$method}");
        }

        if ($curl->get_errno()) {
            throw new \moodle_exception('errorhttp', 'local_external_api_sync', '',
                'calendar_sync cURL error: ' . $curl->error);
        }

        $info      = $curl->get_info();
        $http_code = $info['http_code'] ?? 0;

        // 204 No Content is a valid success for PATCH.
        if ($http_code === 204) {
            return [];
        }

        if ($http_code < 200 || $http_code >= 300) {
            // On 401, invalidate the cached token so the next run re-authenticates.
            if ($http_code === 401) {
                $this->auth->invalidate_cache();
            }
            throw new \moodle_exception('errorhttp', 'local_external_api_sync', '',
                "calendar_sync: Graph API returned HTTP {$http_code} for {$url}: " . substr($raw, 0, 500));
        }

        if (empty($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('errorhttp', 'local_external_api_sync', '',
                'calendar_sync: non-JSON response from Graph: ' . substr($raw, 0, 200));
        }

        return $decoded ?? [];
    }
}
