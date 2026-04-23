<?php
/**
 * Language strings - English.
 *
 * @package    local_external_api_sync
 * @copyright  2026 Eyecare Partners
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Plugin name.
$string['pluginname']                   = 'External API Sync';

// Navigation.
$string['nav_connections']              = 'Connections';
$string['nav_endpoints']                = 'Endpoints';
$string['nav_mappings']                 = 'Field Mappings';
$string['nav_logs']                     = 'Sync Logs';

// Global settings.
$string['settings_heading']             = 'Global Settings';
$string['settings_encrypt_key']         = 'Encryption key';
$string['settings_encrypt_key_desc']    = 'Key used to encrypt stored credentials. Leave blank to use Moodle\'s default encryption.';
$string['settings_log_retention']       = 'Log retention (days)';
$string['settings_log_retention_desc']  = 'How many days to keep sync log records. Default: 90.';
$string['settings_test_mode']           = 'Test mode';
$string['settings_test_mode_desc']      = 'When enabled, syncs will run but no records will be written to Moodle. Use for testing field mappings.';

// Connections list.
$string['connections_title']            = 'External API Connections';
$string['connections_add']              = 'Add connection';
$string['connections_none']             = 'No connections configured yet.';
$string['connection_name']              = 'Name';
$string['connection_auth_type']         = 'Auth type';
$string['connection_base_url']          = 'Base URL';
$string['connection_enabled']           = 'Enabled';
$string['connection_endpoints']         = 'Endpoints';
$string['connection_actions']           = 'Actions';
$string['connection_edit']              = 'Edit connection';
$string['connection_delete']            = 'Delete connection';
$string['connection_delete_confirm']    = 'Are you sure you want to delete this connection and all its endpoints and mappings?';
$string['connection_test']              = 'Test connection';
$string['connection_test_success']      = 'Connection successful.';
$string['connection_test_fail']         = 'Connection failed: {$a}';

// Connection form.
$string['connection_form_title_add']    = 'Add connection';
$string['connection_form_title_edit']   = 'Edit connection: {$a}';
$string['connection_description']       = 'Description';
$string['connection_auth_oauth2']       = 'OAuth 2.0 Client Credentials';
$string['connection_auth_apikey']       = 'API Key';
$string['connection_auth_basic']        = 'Basic Auth (Username / Password)';
$string['connection_auth_bearer']       = 'Bearer Token (Static)';
$string['connection_token_url']         = 'Token URL';
$string['connection_token_url_help']    = 'OAuth2 token endpoint, e.g. https://api.example.com/oauth/token';
$string['connection_client_id']         = 'Client ID';
$string['connection_client_secret']     = 'Client secret';
$string['connection_client_secret_set'] = 'Client secret is set. Enter a new value to change it.';
$string['connection_oauth_scope']       = 'Scope(s)';
$string['connection_oauth_scope_help']  = 'Space-separated OAuth2 scopes, if required.';
$string['connection_api_key']           = 'API key';
$string['connection_api_key_set']       = 'API key is set. Enter a new value to change it.';
$string['connection_api_key_header']    = 'Header name';
$string['connection_api_key_location']  = 'Key location';
$string['connection_api_key_header_loc']= 'HTTP header';
$string['connection_api_key_query_loc'] = 'Query parameter';
$string['connection_basic_username']    = 'Username';
$string['connection_basic_password']    = 'Password';
$string['connection_basic_password_set']= 'Password is set. Enter a new value to change it.';
$string['connection_bearer_token']      = 'Bearer token';
$string['connection_bearer_token_set']  = 'Bearer token is set. Enter a new value to change it.';
$string['connection_extra_headers']     = 'Additional headers';
$string['connection_extra_headers_help']= 'One header per line in format: Header-Name: value';

// Endpoints list.
$string['endpoints_title']              = 'Endpoints for: {$a}';
$string['endpoints_add']                = 'Add endpoint';
$string['endpoints_none']               = 'No endpoints configured for this connection.';
$string['endpoint_name']                = 'Name';
$string['endpoint_path']                = 'Path';
$string['endpoint_direction']           = 'Direction';
$string['endpoint_entity']              = 'Entity';
$string['endpoint_schedule']            = 'Schedule';
$string['endpoint_last_run']            = 'Last run';
$string['endpoint_last_status']         = 'Status';
$string['endpoint_actions']             = 'Actions';
$string['endpoint_pull']                = '↓ Pull';
$string['endpoint_push']                = '↑ Push';
$string['endpoint_run_now']             = 'Run now';
$string['endpoint_edit']                = 'Edit';
$string['endpoint_mappings']            = 'Mappings';
$string['endpoint_delete']              = 'Delete';
$string['endpoint_delete_confirm']      = 'Delete this endpoint and all its field mappings?';
$string['endpoint_status_success']      = 'Success';
$string['endpoint_status_error']        = 'Error';
$string['endpoint_status_partial']      = 'Partial';
$string['endpoint_status_never']        = 'Never run';

// Endpoint form.
$string['endpoint_form_title_add']      = 'Add endpoint';
$string['endpoint_form_title_edit']     = 'Edit endpoint: {$a}';
$string['endpoint_description']         = 'Description';
$string['endpoint_http_method']         = 'HTTP method';
$string['endpoint_entity_user']         = 'User';
$string['endpoint_entity_enrolment']    = 'Enrolment';
$string['endpoint_entity_raw']          = 'Raw (custom)';
$string['endpoint_sync_action']         = 'Sync action';
$string['endpoint_action_create_update']= 'Create or update';
$string['endpoint_action_suspend']      = 'Suspend users';
$string['endpoint_action_enrol']        = 'Enrol users';
$string['endpoint_action_unenrol']      = 'Unenrol users';
$string['endpoint_response_root']       = 'Response data path';
$string['endpoint_response_root_help']  = 'Dot-notation path to the array of records in the response, e.g. Data.Employees. Leave blank if the response is a top-level array.';
$string['endpoint_query_params']        = 'Static query parameters';
$string['endpoint_query_params_help']   = 'One parameter per line in format: key=value';
$string['endpoint_request_body']        = 'Request body template';
$string['endpoint_request_body_help']   = 'JSON body for POST/PUT/push requests. Use {field_name} placeholders for dynamic values.';
$string['endpoint_error_email']         = 'Error report email(s)';
$string['endpoint_error_email_help']    = 'Comma-separated list of email addresses to receive error reports after each sync run.';
$string['endpoint_pagination']          = 'Pagination';
$string['endpoint_pagination_enabled']  = 'Enable pagination';
$string['endpoint_pagination_type']     = 'Pagination type';
$string['endpoint_pagination_page']     = 'Page number';
$string['endpoint_pagination_offset']   = 'Offset';
$string['endpoint_pagination_param']    = 'Page number parameter name';
$string['endpoint_page_size_param']     = 'Page size parameter name';
$string['endpoint_page_size']           = 'Page size';
$string['endpoint_total_count_path']    = 'Total count path';
$string['endpoint_total_count_path_help']= 'Dot-notation path to total record count in response. Used to determine when to stop paginating.';

// Field mappings.
$string['mappings_title']               = 'Field mappings for: {$a}';
$string['mappings_add']                 = 'Add mapping';
$string['mappings_none']                = 'No field mappings configured. At least one key field mapping is required.';
$string['mapping_external_field']       = 'External field';
$string['mapping_internal_field']       = 'Moodle field';
$string['mapping_is_key']               = 'Key field';
$string['mapping_transform']            = 'Transform';
$string['mapping_default']              = 'Default value';
$string['mapping_enabled']              = 'Enabled';
$string['mapping_actions']              = 'Actions';
$string['mapping_edit']                 = 'Edit';
$string['mapping_delete']               = 'Delete';
$string['mapping_delete_confirm']       = 'Delete this field mapping?';
$string['mapping_transform_none']       = 'None';
$string['mapping_transform_uppercase']  = 'Uppercase';
$string['mapping_transform_lowercase']  = 'Lowercase';
$string['mapping_transform_trim']       = 'Trim whitespace';
$string['mapping_transform_date_unix']  = 'Date string → Unix timestamp';
$string['mapping_transform_unix_date']  = 'Unix timestamp → Date string (Y-m-d)';
$string['mapping_key_warning']          = 'At least one mapping must be marked as a key field to match existing records.';

// Moodle standard user fields (shown in internal_field dropdown).
$string['moodle_field_username']        = 'username';
$string['moodle_field_email']           = 'email';
$string['moodle_field_firstname']       = 'firstname';
$string['moodle_field_lastname']        = 'lastname';
$string['moodle_field_idnumber']        = 'idnumber';
$string['moodle_field_phone1']          = 'phone1';
$string['moodle_field_phone2']          = 'phone2';
$string['moodle_field_department']      = 'department';
$string['moodle_field_institution']     = 'institution';
$string['moodle_field_city']            = 'city';
$string['moodle_field_country']         = 'country';
$string['moodle_field_lang']            = 'lang';
$string['moodle_field_suspended']       = 'suspended';

// Sync logs.
$string['logs_title']                   = 'Sync logs';
$string['logs_none']                    = 'No sync runs recorded yet.';
$string['log_connection']               = 'Connection';
$string['log_endpoint']                 = 'Endpoint';
$string['log_run_time']                 = 'Run time';
$string['log_duration']                 = 'Duration';
$string['log_processed']                = 'Processed';
$string['log_created']                  = 'Created';
$string['log_updated']                  = 'Updated';
$string['log_skipped']                  = 'Skipped';
$string['log_failed']                   = 'Failed';
$string['log_status']                   = 'Status';
$string['log_triggered_by']             = 'Triggered by';
$string['log_view_errors']              = 'View errors';
$string['log_errors_title']             = 'Error details for run at {$a}';
$string['log_no_errors']                = 'No errors recorded for this run.';

// Sync execution messages.
$string['sync_running']                 = 'Sync running for endpoint: {$a}';
$string['sync_complete']                = 'Sync complete. Processed: {$a->processed}, Created: {$a->created}, Updated: {$a->updated}, Failed: {$a->failed}';
$string['sync_no_mappings']             = 'No field mappings configured for this endpoint. Skipping.';
$string['sync_no_key_field']            = 'No key field mapping found. Cannot match existing records. Skipping.';
$string['sync_auth_failed']             = 'Authentication failed for connection: {$a}';
$string['sync_request_failed']          = 'API request failed: {$a}';
$string['sync_triggered_manual']        = 'Sync manually triggered by admin.';
$string['sync_run_now_confirm']         = 'Run sync now for endpoint "{$a}"?';

// Error report email.
$string['email_error_subject']          = 'External API Sync error report: {$a->connection} / {$a->endpoint}';
$string['email_error_body']             = "Sync run completed with errors.\n\nConnection: {$a->connection}\nEndpoint: {$a->endpoint}\nRun time: {$a->runtime}\nProcessed: {$a->processed}\nFailed: {$a->failed}\n\nError details:\n{$a->errors}";

// General UI.
$string['save']                         = 'Save changes';
$string['cancel']                       = 'Cancel';
$string['back']                         = 'Back';
$string['enabled']                      = 'Enabled';
$string['disabled']                     = 'Disabled';
$string['yes']                          = 'Yes';
$string['no']                           = 'No';
$string['never']                        = 'Never';
$string['actions']                      = 'Actions';
$string['confirm_delete']               = 'Confirm delete';
$string['error_invalid_connection']     = 'Invalid connection ID.';
$string['error_invalid_endpoint']       = 'Invalid endpoint ID.';
$string['error_nopermission']           = 'You do not have permission to manage External API Sync.';

// Additional strings required by edit_connection.php form.
$string['addconnection']       = 'Add Connection';
$string['backtconnections']    = 'Back to Connections';
$string['connectionname']      = 'Connection Name';
$string['connectionname_help'] = 'A descriptive name for this external service, e.g. "Dayforce HR".';
$string['description']         = 'Description';
$string['description_help']    = 'Optional notes about what this connection is used for.';
$string['base_url']            = 'Base URL';
$string['base_url_help']       = 'The root URL of the API, e.g. https://company.dayforcehcm.com';
$string['auth_type']           = 'Authentication Type';
$string['auth_type_help']      = 'The method used to authenticate requests to this API.';
$string['auth_oauth2']         = 'OAuth 2.0 Client Credentials';
$string['auth_apikey']         = 'API Key';
$string['auth_basic']          = 'Basic Auth (Username / Password)';
$string['auth_bearer']         = 'Bearer Token (Static)';
$string['token_url']           = 'Token URL';
$string['token_url_help']      = 'OAuth2 only. The endpoint used to obtain access tokens.';
$string['client_id']           = 'Client ID';
$string['client_secret']       = 'Client Secret';
$string['oauth_scope']         = 'OAuth Scope';
$string['oauth_scope_help']    = 'Space-separated list of OAuth scopes. Leave blank if not required.';
$string['api_key']             = 'API Key';
$string['api_key_header']      = 'API Key Header Name';
$string['api_key_header_help'] = 'The HTTP header to send the API key in, e.g. X-API-Key.';
$string['api_key_location']    = 'API Key Location';
$string['api_key_location_help'] = 'Whether to send the API key as an HTTP header or a query parameter.';
$string['api_key_param']       = 'Query Parameter Name';
$string['api_key_param_help']  = 'The query parameter name if API Key Location is set to Query Parameter.';
$string['apikey_header']       = 'HTTP Header';
$string['apikey_query']        = 'Query Parameter';
$string['basic_username']      = 'Username';
$string['basic_password']      = 'Password';
$string['bearer_token']        = 'Bearer Token';
$string['editconnection']      = 'Edit Connection';
$string['missingrequired']     = 'This field is required.';
$string['savedsuccess']        = 'Changes saved.';
$string['savechanges']         = 'Save changes';

// -----------------------------------------------------------------------
// Strings used by pages that were missing from the lang file
// -----------------------------------------------------------------------

// Navigation / page titles.
$string['endpoints']           = 'Endpoints';
$string['fieldmappings']       = 'Field Mappings';
$string['synclogs']            = 'Sync Logs';
$string['addendpoint']         = 'Add Endpoint';
$string['addmapping']          = 'Add Field Mapping';
$string['editendpoint']        = 'Edit Endpoint';
$string['editmapping']         = 'Edit Field Mapping';
$string['backtoendpoints']     = 'Back to Endpoints';
$string['backtomappings']      = 'Back to Field Mappings';

// Actions.
$string['edit']                = 'Edit';
$string['delete']              = 'Delete';
$string['enable']              = 'Enable';
$string['disable']             = 'Disable';
$string['confirmdelete']       = 'Are you sure you want to delete "{$a}"? This cannot be undone.';
$string['deletedalert']        = '"{$a}" has been deleted.';
$string['runsyncnow']          = 'Run Sync Now';
$string['synctriggered']       = 'Sync triggered. Check logs for results.';

// Status.
$string['status_enabled']      = 'Enabled';
$string['status_disabled']     = 'Disabled';
$string['neverrun']            = 'Never run';
$string['noendpoints']         = 'No endpoints configured for this connection.';
$string['nomappings']          = 'No field mappings configured for this endpoint.';
$string['nologsyet']           = 'No sync runs recorded yet.';
$string['nokeyfield']          = 'Warning: No key field defined. At least one mapping must be marked as a key field.';

// Endpoint form fields.
$string['endpointname']            = 'Endpoint Name';
$string['endpointname_help']       = 'A descriptive name, e.g. "Pull Employees" or "Push Completions to PowerBI".';
$string['path']                    = 'Path';
$string['path_help']               = 'The API path appended to the base URL, e.g. /api/v1/employees.';
$string['http_method']             = 'HTTP Method';
$string['direction']               = 'Sync Direction';
$string['direction_help']          = 'Pull = fetch data from external API into Moodle. Push = send Moodle data outward.';
$string['direction_pull']          = 'Pull (External → Moodle)';
$string['direction_push']          = 'Push (Moodle → External)';
$string['entity_type']             = 'Entity Type';
$string['entity_type_help']        = 'What kind of Moodle data this endpoint syncs.';
$string['entity_user']             = 'Users';
$string['entity_enrolment']        = 'Course Enrolments';
$string['entity_raw']              = 'Raw (no Moodle write — log only)';
$string['entity_teams_calendar']   = 'Teams Calendar (sync meeting attendees)';
$string['entity_course_completion']  = 'Course Completions (push)';
$string['entity_activity_completion'] = 'Activity Completions (push)';
$string['sync_action']             = 'Sync Action';
$string['sync_action_help']        = 'What to do when a matching record is found or not found in Moodle.';
$string['action_create_update']    = 'Create or Update';
$string['action_suspend']          = 'Suspend (deactivate user)';
$string['action_enrol']            = 'Enrol in Course';
$string['action_unenrol']          = 'Unenrol from Course';
$string['response_root_path']      = 'Response Root Path';
$string['response_root_path_help'] = 'Dot-notation path to the array of records in the API response. e.g. Data.Employees';
$string['request_body_template']   = 'Request Body Template';
$string['request_body_template_help'] = 'JSON template for POST/push requests. Use {field_name} placeholders.';
$string['extra_headers']           = 'Extra Headers (JSON)';
$string['extra_headers_help']      = 'Additional HTTP headers as a JSON object. e.g. {"Accept": "application/json"}';
$string['query_params']            = 'Static Query Parameters (JSON)';
$string['query_params_help']       = 'Query parameters always appended to requests. e.g. {"format": "json"}. For Teams Calendar endpoints, include {"service_account_upn": "training@example.com"} to set the shared organiser account.';
$string['schedule']                = 'Cron Schedule';
$string['schedule_help']           = 'Cron expression for when this endpoint syncs. e.g. "0 2 * * *" = 2am daily.';
$string['error_email']             = 'Error Report Email(s)';
$string['error_email_help']        = 'Comma-separated email addresses for error reports after each sync run.';
$string['pagination_enabled']      = 'Enable Pagination';
$string['pagination_type']         = 'Pagination Type';
$string['pagination_page']         = 'Page Number';
$string['pagination_offset']       = 'Offset';
$string['pagination_cursor']       = 'Cursor';
$string['pagination_param']        = 'Page Parameter Name';
$string['page_size_param']         = 'Page Size Parameter Name';
$string['page_size']               = 'Page Size';
$string['total_count_path']        = 'Total Count Path';
$string['total_count_path_help']   = 'Dot-notation path to the total record count in the response.';

// Field mapping form fields.
$string['external_field']          = 'External Field Path';
$string['external_field_help']     = 'Dot-notation path to the field in the API record. e.g. Employee.FirstName';
$string['internal_field']          = 'Moodle Field';
$string['internal_field_help']     = 'The Moodle user field to write to. Use profile_field_shortname for custom fields.';
$string['is_key_field']            = 'Key Field';
$string['is_key_field_help']       = 'Enable for the field used to match existing Moodle records (usually username or email).';
$string['transform']               = 'Transform';
$string['transform_help']          = 'Optional transformation applied to the value before writing to Moodle.';
$string['transform_none']          = 'None';
$string['transform_uppercase']     = 'Uppercase';
$string['transform_lowercase']     = 'Lowercase';
$string['transform_trim']          = 'Trim Whitespace';
$string['transform_date_unix']     = 'Date → Unix Timestamp';
$string['transform_date_iso']      = 'Date → ISO 8601';
$string['transform_prefix']        = 'Add Prefix';
$string['transform_suffix']        = 'Add Suffix';
$string['transform_arg']           = 'Transform Argument';
$string['transform_arg_help']      = 'For prefix/suffix: the string to add. For date transforms: the input format.';
$string['default_value']           = 'Default Value';
$string['default_value_help']      = 'Value to use when the external field is missing or empty.';
$string['sortorder']               = 'Sort Order';

// Moodle field labels for mapping dropdown.
$string['moodlefield_username']    = 'Username';
$string['moodlefield_firstname']   = 'First Name';
$string['moodlefield_lastname']    = 'Last Name';
$string['moodlefield_email']       = 'Email';
$string['moodlefield_idnumber']    = 'ID Number';
$string['moodlefield_phone1']      = 'Phone';
$string['moodlefield_department']  = 'Department';
$string['moodlefield_institution'] = 'Institution';
$string['moodlefield_city']        = 'City';
$string['moodlefield_country']     = 'Country';
$string['moodlefield_lang']        = 'Language';
$string['moodlefield_timezone']    = 'Timezone';
$string['moodlefield_suspended']   = 'Suspended (0/1)';
$string['moodlefield_auth']        = 'Auth Method';

// Log viewer.
$string['logrun']                  = 'Run Time';
$string['logduration']             = 'Duration';
$string['logfetched']              = 'Fetched';
$string['logcreated']              = 'Created';
$string['logupdated']              = 'Updated';
$string['logskipped']              = 'Skipped';
$string['logfailed']               = 'Failed';
$string['logstatus']               = 'Status';
$string['logstatus_success']       = 'Success';
$string['logstatus_partial']       = 'Partial';
$string['logstatus_error']         = 'Error';
$string['logstatus_never']         = 'Never';
$string['viewerrors']              = 'View Errors';

// Error email.
$string['errorreport_subject']     = 'Sync Error Report: {$a->endpoint} ({$a->date})';
$string['errorreport_body']        = "Sync run completed with errors.\n\nEndpoint: {$a->endpoint}\nConnection: {$a->connection}\nRun time: {$a->runtime}\n\nSummary:\n  Fetched:  {$a->fetched}\n  Created:  {$a->created}\n  Updated:  {$a->updated}\n  Skipped:  {$a->skipped}\n  Failed:   {$a->failed}\n\nErrors:\n{$a->errors}";

// Settings.
$string['settings_log_retention']      = 'Log Retention (days)';
$string['settings_log_retention_desc'] = 'Number of days to keep sync log entries. Set to 0 to keep indefinitely.';

// Scheduled task.
$string['taskname']                = 'External API Sync Runner';

// invalidjson error.
$string['invalidjson']             = 'Invalid JSON: {$a}';

// Parent-child enumeration.
$string['parent_child_settings']       = 'Parent-Child Enumeration';
$string['is_parent_only']              = 'Parent-only endpoint';
$string['is_parent_only_help']         = 'When enabled, this endpoint will not run on its own schedule. It only runs when called by a child endpoint that depends on it. Use this for endpoints that fetch a list of IDs to be enumerated by another endpoint.';
$string['parent_endpoint']             = 'Parent endpoint (ID source)';
$string['parent_endpoint_help']        = 'Select the endpoint that provides the list of IDs this endpoint will enumerate through. When this endpoint runs, the parent will be called first to fetch the ID list. Leave empty for a standalone endpoint.';
$string['parent_id_path']              = 'ID field path in parent response';
$string['parent_id_path_help']         = 'Dot-notation path to the ID field in each record returned by the parent endpoint. For Dayforce employees this is "XRefCode". Supports the same filter syntax as field mappings e.g. "Data.XRefCode".';
$string['parent_id_placeholder']       = 'ID placeholder token';
$string['parent_id_placeholder_help']  = 'The placeholder token in this endpoint\'s path or query parameters that will be replaced with each ID from the parent. Default: {XRefCode}. For example, a path of /Api/Eyecare/V1/Employees/{XRefCode} will be called once per ID with the token substituted.';
$string['parent_only_badge']           = '[Parent]';
$string['child_endpoint_badge']        = '[Child]';
$string['none']                        = '— None —';
$string['parent_only_no_manual'] = 'This is a parent-only endpoint and cannot be run manually. Run its child endpoint instead.';

// Concat transform.
$string['transform_concat'] = 'Concatenate (join two fields)';
