<?php
/**
 * Global plugin settings page.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    // Add a top-level settings page in Site Admin.
    $settings = new admin_settingpage(
        'local_external_api_sync',
        get_string('pluginname', 'local_external_api_sync')
    );

    // Add a navigation node under Site Administration > Local plugins.
    $ADMIN->add('localplugins', new admin_category(
        'local_external_api_sync_category',
        get_string('pluginname', 'local_external_api_sync')
    ));

    // Global settings page.
    $ADMIN->add('local_external_api_sync_category', $settings);

    // Link to Connections manager.
    $ADMIN->add('local_external_api_sync_category', new admin_externalpage(
        'local_external_api_sync_connections',
        get_string('nav_connections', 'local_external_api_sync'),
        new moodle_url('/local/external_api_sync/pages/connections.php')
    ));

    // Link to Sync Logs.
    $ADMIN->add('local_external_api_sync_category', new admin_externalpage(
        'local_external_api_sync_logs',
        get_string('nav_logs', 'local_external_api_sync'),
        new moodle_url('/local/external_api_sync/pages/logs.php')
    ));

    if ($settings) {

        $settings->add(new admin_setting_heading(
            'local_external_api_sync/settings_heading',
            get_string('settings_heading', 'local_external_api_sync'),
            ''
        ));

        // Log retention days.
        $settings->add(new admin_setting_configtext(
            'local_external_api_sync/log_retention_days',
            get_string('settings_log_retention', 'local_external_api_sync'),
            get_string('settings_log_retention_desc', 'local_external_api_sync'),
            '90',
            PARAM_INT
        ));

        // Test mode.
        $settings->add(new admin_setting_configcheckbox(
            'local_external_api_sync/test_mode',
            get_string('settings_test_mode', 'local_external_api_sync'),
            get_string('settings_test_mode_desc', 'local_external_api_sync'),
            0
        ));
    }
}
