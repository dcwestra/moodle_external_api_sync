<?php
namespace local_external_api_sync\api\auth;
defined('MOODLE_INTERNAL') || die();

class basic {
    private $connection;
    public function __construct($connection) { $this->connection = $connection; }

    public function get_auth_header(): string {
        $credentials = base64_encode(
            $this->connection->basic_username . ':' . $this->decrypt($this->connection->basic_password)
        );
        // Return just the header value — client.php prepends 'Authorization: '
        return 'Basic ' . $credentials;
    }

    private function decrypt(string $value): string {
        if (class_exists('\core\encryption')) {
            try { return \core\encryption::decrypt($value); } catch (\Throwable $e) {}
        }
        return $value;
    }
}
