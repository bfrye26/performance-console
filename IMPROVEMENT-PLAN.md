# Performance improvement implementation plan

## Implemented: measurement and verification foundation (2.2.0)

Single sampling decision; inactive MU guard; no blanket scan resolution; inconclusive rechecks; shared save matcher; write/response and comparable-save checks; maintained versioned RUM; explicit reporting window; content-presence assertions for A/B; autoload sample coverage; contextual queue/cache signals; backup rehashing; evidence and saturation labels; regression tests and PHP CI matrix.

## Next: stronger save diagnosis

- Record browser click-to-completion time separately from server time.
- Add optional callback timing with inclusive/exclusive attribution, nested-hook tests, measured overhead and an explicit diagnostic-only switch.
- Recognize registered custom REST prefixes/namespaces, custom taxonomies versus post types, Heartbeat autosaves and supported builder saves. Fail closed for unsupported save flows.
- Add capture ownership/atomic consumption for concurrent requests, save-outcome adapters and before/after cohorts of repeated comparable successful saves.
- Offer a focused next action for measured SQL, remote API and callback causes; retain unattributed work rather than guessing the owning plugin.

## Next: guided improvements and verification

- A per-check coverage ledger: completed/pass/fail/skipped/unsupported/error, with explicit verification criteria before automatic incident resolution.
- Route-scoped, session-only experiments with content/template/form/cart assertions and dependency checks. No global plugin deactivation as an automatic optimization.
- Repair operation records with locks, preconditions, postconditions, rollback limits and before/after evidence.
- Match backup manifests to every affected table. Use a consistent external snapshot or controlled backup workflow for high-risk repairs; separate export, integrity and restore-test status.
- Task-oriented entry points for page loading, saving, admin, search, checkout and background work.

## Next: compatibility and deeper measurement

- Capability detection and honest unsupported states for multisite/network lifecycle, read-only filesystems, custom content directories and external caches/CDNs.
- Independent-request object-cache persistence probes, cache/context cohorts, job due-age/throughput telemetry and safe route grouping for REST/AJAX.
- Browser-derived asset/network/long-task evidence; keep HTML scan heuristics labelled as hints.
- WordPress integration matrix: minimum/latest core, classic/block editors, WooCommerce, ACF/builders, multisite, MySQL/MariaDB and common cache layers.
- RUM full-lifecycle deduplication if adopted, with generation-separated storage and percentile precision matched to product decisions.

## Verification completed locally

- PHP regression suite and JavaScript transport tests pass; all plugin PHP files lint.
- Existing local WordPress database upgraded to metric schema 2.2.0; MU bootstrap 1.3.1 installed.
- Real block-editor draft save captured with matching write/response outcome and distinct component costs. No post was published.
- Safe incident recheck returned observing rather than a false resolution or an unknown-action page.
- Public-route profiling produced five of five verified server-side measurements.
- A real browser fixture stored version-2 TTFB, FCP and LCP metrics through the REST endpoint. INP/CLS were not verified end to end in this browser; unsupported metrics remain absent rather than fabricated zeros.
- Save capture and result layout inspected at 390px and 1280px widths.

Production behavior, all WordPress/editor combinations and the new GitHub CI matrix have not yet been exercised. Measurements from a local fixture do not establish production saving performance.
