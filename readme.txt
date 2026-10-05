=== Performance Console ===
Contributors: cgm
Tags: performance, database, query, profiler, diagnostics, slow queries
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.0.4
License: GPLv2 or later

Production-oriented WordPress diagnostics and remediation with database integrity/schema checks, slow-query attribution, plugin fault analysis, cron/cache/server inspection, RUM and production safety gates.

== Description ==

Performance Console (formerly WP Performance Inspector) identifies database, plugin, query, job, cache, PHP/server and frontend bottlenecks and gives evidence-backed recommendations.

Database diagnostics include current-core schema comparison, missing columns/indexes, integrity checks, orphaned data, autoload pressure, transient buildup, fragmentation, engine/collation problems, lock/connection pressure, InnoDB signals and slow-query/server counters.

The Database Repair Centre can apply bounded fixes where practical, including expired transient cleanup, reversible autoload changes, rollback-backed orphan cleanup and reviewed core schema repairs. It now includes private resumable database backups and a guided maintenance-window path for large repairs, with WP-CLI retained as the preferred option for the largest tables.

Deep signed route profiles provide normalized query fingerprints, duplicate/N+1 detection, component/file/line attribution, safe EXPLAIN metadata, outbound HTTP timing and hook/phase timing. A one-request save profiler captures manual editor saves or autosaves and ranks measured query/HTTP work plus save-hook suspects without storing content or replaying a write. Private plugin-impact probes can exclude one plugin only for a signed diagnostic request without deactivating it for site visitors. Each A/B request is matched to its exact saved server-side PHP measurement; paired deltas, variability and a noise floor prevent ordinary request jitter from being reported as plugin cost.

== Installation ==

1. Upload and activate the plugin.
2. Open Performance in wp-admin.
3. Run a production-safe scan.
4. Install/verify the MU bootstrap for signed deep diagnostics.
5. Review Database Repair Centre actions before applying them.
6. Create and verify a Performance Console database backup before schema/index/repair operations. Large maintenance operations can be run from the guided wp-admin workflow or WP-CLI; CLI remains preferable for the largest tables.

== Changelog ==

= 3.0.4 =
* Fix a fatal error on hosts without the optional mbstring extension: 19 unguarded mb_substr() calls now route through a helper that falls back to substr(). MB_IN_BYTES/GB_IN_BYTES no longer depend on WordPress having loaded them.
* Halve finding write load: PFC_Utils::issue() uses one atomic INSERT ... ON DUPLICATE KEY UPDATE instead of a SELECT plus INSERT/UPDATE, removing a race where concurrent requests both treated a finding as new. Falls back to the read-then-write path when the issue_key UNIQUE index is absent.
* Stop shipping the 12 KB RUM bundle to every visitor. The sampling decision is made server-side, so unsampled visits load nothing. This also fixes a double-sampling bug that multiplied the server sample rate by a client-side roll, meaning the configured RUM rate was never the delivered rate. Adds pfc_rum_sampled and pfc_rum_rate filters, honours doNotTrack and Global Privacy Control, retries a rejected beacon once, refreshes the token before expiry, and reports the largest observed value per metric instead of the last.
* Fix MariaDB version detection. $wpdb->db_version() reports "5.5.5" on MariaDB, which made online InnoDB rebuild permanently unavailable on MariaDB 10.x hosts while blaming an "old or unrecognized" server.
* Fix auto-increment exhaustion detection, which could only ever fire for one column type because of a pre-gate applied before the per-type maximum was known.
* Fix the frontend stylesheet inventory, which missed every stylesheet emitted by WordPress core because the regex required href before rel. Also accept bare (unquoted) width/height/loading attributes.
* Fix metric_percentile() returning the 600000 ms ingest clamp for the final histogram bucket instead of the observed maximum.
* Add retention pruning for the autoload sample ledger and change log, the only two stores with no bound.
* Discard a cached scan report written by a different plugin version instead of rendering it with an unexpected shape.
* Make wp performance database-fix accept --keep-index, which drop_duplicate_index requires but which was never mapped, leaving the action unreachable from WP-CLI. The command now exits non-zero when a repair reports ok=false, and strips control characters from foreign SQL text before printing it.
* Validate the signed RUM payload before charging the rate limit, and cap distinct metric series so a public endpoint cannot create unbounded rows.
* Treat REST booleans correctly: ?deep=false no longer starts a deep scan, and a missing autoload parameter no longer silently disables autoload.
* Escape "_" in SHOW TABLES LIKE lookups, and record a failed Action Scheduler count instead of reporting it as an empty queue.
* Re-enable the admin backup controls and report the reason when an export fails or returns an unexpected state, and produce an actionable message instead of "Unexpected token '<'" when a proxy or host error page intercepts the REST call.
* Tolerate a scalar pfc_runtime option value instead of raising a fatal error on the settings save path.
* Document the outstanding data-integrity and safety items that were deliberately not changed without a live database to validate them.

