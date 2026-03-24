<?php
/**
 * Connections list page — main entry point for the plugin UI.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('local/external_api_sync:manage', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);
$id     = optional_param('id', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/external_api_sync/pages/connections.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('connections_title', 'local_external_api_sync'));
$PAGE->set_heading(get_string('connections_title', 'local_external_api_sync'));
$PAGE->set_pagelayout('admin');

// Handle delete action.
if ($action === 'delete' && $id) {
    require_sesskey();
    $DB->delete_records('ext_api_field_mappings', ['endpoint_id' => $id]); // Clean up orphaned mappings first via endpoints.
    $endpoints = $DB->get_records('ext_api_endpoints', ['connection_id' => $id]);
    foreach ($endpoints as $ep) {
        $DB->delete_records('ext_api_field_mappings', ['endpoint_id' => $ep->id]);
    }
    $DB->delete_records('ext_api_endpoints', ['connection_id' => $id]);
    $DB->delete_records('ext_api_token_cache', ['connection_id' => $id]);
    $DB->delete_records('ext_api_connections', ['id' => $id]);
    redirect(new moodle_url('/local/external_api_sync/pages/connections.php'),
        'Connection deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Handle toggle enabled.
if ($action === 'toggle' && $id) {
    require_sesskey();
    $conn = $DB->get_record('ext_api_connections', ['id' => $id], '*', MUST_EXIST);
    $DB->update_record('ext_api_connections', (object)['id' => $id, 'enabled' => $conn->enabled ? 0 : 1]);
    redirect(new moodle_url('/local/external_api_sync/pages/connections.php'));
}

echo $OUTPUT->header();

// Action buttons row.
$add_url = new moodle_url('/local/external_api_sync/pages/edit_connection.php');
echo html_writer::div(
    $OUTPUT->single_button($add_url, get_string('connections_add', 'local_external_api_sync'), 'get'),
    'mb-3'
);

// Load connections with endpoint counts.
$connections = $DB->get_records('ext_api_connections', null, 'name ASC');

if (empty($connections)) {
    echo $OUTPUT->notification(get_string('connections_none', 'local_external_api_sync'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('connection_name', 'local_external_api_sync'),
        get_string('connection_auth_type', 'local_external_api_sync'),
        get_string('connection_base_url', 'local_external_api_sync'),
        get_string('connection_enabled', 'local_external_api_sync'),
        get_string('connection_endpoints', 'local_external_api_sync'),
        get_string('connection_actions', 'local_external_api_sync'),
    ];
    $table->attributes['class'] = 'generaltable admintable';

    foreach ($connections as $conn) {
        $endpoint_count = $DB->count_records('ext_api_endpoints', ['connection_id' => $conn->id]);
        $endpoints_url  = new moodle_url('/local/external_api_sync/pages/endpoints.php', ['connection_id' => $conn->id]);
        $edit_url       = new moodle_url('/local/external_api_sync/pages/edit_connection.php', ['id' => $conn->id]);
        $delete_url     = new moodle_url('/local/external_api_sync/pages/connections.php',
            ['action' => 'delete', 'id' => $conn->id, 'sesskey' => sesskey()]);
        $toggle_url     = new moodle_url('/local/external_api_sync/pages/connections.php',
            ['action' => 'toggle', 'id' => $conn->id, 'sesskey' => sesskey()]);

        $auth_labels = [
            'oauth2'  => 'OAuth 2.0',
            'apikey'  => 'API Key',
            'basic'   => 'Basic Auth',
            'bearer'  => 'Bearer Token',
        ];
        $auth_label = $auth_labels[$conn->auth_type] ?? $conn->auth_type;

        $enabled_badge = $conn->enabled
            ? html_writer::span('Enabled', 'badge badge-success')
            : html_writer::span('Disabled', 'badge badge-secondary');

        $actions = html_writer::link($edit_url, $OUTPUT->pix_icon('t/edit', 'Edit'), ['title' => 'Edit']) . ' ' .
                   html_writer::link($endpoints_url, $OUTPUT->pix_icon('t/viewdetails', 'Endpoints'), ['title' => 'Endpoints']) . ' ' .
                   html_writer::link($toggle_url, $OUTPUT->pix_icon($conn->enabled ? 'i/hide' : 'i/show', 'Toggle'), ['title' => 'Toggle']) . ' ' .
                   html_writer::link($delete_url, $OUTPUT->pix_icon('t/delete', 'Delete'), [
                       'title'   => 'Delete',
                       'onclick' => 'return confirm("' . get_string('connection_delete_confirm', 'local_external_api_sync') . '")',
                   ]);

        $table->data[] = [
            html_writer::link($endpoints_url, format_string($conn->name)),
            $auth_label,
            html_writer::tag('code', shorten_text($conn->base_url, 50)),
            $enabled_badge,
            html_writer::link($endpoints_url, $endpoint_count . ' endpoint(s)'),
            $actions,
        ];
    }

    echo html_writer::table($table);
}

// Link to logs.
$logs_url = new moodle_url('/local/external_api_sync/pages/logs.php');
echo html_writer::div(
    html_writer::link($logs_url, '📋 ' . get_string('nav_logs', 'local_external_api_sync')),
    'mt-3'
);

echo $OUTPUT->footer();
