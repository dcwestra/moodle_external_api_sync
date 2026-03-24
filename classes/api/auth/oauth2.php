<?php
/**
 * OAuth2 Client Credentials authenticator.
 *
 * Handles token acquisition and caching for OAuth2 client_credentials flow.
 * This is the standard flow for server-to-server API access (e.g. Dayforce).
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_external_api_sync\api\auth;

defined('MOODLE_INTERNAL') || die();

class oauth2 {

    /** @var object Connection record from ext_api_connections */
    private $connection;

    public function __construct($connection) {
        $this->connection = $connection;
    }

    /**
     * Get a valid access token, using cache if available.
     *
     * @return string Access token
     * @throws \moodle_exception on auth failure
     */
    public function get_token(): string {
        global $DB;

        // Check cache first — token still valid with 60s buffer.
        $cached = $DB->get_record('ext_api_token_cache', [
            'connection_id' => $this->connection->id,
        ]);

        if ($cached && $cached->expires_at > (time() + 60)) {
            return $cached->access_token;
        }

        // Fetch a fresh token.
        $token = $this->fetch_token();

        // Upsert into cache.
        $record = new \stdClass();
        $record->connection_id = $this->connection->id;
        $record->access_token  = $token['access_token'];
        $record->token_type    = $token['token_type'] ?? 'Bearer';
        $record->expires_at    = time() + ($token['expires_in'] ?? 3600);
        $record->timecreated   = time();

        if ($cached) {
            $record->id = $cached->id;
            $DB->update_record('ext_api_token_cache', $record);
        } else {
            $DB->insert_record('ext_api_token_cache', $record);
        }

        return $record->access_token;
    }

    /**
     * Make the token request to the OAuth2 token endpoint.
     */
    private function fetch_token(): array {
        // Use native PHP curl for the token request — Moodle's curl wrapper
        // can re-encode the POST body in ways that cause token endpoints to
        // reject the request (missing client_secret errors).
        $body = 'grant_type=client_credentials'
              . '&client_id=' . rawurlencode($this->connection->client_id)
              . '&client_secret=' . rawurlencode($this->decrypt($this->connection->client_secret));

        if (!empty($this->connection->oauth_scope)) {
            $body .= '&scope=' . rawurlencode($this->connection->oauth_scope);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->connection->token_url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code < 200 || $http_code >= 300) {
            throw new \moodle_exception('sync_auth_failed', 'local_external_api_sync',
                '', $this->connection->name . ' (HTTP ' . $http_code . '): ' . $response);
        }

        $data = json_decode($response, true);

        if (empty($data['access_token'])) {
            throw new \moodle_exception('sync_auth_failed', 'local_external_api_sync',
                '', $this->connection->name . ': No access_token in response');
        }

        return $data;
    }

    /**
     * Return Authorization header for use in API requests.
     */
    public function get_auth_header(): string {
        $token = $this->get_token();
        // Return just the value — client.php prepends 'Authorization: '
        return 'Bearer ' . $token;
    }

    /**
     * Clear cached token (e.g. after 401 response).
     */
    public function invalidate_cache(): void {
        global $DB;
        $DB->delete_records('ext_api_token_cache', ['connection_id' => $this->connection->id]);
    }

    private function decrypt(string $value): string {
        // Use Moodle's built-in encryption if available, otherwise return as-is.
        // In production, credentials should be stored encrypted.
        if (class_exists('\core\encryption')) {
            try {
                return \core\encryption::decrypt($value);
            } catch (\Throwable $e) {
                return $value; // Not encrypted, return raw.
            }
        }
        return $value;
    }
}
