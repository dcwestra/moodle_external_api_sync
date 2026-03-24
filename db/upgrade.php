<?php
/**
 * Upgrade steps for local_external_api_sync.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

function xmldb_local_external_api_sync_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026032300) {

        // Add parent-child relationship fields to ext_api_endpoints.
        $table = new xmldb_table('ext_api_endpoints');

        $field = new xmldb_field('parent_endpoint_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('parent_id_path', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'parent_endpoint_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('parent_id_placeholder', XMLDB_TYPE_CHAR, '100', null, null, null, '{XRefCode}', 'parent_id_path');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('is_parent_only', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'parent_id_placeholder');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add ext_api_id_cache table.
        $cache_table = new xmldb_table('ext_api_id_cache');
        $cache_table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $cache_table->add_field('endpoint_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $cache_table->add_field('run_id',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $cache_table->add_field('item_id',     XMLDB_TYPE_CHAR,    '500', null, XMLDB_NOTNULL);
        $cache_table->add_field('processed',   XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, '0');
        $cache_table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
        $cache_table->add_key('primary',      XMLDB_KEY_PRIMARY, ['id']);
        $cache_table->add_key('fk_endpoint',  XMLDB_KEY_FOREIGN, ['endpoint_id'], 'ext_api_endpoints', ['id']);
        $cache_table->add_index('idx_endpoint_run',       XMLDB_INDEX_NOTUNIQUE, ['endpoint_id', 'run_id']);
        $cache_table->add_index('idx_endpoint_processed', XMLDB_INDEX_NOTUNIQUE, ['endpoint_id', 'processed']);

        if (!$dbman->table_exists($cache_table)) {
            $dbman->create_table($cache_table);
        }

        upgrade_plugin_savepoint(true, 2026032300, 'local', 'external_api_sync');
    }

    return true;
}