= 3.0.3 =
* Prevent recursive global-hook instrumentation during the signed metabox callback-timing capture.
* Keep this diagnostic-only correction scoped to one matching save request; normal save behavior is unchanged.

= 3.0.2 =
* Add callback-level timing for selected save hooks during the signed one-shot legacy metabox capture only.
* Preserve original hook registration identity and component attribution; callback arguments and values are never stored.

= 3.0.1 =
* Adds a one-request profiler mode for the block editor's legacy post.php metabox save, distinct from its main REST save.
* The capture ignores REST and ordinary classic-editor requests and is consumed only by the matching compatibility POST.

= 3.0.0 =
* Renamed WP Performance Inspector to Performance Console.
* Existing installs migrate automatically on activation: the seven pfc_* tables, options, transients, maintenance cron event and MU bootstrap are renamed from wpi_* without data loss.

= 2.2.1 =
* Fixed a 20px horizontal overflow on every admin screen caused by the full-bleed background margins.
* Uninstalling now removes all plugin data: tables, options/transients, the maintenance cron event, the MU bootstrap, and backup files.
* Removed two unconditional database reads from normal requests: the MU sampler loads the secret on demand only, and the database version option is autoloaded.
* The MU bootstrap is installed atomically (staging file plus rename), so an interrupted write cannot leave a truncated file in mu-plugins.
* REST autoload changes now share the Repair Centre's protected-option list.

= 2.2.0 =
* Rechecks no longer resolve incidents when scans skip checks, probes fail, or a matching successful save has not been demonstrated.
* Uses one sampling decision and disables leftover MU tracing when the regular plugin is inactive.
* Shares the tested save request matcher with the early bootstrap and records write/response outcomes.
* Bundles web-vitals 6.2.1 locally, separates metric generations, and labels the RUM reporting window and bucket estimates.
* Adds optional content assertions to plugin impact tests, sampling coverage gates to autoload reviews, and clearer evidence/measurement limits.
* Detects same-size backup modifications before repair authorization and distinguishes integrity checks from restore testing.
* Avoids treating future scheduled jobs or missing persistent caching as proven performance faults.
* Adds PHP/JavaScript regression tests and CI.

= 2.1.1 =
* Redesigned the Slow Save Profiler capture panel with explicit manual and autosave choices, a clearer three-step workflow, and stronger visual hierarchy.
* Added distinct ready and armed states, improved capture guidance, and clearer content-safety and diagnostic-overhead messaging.
* Improved responsive behavior, focus visibility, semantic form controls, and contrast for supporting text.

= 2.1.0 =
* Added an administrator-armed capture for the next manual WordPress content save or autosave.
* Detects classic editor, block editor REST, Quick Edit, product and custom-post-type save requests while excluding settings, media, comments and user changes.
* Ranks directly attributed database and outbound HTTP work by component and captures exact query evidence.
* Times save-specific hooks and labels registered callback components as suspects rather than unmeasured causes.
* Uses a signed, ten-minute, single-use browser cookie and stores no post content, titles, field values or request bodies.
* Never replays a write or disables a plugin during saving.

= 2.0.1 =
* Fixed plugin-impact results that could repeat the same apparent cost across unrelated plugins because whole HTTP-request variation was being attributed to the selected plugin.
* Correlates every signed A/B probe with its exact saved server-side PHP run and verifies the requested exclusion in both response headers and stored telemetry.
* Calculates impact from five paired deltas and reports variability, a noise floor and whether the cost is repeatable.
* Identifies all-plugin and excluded-plugin variants in Recent Request Samples.

