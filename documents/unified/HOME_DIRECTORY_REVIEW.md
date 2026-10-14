# Home-directory cleanup and release layout

Assessment: 2026-10-13, America/Chicago. The operator supplied a top-level `ls -la /home/sitesee` listing. This is a preparation checkpoint, not evidence of an archive, deletion or migration. Stripe remains TEST.

## Systemd metadata scan-boundary correction — 2026-10-14

The latest checksum-verified host attempt emitted 10/20-second progress and stopped before archive at `/run/systemd/units/invocation:sys-kernel-config.mount`, whose bare value is `673deca302fc4236af50a0d82113fad4`. Systemd uses this as an invocation ID, not an executable path. Upstream v239/v252/current writer and reader contracts were reviewed to correct the metadata class rather than one instance.

Only protected canonical systemd metadata directories qualify: `/run/systemd/units` with root ownership and `/run/user/<numeric uid>/systemd/units` with matching ownership and invocation metadata only. Root exports cover invocation IDs, log level, log-rate-limit interval/burst and structured regular log-extra-fields. Symlink values must match their exact documented types/ranges, with no absolute targets. A real target is inspected normally. Regular field files must be non-executable, unwritable by group/others and structurally valid, including journal field-name grammar. Path/owner/type/value consistency is checked; disappeared metadata is rechecked and recreated scripts return to normal scanning. Only metadata descriptors/counts/hashes are recorded, without logging field values. Ordinary units/drop-ins and unexpected files/aliases remain inspected.

All unrecognized missing aliases reached in the otherwise completed traversal are collected into one pre-archive failure report (count and at most 50 paths, with truncation flagged). No eligibility/PASS is returned on incomplete checks. Read errors, the 120-second whole-scan deadline and all archive/restore safeguards remain unchanged. The helper does not change or repair systemd files.

All 74 local checks and the independent review pass, including twelve new metadata/schema/role/owner/race/aggregation cases and actual seventeen-file archive/restore. Separate independent default-namespace/schema/path-replacement probes pass, and AST comparison verifies unchanged preservation primitives. Helper SHA256 `0746d82fae1493e0495d0ffa4d2f0ccdef58c799a1d4a9bb1197bbace13fcd4d`. Verified publication and the host archive result remain pending. Evidence/contracts are linked in DEP-007 of the changelog; the exact allowlist below remains seventeen installers.

## Dependency-cycle and scan-runtime correction — 2026-10-14

The operator reports about eight minutes at checksum `OK` without a prepared archive message. One Ctrl+C was advised; no completed interruption/archive result was supplied. Local profiling confirms repeated source scans through expanding relative spellings: the previous helper reads one self-reference 405 times, and a 200,028-byte self-cycle exceeds a 12-second cap. The exact host stack was not captured, so this establishes a code defect without asserting which host frame was stalled.

The new scan uses canonical parent paths for queued source/visited/context identity while retaining each leaf alias and `all_files` flag. The 200,028-byte reproduction now reads once in 0.0478 seconds locally. Application aliases are not collapsed into their targets; distinct hardlink directories retain distinct relative contexts. Existing role/error and archive/restore guards remain intact.

The entire host inventory and graph check now emit immediate status and a 10-second heartbeat to stderr, with a 120-second deadline before any archive. Progress contains only elapsed time and helper function/line, without source contents, paths, arguments or configuration. Timer/handler state is restored after completion, errors or Ctrl+C and before archive moves. An existing armed timer is preserved by refusing to begin. Restore operations remain unchanged. Uninterruptible kernel I/O can delay normal signal handling; the timer never authorizes a partial-check archive.

All 62 local checks and the independent review pass, including nine new graph/context/real-move/alarm/timeout/interruption cases. The independent reviewer reproduces the old cycle, verifies a corrected one-read 200KB scan in 0.04264 seconds, performs real sixteen-file archive/restore with a live dependency preserved, and checks timer cleanup and unchanged original preservation primitives. Helper SHA256 `e7c5365f8284331dcac58f94071e1730af702de29e05447353df9254c214638c`. The operator's archive result remains pending. Keep earlier helpers/journals; the allowlist below remains seventeen files.

