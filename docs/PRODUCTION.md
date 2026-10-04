# Production deployment

Performance Console is intentionally split between low-cost observation and explicit deep diagnostics. Treat database remediation as a maintenance operation, even when the UI labels a fix as bounded or safe.

## Recommended rollout

1. Install on staging first and verify the site behaves normally.
2. Activate and run `wp performance self-test`.
3. Run `wp performance scan` and review critical/high findings.
4. Confirm the MU bootstrap is installed and current.
5. Run a Deep Scan to collect integrity/orphan evidence. Large tables are skipped unless explicitly overridden.
6. Profile representative uncached routes with `wp performance profile URL --runs=3`.
7. For slow editor writes, arm **Profiling → Slow Save Profiler**, perform one representative save in the same browser, then review measured component and hook evidence. The capture adds diagnostic overhead and should be used for cause ranking rather than absolute save latency.
8. Use `wp performance plugin-impact URL plugin/file.php --runs=3` as controlled correlation evidence, not as the sole reason to remove a plugin.
9. Review Database Repair Centre recommendations before changing data/schema.
10. Verify Redis/object cache, page cache and CDN behavior independently because an edge cache can bypass WordPress entirely.
11. Compare the site before/after each meaningful change instead of applying a batch of unrelated optimizations.

## Database repair policy

### Suitable for bounded wp-admin execution

- expired transient deletion
- small reviewed orphan-cleanup batches with a complete rollback snapshot
- reviewed non-core option autoload changes
- core column/index additions only when the affected table is below the conservative web threshold and the definition exactly matches WordPress core
- engine-aware maintenance only when supported by the detected engine and below the web threshold. InnoDB uses CHECK/ANALYZE/OPTIMIZE paths, MyISAM/Aria can expose REPAIR, ARCHIVE repair is reviewed, and CSV repair remains manual because of data-loss risk

### Maintenance-window / CLI only

Use `wp performance database-repairs` to list recommendations. Large schema changes require deliberate CLI use, for example:

```bash
wp performance database-fix repair_core_schema --force-large
```

`--force-large` removes the plugin's web-size refusal. It does **not** make an ALTER, CHECK, OPTIMIZE or repair operation non-blocking. Confirm backups, available disk space, replication/cluster implications and the database engine's online-DDL behavior before using it.

### Manual/DBA operations

The plugin intentionally does not perform these operations without a guarded maintenance workflow:

- create an empty replacement for a missing core table without a verified backup and explicit data-loss acknowledgement; this restores schema only and never restores missing records
- drop plugin/custom indexes
- convert table engines or collations
- perform InnoDB corruption recovery
- kill database sessions
- modify MySQL server configuration
- execute arbitrary index recommendations inferred from a slow query

These operations can cause data loss, long metadata locks or incompatibility with plugin migrations. The scanner reports the evidence and recommended next step instead.


## Storage-engine policy

Run `wp performance database-engines` to see what the live MySQL/MariaDB server reports and which operations Performance Console will expose. Performance Console does not assume every server has the same engines.

- InnoDB: never use `REPAIR TABLE`; use integrity checks, InnoDB transaction/lock/status evidence, backups, rebuild/dump-restore or controlled InnoDB recovery where needed.
- MyISAM/Aria: CHECK/REPAIR/ANALYZE/OPTIMIZE can be offered, but repair/rebuild operations remain maintenance actions.
- ARCHIVE: reviewed CHECK/REPAIR/OPTIMIZE when supported.
- CSV: CHECK is useful, but automatic repair is blocked because repair can discard rows.
- MEMORY/HEAP: non-durable; inspect growth/limits but do not pretend a table repair provides durability.
- NDB and plugin engines: leave cluster/engine recovery to their native tooling unless Performance Console has a verified operation.

## Large-site behavior

- table-size and row estimates are taken from metadata first
- normal web scans avoid exact full-table counts
- deep integrity checks skip tables above the row threshold unless force-large is explicitly requested
- orphan cleanup is batched and revalidated immediately before deletion
- rollback snapshots are bounded to prevent a cleanup action from storing enormous blobs
- Action Scheduler checks use bounded counts/samples for large queues
- query traces and backtraces are capped
- passive request metrics use route classes instead of exact article URLs
- raw profiles have shorter retention than aggregates
- passive query sampling can be disabled entirely by setting the server sample rate to 0%

## Backup expectations

The plugin's rollback history is not a database backup. Before schema repair, table repair/optimize or large cleanup, have a tested database backup or snapshot appropriate to your hosting/database architecture.

## InnoDB repair policy

For InnoDB, Performance Console never uses `REPAIR TABLE`. Use the recovery preflight first. Mutating InnoDB DDL requires an explicit backup/snapshot acknowledgement and is blocked when active lock waits or long-running transactions are visible. Performance Console requests `ALGORITHM=INPLACE, LOCK=NONE` for supported rebuilds and does not retry with `ALGORITHM=COPY`. Large tables require WP-CLI `--force-large` and a maintenance window.

Secondary-index rebuilds are limited to ordinary visible BTREE indexes with reproducible metadata. FULLTEXT, SPATIAL, functional/expression, invisible and MariaDB ignored indexes remain manual. Table rebuilds are advanced maintenance, not a generic corruption repair. Serious clustered/data corruption should be recovered from a verified backup or through controlled dump/reload or engine recovery procedures.
