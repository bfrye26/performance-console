# Performance Console Rename Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename WP Performance Inspector to Performance Console (v3.0.0) across code, docs, build, tests, CI and GitHub, with an automatic in-place data migration for existing installs.

**Architecture:** One ordered mechanical sweep renames every tracked reference and file; then a TDD task adds `PFC_DB::migrate_legacy()` (table/option/transient/cron/MU migration), legacy cleanup in `uninstall.php`, and a bumped bootstrap version; then version + docs; then verification; then the GitHub repo rename/push; then a guarded on-site deployment checkpoint.

**Tech Stack:** PHP 7.4+ WordPress plugin (no runtime Node), custom PHP test harness (`php tests/run.php`), Node only for the RUM build/test, WP-CLI optional (not on PATH on this machine).

## Global Constraints

- Behavior must be identical after the rename; no feature or schema changes.
- PHP 7.4+ compatible, WordPress 6.4+, GPL-2.0-or-later.
- Old names (`WP Performance Inspector`, `wp-performance-inspector`, `WPI`, `wpi_`) may remain ONLY in: `includes/class-pfc-db.php` legacy constants/methods, `includes/class-pfc-bootstrap.php` legacy filenames, `uninstall.php` legacy cleanup, `tests/run.php` legacy assertions, the "formerly known as" notes in `README.md`/`readme.txt`, `dist/**`, and `docs/superpowers/**`.
- The WP-CLI command stays `wp performance` and the menu label stays `Performance`; only the menu slug changes (`wpi` → `pfc`).
- Repo workdir (until Task 6): `C:\laragon\www\CGM-New-2\wp-content\plugins\wp-performance-inspector`.
- Every task ends green: `php -l` on touched PHP + `php tests/run.php` reporting `0 failures`.
- The baseline suite is 28 tests, 0 failures, on PHP 8.5.7 (verified).

---

### Task 1: Mechanical rename sweep (files + contents)

**Files:**
- Rename: `wp-performance-inspector.php` → `performance-console.php`
- Rename: `mu-plugin/wp-performance-inspector-bootstrap.php` → `mu-plugin/performance-console-bootstrap.php`
- Rename: all 16 `includes/class-wpi-*.php` → `includes/class-pfc-*.php`
- Modify: every tracked text file except `dist/**` and `docs/superpowers/**`

**Interfaces:**
- Produces: all classes `PFC_*` in `includes/class-pfc-*.php`; constants `PFC_VERSION`, `PFC_FILE`, `PFC_DIR`, `PFC_URL`, `PFC_BASENAME`; text domain `performance-console`; main plugin file `performance-console.php`.

- [ ] **Step 1: Confirm clean baseline**

```powershell
git status --short          # expect empty
php tests/run.php           # expect: 28 tests, 0 failures
```

- [ ] **Step 2: Rename files with git mv**

```powershell
git mv wp-performance-inspector.php performance-console.php
git mv mu-plugin/wp-performance-inspector-bootstrap.php mu-plugin/performance-console-bootstrap.php
foreach ($file in Get-ChildItem includes/class-wpi-*.php) {
    $newName = 'class-pfc-' . $file.Name.Substring('class-wpi-'.Length)
    git mv $file.FullName (Join-Path $file.DirectoryName $newName)
}
git status --short          # expect 18 renames staged
```

- [ ] **Step 3: Run the ordered content sweep**