Verified replacement: [home-cleanup-6dd2a54ef699](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/releases/tag/home-cleanup-6dd2a54ef699), source `6dd2a54ef699e97f640fe0cb2fb1c68a48da8cce`, maintenance run `38639051054`. All three jobs and every step pass. Python 3.9/3.12 each complete 62 checks unprivileged and as root: 248 executions and no skips. All three assets independently match exact Git source by bytes, SHA256, GitHub digest, size and attachment headers. The root command passes shell syntax checking. Interrupt the earlier foreground check once and wait for the prompt before retrying. Actual host interruption/archive completion remains unreported. No application or approved application-installer payload changed; the separate application date-fixture limitation remains in DEP-003.

## Unrepresentable path-match correction — 2026-10-14

The checksum-verified DEP-004 host retry stopped during source scanning with `ENAMETOOLONG` (errno 36), after a PNG/base64-like fragment was interpreted as a path. The exact reported fragment reproduces the prior helper's error locally. No host archive completion has been evidenced.

The replacement treats only `ENAMETOOLONG` from heuristic absolute, relative or run-parts path predicates as a non-match. Source text is still checked for raw candidate names and installer globs first. Other errors still propagate. Actual enumerated sources, reads, candidates, alias resolution and the existing archive/restore safeguards are unchanged. A representable long path remains eligible for inspection; no arbitrary short length cutoff is introduced.

All 53 local filesystem checks and the independent review pass, including eight new PNG/overlong-path/live-reference/error-boundary/valid-long-path cases and an actual seventeen-file archive/restore around the exact reported image fragment. The independent reviewer also reproduces the old exact-fragment failure and new archive/restore, proves real source-alias errors remain fatal, and verifies by AST that only six heuristic predicates changed within scan and all other old functions are unchanged. Helper SHA256 `39b6d5a6549ec0e3b8d9ef59b7c7ae68ce044c92253defabb97be7f5c7805c8b`. Operator host completion remains pending. Earlier compiler and optional-command corrections remain in place; the seventeen-file allowlist and restore policy below are unchanged.

Verified replacement: [home-cleanup-40f0793fa9de](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/releases/tag/home-cleanup-40f0793fa9de), source `40f0793fa9de44a23983766876753015f968e101`, maintenance run `38635841911`. All three jobs and every step pass. Python 3.9/3.12 each run 53 checks unprivileged and as root: 212 executions with no skips. All three assets independently match exact Git source by bytes, SHA256, GitHub digest, size and attachment headers. The root command passes shell syntax checking. Hosting archive completion is still unverified; preserve previous attempts and journals. No application or approved application-installer payload changed; the separate application date-fixture limitation remains recorded in DEP-003.

## Optional system-command correction — 2026-10-14

The next checksum-verified host attempt stopped before archiving at `/usr/sbin/rcmysql` → absent `/etc/rc.d/init.d/mysql`. The replacement preserves and records broken optional package commands without repairing or changing system services. The class applies only to root-owned symlinks directly in `/usr/bin`, `/usr/sbin`, `/bin` or `/sbin`, with canonical, root-owned parents that are not writable by group/others. Missing targets must be in standard system/package namespaces (`/usr`, system library/command trees, `/opt/cpanel`, alternatives or legacy init directories); any systemd path component and historical installer names are excluded. Missing application/scheduler source aliases and targets outside those package namespaces still stop. Existing links to present scripts/installers are still followed. Classification rechecks that the target remains absent and the alias metadata is unchanged, including final strict resolution after metadata validation. `preserved_system_links` appears in the read-only report, private journal and final archive result.

