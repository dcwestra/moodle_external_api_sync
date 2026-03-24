<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Field mappings list for a given endpoint.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_external_api_sync_connections');
require_capability('local/external_api_sync:manage', context_system::instance());

$endpoint_id = required_param('endpoint_id', PARAM_INT);
$action      = optional_param('action', '', PARAM_ALPHA);
$id          = optional_param('id', 0, PARAM_INT);
$confirm     = optional_param('confirm', 0, PARAM_INT);

$endpoint   = $DB->get_record('ext_api_endpoints',   ['id' => $endpoint_id], '*', MUST_EXIST);
$connection = $DB->get_record('ext_api_connections', ['id' => $endpoint->connection_id], '*', MUST_EXIST);

$list_url    = new moodle_url('/local/external_api_sync/pages/mappings.php',
    ['endpoint_id' => $endpoint_id]);
$ep_list_url = new moodle_url('/local/external_api_sync/pages/endpoints.php',
    ['connection_id' => $connection->id]);

// Delete.
if ($action === 'delete' && $id) {
    require_sesskey();
    if ($confirm) {
        $m = $DB->get_record('ext_api_field_mappings', ['id' => $id], '*', MUST_EXIST);
        $DB->delete_records('ext_api_field_mappings', ['id' => $id]);
        redirect($list_url,
            get_string('deletedalert', 'local_external_api_sync', $m->external_field),
            null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Toggle enabled.
if ($action === 'toggle' && $id) {
    require_sesskey();
    $m = $DB->get_record('ext_api_field_mappings', ['id' => $id], '*', MUST_EXIST);
    $DB->set_field('ext_api_field_mappings', 'enabled', $m->enabled ? 0 : 1, ['id' => $id]);
    redirect($list_url);
}

$PAGE->set_title(get_string('fieldmappings', 'local_external_api_sync'));
echo $OUTPUT->header();
echo $OUTPUT->heading(
    get_string('fieldmappings', 'local_external_api_sync')
    . ': ' . format_string($endpoint->name)
    . ' (' . format_string($connection->name) . ')'
);

// Confirm delete.
if ($action === 'delete' && $id && !$confirm) {
    $m = $DB->get_record('ext_api_field_mappings', ['id' => $id], '*', MUST_EXIST);
    echo $OUTPUT->confirm(
        get_string('confirmdelete', 'local_external_api_sync', $m->external_field),
        new moodle_url('/local/external_api_sync/pages/mappings.php', [
            'endpoint_id' => $endpoint_id,
            'action'      => 'delete',
            'id'          => $id,
            'confirm'     => 1,
            'sesskey'     => sesskey(),
        ]),
        $list_url
    );
    echo $OUTPUT->footer();
    exit;
}

// No-key-field warning.
$key_count = $DB->count_records('ext_api_field_mappings',
    ['endpoint_id' => $endpoint_id, 'is_key_field' => 1, 'enabled' => 1]);
if ($key_count === 0) {
    echo $OUTPUT->notification(
        get_string('nokeyfield', 'local_external_api_sync'),
        \core\output\notification::NOTIFY_WARNING
    );
}

// Nav links + Add button.
echo html_writer::div(
    html_writer::link($ep_list_url, '← ' . get_string('backtoendpoints', 'local_external_api_sync')),
    'mb-2'
);

$add_url = new moodle_url('/local/external_api_sync/pages/edit_mapping.php',
    ['endpoint_id' => $endpoint_id]);
echo html_writer::div(
    $OUTPUT->single_button($add_url, get_string('addmapping', 'local_external_api_sync'), 'get'),
    'mb-3'
);

// Fetch mappings.
$mappings = $DB->get_records('ext_api_field_mappings',
    ['endpoint_id' => $endpoint_id], 'sortorder ASC, id ASC');

if (empty($mappings)) {
    echo $OUTPUT->notification(
        get_string('nomappings', 'local_external_api_sync'),
        \core\output\notification::NOTIFY_INFO
    );
} else {
    $transform_labels = [
        'none'       => get_string('transform_none',      'local_external_api_sync'),
        'uppercase'  => get_string('transform_uppercase', 'local_external_api_sync'),
        'lowercase'  => get_string('transform_lowercase', 'local_external_api_sync'),
        'trim'       => get_string('transform_trim',      'local_external_api_sync'),
        'date_unix'  => get_string('transform_date_unix', 'local_external_api_sync'),
        'date_iso'   => get_string('transform_date_iso',  'local_external_api_sync'),
        'prefix'     => get_string('transform_prefix',    'local_external_api_sync'),
        'suffix'     => get_string('transform_suffix',    'local_external_api_sync'),
    ];

    $table            = new \html_table();
    $table->head      = [
        '#',
        get_string('external_field', 'local_external_api_sync'),
        '→',
        get_string('internal_field', 'local_external_api_sync'),
        get_string('is_key_field',   'local_external_api_sync'),
        get_string('transform',      'local_external_api_sync'),
        get_string('default_value',  'local_external_api_sync'),
        get_string('enabled',        'local_external_api_sync'),
        '',
    ];
    $table->attributes['class'] = 'admintable generaltable table table-sm';

    foreach ($mappings as $m) {
        $key_badge = $m->is_key_field
            ? html_writer::span('KEY', 'badge badge-primary')
            : '';

        $enabled_badge = $m->enabled
            ? html_writer::span(get_string('status_enabled', 'local_external_api_sync'), 'badge badge-success')
            : html_writer::span(get_string('status_disabled', 'local_external_api_sync'), 'badge badge-secondary');

        $transform_label = $transform_labels[$m->transform ?? 'none'] ?? $m->transform;
        if (!empty($m->transform_arg)) {
            $transform_label .= ' (' . s($m->transform_arg) . ')';
        }

        $edit_url = new moodle_url('/local/external_api_sync/pages/edit_mapping.php',
            ['id' => $m->id, 'endpoint_id' => $endpoint_id]);
        $del_url  = new moodle_url('/local/external_api_sync/pages/mappings.php', [
            'endpoint_id' => $endpoint_id, 'action' => 'delete',
            'id' => $m->id, 'sesskey' => sesskey(),
        ]);
        $tog_url  = new moodle_url('/local/external_api_sync/pages/mappings.php', [
            'endpoint_id' => $endpoint_id, 'action' => 'toggle',
            'id' => $m->id, 'sesskey' => sesskey(),
        ]);

        $actions = implode(' ', [
            html_writer::link($edit_url, get_string('edit'),   ['class' => 'btn btn-sm btn-outline-secondary']),
            html_writer::link($tog_url,  $m->enabled ? get_string('disable') : get_string('enable'),
                ['class' => 'btn btn-sm btn-outline-secondary']),
            html_writer::link($del_url,  get_string('delete'), ['class' => 'btn btn-sm btn-outline-danger']),
        ]);

        $table->data[] = [
            $m->sortorder,
            html_writer::tag('code', s($m->external_field)),
            '→',
            html_writer::tag('code', s($m->internal_field)),
            $key_badge,
            $transform_label,
            s($m->default_value ?? ''),
            $enabled_badge,
            $actions,
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
