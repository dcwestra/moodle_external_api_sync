# External API Sync — Moodle Local Plugin

**`local_external_api_sync`**  
*Copyright (C) 2026 Eyecare Partners. All rights reserved.*  
*Licensed under GNU GPL v3. For internal use only.*

A generic, configurable integration plugin for MapleLMS that synchronises data between Moodle and external REST APIs in both directions. Built for Eyecare Partners to connect Dayforce HCM, Microsoft Graph, and analytics platforms with MapleLMS — but designed to work with any REST API.

---

## Features

- **Multiple connections** — Configure any number of external services, each with their own credentials and auth method
- **Four auth methods** — OAuth2 Client Credentials, API Key (header or query param), HTTP Basic Auth, Static Bearer Token
- **Pull and push** — Pull external records into Moodle, or push Moodle data outward
- **Advanced field mapping** — Dot-notation path extraction from deeply nested JSON with array index filters, field value filters, and wildcard collect. See Field Path Syntax below.
- **Parent-child enumeration** — Two-stage API patterns (fetch ID list → fetch detail per ID) are supported natively. The parent endpoint fetches and caches IDs; the child iterates through them automatically.
- **Pagination support** — Page number, offset, and cursor-based pagination
- **Scheduled sync** — Each endpoint has its own cron schedule; a dispatcher task runs every 15 minutes by default (configurable in Site Admin → Scheduled Tasks)
- **Manual sync trigger** — Run any endpoint on demand from the admin UI
- **Error reporting** — Per-run logs with per-record error details; configurable email alerts on failure
- **Credential encryption** — Secrets, passwords, and tokens are AES-256-CBC encrypted at rest

---

## Requirements

- Moodle 4.0+ / MapleLMS
- PHP 8.0+
- `openssl` PHP extension (for credential encryption)

---

## Installation

1. Download the latest `external_api_sync_vX.X.X.zip`
2. Provide the zip to your MapleLMS support team for installation
3. They will place it in `/local/external_api_sync/` and trigger a Moodle upgrade

Or via admin UI: **Site Administration → Plugins → Install plugins** and upload the zip directly.

---

## Entity Types

### Pull (External → Moodle)

| Entity Type | What it does |
|---|---|
| `Users` | Creates or updates Moodle user accounts from external records |
| `Course Enrolments` | Enrols or unenrols users in courses |
| `Raw` | Fetches records and logs them — no Moodle writes. Useful for testing new connections |

### Push (Moodle → External)

| Entity Type | What it does |
|---|---|
| `Users` | Pushes active Moodle user profiles to an external endpoint |
| `Course Completions` | Pushes one record per user per course: completion status, date, grade, pass/fail |
| `Activity Completions` | Pushes one record per user per course module: activity-level completion and grade |
| `Teams Calendar` | Syncs enrolled users as attendees on Microsoft Teams meetings via Graph API |

---

## Configuration

### 1. Add a Connection

**Site Administration → External API Sync → Connections → Add Connection**

| Field | Description |
|---|---|
| Name | Display name e.g. "Dayforce HR" or "Microsoft Graph" |
| Auth Type | OAuth2, API Key, Basic Auth, or Bearer Token |
| Base URL | Root API URL e.g. `https://us252-services.dayforcehcm.com` |
| Token URL | OAuth2 only — token endpoint |
| Client ID / Secret | OAuth2 credentials |
| Scope | OAuth2 scopes e.g. `https://graph.microsoft.com/.default` |

### 2. Add an Endpoint

From the connection's Endpoints page:

| Field | Description |
|---|---|
| Path | API path e.g. `/Api/Eyecare/V1/Employees/{XRefCode}` |
| HTTP Method | GET, POST, PUT, PATCH |
| Direction | Pull or Push |
| Entity Type | See entity types table above |
| Response Root Path | Dot-notation path to the records array e.g. `Data` |
| Query Params (JSON) | Static query parameters e.g. `{"expand": "Contacts,WorkAssignments"}` |
| Schedule | Cron expression e.g. `0 2 * * *` = 2am daily |
| Error Email | Comma-separated addresses for failure alerts |

### 3. Parent-Child Enumeration (Two-Stage APIs)

Some APIs require two calls: first fetch a list of IDs, then fetch detail for each ID. Dayforce is an example — `GET /Employees` returns only XRefCodes, and `GET /Employees/{XRefCode}` returns the full record.

**Step 1 — Create the parent endpoint** (ID list fetcher):

| Field | Value |
|---|---|
| Path | `/Api/Eyecare/V1/Employees` |
| Query Params | `{"employmentStatusXRefCode": "ACTIVE,ON_LEAVE"}` |
| Response Root Path | `Data` |
| Entity Type | `Raw` |
| ☑ Parent-only endpoint | Checked — suppresses independent scheduling |