All 45 local filesystem tests and the independent review pass, including five optional command aliases retained across a real archive/restore, live direct/indirect installer references, negative role/namespace/ownership/mode cases, actual CLI archive/restore reporting and a real wrapper reappearing precisely during target resolution. Independent default-target probes also accept the reported missing MySQL init target and reject the negative role/path cases. Python 3.9 syntax passes; existing archive/restore and other original functions are unchanged except scan and CLI reporting. Helper SHA256 `ec9d041bf98dd9c5444a0c0d4753178ba11ae205915f94b8ae25620c0451d8dd`. Operator host completion remains pending. Existing compiler-link handling remains in place; the exact seventeen-file allowlist and restore policy below are unchanged.

Verified replacement: [home-cleanup-c5802fdef9c2](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/releases/tag/home-cleanup-c5802fdef9c2), source `c5802fdef9c24619dccb133f21fd9a1cd1954e82`, maintenance run `38630520817`. All three jobs and every step pass. Python 3.9/3.12 each complete 45 tests unprivileged and as root: 180 executions and no skips. All three assets independently match exact Git source by full bytes, SHA256, GitHub digest, size and attachment headers. The new operator command passes shell syntax checking. Actual hosting archive completion is still unverified; preserve previous attempts and journals. No application payload or approved application installer changed. The separate application CI date-fixture limitation is recorded in DEP-003.

## Optional compiler runtime correction — 2026-10-14

The next operator attempt passed checksum verification but stopped on the GCC 32-bit stub `/usr/lib/gcc/x86_64-redhat-linux/11/32/libstdc++.so` pointing to the absent `/usr/lib/libstdc++.so.6.0.29`; no files were archived. Optional GCC runtimes can point outside the compiler tree, so the replacement handles their class rather than one library version.

The original source alias must still be inside the canonical-parent GCC namespace. In addition to GCC-tree targets, it may point directly into `/usr/lib`, `/usr/lib64` or their standard `/lib`/`/lib64` aliases only for the same compiled-library family: `lib*.so` with numeric version suffixes and optional numeric Red Hat build stamps, or an identical `lib*.a` basename. Other source roles, unrelated families, scripts, nested/custom directories and path-prefix lookalikes remain blocking. Compiler links are recorded and preserved; no system library or package is repaired, removed or installed.

Local validation and independent review: all 35 checks pass, including fourteen optional runtime/static-library links across 32/64-bit fixtures, exact archive/restore of all seventeen candidates and negative source/target role/family/directory tests. Independent default-namespace probes accept the actual reported pair and reject the negative cases; all other helper functions are unchanged. Helper SHA256 `d6e9f3426b389f7ca1b90d032080a90ceba45eba2a285e9e80466bc11e81defe`. The operator's host archive result remains pending. Earlier correction/publication evidence below is historical; it does not establish a completed host cleanup.

Verified replacement: [home-cleanup-84cac4f1eb49](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/releases/tag/home-cleanup-84cac4f1eb49), exact source `84cac4f1eb4987c1bc39e3f103b563f7089b12b2`, maintenance run `38628177180`. All three jobs and every step pass. Python 3.9/3.12 each run 35 cases unprivileged and as root: 140 executions, no skips. All three assets pass independent exact-source byte, SHA256, GitHub-digest, size and attachment-header verification. The replacement command passes shell syntax checking. Use this verified helper for the same authorized archive scope; preserve prior helpers and journals. The frozen application payload and its approved installers are unchanged. General application CI has a separate unchanged Sunday-sensitive lifecycle fixture failure recorded in DEP-003; this verification claim covers the maintenance checks only.

## Compiler-alias correction — 2026-10-14

The verified read-only host scan reports missing target `/usr/lib/gcc/i686-redhat-linux` at the helper's strict source-symlink resolution. The removed CTC ZIP is not that target. The replacement preserves and records a dangling compiler alias only if its source alias (canonical parent with leaf retained) and missing resolved target are both inside `/usr/lib/gcc` or `/usr/lib64/gcc`. It does not repair or remove system compiler links. Application/scheduler aliases pointing to the compiler tree, other missing dependency aliases and unsafe installer candidates remain blocking.