```powershell
$enc = New-Object System.Text.UTF8Encoding($false)
$replacements = @(
    @('wp-performance-inspector', 'performance-console'),
    @('WP Performance Inspector', 'Performance Console'),
    @('X-WPI', 'X-PFC'),
    @('WPI Bootstrap', 'PFC Bootstrap'),
    @('WPI_', 'PFC_'),
    @('wpi_', 'pfc_'),
    @('wpi/v1', 'pfc/v1'),
    @('wpiRum', 'pfcRum'),
    @('wpiBackupAdmin', 'pfcBackupAdmin'),
    @('wpi-', 'pfc-'),
    @('page=wpi', 'page=pfc'),
    @("'wpi'", "'pfc'"),
    @('"wpi"', '"pfc"')
)
foreach ($f in (git ls-files)) {
    if ($f -match '^(dist/|docs/superpowers/)') { continue }
    $full = Join-Path (Get-Location) $f
    if (-not (Test-Path -LiteralPath $full -PathType Leaf)) { continue }
    $text = [System.IO.File]::ReadAllText($full)
    $new = $text
    foreach ($pair in $replacements) { $new = $new.Replace($pair[0], $pair[1]) }
    $new = [regex]::Replace($new, '\bWPI\b', 'Performance Console')
    $new = [regex]::Replace($new, '\bwpi\b', 'pfc')
    if ($new -ne $text) { [System.IO.File]::WriteAllText($full, $new, $enc); Write-Output "updated: $f" }
}
```

- [ ] **Step 4: Verify no old references remain (pre-migration scope)**

```powershell
rg -n -i "wpi" --glob '!dist/**' --glob '!docs/superpowers/**' .
# expect: no output (zero matches)
rg -n "WP Performance Inspector|Performance Inspector" --glob '!dist/**' --glob '!docs/superpowers/**' .
# expect: no output
```

- [ ] **Step 5: Lint and test**

```powershell
Get-ChildItem includes,mu-plugin,tests -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
php -l performance-console.php; php -l uninstall.php
php tests/run.php           # expect: 28 tests, 0 failures
```

- [ ] **Step 6: Commit**

```powershell
git add -A
git commit -m "Rename plugin to Performance Console across code, docs, build and tests"
```

---

### Task 2: Legacy data migration (TDD)

**Files:**
- Modify: `includes/class-pfc-db.php`
- Modify: `includes/class-pfc-bootstrap.php`
- Modify: `includes/class-pfc-admin.php`
- Modify: `uninstall.php`
- Test: `tests/run.php`

**Interfaces:**
- Consumes: `PFC_Utils::table()` (unchanged), `PFC_Bootstrap::install()` (unchanged signature).
- Produces:
  - `PFC_DB::legacy_table_suffixes(): array` — `['runs','queries','issues','metrics','changes','option_usage','backups']`
  - `PFC_DB::legacy_option_patterns(): array` — `['wpi\_%','\_transient\_wpi\_%','\_transient\_timeout\_wpi\_%']`
  - `PFC_DB::legacy_cron_hook(): string` — `'wpi_daily_maintenance'`
  - `PFC_DB::legacy_state_exists(): bool`
  - `PFC_DB::migrate_legacy(): void` — idempotent

- [ ] **Step 1: Write the failing tests**

Add `require_once dirname( __DIR__ ) . '/includes/class-pfc-db.php';` after the existing `require_once dirname( __DIR__ ) . '/includes/class-pfc-utils.php';` line in `tests/run.php`.

Insert these test cases immediately before the `$failures = 0;` line in `tests/run.php`:

```php
test_case( 'legacy rename map covers every pre-rename data store', static function (): void {
    assert_same( array( 'runs', 'queries', 'issues', 'metrics', 'changes', 'option_usage', 'backups' ), PFC_DB::legacy_table_suffixes() );
    assert_same( array( 'wpi\_%', '\_transient\_wpi\_%', '\_transient\_timeout\_wpi\_%' ), PFC_DB::legacy_option_patterns() );
    assert_same( 'wpi_daily_maintenance', PFC_DB::legacy_cron_hook() );
} );

test_case( 'MU bootstrap install removes legacy wpi filenames', static function (): void {
    $source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-pfc-bootstrap.php' );
    assert_same( true, false !== strpos( $source, '000-wp-performance-inspector-bootstrap.php' ) );
    assert_same( true, false !== strpos( $source, 'wp-performance-inspector-bootstrap.php' ) );
    assert_same( true, false !== strpos( $source, 'legacy_paths' ) );
} );

test_case( 'uninstall removes both the pfc and legacy wpi data stores', static function (): void {
    $source = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
    foreach ( array( 'runs', 'queries', 'issues', 'metrics', 'changes', 'option_usage', 'backups' ) as $suffix ) {
        assert_same( true, false !== strpos( $source, "'" . $suffix . "'" ) );
    }
    assert_same( true, false !== strpos( $source, '{$wpdb->prefix}pfc_{$pfc_suffix}' ) );
    assert_same( true, false !== strpos( $source, '{$wpdb->prefix}wpi_{$pfc_suffix}' ) );
    assert_same( true, false !== strpos( $source, "'wpi_'" ) );
    assert_same( true, false !== strpos( $source, "wp_clear_scheduled_hook( 'wpi_daily_maintenance' )" ) );
    assert_same( true, false !== strpos( $source, '000-wp-performance-inspector-bootstrap.php' ) );
    assert_same( true, false !== strpos( $source, 'wpi-private-backups-' ) );
    assert_same( true, false !== strpos( $source, 'wpi-db-*.sql' ) );
} );

test_case( 'legacy menu slug redirects to the pfc page', static function (): void {
    $source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-pfc-admin.php' );
    assert_same( true, false !== strpos( $source, "'wpi' === \$_GET['page']" ) );
    assert_same( true, false !== strpos( $source, 'add_query_arg' ) );
} );
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: 4 FAIL lines (`Class "PFC_DB" not found`, bootstrap `legacy_paths` missing, uninstall legacy strings missing, admin redirect missing); existing 28 still pass.

- [ ] **Step 3: Implement `PFC_DB` migration**

In `includes/class-pfc-db.php`, add after `const DB_VERSION = '2.2.1';`:

```php
    const LEGACY_TABLE_SUFFIXES = array( 'runs', 'queries', 'issues', 'metrics', 'changes', 'option_usage', 'backups' );
    const LEGACY_OPTION_PATTERNS = array( 'wpi\_%', '\_transient\_wpi\_%', '\_transient\_timeout\_wpi\_%' );
    const LEGACY_CRON_HOOK = 'wpi_daily_maintenance';

    public static function legacy_table_suffixes() { return self::LEGACY_TABLE_SUFFIXES; }
    public static function legacy_option_patterns() { return self::LEGACY_OPTION_PATTERNS; }
    public static function legacy_cron_hook() { return self::LEGACY_CRON_HOOK; }
```

In `activate()`, make the first statement `self::migrate_legacy();` (before `self::install();`).

In `maybe_upgrade()`, insert as the first statements (before the existing `$previous = ...` line):

```php
        if ( false === get_option( 'pfc_db_version', false ) ) {
            self::migrate_legacy();
        }
```

Add these methods to `PFC_DB` (e.g. directly after `maybe_upgrade()`):

```php
    public static function legacy_state_exists() {
        global $wpdb;
        if ( false !== get_option( 'wpi_db_version', false ) ) { return true; }
        $legacy = $wpdb->prefix . 'wpi_runs';
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $legacy ) ) ) === $legacy;
    }

    public static function migrate_legacy() {
        global $wpdb;
        if ( ! self::legacy_state_exists() ) { return; }

        foreach ( self::LEGACY_TABLE_SUFFIXES as $suffix ) {
            $old = $wpdb->prefix . 'wpi_' . $suffix;
            $new = $wpdb->prefix . 'pfc_' . $suffix;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old ) ) ) !== $old ) { continue; }
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $new ) ) ) === $new ) { continue; }
            $wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        $patterns = self::LEGACY_OPTION_PATTERNS;
        $placeholders = implode( ',', array_fill( 0, count( $patterns ), '%s' ) );
        $names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE {$placeholders}", $patterns ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $names ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_name = REPLACE(option_name, 'wpi_', 'pfc_') WHERE option_name LIKE {$placeholders}", $patterns ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            wp_cache_delete( 'alloptions', 'options' );
            foreach ( $names as $name ) { wp_cache_delete( str_replace( 'wpi_', 'pfc_', (string) $name ), 'options' ); }
        }

        $timestamp = wp_next_scheduled( self::LEGACY_CRON_HOOK );
        if ( $timestamp ) { wp_unschedule_event( $timestamp, self::LEGACY_CRON_HOOK ); }
        if ( ! wp_next_scheduled( 'pfc_daily_maintenance' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'pfc_daily_maintenance' );
        }

        PFC_Bootstrap::install();
    }
