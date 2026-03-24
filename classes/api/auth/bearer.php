<?php
namespace local_external_api_sync\api\auth;
defined('MOODLE_INTERNAL') || die();

class bearer {
    private $connection;
    public function __construct($connection) { $this->connection = $connection; }

    public function get_auth_header(): string {
        // Return just the value — client.php prepends 'Authorization: '
        return 'Bearer ' . $this->decrypt($this->connection->bearer_token);
    }

    private function decrypt(string $value): string {
        if (class_exists('\core\encryption')) {
            try { return \core\encryption::decrypt($value); } catch (\Throwable $e) {}
        }
        return $value;
    }
}