The dependency report, private journal and final result include `preserved_compiler_links` as source-to-missing-target metadata. Other OS failures report errno/filename instead of hiding the path. No file content, environment values or provider response is printed. The unchanged archive/restore policy and exact seventeen-file allowlist below still apply.

Current validation: 31 focused checks pass locally and in the independent review, including archive/restore around an unchanged missing compiler alias and four negative cases proving the exception cannot bypass application/scheduler/source-target namespace guards. The independent audit also verifies default compiler namespaces and unchanged original preservation primitives. Current helper SHA256: `68ff0308952d6e12d2746ca783b8b8e30d7939066f06d4711e48510f102174ed`. The operator's successful host archive result remains pending for this correction. The earlier verified DEP-001 publication remains historical evidence below.

Verified replacement: [home-cleanup-3b790b9ea7a7](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/releases/tag/home-cleanup-3b790b9ea7a7), source `3b790b9ea7a7d3cb9c4d52c0bd8639e7f09aee63`, run `38626391845`. Three jobs and every step pass. Python 3.9/3.12 each run 31 cases unprivileged and as root, 124 total and no skips. Helper, review and checksum download assets match exact Git source and pass independent full-byte/SHA256/GitHub-digest/size/attachment checks. The application and its approved release installers have not changed. Use this verified replacement for the same authorized archive scope; retain earlier attempts and journals.

## Authorized archive operation

The user subsequently instructed: “so do that now please.” The standalone `tools/archive-legacy-home-files.py` implements this authorized reversible cleanup. It does not update the application release. Its exact seventeen-name allowlist is below; diagnostics, the unidentified home `pricing.php`, shared credentials, CTC Publishing and existing backups are outside its move scope.

Run the checksum-verified published helper in WHM Terminal as root with `--archive`. One run performs dependency checks and moves only eligible files. Local checks inspect application/server source and configuration, cron, queued `at` jobs, persistent/runtime systemd units and observed process arguments/open files/working directories. Referenced wrappers, including extensionless/relative scripts, `run-parts` directories, installer wildcard launches and live installer-to-installer dependencies, are followed. Inspected literal/wildcard dependencies and observed processes do not establish arbitrary computed launch paths or external automation. Unreadable/changing sources and unreviewed candidates stop the operation; referenced candidates stay in place.

Eligible files move without replacement into a new root-private `/home/sitesee/private-archives/sitesee-legacy-installers-<UTC timestamp>-<nonce>` directory. The tool prints the destination before its first move and retains a durable `manifest.json` with checksums, original inode/owner/group/mode/size/mtime and per-file states. No original file bytes are deleted, and no operational database, integration configuration, gate, service or schedule is changed. The application remains in its existing folders and Stripe remains TEST.

The tool prints the exact restore command. `--restore <printed archive path>` verifies the entire restore set first, retains new/changed originals and unknown archive edits, and never overwrites a destination. An interruption after either rename but before journal update is recoverable. Keep the helper and any partially completed archive/journal after a stopped operation; do not manually delete either. `--check` performs the dependency scan without creating a lock or archive.

Validation: 26 focused checks exercise actual Linux atomic moves and metadata/checksum preservation, direct/transitive/relative/wildcard/process dependencies, source aliases, hardlink/symlink refusal, candidate changes, occupied restore destinations, modified archives, interrupted archive/restore, repeated runs, durable directory publication and read-only checks. Independent review passed for helper SHA256 `2847964b31f2f7da75c866023d5a5d874cf5355116a8b4dda3bd5cb94459e0a2`; its separate filesystem reproductions and all 26 checks passed with no remaining blocker in the declared scan scope. The maintenance CI repeats this suite unprivileged and as root on Python 3.9 and 3.12. Record host `ARCHIVE PASS` separately after the operator runs the command.

