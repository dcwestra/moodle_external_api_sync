<?php
/**
 * Parent-child endpoint runner.
 *
 * Handles the two-stage enumeration pattern:
 *   1. Fire the parent endpoint to get a list of IDs
 *   2. Store IDs in ext_api_id_cache
 *   3. Iterate through IDs, firing the child endpoint once per ID
 *   4. Aggregate results into a single sync log entry
 *
 * The placeholder token in the child endpoint path or query params
 * (default: {XRefCode}) is substituted with each ID in turn.
 * Supports placeholder in URL path and/or query parameter values.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_external_api_sync\sync;

defined('MOODLE_INTERNAL') || die();

use local_external_api_sync\api\client;
use local_external_api_sync\api\response_parser;

class parent_child_runner {

    /**
     * Run a child endpoint that depends on a parent for its ID list.
     *
     * @param object $child_endpoint   Child endpoint DB record
     * @param object $parent_endpoint  Parent endpoint DB record
     * @param object $connection       Decrypted connection record
     * @return array ['processed' => int, 'created' => int, 'updated' => int,
     *               'errors' => int, 'skipped' => int, 'ids_fetched' => int]
     */
    public function run(
        object $child_endpoint,
        object $parent_endpoint,
        object $connection,
        array  $mappings = []
    ): array {

        global $DB;

        $stats = [
            'processed'  => 0,
            'created'    => 0,
            'updated'    => 0,
            'errors'     => 0,
            'error_list' => [],
            'skipped'    => 0,
            'ids_fetched' => 0,
        ];

        // ── Step 1: Fetch IDs from parent endpoint ────────────────────────────
        $run_id = time();
        $ids    = $this->fetch_parent_ids($parent_endpoint, $connection);

        if (empty($ids)) {
            mtrace("  [parent-child] Parent endpoint returned no IDs — nothing to process.");
            return $stats;
        }

        $stats['ids_fetched'] = count($ids);
        mtrace("  [parent-child] Fetched " . count($ids) . " IDs from parent endpoint '{$parent_endpoint->name}'.");

        // ── Step 2: Store IDs in cache ────────────────────────────────────────
        // Clear any previous runs for this parent endpoint first.
        $DB->delete_records('ext_api_id_cache', ['endpoint_id' => $parent_endpoint->id]);

        $cache_records = [];
        foreach ($ids as $item_id) {
            $cache_records[] = (object) [
                'endpoint_id' => $parent_endpoint->id,
                'run_id'      => $run_id,
                'item_id'     => (string) $item_id,
                'processed'   => 0,
                'timecreated' => time(),
            ];
        }
        $DB->insert_records('ext_api_id_cache', $cache_records);

        // ── Step 3: Iterate through IDs, fire child endpoint for each ─────────
        $placeholder = $child_endpoint->parent_id_placeholder ?: '{XRefCode}';

        // Determine the sync handler based on child endpoint entity type.
        foreach ($cache_records as $cache_row) {
            $item_id = $cache_row->item_id;

            try {
                // Build a modified endpoint with the placeholder substituted.
                $resolved_endpoint = $this->resolve_endpoint($child_endpoint, $placeholder, $item_id);

                // Fetch the detail record.
                $api_client = new client($connection, $resolved_endpoint);
                $records    = $api_client->fetch_all();

                if (empty($records)) {
                    $stats['skipped']++;
                } else {
                    // For detail endpoints the response is usually a single
                    // object at the root path, not a collection. Normalise.
                    if (!isset($records[0])) {
                        $records = [$records];
                    }

                    // Process through the appropriate sync handler.
                    $result = $this->process_records($records, $child_endpoint, $mappings);
                    $stats['processed'] += $result['processed'] ?? count($records);
                    $stats['created']   += $result['created']   ?? 0;
                    $stats['updated']   += $result['updated']   ?? 0;
                    $stats['skipped']   += $result['skipped']   ?? 0;
                    if (!empty($result['failed'])) {
                        $stats['errors']      += $result['failed'];
                        $stats['error_list']   = array_merge(
                            $stats['error_list'],
                            $result['errors'] ?? []
                        );
                    }
                }

                // Mark as processed.
                $DB->set_field('ext_api_id_cache', 'processed', 1,
                    ['endpoint_id' => $parent_endpoint->id, 'run_id' => $run_id, 'item_id' => $item_id]);

            } catch (\Throwable $e) {
                $stats['errors']++;
                $stats['error_list'][] = [
                    'record' => $item_id,
                    'error'  => $e->getMessage(),
                ];
                mtrace("  [parent-child] Error processing ID '{$item_id}': " . $e->getMessage());

                // Log individual ID failure but continue with remaining IDs.
                $DB->set_field('ext_api_id_cache', 'processed', 1,
                    ['endpoint_id' => $parent_endpoint->id, 'run_id' => $run_id, 'item_id' => $item_id]);
            }
        }

        mtrace("  [parent-child] Complete. Processed: {$stats['processed']}, "
            . "Created: {$stats['created']}, Updated: {$stats['updated']}, "
            . "Errors: {$stats['errors']}, Skipped: {$stats['skipped']}.");

        return $stats;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch the list of IDs from the parent endpoint.
     * Uses parent_id_path to extract IDs from the response.
     *
     * @param object $parent_endpoint
     * @param object $connection
     * @return string[] Flat array of ID values
     */
    private function fetch_parent_ids(object $parent_endpoint, object $connection): array {
        $api_client = new client($connection, $parent_endpoint);
        $records    = $api_client->fetch_all();

        if (empty($records)) {
            return [];
        }

        $id_path = $parent_endpoint->parent_id_path ?: 'XRefCode';
        $ids     = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $value = response_parser::get_value($record, $id_path);
            if ($value !== null && $value !== '') {
                $ids[] = (string) $value;
            }
        }

        return array_unique($ids);
    }

    /**
     * Return a modified endpoint record with the placeholder substituted
     * in both the path and any query parameter values.
     *
     * Supports:
     *   Path:   /Api/Eyecare/V1/Employees/{XRefCode}   → /Api/Eyecare/V1/Employees/0993954
     *   Params: {"id": "{XRefCode}"}                   → {"id": "0993954"}
     *
     * @param object $endpoint
     * @param string $placeholder  e.g. {XRefCode}
     * @param string $value        e.g. 0993954
     * @return object Cloned endpoint with substitution applied
     */
    private function resolve_endpoint(object $endpoint, string $placeholder, string $value): object {
        $resolved = clone $endpoint;

        // Substitute in URL path.
        $resolved->path = str_replace($placeholder, rawurlencode($value), $endpoint->path);

        // Substitute in query parameters JSON if present.
        if (!empty($endpoint->query_params)) {
            $resolved->query_params = str_replace(
                $placeholder,
                $value,
                $endpoint->query_params
            );
        }

        return $resolved;
    }

    /**
     * Get the appropriate sync handler for the child endpoint entity type.
     *
     * @param object $endpoint
     * @return object Sync handler with sync_record() method
     * @throws \moodle_exception
     */
    /**
     * Process a batch of records through the correct sync handler.
     * Handlers use a process(array $records) interface.
     *
     * @param array  $records   Records to process
     * @param object $endpoint  Child endpoint config
     * @param array  $mappings  Field mappings
     * @return array Stats array with processed/created/updated/skipped/failed/errors keys
     */
    private function process_records(array $records, object $endpoint, array $mappings): array {
        switch ($endpoint->entity_type) {
            case 'user':
                $handler = new \local_external_api_sync\sync\user_sync($endpoint, $mappings);
                $result  = $handler->process($records);
                $result['errors'] = $handler->get_errors();
                return $result;

            case 'enrolment':
                $handler = new \local_external_api_sync\sync\enrolment_sync($endpoint, $mappings);
                $result  = $handler->process($records);
                $result['errors'] = $handler->get_errors();
                return $result;

            case 'raw':
                // Raw mode in parent-child context — just count, no Moodle writes.
                mtrace("  [parent-child] Raw mode: " . count($records) . " record(s) fetched for this ID.");
                return ['processed' => count($records), 'created' => 0,
                        'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

            default:
                throw new \moodle_exception('errorentitytype', 'local_external_api_sync',
                    '', $endpoint->entity_type);
        }
    }
}


