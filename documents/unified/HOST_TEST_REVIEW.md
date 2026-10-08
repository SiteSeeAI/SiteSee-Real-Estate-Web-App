# Existing-host TEST review

Use existing re.sitesee.ai, cPanel Terminal as sitesee, and /home/sitesee/staging. The operator declined a separate hostname. Working PHP 8.2 CLI: /opt/cpanel/ea-php82/root/usr/bin/php. Do not use the absent php-cli path or WHM root terminal.

The current frozen candidate is source bd026c77d5963182000d06b58d80e9b7eb7fe944, package unified-test-bd026c77d596.tar.gz. Package SHA256: 9c6f292d4b78324c8a1f2794de4de1d5755701becb2a29729dff626ce25945c0. Matching standalone installer SHA256: 75b62c0188c2daa0fdf2d2375263f7da161599a189cbdaada4605103769b5cd6.

No manual Apache, Turnstile or SVG edit is needed. The public Apache directives already exist on separate correct physical lines; the cPanel PHP82 handler is retained. Turnstile has the Corporate/Audit public site key substituted by the preserved historical installer, not an unknown code change. The SVG differs only in CRLF line endings. The new installer preserves all three files exactly, validates all application logic and artwork, retains owner/group/mode/inode/time, and records actual installed hashes. Other changes still stop. Do not replace the configured site key with its source template marker; do not replay the old Corporate protection installer or the superseded SVG repair helper.

The previous helper examined all 267 source paths and reported only Turnstile as the remaining unknown, with the SVG already recognized as equivalent. It changed no application files. Prior uploads are retained under /home/sitesee/staging/verified-download-nwsh5cdz. The earlier private .htaccess mode 0666 was repaired to 0600; no application installation has occurred.

Fetch both current matching files directly into staging using the supplied verified download command. It checks both hashes before replacement and retains previous staged files privately. Alternatively upload both using File Manager. Verify the installer before executing read-only preflight:

```sh
printf '%s  %s\n' 75b62c0188c2daa0fdf2d2375263f7da161599a189cbdaada4605103769b5cd6 /home/sitesee/staging/install-unified.py | sha256sum -c - &&
python3 /home/sitesee/staging/install-unified.py \
  --package /home/sitesee/staging/unified-test-bd026c77d596.tar.gz \
  --sha256 9c6f292d4b78324c8a1f2794de4de1d5755701becb2a29729dff626ce25945c0 \
  --php /opt/cpanel/ea-php82/root/usr/bin/php \
  --preflight
```

Preflight checks metadata, unchanged source, active integrity records, CLI extensions/syntax, free space and read-only SQLite integrity. It prints actual preserved public hashes and creates no install journal. If it stops, preserve all files and review the stated cause; do not use --resume for a failed preflight. CLI does not prove FPM/provider bindings or connected acceptance.

After successful host preflight, review FPM/signing settings, current TEST bindings and exclusive reconciliation worker/webhook ownership. Prepare the exact backup/recovery/update and connected-TEST acceptance scope for explicit server-update approval. Approval must arrive before --install. Do not replay incremental installers. The unified installer makes durable source backups and a WAL-consistent SQLite snapshot before approved replacements, supports guarded resume/verify and code rollback that preserves newer operational ledger/provider state. Restores must rehearse on a new isolated private destination; never overwrite newer successful payments or provider outcomes with an old snapshot.

Installed phone/inbox, role/order isolation, TEST deposit/payment evidence, calendar/CRM/invitations, vendor closeout, final collection and finished-delivery acceptance remain pending on this existing hostname. Keep protected records, signing secrets, integration identities, callbacks and locks. Keep Stripe TEST; LIVE, cutover and PR merges remain separate decisions. Never paste credentials or customer data.