= 2.0.0 =
* Added grouped incident lifecycle, recurrence evidence, verification, snooze, resolution and accepted-risk controls.
* Fixed stale route findings, idle database-daemon false positives and expected database capability-probe noise.
* Added route-aware p75 RUM with client sampling, single-use tokens and ingestion rate limiting.
* Added warm-up, alternating five-pair plugin experiments with response comparability and confidence checks.
* Split the dashboard into lightweight server-rendered views and conditionally loaded diagnostic modules.
* Added representative route suites, configurable deep-scan routes, responsive incident cards and regression tests.
* Fixed MariaDB-compatible primary-key discovery during resumable database backups.

= 1.10.1 =
* Reworks plugin-owned exact duplicate-index repairs into a clear KEEP/REMOVE decision in the Database Repair Centre.
* Shows the owning plugin, canonical-name state, risk, confidence, expected functional impact and storage/write impact.
* Explicitly advises against creating a canonical replacement index when an equivalent verified legacy index is already being retained.
* Renames the action to Remove Duplicate Index while preserving backup, large-table, ownership and live-signature safety checks.

= 1.10.0 =
* Adds plugin-managed database index ownership through the `pfc_managed_database_indexes` registry.
* Integrates with CGM Authors 5.2.4 so Performance Console recognizes `cgm_authors_lookup` as canonical and the historical `cgm_idx_*` names as managed legacy aliases.
* Duplicate-index repairs now keep the canonical name when present, otherwise the owning plugin's highest-priority legacy alias, rather than selecting by result order.
* Revalidates managed-index ownership and keep/drop direction immediately before DDL.
* Database Repair Centre now shows the owning plugin and canonical-name state for registered equivalent indexes.

= 1.9.2 =
* Fixed core-column repair when the selected column is part of a malformed live PRIMARY KEY even though WordPress does not expect that column in the key. This specifically prevents the MySQL/MariaDB error "All parts of a PRIMARY KEY must be NOT NULL" when correcting nullable core columns such as wp_usermeta.meta_key.
* Added live-vs-expected PRIMARY KEY dependency planning. Performance Console can atomically drop the malformed live key, correct the selected column, and restore the exact WordPress PRIMARY KEY in one ALTER.
* Preview SQL now comes from the same dependency planner used at execution time, so dependency repairs are visible before confirmation.
* Retained NULL, duplicate-key and signed-to-unsigned preflights for all coordinated PRIMARY KEY work.

= 1.9.0 =
* New indigo/cyan Performance Console colour system distinct from CGM Tag Manager.
* Removed external Google Fonts admin request.
* Fixed tab-row vertical overflow/scrollbar.
* Exact full-dataset finding counts with 200-row display limit called out separately.
* Expanded Overview priority queue and scan snapshot.
* Database repair results redirect back to the relevant remediation view.
* Dependency-aware missing core PRIMARY KEY repair with NULL/duplicate/negative-value preflights.

= 1.8.0 =
* Rebuilt the wp-admin experience to match the current CGM Suite design language used by CGM Tag Manager.
* Added suite-style header, typography, pine/ember colour system, patterned background, cards, buttons, forms, tables, notices and status badges.
* Added Overview, Findings, Database, Profiling, Monitoring and System navigation without removing existing diagnostic or repair functionality.
* Added hash-aware navigation so backup, repair and InnoDB transaction links open the correct interface view.
* Added responsive layouts and improved mobile handling for large diagnostic tables.


= 1.7.0 =
* Added an InnoDB Transaction Manager directly in wp-admin for DDL-blocking, stuck and idle transactions.
* Shows MySQL thread/transaction IDs, age/state, connection user/host/database, rows locked/modified, normalized SQL and blocker/waiter relationships.
* Adds risk-classified guarded `KILL CONNECTION` handling with explicit rollback acknowledgement and an additional high-risk rollback acknowledgement for very old/large transactions.
* Performance Console refuses to terminate its own connection, database/server system sessions, replication/daemon sessions or transactions already rolling back.
* DDL safety errors now link directly to the Transaction Manager and include live transaction context.
* Added `wp performance innodb-transactions` and `wp performance innodb-terminate`.
* Long-running transaction findings now point to the in-plugin remediation workflow instead of only telling administrators to resolve the transaction externally.

