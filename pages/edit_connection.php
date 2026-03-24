<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Create / edit a connection.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

use local_external_api_sync\util\crypto;

admin_externalpage_setup('local_external_api_sync_connections');
require_capability('local/external_api_sync:manage', context_system::instance());

$id = optional_param('id', 0, PARAM_INT);

$record = null;
if ($id) {
    $record = $DB->get_record('ext_api_connections', ['id' => $id], '*', MUST_EXIST);
}

// -----------------------------------------------------------------------
// Form definition
// -----------------------------------------------------------------------
class edit_connection_form extends moodleform {

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        // Basic info.
        $mform->addElement('header', 'basic_header',
            get_string('connectionname', 'local_external_api_sync'));

        $mform->addElement('text', 'name',
            get_string('connectionname', 'local_external_api_sync'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addHelpButton('name', 'connectionname', 'local_external_api_sync');

        $mform->addElement('textarea', 'description',
            get_string('description', 'local_external_api_sync'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);

        $mform->addElement('text', 'base_url',
            get_string('base_url', 'local_external_api_sync'), ['size' => 60]);
        $mform->setType('base_url', PARAM_URL);
        $mform->addRule('base_url', null, 'required', null, 'client');
        $mform->addHelpButton('base_url', 'base_url', 'local_external_api_sync');

        $mform->addElement('advcheckbox', 'enabled',
            get_string('enabled', 'local_external_api_sync'));
        $mform->setDefault('enabled', 1);

        // Auth type.
        $mform->addElement('header', 'auth_header',
            get_string('auth_type', 'local_external_api_sync'));

        $auth_options = [
            'oauth2' => get_string('auth_oauth2', 'local_external_api_sync'),
            'apikey' => get_string('auth_apikey', 'local_external_api_sync'),
            'basic'  => get_string('auth_basic',  'local_external_api_sync'),
            'bearer' => get_string('auth_bearer', 'local_external_api_sync'),
        ];
        $mform->addElement('select', 'auth_type',
            get_string('auth_type', 'local_external_api_sync'), $auth_options);
        $mform->addHelpButton('auth_type', 'auth_type', 'local_external_api_sync');

        // OAuth2.
        $mform->addElement('header', 'oauth2_header',
            get_string('auth_oauth2', 'local_external_api_sync'));
        $mform->addElement('text', 'token_url',
            get_string('token_url', 'local_external_api_sync'), ['size' => 60]);
        $mform->setType('token_url', PARAM_URL);
        $mform->addHelpButton('token_url', 'token_url', 'local_external_api_sync');
        $mform->addElement('text', 'client_id',
            get_string('client_id', 'local_external_api_sync'), ['size' => 60]);
        $mform->setType('client_id', PARAM_TEXT);
        $mform->addElement('passwordunmask', 'client_secret',
            get_string('client_secret', 'local_external_api_sync'));
        $mform->setType('client_secret', PARAM_TEXT);
        $mform->addElement('text', 'oauth_scope',
            get_string('oauth_scope', 'local_external_api_sync'), ['size' => 60]);
        $mform->setType('oauth_scope', PARAM_TEXT);
        $mform->addHelpButton('oauth_scope', 'oauth_scope', 'local_external_api_sync');

        // API Key.
        $mform->addElement('header', 'apikey_header',
            get_string('auth_apikey', 'local_external_api_sync'));
        $mform->addElement('passwordunmask', 'api_key',
            get_string('api_key', 'local_external_api_sync'));
        $mform->setType('api_key', PARAM_TEXT);
        $mform->addElement('text', 'api_key_header',
            get_string('api_key_header', 'local_external_api_sync'), ['size' => 40]);
        $mform->setType('api_key_header', PARAM_TEXT);
        $mform->setDefault('api_key_header', 'X-API-Key');
        $mform->addHelpButton('api_key_header', 'api_key_header', 'local_external_api_sync');
        $location_options = [
            'header' => get_string('apikey_header', 'local_external_api_sync'),
            'query'  => get_string('apikey_query',  'local_external_api_sync'),
        ];
        $mform->addElement('select', 'api_key_location',
            get_string('api_key_location', 'local_external_api_sync'), $location_options);
        $mform->addElement('text', 'api_key_param',
            get_string('api_key_param', 'local_external_api_sync'), ['size' => 40]);
        $mform->setType('api_key_param', PARAM_TEXT);
        $mform->addHelpButton('api_key_param', 'api_key_param', 'local_external_api_sync');

        // Basic auth.
        $mform->addElement('header', 'basic_auth_header',
            get_string('auth_basic', 'local_external_api_sync'));
        $mform->addElement('text', 'basic_username',
            get_string('basic_username', 'local_external_api_sync'), ['size' => 40]);
        $mform->setType('basic_username', PARAM_TEXT);
        $mform->addElement('passwordunmask', 'basic_password',
            get_string('basic_password', 'local_external_api_sync'));
        $mform->setType('basic_password', PARAM_TEXT);

        // Bearer token.
        $mform->addElement('header', 'bearer_header',
            get_string('auth_bearer', 'local_external_api_sync'));
        $mform->addElement('passwordunmask', 'bearer_token',
            get_string('bearer_token', 'local_external_api_sync'));
        $mform->setType('bearer_token', PARAM_TEXT);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['name'])) {
            $errors['name'] = get_string('missingrequired', 'local_external_api_sync');
        }
        if (empty($data['base_url'])) {
            $errors['base_url'] = get_string('missingrequired', 'local_external_api_sync');
        }
        return $errors;
    }
}

