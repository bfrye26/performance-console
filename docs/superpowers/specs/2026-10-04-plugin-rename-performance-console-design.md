# Performance Console rename (formerly WP Performance Inspector) — Design

Date: 2026-10-04
Status: Approved
Repo: bfrye26/wp-performance-inspector (to be renamed bfrye26/performance-console)
Local clone: C:\laragon\www\CGM-New-2\wp-content\plugins\wp-performance-inspector

## Context

The plugin is a production-oriented WordPress diagnostics, profiling, incident
workflow, RUM and guarded database-repair suite (v2.2.1). Its current name, WP
Performance Inspector, must change to **Performance Console** (slug
`performance-console`, code prefix `PFC_`/`pfc_`).

Constraints agreed with the owner:

- Descriptive name, "Performance" retained, no "WP"/"WordPress" in the display name.
- Full rename: public surface, PHP symbols, persisted identifiers, docs and GitHub.
- Existing installs (CGM-New-2 and any others) must keep their data via migration.
- Not published on WordPress.org, so the slug and text domain are free to change.
- WordPress.org slug `performance-console` is currently free.

## Goals

1. Rename every reference in code, docs, build scripts, tests, CI and GitHub.
2. Migrate existing data (7 tables, options, transients, cron, MU bootstrap)
   transparently on upgrade.
3. Keep behavior identical; this is a rename plus migration, no feature changes.

## Non-goals

- No refactors, feature work or schema changes.
- No rename of the `wp performance` WP-CLI command (name-agnostic; keeps scripts working).
- No rewriting of `dist/` historical release artifacts (old zips, release notes).
- No WordPress.org submission work.

## Identity mapping

| Old | New |
|---|---|
| `WP Performance Inspector` (display name, UI prose) | `Performance Console` |
| `wp-performance-inspector` (slug, text domain, folder, main file, MU template filename, package name) | `performance-console` |
| `WPI_` classes/constants (`WPI_DB`, `WPI_Utils`, `WPI_VERSION`, ...) | `PFC_` (`PFC_DB`, `PFC_Utils`, `PFC_VERSION`, ...) |
| `wpi_` tables/options/transients/cron/hooks/action names/array keys (`wpi_runs`, `wpi_secret`, `wpi_daily_maintenance`, ...) | `pfc_` equivalents |
| REST namespace `wpi/v1` | `pfc/v1` |
| Signed-diagnostic query params `wpi_diag`, `wpi_ts`, `wpi_sig`, `wpi_exclude`, `wpi_probe` | `pfc_*` equivalents |
| Capture cookie `wpi_capture_save` | `pfc_capture_save` |
| Response headers `X-WPI-Diagnostic`, `X-WPI-Probe-ID`, `X-WPI-Excluded-Plugin` | `X-PFC-*` |
| admin-post actions `wpi_scan`, `wpi_db_fix`, ... and nonces | `pfc_*` |
| Admin menu slug `admin.php?page=wpi`, title "Performance Inspector" | `admin.php?page=pfc`, title "Performance Console" |
| CSS classes `.wpi-*`, `data-wpi-*`, JS globals `window.wpiRum`, `window.wpiBackupAdmin` | `.pfc-*`, `data-pfc-*`, `window.pfcRum`, `window.pfcBackupAdmin` |
| MU bootstrap `000-wp-performance-inspector-bootstrap.php` (+ legacy no-prefix name) | `000-performance-console-bootstrap.php` |
| MU template `mu-plugin/wp-performance-inspector-bootstrap.php` | `mu-plugin/performance-console-bootstrap.php` |
| Build output `wp-performance-inspector-<v>.zip`, zip root `wp-performance-inspector/` | `performance-console-<v>.zip`, root `performance-console/` |
| Backup dirs `wpi-private-backups-<hash>`, files `wpi-db-*.sql` | `pfc-private-backups-<hash>`, `pfc-db-*.sql` (old dirs cleaned by uninstall) |
| GitHub repo `bfrye26/wp-performance-inspector` | `bfrye26/performance-console` |

Intentional exceptions (legacy names remain here only):

