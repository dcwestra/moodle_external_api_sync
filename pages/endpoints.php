<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Endpoints list page for a given connection.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_external_api_sync_connections');
require_capability('local/external_api_sync:manage', context_system::instance());

$connection_id = required_param('connection_id', PARAM_INT);
$action        = optional_param('action', '', PARAM_ALPHA);
$id            = optional_param('id', 0, PARAM_INT);
$confirm       = optional_param('confirm', 0, PARAM_INT);

$connection = $DB->get_record('ext_api_connections', ['id' => $connection_id], '*', MUST_EXIST);

$list_url = new moodle_url('/local/external_api_sync/pages/endpoints.php',
    ['connection_id' => $connection_id]);

// Handle delete.
if ($action === 'delete' && $id) {
    require_sesskey();
    if ($confirm) {
        $ep = $DB->get_record('ext_api_endpoints', ['id' => $id], '*', MUST_EXIST);
        $DB->delete_records('ext_api_field_mappings', ['endpoint_id' => $id]);
        $DB->delete_records('ext_api_sync_log',       ['endpoint_id' => $id]);
        $DB->delete_records('ext_api_endpoints',      ['id' => $id]);
        redirect($list_url,
            get_string('deletedalert', 'local_external_api_sync', $ep->name),
            null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Handle toggle.
if ($action === 'toggle' && $id) {
    require_sesskey();
    $ep = $DB->get_record('ext_api_endpoints', ['id' => $id], '*', MUST_EXIST);
    $DB->set_field('ext_api_endpoints', 'enabled', $ep->enabled ? 0 : 1, ['id' => $id]);
    redirect($list_url);
}

// Handle manual sync trigger.
if ($action === 'runsync' && $id) {
    require_sesskey();
    require_capability('local/external_api_sync:runsync', context_system::instance());

    $ep         = $DB->get_record('ext_api_endpoints', ['id' => $id], '*', MUST_EXIST);
    $conn       = $DB->get_record('ext_api_connections', ['id' => $ep->connection_id], '*', MUST_EXIST);
    $mappings   = array_values($DB->get_records('ext_api_field_mappings',
        ['endpoint_id' => $id], 'sortorder ASC'));

    // Decrypt and run.
    $conn = \local_external_api_sync\util\crypto::decrypt_connection($conn);

    try {
        $now        = time();
        $start_time = microtime(true);

        // Parent-only endpoints cannot be run manually — they only run via their child.
        if (!empty($ep->is_parent_only)) {
            redirect($list_url,
                get_string('parent_only_no_manual', 'local_external_api_sync'),
                null, \core\output\notification::NOTIFY_WARNING);
        }

        // Route through parent-child runner if this endpoint has a parent.
        if (!empty($ep->parent_endpoint_id)) {
            $parent = $DB->get_record('ext_api_endpoints',
                ['id' => $ep->parent_endpoint_id], '*', MUST_EXIST);
            $runner  = new \local_external_api_sync\sync\parent_child_runner();
            $interim = $runner->run($ep, $parent, $conn, $mappings);
            $result  = [
                'created' => $interim['created'],
                'updated' => $interim['updated'],
                'skipped' => $interim['skipped'],
                'failed'  => $interim['errors'],
                'errors'  => $interim['error_list'] ?? [],
            ];
            $fetched = $interim['ids_fetched'] ?? 0;
        } elseif ($ep->direction === 'push') {
            $client = new \local_external_api_sync\api\client($conn, $ep);
            $syncer = new \local_external_api_sync\sync\push_sync($ep, $mappings, $client);
            $result = $syncer->process();
            $fetched = 0;
        } else {
            $client  = new \local_external_api_sync\api\client($conn, $ep);
            $records = $client->fetch_all();
            $fetched = count($records);
            switch ($ep->entity_type) {
                case 'user':
                    $syncer = new \local_external_api_sync\sync\user_sync($ep, $mappings);
                    $result = $syncer->process($records);
                    break;
                case 'enrolment':
                    $syncer = new \local_external_api_sync\sync\enrolment_sync($ep, $mappings);
                    $result = $syncer->process($records);
                    break;
                default:
                    $result = ['created' => 0, 'updated' => 0, 'skipped' => $fetched,
                               'failed' => 0, 'errors' => []];
            }
        }

        $status = ($result['failed'] ?? 0) > 0
            ? (($result['created'] + $result['updated']) > 0 ? 'partial' : 'error')
            : 'success';

        $DB->insert_record('ext_api_sync_log', (object)[
            'endpoint_id'      => $id,
            'connection_id'    => $ep->connection_id,
            'run_time'         => $now,
            'duration_seconds' => (int)(microtime(true) - $start_time),
            'records_fetched'  => $fetched,
            'records_created'  => $result['created']  ?? 0,
            'records_updated'  => $result['updated']  ?? 0,
            'records_skipped'  => $result['skipped']  ?? 0,
            'records_failed'   => $result['failed']   ?? 0,
            'status'           => $status,
            'error_details'    => !empty($result['errors']) ? json_encode($result['errors']) : null,
            'error_email_sent' => 0,
        ]);
        $DB->update_record('ext_api_endpoints', (object)[
            'id'           => $id,
            'last_run'     => $now,
            'last_status'  => $status,
            'timemodified' => $now,
        ]);

        redirect($list_url,
            get_string('synctriggered', 'local_external_api_sync'),
            null, \core\output\notification::NOTIFY_SUCCESS);

    } catch (\Throwable $e) {
        redirect($list_url,
            'Sync failed: ' . $e->getMessage(),
            null, \core\output\notification::NOTIFY_ERROR);
    }
}

$PAGE->set_title(get_string('endpoints', 'local_external_api_sync'));
echo $OUTPUT->header();
echo $OUTPUT->heading(
    get_string('endpoints', 'local_external_api_sync') . ': ' . format_string($connection->name)
);

// Confirm delete dialog.
if ($action === 'delete' && $id && !$confirm) {
    $ep = $DB->get_record('ext_api_endpoints', ['id' => $id], '*', MUST_EXIST);
    echo $OUTPUT->confirm(
        get_string('confirmdelete', 'local_external_api_sync', $ep->name),
        new moodle_url('/local/external_api_sync/pages/endpoints.php', [
            'connection_id' => $connection_id,
            'action'        => 'delete',
            'id'            => $id,
            'confirm'       => 1,
            'sesskey'       => sesskey(),
        ]),
        $list_url
    );
    echo $OUTPUT->footer();
    exit;
}

// Back link + Add button.
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/external_api_sync/pages/connections.php'),
        '← ' . get_string('backtconnections', 'local_external_api_sync')
    ), 'mb-2'
);

$add_url = new moodle_url('/local/external_api_sync/pages/edit_endpoint.php',
    ['connection_id' => $connection_id]);
echo html_writer::div(
    $OUTPUT->single_button($add_url, get_string('addendpoint', 'local_external_api_sync'), 'get'),
    'mb-3'
);

// Fetch endpoints.
$endpoints = $DB->get_records('ext_api_endpoints',
    ['connection_id' => $connection_id], 'name ASC');

if (empty($endpoints)) {
    echo $OUTPUT->notification(
        get_string('noendpoints', 'local_external_api_sync'),
        \core\output\notification::NOTIFY_INFO
    );
} else {
    $direction_labels = [
        'pull' => '↓ ' . get_string('direction_pull', 'local_external_api_sync'),
        'push' => '↑ ' . get_string('direction_push', 'local_external_api_sync'),
    ];
    $entity_labels = [
        'user'      => get_string('entity_user',      'local_external_api_sync'),
        'enrolment' => get_string('entity_enrolment', 'local_external_api_sync'),
        'raw'       => get_string('entity_raw',       'local_external_api_sync'),
    ];
    $status_classes = [
        'success' => 'badge-success',
        'partial' => 'badge-warning',
        'error'   => 'badge-danger',
        'never'   => 'badge-secondary',
    ];

    $table            = new \html_table();
    $table->head      = [
        get_string('endpointname', 'local_external_api_sync'),
        get_string('direction',    'local_external_api_sync'),
        get_string('entity_type',  'local_external_api_sync'),
        get_string('schedule',     'local_external_api_sync'),
        'Last Run',
        get_string('logstatus',    'local_external_api_sync'),
        get_string('enabled',      'local_external_api_sync'),
        '',
    ];
    $table->attributes['class'] = 'admintable generaltable table table-sm';

    foreach ($endpoints as $ep) {
        $last_run  = $ep->last_run
            ? userdate($ep->last_run, get_string('strftimedatetimeshort', 'langconfig'))
            : get_string('neverrun', 'local_external_api_sync');

        $status    = $ep->last_status ?: 'never';
        $status_cls = $status_classes[$status] ?? 'badge-secondary';
        $status_badge = html_writer::span(
            get_string('logstatus_' . $status, 'local_external_api_sync', $status),
            'badge ' . $status_cls
        );

        $enabled_badge = $ep->enabled
            ? html_writer::span(get_string('status_enabled', 'local_external_api_sync'), 'badge badge-success')
            : html_writer::span(get_string('status_disabled', 'local_external_api_sync'), 'badge badge-secondary');

        $map_url  = new moodle_url('/local/external_api_sync/pages/mappings.php', ['endpoint_id' => $ep->id]);
        $edit_url = new moodle_url('/local/external_api_sync/pages/edit_endpoint.php',
            ['id' => $ep->id, 'connection_id' => $connection_id]);
        $del_url  = new moodle_url('/local/external_api_sync/pages/endpoints.php', [
            'connection_id' => $connection_id, 'action' => 'delete',
            'id' => $ep->id, 'sesskey' => sesskey(),
        ]);
        $tog_url  = new moodle_url('/local/external_api_sync/pages/endpoints.php', [
            'connection_id' => $connection_id, 'action' => 'toggle',
            'id' => $ep->id, 'sesskey' => sesskey(),
        ]);
        $run_url  = new moodle_url('/local/external_api_sync/pages/endpoints.php', [
            'connection_id' => $connection_id, 'action' => 'runsync',
            'id' => $ep->id, 'sesskey' => sesskey(),
        ]);
        $log_url  = new moodle_url('/local/external_api_sync/pages/logs.php', ['endpoint_id' => $ep->id]);

        $actions = implode(' ', [
            html_writer::link($map_url,  'Mappings', ['class' => 'btn btn-sm btn-outline-primary']),
            html_writer::link($edit_url, get_string('edit'), ['class' => 'btn btn-sm btn-outline-secondary']),
            html_writer::link($run_url,  get_string('runsyncnow', 'local_external_api_sync'),
                ['class' => 'btn btn-sm btn-outline-success']),
            html_writer::link($log_url,  get_string('synclogs', 'local_external_api_sync'),
                ['class' => 'btn btn-sm btn-outline-info']),
            html_writer::link($tog_url,  $ep->enabled ? get_string('disable') : get_string('enable'),
                ['class' => 'btn btn-sm btn-outline-secondary']),
            html_writer::link($del_url,  get_string('delete'),
                ['class' => 'btn btn-sm btn-outline-danger']),
        ]);

        $table->data[] = [
            format_string($ep->name)
                . (!empty($ep->is_parent_only)
                    ? ' ' . html_writer::span(
                        get_string('parent_only_badge', 'local_external_api_sync'),
                        'badge badge-info ml-1')
                    : '')
                . (!empty($ep->parent_endpoint_id)
                    ? ' ' . html_writer::span(
                        get_string('child_endpoint_badge', 'local_external_api_sync'),
                        'badge badge-secondary ml-1')
                    : ''),
            $direction_labels[$ep->direction] ?? $ep->direction,
            $entity_labels[$ep->entity_type] ?? $ep->entity_type,
            html_writer::tag('code', s($ep->schedule ?: '*')),
            $last_run,
            $status_badge,
            $enabled_badge,
            $actions,
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
