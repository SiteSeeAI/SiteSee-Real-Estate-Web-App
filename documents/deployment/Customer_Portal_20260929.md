# SiteSee Customer Portal TEST Installation

Release `portal-20260929-r1` adds the complete customer portal to the verified appointment r4 installation. Upload one installer; it contains all 19 portal application files, including the reviewed signed webhook. Existing pricing, scheduling, communication, configuration, credentials, data and cron stay in place. The installer checks their expected source and release hashes and stops on unknown edits.

The portal is not yet installed. The package provides local preflight, disabled installation, TEST activation and recovery. Actual Stripe SDK/provider, email receipt, Microsoft and CRM behavior require the installed TEST check below. Source tests use synthetic customers and blocked or mocked providers. No live payments are enabled.

## Upload And Install

Extract `SiteSee_Portal_TEST_20260929.zip` on your computer. In cPanel File Manager, upload `install-customer-portal.py` to `/home/sitesee/`, outside `public_html`. Check that the filename has no added number or suffix. Do not manually replace application files or upload the tests.

In WHM Terminal as root, run this block. Each step runs only if the preceding step succeeds:

```sh
python3 -B /home/sitesee/install-customer-portal.py --check &&
python3 -B /home/sitesee/install-customer-portal.py --install &&
python3 -B /home/sitesee/install-customer-portal.py --enable-test
```

Save the output, particularly the backup directory. If any check stops, return the complete output for review. Do not bypass the check, edit manifests, or use an older installer to overwrite this release. An interrupted installation can be resumed with the same `--install` command: it verifies every backup and current file before restoring the incomplete attempt and installing again.

The check verifies pinned dependencies, the r4 release, expected existing release records, brand fonts, safe file paths and ownership, and PHP CLI syntax/extensions. It checks the existing checkout file is marked TEST. It does not prove the live PHP-FPM environment, provider credentials, webhook delivery, inbox receipt or CRM behavior. The application continues to require the existing booking TEST flag and TEST-only payment keys at runtime. No PHP-FPM settings are changed.

## Confirm The Installed TEST Journey

Open `https://re.sitesee.ai/account.php`. The sign-in page should load with Poppins and Inter and a visible TEST notice. A 503 means the private activation gate or existing booking TEST environment still needs attention; return the installer output and page result. Do not change Stripe keys or server settings to work around it.

Use the approved controlled address `cro@sitesee.ai`. Request a sign-in email, confirm it arrives, and open the one-time link. My Orders should open. Historical ownership is never assigned by email alone; use a valid existing private order link only when ownership is established. Keep protected order `D32FFC7458` unchanged.

Place one fresh TEST order with a future eligible window. Review property, services, current price, access details and fresh consent. Complete a Stripe TEST deposit using the established test-payment procedure. A return page alone is insufficient: Check Payment Status must show the saved signed-webhook result. Retry the same order if interrupted; do not create another order to recover it. Record the new reference.

Use the established staff review and calendar-confirmation workflow for this fresh reference. Verify the actual customer email receipt and the saved Microsoft/CRM association. Do not resend a notice that already has saved delivery evidence. Complete an approved TEST balance with fresh consent, then inspect Billing & Receipts and restricted payment-method access. Confirm paid/deposit/balance amounts and receipt ownership agree.

On that fresh eligible order only, check portal appointment controls and saved status. Existing timing, recipient and notice rules remain in force. A cancellation is separate from refund processing. These are changed portal integration checks; the completed booking validation cycle and direct Microsoft calendar editing are not reopened.

Sign out and confirm the order is unavailable until sign-in. Mobile layout and two-customer isolation already passed isolated browser checks. Any additional real test inbox requires explicit authorization before sending messages to it. Treat ambiguous historical or Stripe customer records as staff reconciliation cases; never attach them automatically.

## Disable Or Restore

If a problem appears after activation, run:

```sh
python3 -B /home/sitesee/install-customer-portal.py --disable
```

This closes account access on subsequent requests, even if an inherited portal environment flag is enabled. It retains orders, sessions, payment records, the signed webhook and its dependencies so delayed payment notifications can finish. An already executing request can finish; disabling does not reverse a submitted action. Existing non-portal booking routes continue to operate.

Before the portal has ever been activated, a complete file rollback is available:

```sh
python3 -B /home/sitesee/install-customer-portal.py --rollback
```

Rollback verifies all target bytes and backups before restoring the original webhook and release records and removing the new portal files. It never restores or deletes database records. After activation, the durable activation marker blocks file rollback: disable and fix forward instead. Do not remove that marker to force a rollback. Retain the backup and journal; they are private, owned by `sitesee`, mode 0600 for files.

No merge to main, public navigation promotion, live Stripe activation, credential change, customer bulk message or automatic historical reconciliation is included.
