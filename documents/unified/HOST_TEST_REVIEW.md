# Existing-host TEST review

The operator chose the existing `https://re.sitesee.ai` hostname and confirmed cPanel Terminal with PHP 8.2. No new domain is required. The source release is `unified-test-3aa76798d011`, based on exact commit `3aa76798d011d2f30b7002aaa716b3f66153f529`. The package SHA-256 is `6959643cf7d4b2e08da09985740c01cefc38cf89f93d814b39a13bc114fba1fc`.

The hosting update is not authorized or executed by this document. Finish independent release review and privileged CI first. Then stage the exact package and its matching installer in a private account-owned directory outside `public_html`, verify their checksums and run the read-only preflight. Preflight reports any unknown deployed edits, runtime/permission differences or earlier incomplete installer so they can be reviewed without replacing files. CLI checks do not prove FPM settings; retain the existing FPM pool, signing secret, TEST provider bindings and corporate/mail bridges.

Use cPanel Terminal as account `sitesee`. Python 3 and either the cPanel PHP 8.2 CLI binary below or its verified `php` counterpart are required. The expected roots remain `/home/sitesee/.sitesee-real-estate` and `/home/sitesee/public_html/re`; the existing private database path must be retained. Supply `--database` if the actual database path differs from the default. Never paste credentials or customer data into chat or logs.

After the candidate is approved and privately staged, the preflight command has this form:

```sh
python3 /home/sitesee/unified-test-3aa76798d011/install-unified.py \
  --package /home/sitesee/unified-test-3aa76798d011/unified-test-3aa76798d011.tar.gz \
  --sha256 6959643cf7d4b2e08da09985740c01cefc38cf89f93d814b39a13bc114fba1fc \
  --php /opt/cpanel/ea-php82/root/usr/bin/php-cli \
  --preflight
```

Before installation, verify there is one authoritative reconciliation worker and webhook consumer for the existing ledger, retain their ownership/locks and establish a controlled update window. A copied database must never compete for the same payment, invitation or calendar state. Present the exact package, successful host preflight, backup/recovery plan and installed TEST acceptance scope for server-update approval. An approval must arrive; elapsed time does not authorize the update.

The approved update uses the same exact command with `--install`. It performs the durable file backups and SQLite-consistent snapshot before replacements, records the private backup path and verifies the result. If interrupted, preserve that package and journal and use `--resume` after reviewing the cause. `--verify` confirms installed files, metadata, backup integrity and local TEST gates. Unknown edits stop recovery. Do not replay the old incremental installers.

`--restore-rehearsal /home/sitesee/<new-private-directory>/restored.sqlite` verifies the snapshot in a new isolated account-owned 0700 directory. It refuses operational or public destinations. `--rollback-code` restores original code and release metadata only; it preserves the current database, configurations, sessions and provider state. Review new side effects before code recovery and repair forward where required; never overwrite newer successful payments or confirmations with the snapshot.

Once installed, use the preserved acceptance matrix to review phone sign-in, owned orders, role isolation, TEST deposit and payment evidence, calendar/invitation/inbox/CRM states, vendor assignment and one deliberately approved TEST closeout, final collection and finished deliverable release. Browser redirects or a deployment success line do not prove those outcomes. Preserve all protected existing records and integration identities. Stripe LIVE requires a separate explicit decision.