- `PFC_DB::migrate_legacy()` and its tests reference old `wpi_*` identifiers.
- `uninstall.php` also cleans legacy `wpi_*` leftovers defensively.
- This spec document.
- `dist/` artifacts and historical release-notes files.
- 2.x changelog entries in README/readme.txt get the new name plus a
  "formerly known as WP Performance Inspector" note near the top.

## Migration algorithm

`PFC_DB::migrate_legacy()` — idempotent, safe to re-run, called from
`PFC_DB::activate()` before `install()`, and from `maybe_upgrade()` when legacy
data is detected (`wpi_db_version` option or legacy tables present):

1. Tables, per suffix (`runs`, `queries`, `issues`, `metrics`, `changes`,
   `option_usage`, `backups`):
   - if `{prefix}wpi_<suffix>` exists and `{prefix}pfc_<suffix>` does not,
     `RENAME TABLE {prefix}wpi_<suffix> TO {prefix}pfc_<suffix>`.
   - if both exist, leave the legacy table untouched (never clobber).
2. Options/transients: single statement
   `UPDATE {options} SET option_name = REPLACE(option_name, 'wpi_', 'pfc_')
   WHERE option_name LIKE 'wpi\_%' OR option_name LIKE '\_transient\_wpi\_%'
   OR option_name LIKE '\_transient\_timeout\_wpi\_%'`, then clear the options
   cache (`alloptions` and each renamed option). Covers `wpi_runtime`,
   `wpi_secret`, `wpi_usage_started_at`, `wpi_db_version`, `wpi_last_scan`,
   RUM/notice transients, `wpi_bootstrap_checked`.
3. Cron: `wp_clear_scheduled_hook('wpi_daily_maintenance')`; schedule
   `pfc_daily_maintenance` when missing.
4. MU bootstrap: `PFC_Bootstrap::install()` writes the new file; unlink
   `000-wp-performance-inspector-bootstrap.php` and
   `wp-performance-inspector-bootstrap.php` when present.
5. No step removes data. Interrupted runs resume per-step on the next request.

Upgrade order in `activate()`: `migrate_legacy()` → `install()` → option
defaults → cron → bootstrap. `maybe_upgrade()` runs the migration first when
legacy state is detected, then the normal versioned upgrade path.

Rollout mechanics: renaming the plugin folder deactivates the plugin in
WordPress; the data migration runs when the renamed plugin is activated.
Multisite: migration runs per site as each site loads the active plugin.
Declined/no-op installs: migration only runs when the legacy `wpi_db_version`
option exists or at least one legacy table is present; otherwise it is skipped
with no queries beyond the detection checks.

## GitHub / repo changes

1. `gh repo rename performance-console` (GitHub keeps redirects from the old name).
2. Update repo description to the Performance Console wording; refresh topics
   (`wordpress`, `wordpress-plugin`, `performance`, `diagnostics`, `profiling`, `database`).
3. `git remote set-url origin https://github.com/bfrye26/performance-console.git`.
4. Single commit on `main`:
   `Rename plugin to Performance Console (3.0.0) with legacy data migration`
   (spec commit precedes it), then push.

## Versioning

- Plugin header, `PFC_VERSION`, `PFC_DB::DB_VERSION`, readme stable tag: `3.0.0`.
- MU bootstrap `VERSION` bumped to force regeneration of the installed file.

## Verification

- `php -l` on every PHP file (same list CI checks).
- `php tests/run.php` — updated suite plus a new migration test asserting the
  legacy table/option mapping and idempotency guards.
- `npm ci --ignore-scripts`, `npm run build:rum`, `git diff --exit-code -- assets/vendor`, `npm test`.
- Grep sweep: no `WPI`/`wpi_`/`wp-performance-inspector` outside the intentional
  exceptions listed above.
- Optional end-to-end on CGM-New-2: replace the plugin folder, activate, confirm
  the 7 tables were renamed with rows intact, options/cron/MU migrated, and the
  dashboard, REST and WP-CLI still work.

## Risks

- Cached pages may still beacon the old `wpi/v1/rum` REST route until the page
  cache is purged (matches the documented cache-purge note on upgrades).
- A legacy MU bootstrap file left behind is inert: it early-returns when the old
  plugin basename is not active, and migration deletes it.
- If a same-named `pfc_*` table appears before migration, the legacy table is
  preserved untouched; manual review is required (never auto-merged).