```

- [ ] **Step 4: Implement bootstrap legacy cleanup**

In `includes/class-pfc-bootstrap.php`, replace the `legacy_path()` method:

```php
    private static function legacy_path() { return trailingslashit( WPMU_PLUGIN_DIR ) . 'performance-console-bootstrap.php'; }
```

with:

```php
    private static function legacy_paths() {
        return array(
            trailingslashit( WPMU_PLUGIN_DIR ) . '000-wp-performance-inspector-bootstrap.php',
            trailingslashit( WPMU_PLUGIN_DIR ) . 'wp-performance-inspector-bootstrap.php',
        );
    }
```

and in `install()` replace:

```php
        $legacy = self::legacy_path();
        if ( $legacy !== $target && file_exists( $legacy ) ) { @unlink( $legacy ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
```

with:

```php
        foreach ( self::legacy_paths() as $legacy ) {
            if ( $legacy !== $target && file_exists( $legacy ) ) { @unlink( $legacy ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        }
```

- [ ] **Step 5: Add legacy menu-slug redirect**

In `includes/class-pfc-admin.php`, at the top of `menu()` (before the `add_menu_page(...)` call), insert:

```php
        if ( isset( $_GET['page'] ) && 'wpi' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $args = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $args['page'] = 'pfc';
            wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
            exit;
        }
```

This keeps old `admin.php?page=wpi...` bookmarks and deep links working (the `view` argument and hash targets are preserved; the hash itself is a browser-side fragment).

- [ ] **Step 6: Implement uninstall legacy cleanup**

Replace the full body of `uninstall.php` after `global $wpdb;` with:

```php
$pfc_backups_table = $wpdb->prefix . 'pfc_backups';
$pfc_backup_paths = array();
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $pfc_backups_table ) ) ) === $pfc_backups_table ) {
    $pfc_backup_paths = (array) $wpdb->get_col( "SELECT file_path FROM `{$pfc_backups_table}` WHERE file_path <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
$wpi_backups_table = $wpdb->prefix . 'wpi_backups';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpi_backups_table ) ) ) === $wpi_backups_table ) {
    $pfc_backup_paths = array_merge( $pfc_backup_paths, (array) $wpdb->get_col( "SELECT file_path FROM `{$wpi_backups_table}` WHERE file_path <> ''" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

foreach ( array( 'runs', 'queries', 'issues', 'metrics', 'changes', 'option_usage', 'backups' ) as $pfc_suffix ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}pfc_{$pfc_suffix}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wpi_{$pfc_suffix}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$pfc_options_like = $wpdb->esc_like( 'pfc_' ) . '%';
$pfc_transient_like = $wpdb->esc_like( '_transient_pfc_' ) . '%';
$pfc_transient_timeout_like = $wpdb->esc_like( '_transient_timeout_pfc_' ) . '%';
$wpi_options_like = $wpdb->esc_like( 'wpi_' ) . '%';
$wpi_transient_like = $wpdb->esc_like( '_transient_wpi_' ) . '%';
$wpi_transient_timeout_like = $wpdb->esc_like( '_transient_timeout_wpi_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $pfc_options_like, $pfc_transient_like, $pfc_transient_timeout_like, $wpi_options_like, $wpi_transient_like, $wpi_transient_timeout_like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

wp_clear_scheduled_hook( 'pfc_daily_maintenance' );
wp_clear_scheduled_hook( 'wpi_daily_maintenance' );

if ( $pfc_backup_paths ) {
    foreach ( $pfc_backup_paths as $pfc_path ) {
        if ( is_string( $pfc_path ) && is_file( $pfc_path ) ) { @unlink( $pfc_path ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
    }
}

if ( defined( 'WP_CONTENT_DIR' ) ) {
    $pfc_hash = substr( hash( 'sha256', ( defined( 'AUTH_KEY' ) ? AUTH_KEY : ABSPATH ) . DB_NAME ), 0, 16 );
    $pfc_dirs = array(
        trailingslashit( WP_CONTENT_DIR ) . 'pfc-private-backups-' . $pfc_hash,
        trailingslashit( WP_CONTENT_DIR ) . 'wpi-private-backups-' . $pfc_hash,
        trailingslashit( dirname( untrailingslashit( ABSPATH ) ) ) . '.pfc-private-backups-' . $pfc_hash,
        trailingslashit( dirname( untrailingslashit( ABSPATH ) ) ) . '.wpi-private-backups-' . $pfc_hash,
    );
    $pfc_temp = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
    if ( $pfc_temp ) {
        $pfc_dirs[] = trailingslashit( $pfc_temp ) . 'pfc-private-backups-' . $pfc_hash;
        $pfc_dirs[] = trailingslashit( $pfc_temp ) . 'wpi-private-backups-' . $pfc_hash;
    }
    foreach ( array_unique( $pfc_dirs ) as $pfc_dir ) {
        if ( ! is_dir( $pfc_dir ) ) { continue; }
        foreach ( (array) glob( trailingslashit( $pfc_dir ) . 'pfc-db-*.sql' ) as $pfc_file ) { @unlink( $pfc_file ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        foreach ( (array) glob( trailingslashit( $pfc_dir ) . 'wpi-db-*.sql' ) as $pfc_file ) { @unlink( $pfc_file ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        foreach ( array( '.htaccess', 'web.config', 'index.php' ) as $pfc_file ) { @unlink( trailingslashit( $pfc_dir ) . $pfc_file ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        @rmdir( $pfc_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    }
}

if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
    foreach ( array( '000-performance-console-bootstrap.php', 'performance-console-bootstrap.php', '000-wp-performance-inspector-bootstrap.php', 'wp-performance-inspector-bootstrap.php' ) as $pfc_mu ) {
        $pfc_mu_path = trailingslashit( WPMU_PLUGIN_DIR ) . $pfc_mu;
        if ( is_file( $pfc_mu_path ) ) { @unlink( $pfc_mu_path ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
    }
}
```

Keep the file docblock updated to mention legacy `wpi_*` cleanup.

- [ ] **Step 7: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: 32 tests, 0 failures.

- [ ] **Step 8: Lint and commit**

```powershell
php -l includes/class-pfc-db.php; php -l includes/class-pfc-bootstrap.php; php -l includes/class-pfc-admin.php; php -l uninstall.php; php -l tests/run.php
git add includes/class-pfc-db.php includes/class-pfc-bootstrap.php includes/class-pfc-admin.php uninstall.php tests/run.php
git commit -m "Add wpi-to-pfc legacy data migration on upgrade"
```

---

### Task 3: Version 3.0.0 + docs

**Files:**
- Modify: `performance-console.php`
- Modify: `includes/class-pfc-db.php`
- Modify: `includes/class-pfc-bootstrap.php`
- Modify: `mu-plugin/performance-console-bootstrap.php`
- Modify: `readme.txt`
- Modify: `README.md`

**Interfaces:**
- Consumes: `PFC_Bootstrap::status()` regex `/PFC Bootstrap Version:\s*([0-9.]+)/` must match the new MU header.
- Produces: version `3.0.0` consistently; MU bootstrap `2.0.0` forcing regeneration.

- [ ] **Step 1: Bump versions**

- `performance-console.php`: `* Version:     2.2.1` → `* Version:     3.0.0`; `define( 'PFC_VERSION', '2.2.1' );` → `'3.0.0'`.
- `includes/class-pfc-db.php`: `const DB_VERSION = '2.2.1';` → `'3.0.0'`.
- `includes/class-pfc-bootstrap.php`: `const VERSION = '1.3.2';` → `'2.0.0'`.
- `mu-plugin/performance-console-bootstrap.php`: header `PFC Bootstrap Version: 1.3.2` → `2.0.0`.
- `readme.txt`: `Stable tag: 2.2.1` → `3.0.0`.

- [ ] **Step 2: Add changelog entries and formerly-known-as notes**

`readme.txt` — under `== Changelog ==` prepend:

```
= 3.0.0 =
* Renamed WP Performance Inspector to Performance Console.
* Existing installs migrate automatically on activation: the seven pfc_* tables, options, transients, maintenance cron event and MU bootstrap are renamed from wpi_* without data loss.
```

`readme.txt` — first line of the `== Description ==` section becomes:

```
Performance Console (formerly WP Performance Inspector) identifies database, plugin, query, job, cache, PHP/server and frontend bottlenecks and gives evidence-backed recommendations.
```

`README.md` — change the title to `# Performance Console 3.0.0` and insert after it:

```markdown
## 3.0.0 rename and migration

Performance Console is the new name of WP Performance Inspector. WordPress.org has never hosted the plugin, so the rename also changes the text domain and code prefixes from `wpi_`/`WPI_` to `pfc_`/`PFC_`. Existing installs upgrade in place: on activation the plugin renames its seven tables, options, transients, maintenance cron event and MU bootstrap from `wpi_*` to `pfc_*` without deleting diagnostic history or backups.
```

- [ ] **Step 3: Verify and commit**

```powershell
php -l performance-console.php; php -l includes/class-pfc-db.php; php -l includes/class-pfc-bootstrap.php; php -l mu-plugin/performance-console-bootstrap.php
php tests/run.php           # expect: 32 tests, 0 failures
git add -A
git commit -m "Release Performance Console 3.0.0"
```

---

### Task 4: Final verification sweep

**Files:**
- No planned modifications; fix anything the checks surface.

- [ ] **Step 1: Grep for leftovers**

```powershell
rg -n -i "wpi" --glob '!dist/**' --glob '!docs/superpowers/**' .
```

Expected matches ONLY in: `includes/class-pfc-db.php` (legacy constants/SQL), `includes/class-pfc-bootstrap.php` (legacy filenames), `uninstall.php` (legacy cleanup), `tests/run.php` (legacy assertions), `README.md`/`readme.txt` (formerly notes). Any other file is a bug — fix it and amend nothing; make a new commit.

```powershell
rg -n "wp-performance-inspector|WP Performance Inspector" --glob '!dist/**' --glob '!docs/superpowers/**' .
```

Expected: same allowed files only.

- [ ] **Step 2: Full local checks**

```powershell
Get-ChildItem includes,mu-plugin,tests -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
php -l performance-console.php; php -l uninstall.php
php tests/run.php           # expect: 32 tests, 0 failures
npm ci --ignore-scripts
npm run build:rum
git diff --exit-code -- assets/vendor   # expect: clean (pinned artifact unchanged)
npm test                    # expect: all RUM transport tests pass
git status --short          # expect: clean
```

- [ ] **Step 3: Commit any fixes** (only if Step 1/2 surfaced changes)

```powershell
git add -A
git commit -m "Fix rename sweep leftovers"
```

---

### Task 5: Rename the GitHub repository and push

**Files:**
- Modify: `.git/config` remote (via `git remote set-url`)

- [ ] **Step 1: Rename the repo**

```powershell
gh repo rename performance-console --confirm
```

Expected: `✓ Renamed repository bfrye26/wp-performance-inspector to bfrye26/performance-console`. GitHub keeps redirects from the old URL.

- [ ] **Step 2: Update description and topics**

```powershell
gh repo edit --description "Production-oriented WordPress performance diagnostics, incident workflows, profiling, RUM, and guarded database repair." --add-topic wordpress,wordpress-plugin,performance,diagnostics,profiling,database
```

- [ ] **Step 3: Point origin at the new name and push**

```powershell
git remote set-url origin https://github.com/bfrye26/performance-console.git
git remote -v
git push -u origin main
git status --short          # expect: clean, up to date with origin/main
```

- [ ] **Step 4: Verify on GitHub**

```powershell
gh repo view --json name,description,repositoryTopics,url
gh run list --limit 5       # CI starts for the pushed commits; all should pass when finished
```

---

### Task 6: Deploy and verify on CGM-New-2 (manual checkpoint)

**Files:**
- Rename: `C:\laragon\www\CGM-New-2\wp-content\plugins\wp-performance-inspector` → `...\plugins\performance-console`

**Interfaces:**
- Consumes: everything above. After this task all future commands in this repo use the new path.

- [ ] **Step 1: Back up the database first**

Use the site's existing DB backup mechanism (or phpMyAdmin export). The migration is forward-only; a DB dump is the rollback path.

- [ ] **Step 2: Rename the plugin folder**

```powershell
Rename-Item -LiteralPath "C:\laragon\www\CGM-New-2\wp-content\plugins\wp-performance-inspector" -NewName "performance-console"
```

Close any editor/terminal holding files in the old folder if Windows refuses the rename.

- [ ] **Step 3: Activate in wp-admin (human action)**

WP will show the plugin inactive because the old basename no longer exists. Open Plugins → activate **Performance Console**. Activation runs `PFC_DB::migrate_legacy()` before `install()`.

- [ ] **Step 4: Verify the migration (phpMyAdmin)**

```sql
SHOW TABLES LIKE 'wp_pfc_%';     -- expect 7 tables
SHOW TABLES LIKE 'wp_wpi_%';     -- expect 0 tables
SELECT option_name FROM wp_options WHERE option_name IN ('pfc_db_version','pfc_secret','pfc_runtime','pfc_usage_started_at');
SELECT COUNT(*) FROM wp_pfc_issues;   -- diagnostic history intact (non-zero if the site had incidents)
```

Also confirm `wp-content/mu-plugins/000-performance-console-bootstrap.php` exists and the old `000-wp-performance-inspector-bootstrap.php` is gone.

- [ ] **Step 5: Smoke test**

- Open `/wp-admin/admin.php?page=pfc` — dashboard renders.
- Visit `/wp-admin/admin.php?page=wpi&view=database` — redirects to the new slug.
- Run a Production-Safe Scan and confirm it completes and records a result.
- Confirm no PHP notices/errors in `wp-content/debug.log` (if enabled).

- [ ] **Step 6: Confirm repo state from the new path**

```powershell
git -C C:\laragon\www\CGM-New-2\wp-content\plugins\performance-console status --short
git -C C:\laragon\www\CGM-New-2\wp-content\plugins\performance-console remote -v
```

---

## Notes for the implementer

- Never re-run the Task 1 sweep after Task 2: the migration code intentionally contains `wpi_` literals.
- The sweep excludes `dist/**` and `docs/superpowers/**`; both intentionally keep historical/old-name content.
- `wp performance` CLI commands are unchanged; if WP-CLI is unavailable on this machine, CLI verification happens on a host that has it.
- If `gh repo rename` fails, fall back to renaming in GitHub Settings → General → Repository name; the rest of the steps are unchanged.
