<?php
/**
 * Scheduled task definitions.
 *
 * Note: Each enabled endpoint gets its own dynamic scheduled task
 * created/updated by the plugin when endpoints are saved. This file
 * defines the single "dispatcher" task that reads enabled endpoints
 * and runs each one on its configured cron schedule.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_external_api_sync\task\sync_task',
        'blocking'  => 0,
        'minute'    => '*/15',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
];
