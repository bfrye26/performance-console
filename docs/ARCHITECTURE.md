# Architecture

WP Performance Inspector separates inexpensive monitoring, broad health scanning, signed deep profiling and explicit remediation.

## Normal plugin

The normal plugin owns:

- dedicated telemetry/history tables
- system/plugin/error-log diagnostics
- database schema/runtime/integrity analysis
- Database Repair Centre and rollback records
- WP-Cron and Action Scheduler analysis
- cache/page-cache checks
- representative frontend route suites and route-grouped RUM histograms for p75 reporting
- root-cause incident grouping with recurrence, source, confidence, verification and lifecycle history
- regression/change tracking
- admin UI and WP-CLI

## MU bootstrap

The small MU bootstrap loads early enough to:

- validate short-lived HMAC diagnostic requests
- mark diagnostic responses so the caller can prove WordPress actually handled the request
- set no-cache signals for diagnostics
- privately exclude one selected plugin from the active plugin arrays for a signed A/B request
- enable detailed query collection/backtrace capture only when needed by profiling/sampling
- capture early WordPress/plugin bootstrap phase timing
- validate a short-lived administrator save-capture cookie before normal plugins load and identify the next matching classic, REST, Quick Edit or autosave request

Save diagnostics observe one real write and persist it as a `save` run. The cookie is signed, scoped to the arming administrator, HttpOnly, SameSite=Lax, expires after ten minutes and is cleared when a matching request begins. A manual-save capture deliberately ignores autosaves. The stored context is limited to request kind, method, user ID, post type and numeric post ID; request bodies, titles, content and field values are not persisted.

Database queries and outbound HTTP calls can be attributed from their traces. Save hook duration is measured as a whole, and registered callback components are reported only as suspects because wrapping arbitrary third-party callbacks would risk changing filter/action semantics.

Every plugin-impact request carries a signed probe UUID and the intended exclusion. The response echoes both, and the normal plugin persists both with the server-side PHP measurement. WPI accepts a sample only when the response and stored run match the request. Impact is the median of paired all-plugin minus excluded-plugin PHP timings; median absolute deviation establishes a per-test noise floor before WPI calls the result repeatable.

No active-plugin state is changed for ordinary visitors and plugin activation/deactivation hooks are not run by a private exclusion probe.

## Database analysis

The scanner parses `wp_get_db_schema()` from the installed WordPress version and compares required live core tables against those column/index definitions. Missing-table handling is intentionally different from missing-column/index handling because automatically recreating an empty missing table can conceal actual data loss.

Database remediation accepts only an allowlisted action and validated identifiers/WordPress schema fragments. Web actions are row/size-gated. Core primary/unique keys are duplicate-preflighted. Orphan cleanup rechecks the parent relationship and creates a bounded rollback snapshot before deletion.

`CHECK TABLE`, `ALTER TABLE`, `REPAIR TABLE`, `ANALYZE TABLE` and `OPTIMIZE TABLE` are never background housekeeping. They are explicit diagnostic/maintenance actions.

## Query/privacy model

Raw SQL literals are normalized before persistence so ordinary diagnostic history is not intended to store post content, emails, tokens, search strings or other SQL values. Query traces are capped and detailed profiling is signed/short-lived.

## Large-site model

Heavy database operations are bounded in web requests. Exact/deeper work can be explicitly invoked in WP-CLI. Passive traffic metrics are sampled, batched and grouped by low-cardinality route classes. Growing diagnostic data uses dedicated tables and retention cleanup.

## Drop-in compatibility

The plugin does not overwrite `db.php`, `object-cache.php` or `advanced-cache.php`. Existing cache/database drop-ins remain authoritative.