**Step 2 — Create the child endpoint** (detail fetcher):

| Field | Value |
|---|---|
| Path | `/Api/Eyecare/V1/Employees/{XRefCode}` |
| Query Params | `{"expand": "Contacts,EmploymentStatuses,WorkAssignments,OrgUnitInfos"}` |
| Response Root Path | `Data` |
| Entity Type | `Users` |
| Parent endpoint | Select the parent endpoint created above |
| ID field path in parent response | `XRefCode` |
| ID placeholder token | `{XRefCode}` |

When the child endpoint's cron fires, it automatically triggers the parent first, stores all IDs in a temporary cache table, then iterates through them making one detail call per ID. All results are aggregated into a single sync log entry.

The placeholder token `{XRefCode}` is substituted with each ID in both the URL path and query parameter values, so both of these patterns work:

```
/Api/Eyecare/V1/Employees/{XRefCode}          ← path substitution
/Api/v1/users?id={XRefCode}                   ← query param substitution
```

### 4. Add Field Mappings

Map external API fields to Moodle fields using dot-notation paths with optional array filters.

**Example — Dayforce employee pull:**

| External Field | → | Internal Field | Key? |
|---|---|---|---|
| `EmployeeNumber` | → | `username` | ✓ |
| `FirstName` | → | `firstname` | |
| `LastName` | → | `lastname` | |
| `Contacts.Items[ContactInformationType.XRefCode=BusinessEmail].ElectronicAddress` | → | `email` | |
| `EmploymentStatuses.Items[0].EmploymentStatus.ShortName` | → | `profile_field_employment_status` | |
| `WorkAssignments.Items[0].Position.Job.ShortName` | → | `profile_field_job_title` | |
| `WorkAssignments.Items[0].Location.LegalEntity.ShortName` | → | `profile_field_legal_entity` | |
| `OrgUnitInfos.Items[OrgUnitDetail.OrgLevel.XRefCode=Region].OrgUnitDetail.ShortName` | → | `profile_field_region` | |
| `OrgUnitInfos.Items[OrgUnitDetail.OrgLevel.XRefCode=District].OrgUnitDetail.ShortName` | → | `profile_field_district` | |
| `OrgUnitInfos.Items[OrgUnitDetail.OrgLevel.XRefCode=Site].OrgUnitDetail.ShortName` | → | `profile_field_site` | |

At least one field must be marked as the **Key Field** — used to match API records to existing Moodle users. For Dayforce, `EmployeeNumber` mapped to `username` is the recommended key.

---

## Field Path Syntax

The response parser supports dot-notation with array filters for navigating deeply nested JSON.

### Basic dot-notation
```
FirstName
WorkAssignments.Items
Position.Job.ShortName
```

### Array index (zero-based, negative counts from end)
```
Items[0]          → first element
Items[-1]         → last element
Items[1]          → second element
```

### Field=Value filter (returns first matching element)
```
Items[XRefCode=ACTIVE]
Items[ContactInformationType.XRefCode=BusinessEmail]
Items[OrgUnitDetail.OrgLevel.XRefCode=Region]
```

### Wildcard collect (returns array of values from all elements)
```
Items[*].ElectronicAddress
```

### Combined
```
Contacts.Items[ContactInformationType.XRefCode=BusinessEmail].ElectronicAddress
OrgUnitInfos.Items[OrgUnitDetail.OrgLevel.XRefCode=Site].OrgUnitDetail.Address.City
EmploymentStatuses.Items[0].EmploymentStatus.ShortName
WorkAssignments.Items[0].Position.Department.ShortName
```

---

## Push Field Reference

### Course Completions

| Internal Field | Description |
|---|---|
| `username`, `email`, `firstname`, `lastname`, `idnumber`, `department`, `institution` | User fields |
| `profile_field_{shortname}` | Custom profile fields |
| `course_id`, `course_shortname`, `course_fullname`, `course_idnumber`, `course_category` | Course fields |
| `completion_status` | Human-readable: `complete`, `incomplete`, `complete_pass`, `complete_fail` |
| `completion_date` | Unix timestamp (null if incomplete) |
| `completion_date_iso` | ISO 8601 string (null if incomplete) |
| `final_grade` | Numeric final grade |
| `final_grade_percent` | Grade as 0–100 percentage |
| `passed` | 1, 0, or null |

### Activity Completions

All course completion fields above, plus:

| Internal Field | Description |
|---|---|
| `activity_name` | Display name of the activity |
| `activity_type` | Module type e.g. `quiz`, `scorm`, `lesson` |
| `activity_grade` | Numeric grade for this activity |
| `activity_passed` | 1, 0, or null |

