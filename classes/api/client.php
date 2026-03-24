<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Generic authenticated HTTP client for External API Sync.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_external_api_sync\api;

defined('MOODLE_INTERNAL') || die();

use local_external_api_sync\api\auth\oauth2;
use local_external_api_sync\api\auth\apikey;
use local_external_api_sync\api\auth\basic;
use local_external_api_sync\api\auth\bearer;

/**
 * Authenticated HTTP client with pagination support.
 */
class client {

    /** @var object Connection DB record (decrypted) */
    private $connection;

    /** @var object Endpoint DB record */
    private $endpoint;

    /** @var oauth2|apikey|basic|bearer Auth handler */
    private $auth_handler;

    /**
     * @param object $connection Decrypted connection record
     * @param object $endpoint   Endpoint record
     */
    public function __construct(object $connection, object $endpoint) {
        $this->connection   = $connection;
        $this->endpoint     = $endpoint;
        $this->auth_handler = $this->build_auth_handler();
    }

    /**
     * Fetch all records from the endpoint, handling pagination automatically.
     *
     * @param array $extra_params Additional query params
     * @return array Flat array of all records
     * @throws \moodle_exception
     */
    public function fetch_all(array $extra_params = []): array {
        $all_records = [];

        if (!$this->endpoint->pagination_enabled) {
            $response = $this->request(
                $this->endpoint->http_method,
                $this->build_url($extra_params)
            );
            return response_parser::extract_records(
                $response,
                $this->endpoint->response_root_path ?? ''
            );
        }

        // Paginated fetch.
        $page      = 0;
        $page_size = (int) ($this->endpoint->page_size ?: 100);
        $fetched   = 0;
        $total     = null;

        do {
            $params = array_merge($extra_params, [
                $this->endpoint->pagination_param  => $page,
                $this->endpoint->page_size_param   => $page_size,
            ]);

            $response = $this->request(
                $this->endpoint->http_method,
                $this->build_url($params)
            );

            $records     = response_parser::extract_records(
                $response,
                $this->endpoint->response_root_path ?? ''
            );
            $all_records = array_merge($all_records, $records);
            $fetched    += count($records);

            if ($total === null && !empty($this->endpoint->total_count_path)) {
                $total = (int) response_parser::extract_value(
                    $response,
                    $this->endpoint->total_count_path
                );
            }

            $page++;

            if (count($records) < $page_size) break;
            if ($total !== null && $fetched >= $total) break;
            if (count($records) === 0) break;

        } while (true);

        return $all_records;
    }

    /**
     * Push a single payload to the endpoint.
     *
     * @param string $body JSON-encoded request body
     * @return array Decoded response
     * @throws \moodle_exception
     */
    public function push(string $body): array {
        return $this->request(
            $this->endpoint->http_method,
            $this->build_url(),
            $body
        );
    }

    /**
     * Make a single HTTP request.
     *
     * @param string      $method GET|POST|PUT|PATCH
     * @param string      $url    Full URL
     * @param string|null $body   Request body for POST/PUT
     * @return array Decoded JSON response
     * @throws \moodle_exception
     */
    private function request(string $method, string $url, ?string $body = null): array {
        // Use native PHP curl throughout — Moodle's \curl wrapper is not
        // always available in CLI/task contexts and can mangle request bodies.
        $method  = strtoupper($method);
        $headers = $this->build_headers();

        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ];

        switch ($method) {
            case 'GET':
                $opts[CURLOPT_HTTPGET] = true;
                break;
            case 'POST':
                $opts[CURLOPT_POST]       = true;
                $opts[CURLOPT_POSTFIELDS] = $body ?? '';
                break;
            case 'PUT':
                $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
                $opts[CURLOPT_POSTFIELDS]    = $body ?? '';
                break;
            case 'PATCH':
                $opts[CURLOPT_CUSTOMREQUEST] = 'PATCH';
                $opts[CURLOPT_POSTFIELDS]    = $body ?? '';
                break;
            default:
                throw new \moodle_exception('errorhttp', 'local_external_api_sync',
                    '', "Unsupported HTTP method: $method");
        }

        curl_setopt_array($ch, $opts);
        $raw       = curl_exec($ch);
        $errno     = curl_errno($ch);
        $error     = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new \moodle_exception('errorhttp', 'local_external_api_sync',
                '', "cURL error $errno: $error");
        }

        if ($http_code < 200 || $http_code >= 300) {
            throw new \moodle_exception('errorhttp', 'local_external_api_sync',
                '', "HTTP $http_code from $url: " . substr($raw, 0, 500));
        }

        $decoded = json_decode($raw, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('errorhttp', 'local_external_api_sync',
                '', 'Response is not valid JSON: ' . substr($raw, 0, 200));
        }

        return $decoded ?? [];
    }

    /**
     * Build the full request URL with query parameters.
     */
    private function build_url(array $extra_params = []): string {
        $base = rtrim($this->connection->base_url, '/');
        $path = '/' . ltrim($this->endpoint->path, '/');

        $static_params = [];
        if (!empty($this->endpoint->query_params)) {
            $static_params = json_decode($this->endpoint->query_params, true) ?? [];
        }

        $auth_params = [];
        if ($this->connection->auth_type === 'apikey') {
            $auth_params = $this->auth_handler->get_query_params();
        }

        $all_params = array_merge($static_params, $auth_params, $extra_params);
        $url        = $base . $path;

        if (!empty($all_params)) {
            $url .= '?' . http_build_query($all_params);
        }

        return $url;
    }

    /**
     * Build the HTTP headers array.
     */
    private function build_headers(): array {
        $headers = ['Accept: application/json'];

        switch ($this->connection->auth_type) {
            case 'oauth2':
                $headers[] = 'Authorization: ' . $this->auth_handler->get_auth_header();
                break;
            case 'basic':
                $headers[] = 'Authorization: ' . $this->auth_handler->get_auth_header();
                break;
            case 'bearer':
                $headers[] = 'Authorization: ' . $this->auth_handler->get_auth_header();
                break;
            case 'apikey':
                // get_auth_header() returns "Header-Name: value" string, or null for query param mode.
                $header_info = $this->auth_handler->get_auth_header();
                if ($header_info !== null) {
                    $headers[] = $header_info;
                }
                break;
        }

        if (!empty($this->endpoint->extra_headers)) {
            $extra = json_decode($this->endpoint->extra_headers, true) ?? [];
            foreach ($extra as $name => $value) {
                $headers[] = "$name: $value";
            }
        }

        return $headers;
    }

    /**
     * Build the correct auth handler for this connection type.
     */
    private function build_auth_handler() {
        switch ($this->connection->auth_type) {
            case 'oauth2':
                return new oauth2($this->connection);
            case 'apikey':
                return new apikey($this->connection);
            case 'basic':
                return new basic($this->connection);
            case 'bearer':
                return new bearer($this->connection);
            default:
                throw new \moodle_exception('errorauth', 'local_external_api_sync',
                    '', "Unknown auth type: {$this->connection->auth_type}");
        }
    }
}
