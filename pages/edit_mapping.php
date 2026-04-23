<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Create / edit a field mapping.
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

$id          = optional_param('id', 0, PARAM_INT);
$endpoint_id = required_param('endpoint_id', PARAM_INT);

$endpoint   = $DB->get_record('ext_api_endpoints',   ['id' => $endpoint_id], '*', MUST_EXIST);
$connection = $DB->get_record('ext_api_connections', ['id' => $endpoint->connection_id], '*', MUST_EXIST);
$record     = null;
if ($id) {
    $record = $DB->get_record('ext_api_field_mappings', ['id' => $id], '*', MUST_EXIST);
}

$list_url = new moodle_url('/local/external_api_sync/pages/mappings.php',
    ['endpoint_id' => $endpoint_id]);

// -----------------------------------------------------------------------
// Build the internal field options list
// -----------------------------------------------------------------------
function get_internal_field_options(): array {
    global $DB;

    // Standard user fields.
    $options = [
        '--- Standard User Fields ---' => [],
        'username'    => get_string('moodlefield_username',    'local_external_api_sync'),
        'firstname'   => get_string('moodlefield_firstname',   'local_external_api_sync'),
        'lastname'    => get_string('moodlefield_lastname',    'local_external_api_sync'),
        'email'       => get_string('moodlefield_email',       'local_external_api_sync'),
        'idnumber'    => get_string('moodlefield_idnumber',    'local_external_api_sync'),
        'phone1'      => get_string('moodlefield_phone1',      'local_external_api_sync'),
        'department'  => get_string('moodlefield_department',  'local_external_api_sync'),
        'institution' => get_string('moodlefield_institution', 'local_external_api_sync'),
        'city'        => get_string('moodlefield_city',        'local_external_api_sync'),
        'country'     => get_string('moodlefield_country',     'local_external_api_sync'),
        'lang'        => get_string('moodlefield_lang',        'local_external_api_sync'),
        'timezone'    => get_string('moodlefield_timezone',    'local_external_api_sync'),
        'suspended'   => get_string('moodlefield_suspended',   'local_external_api_sync'),
        'auth'        => get_string('moodlefield_auth',        'local_external_api_sync'),
    ];

    // Custom profile fields.
    $profile_fields = $DB->get_records('user_info_field', null, 'sortorder ASC', 'shortname, name');
    if (!empty($profile_fields)) {
        $options['--- Custom Profile Fields ---'] = [];
        foreach ($profile_fields as $pf) {
            $key = 'profile_field_' . $pf->shortname;
            $options[$key] = $pf->name . ' (' . $key . ')';
        }
    }

    // Enrolment fields.
    $options['--- Enrolment Fields ---']  = [];
    $options['courseid']         = 'Course ID (numeric)';
    $options['courseshortname']  = 'Course Short Name';
    $options['courseidnumber']   = 'Course ID Number';
    $options['roleshortname']    = 'Role Short Name (e.g. student)';

    return $options;
}

