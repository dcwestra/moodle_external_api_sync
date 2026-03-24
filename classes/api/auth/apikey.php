<?php
/**
 * API Key authenticator.
 *
 * Supports API key in HTTP header or query parameter.
 * When location = header: get_auth_header() returns the full "Header-Name: value" string.
 * When location = query:  get_query_params() returns [param_name => value] array.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_external_api_sync\api\auth;

defined('MOODLE_INTERNAL') || die();

class apikey {

    private $connection;

    public function __construct($connection) {
        $this->connection = $connection;
    }

    /**
     * Return the full header string for header-based API key auth.
     * Returns null if the key is configured as a query parameter instead.
     *
     * client.php checks: if ($header_info !== null) { $headers[] = $header_info; }
     *
     * @return string|null  e.g. "X-API-Key: abc123" or null
     */
    public function get_auth_header(): ?string {
        if ($this->connection->api_key_location === 'header') {
            $header_name = $this->connection->api_key_header ?: 'X-API-Key';
            return $header_name . ': ' . $this->decrypt($this->connection->api_key);
        }
        return null; // Query param mode — handled by get_query_params().
    }

    /**
     * Return query parameters array for query-based API key auth.
     * Returns empty array if the key is configured as a header instead.
     *
     * @return array  e.g. ['api_key' => 'abc123'] or []
     */
    public function get_query_params(): array {
        if ($this->connection->api_key_location === 'query') {
            $param_name = $this->connection->api_key_param ?: 'api_key';
            return [$param_name => $this->decrypt($this->connection->api_key)];
        }
        return [];
    }

    private function decrypt(string $value): string {
        if (class_exists('\\core\\encryption')) {
            try {
                return \core\encryption::decrypt($value);
            } catch (\Throwable $e) {
                return $value;
            }
        }
        return $value;
    }
}