// -----------------------------------------------------------------------
// Process form
// -----------------------------------------------------------------------
$form = new edit_connection_form(null, ['record' => $record]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/external_api_sync/pages/connections.php'));
}

if ($data = $form->get_data()) {
    $now = time();
    $sensitive = ['client_secret', 'api_key', 'basic_password', 'bearer_token'];
    foreach ($sensitive as $field) {
        if (!empty($data->$field)) {
            $data->$field = crypto::encrypt($data->$field);
        } elseif ($record && !empty($record->$field)) {
            // Field left blank on edit — preserve existing encrypted value.
            $data->$field = $record->$field;
        }
    }

    if ($data->id) {
        $data->timemodified = $now;
        $data->usermodified = $USER->id;
        $DB->update_record('ext_api_connections', $data);
    } else {
        $data->timecreated  = $now;
        $data->timemodified = $now;
        $data->usermodified = $USER->id;
        $DB->insert_record('ext_api_connections', $data);
    }

    redirect(
        new moodle_url('/local/external_api_sync/pages/connections.php'),
        get_string('savedsuccess', 'local_external_api_sync'),
        null, \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($record) {
    $display = clone $record;
    // Never pre-fill password fields.
    foreach (['client_secret', 'api_key', 'basic_password', 'bearer_token'] as $f) {
        $display->$f = '';
    }
    $form->set_data($display);
}

// -----------------------------------------------------------------------
// Output
// -----------------------------------------------------------------------
$heading = $id
    ? get_string('editconnection', 'local_external_api_sync')
    : get_string('addconnection',  'local_external_api_sync');

$PAGE->set_title($heading);
echo $OUTPUT->header();
echo $OUTPUT->heading($heading);

echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/external_api_sync/pages/connections.php'),
        '← ' . get_string('backtconnections', 'local_external_api_sync')
    ), 'mb-3'
);

// JS: show only the relevant auth section.
$PAGE->requires->js_amd_inline("
require(['jquery'], function(\$) {
    function showAuthSection() {
        var type = \$('#id_auth_type').val();
        \$('#id_oauth2_header, #id_apikey_header, #id_basic_auth_header, #id_bearer_header')
            .closest('.fcontainer').hide();
        if (type === 'oauth2') \$('#id_oauth2_header').closest('.fcontainer').show();
        if (type === 'apikey') \$('#id_apikey_header').closest('.fcontainer').show();
        if (type === 'basic')  \$('#id_basic_auth_header').closest('.fcontainer').show();
        if (type === 'bearer') \$('#id_bearer_header').closest('.fcontainer').show();
    }
    showAuthSection();
    \$('#id_auth_type').on('change', showAuthSection);
});
");

$form->display();
echo $OUTPUT->footer();