// -----------------------------------------------------------------------
// Form definition
// -----------------------------------------------------------------------
class edit_mapping_form extends moodleform {

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'endpoint_id', 0);
        $mform->setType('endpoint_id', PARAM_INT);

        $mform->addElement('header', 'mapping_header',
            get_string('fieldmappings', 'local_external_api_sync'));

        // External field.
        $mform->addElement('text', 'external_field',
            get_string('external_field', 'local_external_api_sync'), ['size' => 60]);
        $mform->setType('external_field', PARAM_TEXT);
        $mform->addRule('external_field', null, 'required', null, 'client');
        $mform->addHelpButton('external_field', 'external_field', 'local_external_api_sync');

        // Internal field — dropdown of known fields + free-text fallback.
        $internal_options = get_internal_field_options();
        $mform->addElement('select', 'internal_field',
            get_string('internal_field', 'local_external_api_sync'), $internal_options);
        $mform->addHelpButton('internal_field', 'internal_field', 'local_external_api_sync');

        // Custom internal field (for fields not in the dropdown).
        $mform->addElement('text', 'internal_field_custom',
            get_string('internal_field', 'local_external_api_sync') . ' (custom)',
            ['size' => 40, 'placeholder' => 'Leave blank to use dropdown above']);
        $mform->setType('internal_field_custom', PARAM_TEXT);

        // Key field toggle.
        $mform->addElement('advcheckbox', 'is_key_field',
            get_string('is_key_field', 'local_external_api_sync'));
        $mform->addHelpButton('is_key_field', 'is_key_field', 'local_external_api_sync');

        // Transform.
        $mform->addElement('header', 'transform_header',
            get_string('transform', 'local_external_api_sync'));

        $transform_opts = [
            'none'      => get_string('transform_none',      'local_external_api_sync'),
            'uppercase' => get_string('transform_uppercase', 'local_external_api_sync'),
            'lowercase' => get_string('transform_lowercase', 'local_external_api_sync'),
            'trim'      => get_string('transform_trim',      'local_external_api_sync'),
            'date_unix' => get_string('transform_date_unix', 'local_external_api_sync'),
            'date_iso'  => get_string('transform_date_iso',  'local_external_api_sync'),
            'prefix'    => get_string('transform_prefix',    'local_external_api_sync'),
            'suffix'    => get_string('transform_suffix',    'local_external_api_sync'),
            'concat'    => get_string('transform_concat',    'local_external_api_sync'),
        ];
        $mform->addElement('select', 'transform',
            get_string('transform', 'local_external_api_sync'), $transform_opts);
        $mform->addHelpButton('transform', 'transform', 'local_external_api_sync');

        $mform->addElement('text', 'transform_arg',
            get_string('transform_arg', 'local_external_api_sync'), ['size' => 40]);
        $mform->setType('transform_arg', PARAM_TEXT);
        $mform->addHelpButton('transform_arg', 'transform_arg', 'local_external_api_sync');

        $mform->addElement('text', 'default_value',
            get_string('default_value', 'local_external_api_sync'), ['size' => 40]);
        $mform->setType('default_value', PARAM_TEXT);
        $mform->addHelpButton('default_value', 'default_value', 'local_external_api_sync');

        // Sort order.
        $mform->addElement('text', 'sortorder',
            get_string('sortorder', 'local_external_api_sync'), ['size' => 5]);
        $mform->setType('sortorder', PARAM_INT);
        $mform->setDefault('sortorder', 0);

        $mform->addElement('advcheckbox', 'enabled',
            get_string('enabled', 'local_external_api_sync'));
        $mform->setDefault('enabled', 1);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['external_field'])) {
            $errors['external_field'] = get_string('missingrequired', 'local_external_api_sync');
        }
        if (empty($data['internal_field']) && empty($data['internal_field_custom'])) {
            $errors['internal_field'] = get_string('missingrequired', 'local_external_api_sync');
        }
        return $errors;
    }
}

// -----------------------------------------------------------------------
// Process form
// -----------------------------------------------------------------------
$form = new edit_mapping_form();

if ($form->is_cancelled()) {
    redirect($list_url);
}

if ($data = $form->get_data()) {
    // If custom internal field was provided, use it.
    if (!empty($data->internal_field_custom)) {
        $data->internal_field = trim($data->internal_field_custom);
    }
    unset($data->internal_field_custom);

    if ($data->id) {
        $DB->update_record('ext_api_field_mappings', $data);
    } else {
        $DB->insert_record('ext_api_field_mappings', $data);
    }

    redirect($list_url,
        get_string('savedsuccess', 'local_external_api_sync'),
        null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($record) {
    // Check if internal_field is in the standard list; if not, put it in the custom box.
    $standard_keys = array_keys(get_internal_field_options());
    if (!in_array($record->internal_field, $standard_keys, true)) {
        $record->internal_field_custom = $record->internal_field;
        $record->internal_field        = 'username'; // Reset dropdown to something safe.
    }
    $form->set_data($record);
} else {
    $form->set_data(['endpoint_id' => $endpoint_id]);
}

// -----------------------------------------------------------------------
// Output
// -----------------------------------------------------------------------
$heading = $id
    ? get_string('editmapping',  'local_external_api_sync')
    : get_string('addmapping',   'local_external_api_sync');

$PAGE->set_title($heading);
echo $OUTPUT->header();
echo $OUTPUT->heading(
    $heading . ': ' . format_string($endpoint->name)
    . ' (' . format_string($connection->name) . ')'
);

echo html_writer::div(
    html_writer::link($list_url, '← ' . get_string('backtomappings', 'local_external_api_sync')),
    'mb-3'
);

// Direction hint.
if ($endpoint->direction === 'push') {
    echo $OUTPUT->notification(
        'Push direction: <strong>External Field</strong> = output JSON key name. '
        . '<strong>Internal Field</strong> = Moodle field to read from.',
        \core\output\notification::NOTIFY_INFO
    );
} else {
    echo $OUTPUT->notification(
        'Pull direction: <strong>External Field</strong> = dot-notation path in API response '
        . '(e.g. Employee.FirstName). <strong>Internal Field</strong> = Moodle field to write to.',
        \core\output\notification::NOTIFY_INFO
    );
}

$form->display();
echo $OUTPUT->footer();
