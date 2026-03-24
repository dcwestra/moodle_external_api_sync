<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Encryption utility for stored credentials.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_external_api_sync\util;

defined('MOODLE_INTERNAL') || die();

/**
 * AES-256-CBC encryption for sensitive credential fields.
 *
 * Uses the site's configured encrypt_key plugin setting.
 * Falls back to storing plaintext if no key is configured (with a warning).
 */
class crypto {

    /** Prefix prepended to encrypted values so we can detect them. */
    const ENCRYPTED_PREFIX = 'EAS_ENC:';

    /**
     * Encrypt a plaintext string for storage.
     *
     * @param string $plaintext
     * @return string Encrypted, base64-encoded string with prefix
     */
    public static function encrypt(string $plaintext): string {
        if ($plaintext === '') {
            return '';
        }

        $key = self::get_key();
        if ($key === '') {
            // No key configured; store plaintext (warn in logs).
            debugging('local_external_api_sync: No encryption key configured. Credentials stored in plaintext.',
                DEBUG_DEVELOPER);
            return $plaintext;
        }

        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            debugging('local_external_api_sync: Encryption failed.', DEBUG_DEVELOPER);
            return $plaintext;
        }

        return self::ENCRYPTED_PREFIX . base64_encode($iv . $cipher);
    }

    /**
     * Decrypt a stored credential.
     *
     * @param string $stored The value from the database
     * @return string Plaintext
     */
    public static function decrypt(string $stored): string {
        if ($stored === '' || !str_starts_with($stored, self::ENCRYPTED_PREFIX)) {
            // Not encrypted (legacy or no-key scenario).
            return $stored;
        }

        $key = self::get_key();
        if ($key === '') {
            return $stored; // Can't decrypt without a key.
        }

        $encoded = substr($stored, strlen(self::ENCRYPTED_PREFIX));
        $raw     = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) < 17) {
            return '';
        }

        $iv     = substr($raw, 0, 16);
        $cipher = substr($raw, 16);

        $plaintext = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        return $plaintext !== false ? $plaintext : '';
    }

    /**
     * Decrypt all sensitive fields on a connection record in-place.
     *
     * @param object $connection
     * @return object The same object with decrypted fields
     */
    public static function decrypt_connection(object $connection): object {
        $sensitive_fields = [
            'client_secret',
            'api_key',
            'basic_password',
            'bearer_token',
        ];

        foreach ($sensitive_fields as $field) {
            if (isset($connection->$field) && $connection->$field !== '') {
                $connection->$field = self::decrypt($connection->$field);
            }
        }

        return $connection;
    }

    /**
     * Get the configured encryption key, derived to 32 bytes for AES-256.
     *
     * @return string 32-byte key, or empty string if not configured
     */
    private static function get_key(): string {
        $raw_key = get_config('local_external_api_sync', 'encrypt_key');
        if (empty($raw_key)) {
            return '';
        }
        // Derive a consistent 32-byte key regardless of input length.
        return hash('sha256', $raw_key, true);
    }
}
