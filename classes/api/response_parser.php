<?php
/**
 * Parses API responses and extracts records using dot-notation paths.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_external_api_sync\api;

defined('MOODLE_INTERNAL') || die();

class response_parser {

    /**
     * Extract a nested value from an array using dot notation with array filter support.
     *
     * Standard dot notation:
     *   'Data.Employees'  → $data['Data']['Employees']
     *
     * Array index filter (zero-based, negative for from end):
     *   'Items[0]'        → first element of Items array
     *   'Items[-1]'       → last element of Items array
     *
     * Array field filter (returns first matching element):
     *   'Items[XRefCode=ACTIVE]'
     *   'Items[ContactInformationType.XRefCode=BusinessEmail]'
     *
     * Wildcard collect (returns array of values):
     *   'Items[*].ElectronicAddress'
     *
     * Filters and dot notation can be combined freely:
     *   'Contacts.Items[ContactInformationType.XRefCode=BusinessEmail].ElectronicAddress'
     *   'EmploymentStatuses.Items[0].EmploymentStatus.XRefCode'
     *   'EmployeeProperties.Items[EmployeeProperty.XRefCode=EmployeePropertyXrefCode9001].OptionValue.ShortName'
     *
     * @param array  $data Response data array
     * @param string $path Dot-notation path with optional array filters
     * @return mixed Value at path, or null if not found
     */
    public static function get_value(array $data, string $path) {
        if (empty($path)) {
            return $data;
        }

        // Tokenise the path, preserving bracket expressions as single tokens.
        // e.g. "Contacts.Items[XRefCode=BusinessEmail].ElectronicAddress"
        // → ["Contacts", "Items[XRefCode=BusinessEmail]", "ElectronicAddress"]
        $tokens  = self::tokenise_path($path);
        $current = $data;

        foreach ($tokens as $token) {
            if ($current === null) {
                return null;
            }

            // Check for array filter syntax: Key[...] or just Key
            if (preg_match('/^([^\[]*)\[(.+)\]$/', $token, $matches)) {
                $key    = $matches[1]; // e.g. "Items" (may be empty for chained filters)
                $filter = $matches[2]; // e.g. "0", "-1", "*", "XRefCode=ACTIVE"

                // Navigate to the key first (unless empty, meaning current is already the array).
                if ($key !== '') {
                    if (!is_array($current) || !array_key_exists($key, $current)) {
                        return null;
                    }
                    $current = $current[$key];
                }

                if (!is_array($current)) {
                    return null;
                }

                // Re-index to ensure numeric access works.
                $current = array_values($current);

                if ($filter === '*') {
                    // Wildcard — collect this array for further traversal.
                    // The next token will be extracted from each element.
                    continue;
                }

                if (is_numeric($filter)) {
                    // Numeric index.
                    $idx = (int) $filter;
                    if ($idx < 0) {
                        $idx = count($current) + $idx;
                    }
                    $current = $current[$idx] ?? null;
                    continue;
                }

                // Field=Value filter — find first matching element.
                if (strpos($filter, '=') !== false) {
                    [$filter_path, $filter_value] = explode('=', $filter, 2);
                    $filter_path  = trim($filter_path);
                    $filter_value = trim($filter_value);

                    $found = null;
                    foreach ($current as $item) {
                        if (!is_array($item)) continue;
                        $item_value = self::get_value($item, $filter_path);

                        // Compare item value against filter value.
                        // Handles exact string match, case-insensitive match,
                        // and boolean values (true/false/1/0).
                        $match = false;

                        if (is_bool($item_value)) {
                            // Boolean: match true/1 and false/0
                            $fv_lower = strtolower($filter_value);
                            $match = ($item_value === true  && ($fv_lower === 'true'  || $fv_lower === '1'))
                                  || ($item_value === false && ($fv_lower === 'false' || $fv_lower === '0'));
                        } else {
                            // String/int: exact match first, case-insensitive fallback
                            $sv = (string) $item_value;
                            $match = ($sv === $filter_value)
                                  || (strtolower($sv) === strtolower($filter_value));
                        }

                        if ($match) {
                            $found = $item;
                            break;
                        }
                    }
                    $current = $found;
                    continue;
                }

                // Unknown filter syntax — treat as key lookup.
                $current = $current[$filter] ?? null;

            } else {
                // Plain key navigation.
                if (is_array($current) && isset($current[0]) && is_array($current[0])) {
                    // Current is a sequential array — we're in wildcard mode.
                    // Collect this key from each element.
                    $collected = [];
                    foreach ($current as $item) {
                        if (is_array($item) && array_key_exists($token, $item)) {
                            $collected[] = $item[$token];
                        }
                    }
                    $current = empty($collected) ? null : (count($collected) === 1 ? $collected[0] : $collected);
                } else {
                    if (!is_array($current) || !array_key_exists($token, $current)) {
                        return null;
                    }
                    $current = $current[$token];
                }
            }
        }

        return $current;
    }

    /**
     * Tokenise a dot-notation path, keeping bracket expressions intact.
     *
     * "A.B[X=Y].C" → ["A", "B[X=Y]", "C"]
     * "A.B[0].C.D[*].E" → ["A", "B[0]", "C", "D[*]", "E"]
     *
     * @param string $path
     * @return string[]
     */
    private static function tokenise_path(string $path): array {
        $tokens  = [];
        $current = '';
        $depth   = 0;

        for ($i = 0; $i < strlen($path); $i++) {
            $char = $path[$i];

            if ($char === '[') {
                $depth++;
                $current .= $char;
            } elseif ($char === ']') {
                $depth--;
                $current .= $char;
            } elseif ($char === '.' && $depth === 0) {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current  = '';
                }
            } else {
                $current .= $char;
            }
        }

        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /**
     * Extract the records array from a response using the endpoint's
     * configured response_root_path.
     *
     * @param array  $response     Full decoded response
     * @param string $root_path    Dot-notation path to records array
     * @return array List of records
     */
    public static function extract_records(array $response, string $root_path = ''): array {
        if (empty($root_path)) {
            // Response is itself the array of records.
            if (isset($response[0]) || empty($response)) {
                return $response;
            }
            return [$response]; // Single record response.
        }

        $records = self::get_value($response, $root_path);

        if (!is_array($records)) {
            return [];
        }

        // If it's an associative array (single record), wrap it.
        if (!isset($records[0]) && !empty($records)) {
            return [$records];
        }

        return $records;
    }

    /**
     * Extract a single field value from a record using dot notation.
     * Supports both flat and nested field paths.
     *
     * @param array  $record       Single record array
     * @param string $field_path   Dot-notation path e.g. "Employee.FirstName"
     * @return mixed|null
     */
    public static function extract_field(array $record, string $field_path) {
        return self::get_value($record, $field_path);
    }

    /**
     * Apply a transform to a value.
     *
     * @param mixed  $value     Raw value from API
     * @param string $transform Transform name
     * @return mixed Transformed value
     */
    public static function apply_transform($value, string $transform) {
        if ($value === null || $value === '') {
            return $value;
        }

        switch ($transform) {
            case 'uppercase':
                return strtoupper((string) $value);

            case 'lowercase':
                return strtolower((string) $value);

            case 'trim':
                return trim((string) $value);

            case 'date_unix':
                // Convert date string to Unix timestamp.
                $timestamp = strtotime((string) $value);
                return $timestamp !== false ? $timestamp : $value;

            case 'unix_date':
                // Convert Unix timestamp to Y-m-d string.
                return is_numeric($value) ? date('Y-m-d', (int) $value) : $value;

            case 'none':
            default:
                return $value;
        }
    }

    /**
     * Map a raw API record to Moodle field values using field mappings.
     *
     * @param array $record      Single API record
     * @param array $mappings    Array of mapping objects from ext_api_field_mappings
     * @return array Associative array of internal_field => value
     */
    public static function map_record(array $record, array $mappings): array {
        $mapped = [];

        foreach ($mappings as $mapping) {
            if (empty($mapping->enabled)) {
                continue;
            }

            $value = self::extract_field($record, $mapping->external_field);

            // Apply default if value is empty.
            if (($value === null || $value === '') && !empty($mapping->default_value)) {
                $value = $mapping->default_value;
            }

            // Apply transform.
            if (!empty($mapping->transform) && $mapping->transform !== 'none') {
                $value = self::apply_transform($value, $mapping->transform);
            }

            $mapped[$mapping->internal_field] = $value;
        }

        return $mapped;
    }
}
