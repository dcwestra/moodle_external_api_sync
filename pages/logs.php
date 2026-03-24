<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Sync log viewer.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_external_api_sync_logs');
require_capability('local/external_api_sync:viewlogs', context_system::instance());

$endpoint_id = optional_param('endpoint_id', 0, PARAM_INT);
$log_id      = optional_param('log_id', 0, PARAM_INT);
$page        = optional_param('page', 0, PARAM_INT);
$perpage     = 25;

// Optional filter by endpoint.
$endpoint   = null;
$connection = null;
if ($endpoint_id) {
    $endpoint   = $DB->get_record('ext_api_endpoints',   ['id' => $endpoint_id]);
    if ($endpoint) {
        $connection = $DB->get_record('ext_api_connections', ['id' => $endpoint->connection_id]);
    }
}

$PAGE->set_title(get_string('synclogs', 'local_external_api_sync'));
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('synclogs', 'local_external_api_sync'));

// -----------------------------------------------------------------------
// Error detail drill-down
// -----------------------------------------------------------------------
if ($log_id) {
    $log = $DB->get_record('ext_api_sync_log', ['id' => $log_id], '*', MUST_EXIST);
    $ep  = $DB->get_record('ext_api_endpoints', ['id' => $log->endpoint_id]);
    $cn  = $ep ? $DB->get_record('ext_api_connections', ['id' => $ep->connection_id]) : null;

    $back_url = new moodle_url('/local/external_api_sync/pages/logs.php',
        ['endpoint_id' => $log->endpoint_id]);
    echo html_writer::div(
        html_writer::link($back_url, '← Back to logs'),
        'mb-3'
    );

    echo $OUTPUT->heading(
        'Error Details: ' . format_string($ep->name ?? 'Unknown')
        . ' @ ' . userdate($log->run_time, get_string('strftimedatetimeshort', 'langconfig')),
        3
    );

    // Summary row.
    $summary = html_writer::tag('dl',
        implode('', array_map(fn($k, $v) =>
            html_writer::tag('dt', $k) . html_writer::tag('dd', $v),
            [
                'Connection', 'Status', 'Run time', 'Duration',
                'Fetched', 'Created', 'Updated', 'Skipped', 'Failed',
            ],
            [
                format_string($cn->name ?? 'Unknown'),
                html_writer::span(
                    get_string('logstatus_' . $log->status, 'local_external_api_sync', $log->status),
                    'badge badge-' . ['success' => 'success', 'partial' => 'warning', 'error' => 'danger'][$log->status] ?? 'secondary'
                ),
                userdate($log->run_time, get_string('strftimedatetimeshort', 'langconfig')),
                $log->duration_seconds . 's',
                $log->records_fetched,
                $log->records_created,
                $log->records_updated,
                $log->records_skipped,
                $log->records_failed,
            ]
        )),
        ['class' => 'row']
    );
    echo html_writer::div($summary, 'card card-body mb-3');

    if (!empty($log->error_details)) {
        $errors = json_decode($log->error_details, true) ?? [];
        if (!empty($errors)) {
            $etable = new \html_table();
            $etable->head = ['Record', 'Error'];
            $etable->attributes['class'] = 'generaltable table table-sm table-striped';
            foreach ($errors as $err) {
                $etable->data[] = [
                    html_writer::tag('code', s($err['record'] ?? 'unknown')),
                    s($err['error'] ?? ''),
                ];
            }
            echo html_writer::table($etable);
        } else {
            echo $OUTPUT->notification('No error details available.', \core\output\notification::NOTIFY_INFO);
        }
    } else {
        echo $OUTPUT->notification('No errors recorded for this run.', \core\output\notification::NOTIFY_SUCCESS);
    }

    echo $OUTPUT->footer();
    exit;
}

// -----------------------------------------------------------------------
// Main log list
// -----------------------------------------------------------------------

// Back link if filtered.
if ($endpoint) {
    $ep_list_url = new moodle_url('/local/external_api_sync/pages/endpoints.php',
        ['connection_id' => $connection->id ?? 0]);
    echo html_writer::div(
        html_writer::link($ep_list_url, '← ' . get_string('backtoendpoints', 'local_external_api_sync')),
        'mb-2'
    );
    echo $OUTPUT->heading(
        format_string($endpoint->name) . ' — ' . format_string($connection->name ?? ''),
        3
    );
}