---

## Field Transform Reference

| Transform | Description |
|---|---|
| `none` | Return value as-is |
| `uppercase` | Convert to uppercase |
| `lowercase` | Convert to lowercase |
| `trim` | Strip leading/trailing whitespace |
| `date_unix` | Parse date string → Unix timestamp |
| `unix_date` | Unix timestamp → Y-m-d string |
| `prefix` | Prepend a string (set value in Transform Argument) |
| `suffix` | Append a string (set value in Transform Argument) |

---

## Architecture

```
local_external_api_sync/
├── db/
│   ├── install.xml              # 6 DB tables (see below)
│   ├── upgrade.php              # Upgrade steps
│   ├── tasks.php                # Dispatcher scheduled task
│   └── access.php               # Capabilities: manage, viewlogs, runsync
├── classes/
│   ├── api/
│   │   ├── auth/
│   │   │   ├── basic.php        # HTTP Basic Auth
│   │   │   ├── bearer.php       # Static Bearer Token
│   │   │   ├── oauth2.php       # OAuth2 Client Credentials (native curl, token cache)
│   │   │   └── apikey.php       # API Key — header or query param
│   │   ├── client.php           # Authenticated HTTP client (native curl, pagination)
│   │   └── response_parser.php  # Dot-notation + array filter field extraction
│   ├── sync/
│   │   ├── user_sync.php        # Pull → create/update Moodle users
│   │   ├── enrolment_sync.php   # Pull → enrol/unenrol in courses
│   │   ├── push_sync.php        # Push → user profiles, completions, grades
│   │   ├── calendar_sync.php    # Push → Teams meeting attendees via Graph API
│   │   └── parent_child_runner.php  # Two-stage enumeration orchestrator
│   ├── task/
│   │   └── sync_task.php        # Scheduled task — dispatcher + parent-child routing
│   └── util/
│       └── crypto.php           # AES-256-CBC credential encryption
├── pages/
│   ├── connections.php          # Connection list
│   ├── edit_connection.php      # Add/edit connection
│   ├── endpoints.php            # Endpoint list (with [Parent] / [Child] badges)
│   ├── edit_endpoint.php        # Add/edit endpoint (includes parent-child settings)
│   ├── mappings.php             # Field mapping list
│   ├── edit_mapping.php         # Add/edit mapping
│   └── logs.php                 # Sync log viewer + error drill-down
├── lang/en/
├── settings.php
├── version.php
├── README.md
└── LICENSE
```

---

## Database Tables

| Table | Purpose |
|---|---|
| `ext_api_connections` | One row per external service — credentials, auth type, base URL |
| `ext_api_endpoints` | One row per API endpoint — path, direction, entity type, schedule, parent-child config |
| `ext_api_field_mappings` | One row per field mapping — external path → Moodle field, transform |
| `ext_api_sync_log` | One row per sync run — counts, status, per-record error details |
| `ext_api_token_cache` | Cached OAuth2 access tokens to avoid re-authenticating each run |
| `ext_api_id_cache` | Temporary ID cache for parent-child enumeration — cleared and repopulated each run |

---

## Version History

| Version | Changes |
|---|---|
| 1.0.0 | Initial release — user sync, enrolment sync, push users, OAuth2/APIKey/Basic/Bearer auth |
| 1.1.0 | Added Teams Calendar entity type |
| 1.2.0 | Added Course Completion and Activity Completion push entity types; fixed auth handler doubled Authorization header bug; switched all HTTP calls to native PHP curl; added array filter syntax to response parser (`Items[Field=Value]`, `Items[0]`, `Items[*]`); added parent-child endpoint enumeration for two-stage APIs; fixed manual run button to route through parent-child logic |
| 1.2.1 | Fixed `is_due()` cron evaluator — replaced interval estimation with proper cron expression parsing supporting exact values, `*`, step (`*/N`), lists, and ranges; fixes endpoints not firing on correct schedule; added `set_time_limit(0)` for long-running syncs |
| 1.2.2 | Source of truth enforcement — user sync now correctly overwrites Moodle fields with API values including previously blank fields; skips writes when values are identical for efficiency; Dayforce always wins on field conflicts |
| 1.2.3 | Implemented Suspend sync action — endpoints with Sync Action = Suspend now correctly set suspended = 1 on matched Moodle users; unrecognised users are skipped silently |

---

## License

GNU General Public License v3.0 — see [LICENSE](LICENSE) for full terms.

Copyright (C) 2026 Eyecare Partners. This software was developed for internal use at Eyecare Partners. Distribution outside of Eyecare Partners is not authorized except as required by the terms of the GNU GPL v3.