Verified publication: [home-cleanup-d0b859cac2e4](https://github.com/SiteSeeAI/SiteSee-Real-Estate-Web-App/releases/tag/home-cleanup-d0b859cac2e4), source `d0b859cac2e41a8aa32a86d2a795e9133a48c84e`, CI run `38490791103`. Both Python versions completed 26 tests under each privilege level (104 total, no skips); all three jobs and every step passed. The three asset downloads passed independent byte/checksum/digest/size/attachment verification. Hosting execution and its archive path remain pending.

Host attempt: the matching helper at `/root/sitesee-home-cleanup.x8ZW9S/archive-legacy-home-files.py` passed checksum verification and stopped with `FileNotFoundError`. The later operator-supplied home listing retains all seventeen candidates. No prepared archive path or successful move is evidenced. The missing filename was hidden by the CLI; a read-only invocation of the verified helper's dependency scanner must establish that filename and helper frame before correcting the host-specific cause. The separately removed CTC Publishing ZIP is not an archive target; its causal relevance is unknown. Do not bypass source/dependency guards or manually delete installer candidates to avoid this error.

## Historical installer candidates

These seventeen loose installers are the first archive candidates. The inspected current public/private application source contains no references to these home-directory installer paths. Historical tools and deployment documents retain references to some of them. Their presence in the home listing does not establish that server automation never invokes them; check current local schedules and service configuration before moving them. Archive the existing bytes and metadata with an explicit file list and a checksum record. Do not replay these installers or use a broad `install-*` deletion.

```text
install-customer-portal-pathfix.py
install-customer-portal.py
install-microsoft-calendar-connection.py
install-microsoft-calendar-connection-r2.py
install-portal-complete.py
install-re-business-workflow.py
install-re-business-workflow-r2.py
install-re-calendar-notice-20261002-r1.py
install-re-draft-recovery-r1.py
install-re-email-change-20261002-r1.py
install-re-job-closeout-20261002-r1.py
install-re-job-closeout-20261003-r1_1.py
install-re-onsite-services-20261005-r1.py
install-re-portal-polish-20261002-r1.py
install-re-staff-review-20261002-r1.py
install-re-test-recipient-20261002-r1.py
install-re-vendor-accounts-20261005-r1.py
```

The standalone booking/calendar check, move, contact-link, calendar-connection completion and legacy-cancellation repair scripts are separate diagnostic/recovery candidates. Existing cleanup reports, quarantine tools/results and verification files are history; retain them until their provenance and corresponding backups are reviewed. The loose home-directory `pricing.php` has not been identified or compared with the live pricing route and must remain in place for now.

## Preserve

- `/home/sitesee/.sitesee-real-estate`: current private code, configuration, operational records, sessions, deployment records, backups and worker lock.
- `/home/sitesee/public_html/re`: current public application root. Other `public_html` sites are outside this cleanup.
- `/home/sitesee/.sitesee-graph-mail.json`: actively referenced by both `_private/server/booking-microsoft-calendar.php` and `booking-mail-client.php`; its fixed location is a current integration contract.
- `staging`: retained packages and installation evidence; inventory before any retention cleanup.
- CTC Publishing files/directories and its cleanup/setup/update artifacts: a separate application, outside SiteSee cleanup.
- cPanel, mail, SSL, account configuration, logs, shell settings, home symlinks and existing quarantine/backups.

## Versioned application layout

The intended boundary is one version directory containing release code and public assets, with persistent configuration, data, sessions and backups in a separate shared private directory. Only the release's `public` subdirectory is served by cPanel; its private code and shared state must not become web-accessible.

The current installer updates fixed public/private roots in place. `unified-deployments` holds backup/journal evidence and `staging` holds packages; neither is the active versioned application directory. A version-folder migration therefore requires a separately prepared installer/configuration transition, not moving folders manually. Existing source assumes private state relative to the private code directory, requires a canonical non-symlink private root, and pins the existing worker path/lock and Graph credential path. Preserve URLs, signed links, webhook ownership, provider IDs, records and financial repair-forward rules during any transition.

Before a concrete migration: inventory the installed release and runtime paths, separate shared-state dependencies, prepare the exact destination and cPanel mapping, verify isolated deployment/recovery, and retain the accepted TEST functionality. No production activation or database reset follows from this assessment.
