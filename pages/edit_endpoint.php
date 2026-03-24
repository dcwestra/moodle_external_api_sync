<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Create / edit an endpoint.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

admin_externalpage_setup('local_external_api_sync_connections');
require_capability('local/external_api_sync:manage', context_system::instance());

$id            = optional_param('id', 0, PARAM_INT);
$connection_id = required_param('connection_id', PARAM_INT);

$connection = $DB->get_record('ext_api_connections', ['id' => $connection_id], '*', MUST_EXIST);
$record     = null;
if ($id) {
    $record = $DB->get_record('ext_api_endpoints', ['id' => $id], '*', MUST_EXIST);
}

$list_url = new moodle_url('/local/external_api_sync/pages/endpoints.php',
    ['connection_id' => $connection_id]);

// -----------------------------------------------------------------------
// Form definition
// -----------------------------------------------------------------------
class edit_endpoint_form extends moodleform {

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'connection_id', 0);
        $mform->setType('connection_id', PARAM_INT);

        // Basic info.
        $mform->addElement('header', 'basic_header', get_string('endpointname', 'local_external_api_sync'));

        $mform->addElement('text', 'name',
            get_string('endpointname', 'local_external_api_sync'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addHelpButton('name', 'endpointname', 'local_external_api_sync');

        $mform->addElement('textarea', 'description',
            get_string('description', 'local_external_api_sync'), ['rows' => 2, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);

        $mform->addElement('text', 'path',
            get_string('path', 'local_external_api_sync'), ['size' => 60]);
        $mform->setType('path', PARAM_RAW);
        $mform->addRule('path', null, 'required', null, 'client');
        $mform->addHelpButton('path', 'path', 'local_external_api_sync');

        $mform->addElement('select', 'http_method',
            get_string('http_method', 'local_external_api_sync'),
            ['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH']);

        $mform->addElement('advcheckbox', 'enabled',
            get_string('enabled', 'local_external_api_sync'));
        $mform->setDefault('enabled', 1);

        // Sync behaviour.
        $mform->addElement('header', 'sync_header', get_string('direction', 'local_external_api_sync'));

        $direction_opts = [
            'pull' => get_string('direction_pull', 'local_external_api_sync'),
            'push' => get_string('direction_push', 'local_external_api_sync'),
        ];
        $mform->addElement('select', 'direction',
            get_string('direction', 'local_external_api_sync'), $direction_opts);
        $mform->addHelpButton('direction', 'direction', 'local_external_api_sync');

        $entity_opts = [
            'user'                => get_string('entity_user',                'local_external_api_sync'),
            'enrolment'           => get_string('entity_enrolment',           'local_external_api_sync'),
            'raw'                 => get_string('entity_raw',                 'local_external_api_sync'),
            'teams_calendar'      => get_string('entity_teams_calendar',      'local_external_api_sync'),
            'course_completion'   => get_string('entity_course_completion',   'local_external_api_sync'),
            'activity_completion' => get_string('entity_activity_completion', 'local_external_api_sync'),
        ];
        $mform->addElement('select', 'entity_type',
            get_string('entity_type', 'local_external_api_sync'), $entity_opts);
        $mform->addHelpButton('entity_type', 'entity_type', 'local_external_api_sync');

        $action_opts = [
            'create_update' => get_string('action_create_update', 'local_external_api_sync'),
            'suspend'       => get_string('action_suspend',       'local_external_api_sync'),
            'enrol'         => get_string('action_enrol',         'local_external_api_sync'),
            'unenrol'       => get_string('action_unenrol',       'local_external_api_sync'),
        ];
        $mform->addElement('select', 'sync_action',
            get_string('sync_action', 'local_external_api_sync'), $action_opts);
        $mform->addHelpButton('sync_action', 'sync_action', 'local_external_api_sync');

        // Response / request parsing.
        $mform->addElement('header', 'parsing_header',
            get_string('response_root_path', 'local_external_api_sync'));

        $mform->addElement('text', 'response_root_path',
            get_string('response_root_path', 'local_external_api_sync'), ['size' => 60]);
        $mform->setType('response_root_path', PARAM_TEXT);
        $mform->addHelpButton('response_root_path', 'response_root_path', 'local_external_api_sync');

        $mform->addElement('textarea', 'request_body_template',
            get_string('request_body_template', 'local_external_api_sync'), ['rows' => 5, 'cols' => 60]);
        $mform->setType('request_body_template', PARAM_RAW);
        $mform->addHelpButton('request_body_template', 'request_body_template', 'local_external_api_sync');

        $mform->addElement('textarea', 'extra_headers',
            get_string('extra_headers', 'local_external_api_sync'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('extra_headers', PARAM_RAW);
        $mform->addHelpButton('extra_headers', 'extra_headers', 'local_external_api_sync');

        $mform->addElement('textarea', 'query_params',
            get_string('query_params', 'local_external_api_sync'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('query_params', PARAM_RAW);
        $mform->addHelpButton('query_params', 'query_params', 'local_external_api_sync');

        // Pagination.
        $mform->addElement('header', 'pagination_header',
            get_string('pagination_enabled', 'local_external_api_sync'));

        $mform->addElement('advcheckbox', 'pagination_enabled',
            get_string('pagination_enabled', 'local_external_api_sync'));

        $pagination_type_opts = [
            'page'   => get_string('pagination_page',   'local_external_api_sync'),
            'offset' => get_string('pagination_offset', 'local_external_api_sync'),
            'cursor' => get_string('pagination_cursor', 'local_external_api_sync'),
        ];
        $mform->addElement('select', 'pagination_type',
            get_string('pagination_type', 'local_external_api_sync'), $pagination_type_opts);

        $mform->addElement('text', 'pagination_param',
            get_string('pagination_param', 'local_external_api_sync'), ['size' => 30]);
        $mform->setType('pagination_param', PARAM_TEXT);
        $mform->setDefault('pagination_param', 'pageNumber');

        $mform->addElement('text', 'page_size_param',
            get_string('page_size_param', 'local_external_api_sync'), ['size' => 30]);
        $mform->setType('page_size_param', PARAM_TEXT);
        $mform->setDefault('page_size_param', 'pageSize');

        $mform->addElement('text', 'page_size',
            get_string('page_size', 'local_external_api_sync'), ['size' => 10]);
        $mform->setType('page_size', PARAM_INT);
        $mform->setDefault('page_size', 100);

        $mform->addElement('text', 'total_count_path',
            get_string('total_count_path', 'local_external_api_sync'), ['size' => 40]);
        $mform->setType('total_count_path', PARAM_TEXT);
        $mform->addHelpButton('total_count_path', 'total_count_path', 'local_external_api_sync');

        // Schedule & notifications.
        $mform->addElement('header', 'schedule_header', get_string('schedule', 'local_external_api_sync'));

        $mform->addElement('text', 'schedule',
            get_string('schedule', 'local_external_api_sync'), ['size' => 30]);
        $mform->setType('schedule', PARAM_TEXT);
        $mform->setDefault('schedule', '0 2 * * *');
        $mform->addHelpButton('schedule', 'schedule', 'local_external_api_sync');

        $mform->addElement('text', 'error_email',
            get_string('error_email', 'local_external_api_sync'), ['size' => 60]);
        $mform->setType('error_email', PARAM_TEXT);
        $mform->addHelpButton('error_email', 'error_email', 'local_external_api_sync');

        // ── Parent-Child Enumeration ──────────────────────────────────────────
        $mform->addElement('header', 'parent_child_header',
            get_string('parent_child_settings', 'local_external_api_sync'));

        $mform->addElement('advcheckbox', 'is_parent_only',
            get_string('is_parent_only', 'local_external_api_sync'));
        $mform->addHelpButton('is_parent_only', 'is_parent_only', 'local_external_api_sync');

        // Build list of endpoints in same connection for parent selection.
        // Populated dynamically — connection_id may not be set for new endpoints.
        $parent_opts = [0 => get_string('none', 'local_external_api_sync')];
        if (!empty($this->_customdata['connection_id'])) {
            global $DB;
            $siblings = $DB->get_records('ext_api_endpoints',
                ['connection_id' => $this->_customdata['connection_id']],
                'name ASC', 'id, name, is_parent_only');
            foreach ($siblings as $sib) {
                if (empty($this->_customdata['endpoint_id']) || $sib->id != $this->_customdata['endpoint_id']) {
                    $label = $sib->name;
                    if (!empty($sib->is_parent_only)) {
                        $label .= ' ' . get_string('parent_only_badge', 'local_external_api_sync');
                    }
                    $parent_opts[$sib->id] = $label;
                }
            }
        }

        $mform->addElement('select', 'parent_endpoint_id',
            get_string('parent_endpoint', 'local_external_api_sync'), $parent_opts);
        $mform->addHelpButton('parent_endpoint_id', 'parent_endpoint', 'local_external_api_sync');

        $mform->addElement('text', 'parent_id_path',
            get_string('parent_id_path', 'local_external_api_sync'), ['size' => 60]);
        $mform->setType('parent_id_path', PARAM_TEXT);
        $mform->addHelpButton('parent_id_path', 'parent_id_path', 'local_external_api_sync');
        $mform->setDefault('parent_id_path', 'XRefCode');
        $mform->hideIf('parent_id_path', 'parent_endpoint_id', 'eq', 0);

        $mform->addElement('text', 'parent_id_placeholder',
            get_string('parent_id_placeholder', 'local_external_api_sync'), ['size' => 30]);
        $mform->setType('parent_id_placeholder', PARAM_TEXT);
        $mform->addHelpButton('parent_id_placeholder', 'parent_id_placeholder', 'local_external_api_sync');
        $mform->setDefault('parent_id_placeholder', '{XRefCode}');
        $mform->hideIf('parent_id_placeholder', 'parent_endpoint_id', 'eq', 0);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (empty($data['name'])) {
            $errors['name'] = get_string('missingrequired', 'local_external_api_sync');
        }
        if (empty($data['path'])) {
            $errors['path'] = get_string('missingrequired', 'local_external_api_sync');
        }
        // Validate JSON fields.
        foreach (['extra_headers', 'query_params', 'request_body_template'] as $field) {
            if (!empty($data[$field])) {
                json_decode($data[$field]);
                if (json_last_error() !== JSON_ERROR_NONE && $field !== 'request_body_template') {
                    $errors[$field] = get_string('invalidjson', 'local_external_api_sync',
                        json_last_error_msg());
                }
            }
        }

        return $errors;
    }
}

// -----------------------------------------------------------------------
// Process form
// -----------------------------------------------------------------------
$form = new edit_endpoint_form(null, [
    'connection_id' => $connection_id,
    'endpoint_id'   => $id,  // Current endpoint ID so it excludes itself from parent list.
]);

if ($form->is_cancelled()) {
    redirect($list_url);
}

if ($data = $form->get_data()) {
    $now = time();

    if ($data->id) {
        $data->timemodified = $now;
        $data->usermodified = $USER->id;
        $DB->update_record('ext_api_endpoints', $data);
    } else {
        $data->timecreated  = $now;
        $data->timemodified = $now;
        $data->usermodified = $USER->id;
        $data->last_status  = 'never';
        $DB->insert_record('ext_api_endpoints', $data);
    }

    redirect($list_url,
        get_string('savedsuccess', 'local_external_api_sync'),
        null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($record) {
    $form->set_data($record);
} else {
    $form->set_data(['connection_id' => $connection_id]);
}

// -----------------------------------------------------------------------
// Output
// -----------------------------------------------------------------------
$heading = $id
    ? get_string('editendpoint', 'local_external_api_sync')
    : get_string('addendpoint',  'local_external_api_sync');

$PAGE->set_title($heading);
echo $OUTPUT->header();
echo $OUTPUT->heading($heading . ': ' . format_string($connection->name));

echo html_writer::div(
    html_writer::link($list_url, '← ' . get_string('backtoendpoints', 'local_external_api_sync')),
    'mb-3'
);

$form->display();
echo $OUTPUT->footer();
