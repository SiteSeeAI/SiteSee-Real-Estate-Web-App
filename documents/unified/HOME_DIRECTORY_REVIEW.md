# Home-directory cleanup and release layout

Assessment: 2026-10-13, America/Chicago. The operator supplied a top-level `ls -la /home/sitesee` listing. This is a preparation checkpoint, not evidence of an archive, deletion or migration. Stripe remains TEST.

## Authorized archive operation

The user subsequently instructed: “so do that now please.” The standalone `tools/archive-legacy-home-files.py` implements this authorized reversible cleanup. It does not update the application release. Its exact seventeen-name allowlist is below; diagnostics, the unidentified home `pricing.php`, shared credentials, CTC Publishing and existing backups are outside its move scope.

Run the checksum-verified published helper in WHM Terminal as root with `--archive`. One run performs dependency checks and moves only eligible files. Local checks inspect application/server source and configuration, cron, queued `at` jobs, persistent/runtime systemd units and observed process arguments/open files/working directories. Referenced wrappers, including extensionless/relative scripts, `run-parts` directories, installer wildcard launches and live installer-to-installer dependencies, are followed. Inspected literal/wildcard dependencies and observed processes do not establish arbitrary computed launch paths or external automation. Unreadable/changing sources and unreviewed candidates stop the operation; referenced candidates stay in place.

Eligible files move without replacement into a new root-private `/home/sitesee/private-archives/sitesee-legacy-installers-<UTC timestamp>-<nonce>` directory. The tool prints the destination before its first move and retains a durable `manifest.json` with checksums, original inode/owner/group/mode/size/mtime and per-file states. No original file bytes are deleted, and no operational database, integration configuration, gate, service or schedule is changed. The application remains in its existing folders and Stripe remains TEST.

The tool prints the exact restore command. `--restore <printed archive path>` verifies the entire restore set first, retains new/changed originals and unknown archive edits, and never overwrites a destination. An interruption after either rename but before journal update is recoverable. Keep the helper and any partially completed archive/journal after a stopped operation; do not manually delete either. `--check` performs the dependency scan without creating a lock or archive.

Validation: 26 focused checks exercise actual Linux atomic moves and metadata/checksum preservation, direct/transitive/relative/wildcard/process dependencies, source aliases, hardlink/symlink refusal, candidate changes, occupied restore destinations, modified archives, interrupted archive/restore, repeated runs, durable directory publication and read-only checks. Independent review passed for helper SHA256 `2847964b31f2f7da75c866023d5a5d874cf5355116a8b4dda3bd5cb94459e0a2`; its separate filesystem reproductions and all 26 checks passed with no remaining blocker in the declared scan scope. The maintenance CI repeats this suite unprivileged and as root on Python 3.9 and 3.12. Record host `ARCHIVE PASS` separately after the operator runs the command.

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
