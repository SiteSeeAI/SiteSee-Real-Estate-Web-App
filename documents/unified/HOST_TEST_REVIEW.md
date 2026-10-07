# Existing-host TEST review

The operator chose the existing `https://re.sitesee.ai` hostname and confirmed cPanel Terminal with PHP 8.2. No new domain is required. The source release is `unified-test-d380d41a0903`, based on exact commit `d380d41a090342d82255906f73fad518d722020d`. The package SHA-256 is `3a33d161bcd2ed96485bb23b6560eef63df6a5c844a33313dfb1b475c4fd83ae`. The matching standalone `install-unified.py` SHA-256 is `ef9442311d50a44f4b3b7352550fee0fe9c81b68cf2c9d1dd44642cdda7a2503`.

The hosting update is not authorized or executed by this document. Independent release review and privileged CI have passed on this exact source commit. Stage the exact package and its matching installer in a private account-owned directory outside `public_html`, verify their checksums and run the read-only preflight. Preflight reports any unknown deployed edits, runtime/permission differences or earlier incomplete installer so they can be reviewed without replacing files. CLI checks do not prove FPM settings; retain the existing FPM pool, signing secret, TEST provider bindings and corporate/mail bridges.

Use cPanel Terminal as account `sitesee`. Python 3 and the verified cPanel PHP 8.2 CLI binary below are required. The operator confirmed the account UID 1009/GID 1011. The former `php-cli` path does not exist on this host. The expected roots remain `/home/sitesee/.sitesee-real-estate` and `/home/sitesee/public_html/re`; the existing private database path must be retained. Supply `--database` if the actual database path differs from the default. Never paste credentials or customer data into chat or logs.

Create the private staging folder in cPanel Terminal, then upload the exact package and matching installer into it using File Manager:

```sh
mkdir -p /home/sitesee/staging
chmod 700 /home/sitesee/staging
```

The first host preflight discovered an existing private `.htaccess` mode of 0666. The operator set that one file to 0600; the private source checks then passed. The public `.htaccess` has a malformed first line, `Options -IndexesDirectoryIndex index.html`, and a legitimate cPanel PHP 8.2 handler. Its reported pre-repair SHA-256 is `e640befd468cd72a53a925ae633490e09fae922c7bf7299e9d73b523178c7ee2`. The raw host bytes have not been independently reconstructed.

Before another preflight, use File Manager to copy `/home/sitesee/public_html/re/.htaccess` into `/home/sitesee/staging/re-htaccess-before.txt`; preserve any existing backup instead of overwriting it. The staging folder must be 0700 and the backup 0600. In the original public file, replace only the joined first line with these two lines followed by one blank line:

```apache
Options -Indexes
DirectoryIndex index.html

```

Retain every later line, including the full cPanel-generated `ea-php82` handler block. This is a targeted repair of existing Apache syntax, not application installation. If the file no longer matches the reported text/hash, stop and review the new differences. The revised installer validates all application directives and exactly the reviewed handler, preserves the original host bytes/mode/owner/inode/mtime during application installation and code recovery, and records their actual digest. Other changes still stop preflight.

A read-only inventory of all 267 payload paths has been requested to collect any remaining host differences together. No application deployment is approved by a file repair, an inventory, or a successful preflight.

Run this read-only preflight after the upload:

```sh
printf '%s  %s\n' ef9442311d50a44f4b3b7352550fee0fe9c81b68cf2c9d1dd44642cdda7a2503 /home/sitesee/staging/install-unified.py | sha256sum -c - &&
python3 /home/sitesee/staging/install-unified.py \
  --package /home/sitesee/staging/unified-test-d380d41a0903.tar.gz \
  --sha256 3a33d161bcd2ed96485bb23b6560eef63df6a5c844a33313dfb1b475c4fd83ae \
  --php /opt/cpanel/ea-php82/root/usr/bin/php \
  --preflight
```

Before installation, verify there is one authoritative reconciliation worker and webhook consumer for the existing ledger, retain their ownership/locks and establish a controlled update window. A copied database must never compete for the same payment, invitation or calendar state. Present the exact package, successful host preflight, backup/recovery plan and installed TEST acceptance scope for server-update approval. An approval must arrive; elapsed time does not authorize the update.

The approved update uses the same exact command with `--install`. It performs the durable file backups and SQLite-consistent snapshot before replacements, records the private backup path and verifies the result. If interrupted, preserve that package and journal and use `--resume` after reviewing the cause. `--verify` confirms installed files, metadata, backup integrity and local TEST gates. Unknown edits stop recovery. Do not replay the old incremental installers.

`--restore-rehearsal /home/sitesee/<new-private-directory>/restored.sqlite` verifies the snapshot in a new isolated account-owned 0700 directory. It refuses operational or public destinations. `--rollback-code` restores original code and release metadata only; it preserves the current database, configurations, sessions and provider state. Review new side effects before code recovery and repair forward where required; never overwrite newer successful payments or confirmations with the snapshot.

Once installed, use the preserved acceptance matrix to review phone sign-in, owned orders, role isolation, TEST deposit and payment evidence, calendar/invitation/inbox/CRM states, vendor assignment and one deliberately approved TEST closeout, final collection and finished deliverable release. Browser redirects or a deployment success line do not prove those outcomes. Preserve all protected existing records and integration identities. Stripe LIVE requires a separate explicit decision.