// Endpoint filter dropdown.
$all_endpoints = $DB->get_records_sql(
    "SELECT e.id, e.name, c.name AS connection_name
       FROM {ext_api_endpoints} e
       JOIN {ext_api_connections} c ON c.id = e.connection_id
      ORDER BY c.name, e.name"
);
$filter_options = [0 => 'All Endpoints'];
foreach ($all_endpoints as $ep) {
    $filter_options[$ep->id] = format_string($ep->connection_name) . ' → ' . format_string($ep->name);
}
$filter_url = new moodle_url('/local/external_api_sync/pages/logs.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $filter_url, 'class' => 'mb-3 form-inline']);
echo html_writer::label('Filter by endpoint: ', 'endpoint_filter', true, ['class' => 'mr-2']);
echo html_writer::select($filter_options, 'endpoint_id', $endpoint_id, false,
    ['id' => 'endpoint_filter', 'class' => 'custom-select mr-2', 'onchange' => 'this.form.submit()']);
echo html_writer::end_tag('form');

// Query.
$where  = $endpoint_id ? 'l.endpoint_id = :epid' : '1=1';
$params = $endpoint_id ? ['epid' => $endpoint_id] : [];

$total = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {ext_api_sync_log} l WHERE $where", $params);

$sql = "SELECT l.*, e.name AS endpoint_name, c.name AS connection_name
          FROM {ext_api_sync_log} l
          JOIN {ext_api_endpoints} e ON e.id = l.endpoint_id
          JOIN {ext_api_connections} c ON c.id = e.connection_id
         WHERE $where
      ORDER BY l.run_time DESC";

$logs = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

if (empty($logs)) {
    echo $OUTPUT->notification(
        get_string('nologsyet', 'local_external_api_sync'),
        \core\output\notification::NOTIFY_INFO
    );
} else {
    $status_classes = [
        'success' => 'badge-success',
        'partial' => 'badge-warning',
        'error'   => 'badge-danger',
    ];

    $table = new \html_table();
    $table->head = [
        get_string('logrun',       'local_external_api_sync'),
        'Connection / Endpoint',
        get_string('logduration',  'local_external_api_sync'),
        get_string('logfetched',   'local_external_api_sync'),
        get_string('logcreated',   'local_external_api_sync'),
        get_string('logupdated',   'local_external_api_sync'),
        get_string('logskipped',   'local_external_api_sync'),
        get_string('logfailed',    'local_external_api_sync'),
        get_string('logstatus',    'local_external_api_sync'),
        '',
    ];
    $table->attributes['class'] = 'admintable generaltable table table-sm table-striped';

    foreach ($logs as $log) {
        $status    = $log->status ?? 'success';
        $cls       = $status_classes[$status] ?? 'badge-secondary';
        $status_b  = html_writer::span(
            get_string('logstatus_' . $status, 'local_external_api_sync', $status),
            'badge ' . $cls
        );

        $detail_url = new moodle_url('/local/external_api_sync/pages/logs.php',
            ['log_id' => $log->id]);
        $actions = [];
        if ($log->records_failed > 0) {
            $actions[] = html_writer::link($detail_url,
                get_string('viewerrors', 'local_external_api_sync'),
                ['class' => 'btn btn-sm btn-outline-danger']);
        } else {
            $actions[] = html_writer::link($detail_url, 'Details',
                ['class' => 'btn btn-sm btn-outline-secondary']);
        }

        $table->data[] = [
            userdate($log->run_time, get_string('strftimedatetimeshort', 'langconfig')),
            format_string($log->connection_name) . ' → ' . format_string($log->endpoint_name),
            $log->duration_seconds . 's',
            $log->records_fetched,
            $log->records_created,
            $log->records_updated,
            $log->records_skipped,
            html_writer::span($log->records_failed,
                $log->records_failed > 0 ? 'text-danger font-weight-bold' : ''),
            $status_b,
            implode(' ', $actions),
        ];
    }

    echo html_writer::table($table);

    // Pager.
    echo $OUTPUT->paging_bar($total, $page, $perpage,
        new moodle_url('/local/external_api_sync/pages/logs.php',
            ['endpoint_id' => $endpoint_id]));
}

echo $OUTPUT->footer();
