# Home-directory cleanup and release layout

Assessment: 2026-10-13, America/Chicago. The operator supplied a top-level `ls -la /home/sitesee` listing. This is a preparation checkpoint, not evidence of an archive, deletion or migration. Stripe remains TEST.

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