= 1.6.1 =
* Rebuilt browser database backups around adaptive high-throughput batches instead of 100 rows per REST request.
* Each backup step now processes multiple SELECT/INSERT chunks for up to a bounded time/byte budget before yielding.
* Added table-width-aware initial batch sizing and runtime adaptive batch sizing from actual exported SQL bytes.
* Added composite-primary-key cursor pagination to avoid OFFSET degradation on tables such as term relationships and plugin queues.
* Increased multi-row INSERT statement sizes while retaining bounded packet/memory behavior.
* Added live backup throughput, adaptive batch, current-table progress and improved estimated overall progress.
* Reduced artificial delay between browser backup steps.

= 1.6.0 =
* Added a Database Backups area with private logical schema/data exports.
* Browser backups are resumable and process rows in bounded batches; numeric primary keys use cursor pagination to avoid large OFFSET scans.
* Added backup completion markers, SHA-256 verification, authenticated downloads and private/randomized storage with deny rules.
* Recent verified Performance Console backups can satisfy Repair Centre backup requirements directly. External verified snapshots/backups remain supported.
* Added guided wp-admin maintenance execution for previously CLI-only large repairs, retaining backup, lock, disk, DDL and explicit-risk gates.
* WP-CLI remains available as the recommended path for the largest database operations.
* Added `wp performance database-backup` and `wp performance database-backups`.
* Fixed CLI repair acknowledgement forwarding for `--danger-confirmed` and `--data-loss-confirmed`.
* Backup exports skip generated columns and preserve very large BIGINT cursors without PHP integer truncation.

= 1.5.1 =
* Fixed false WordPress core schema drift caused by truncating column definitions at datatype parentheses.
* Added balanced CREATE TABLE parsing so all core columns/indexes are inspected correctly.
* Clears stale schema-drift findings/cached scans on upgrade so a fresh scan is required before schema repair.
* Improved schema/index recommendations to point directly to available Repair Centre actions.
* Added explicit guided recovery steps for unsupported/custom storage engines instead of a dead-end manual-review state.
* Added guided InnoDB recovery preflights with integrity, index, foreign-key, lock/transaction, online-DDL and disk-space evidence.
* Added parsed InnoDB deadlock participants and active blocker/waiter graphs.
* Added InnoDB buffer-pool, redo-capacity, purge-pressure and innodb_force_recovery guidance.
* Added verified-backup acknowledgement requirements for schema/repair/optimize and InnoDB rebuild mutations.
* Added guarded secondary BTREE index rebuilds using explicit ALGORITHM=INPLACE, LOCK=NONE with no COPY fallback.
* Added advanced InnoDB table rebuild support through guarded WP-CLI maintenance workflows.
* Added post-repair CHECK TABLE, ANALYZE TABLE and rebuilt-index verification.
* Added conservative review-only missing-index candidates for simple slow single-table queries.
* Added live SHOW ENGINES discovery and engine-specific maintenance capability rules for InnoDB, MyISAM, MariaDB Aria, ARCHIVE, CSV, MEMORY, NDB and unknown/plugin engines.
* Automatic CSV repair remains blocked because of data-loss risk.



= 1.2.0 =
* Added current-WordPress core table/column/index schema verification.
* Added Database Repair Centre with size-gated core schema repairs.
* Added bounded expired transient and orphan cleanup with rollback snapshots where supported.
* Added reversible autoload remediation based on sampled option-use evidence.
* Added broader integrity checks across WordPress/plugin/custom tables.
* Added InnoDB deadlock, lock, history-list, connection, temporary-table and scan/full-join diagnostics.
* Added auto-increment exhaustion, fragmentation, collation/engine and duplicate-index diagnostics.
* Hardened plugin-impact probes so HTTP failures/cached responses cannot be reported as valid speedups.
* Expanded plugin/runtime, frontend, cron, Action Scheduler and regression diagnostics.

= 1.1.0 =
* Expanded plugin, frontend, database and production telemetry diagnostics.
* Added signed query backtrace capture and lower-cardinality passive monitoring.

= 1.0.0 =
* Initial diagnostic framework.
