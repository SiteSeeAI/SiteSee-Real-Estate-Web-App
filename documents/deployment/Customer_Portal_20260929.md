# SiteSee Phone-Login Portal — One-Package Installation

Release: portal-20260929-r2. Installer revision: r2.1-cpanel. Updated 2026 09 29.

This revision recognizes the verified cPanel website-root mode 0750 and the three existing calendar maintenance scripts. It preserves website-root ownership, group and permissions. The application payload is unchanged. The terminal must begin with INSTALLER REVISION: r2.1-cpanel; otherwise the old installer is still uploaded.
This guide replaces the earlier r1/email-login instructions and individual path fixes.
Delivery window: 2½–5 days from the original 2026 09 28 start; do not restart the clock.

## One Upload, One Command

Extract SiteSee_Portal_Complete_TEST_20260929.zip. Upload only
`install-portal-complete.py` to `/home/sitesee/` through cPanel, preserving its name.
Do not upload the enclosed source or test files to the website. Do not rerun the old installer.

In WHM Terminal as root:

```bash
python3 -B /home/sitesee/install-portal-complete.py --deploy
```

This command inspects the entire reviewed dependency set, prints all detected code/path/metadata blockers together, fixes only verified metadata, restores missing reviewed dependencies, backs up the affected files, installs the complete phone-login application, and offers private phone setup. An unknown file edit, unexpected owner, symlink, hardlink, inconsistent journal or unsupported prior release stops replacement. Backups and journals remain available. No credentials or customer records are printed.

## Phone Setup In The Same Command

The current SMS adapter uses Twilio Verify. Have the account SID, Verify service SID, auth token, approved test cell number in international format, and the existing approved customer email available. Credentials and identity fields are entered with hidden terminal input and are stored outside public_html with owner-only permissions. The email identifies the existing approved customer for staff enrollment; it is not a customer login method. Staff must explicitly confirm ownership of the number and record verification evidence. Shared or conflicting numbers require staff review, not an automatic reassignment.

The installer sends no SMS and makes no Stripe, mail, calendar or CRM request. Twilio's normal Verify credentials are required; the local TEST setting restricts permitted recipients and does not simulate Twilio. An actual text is requested later through the sign-in form, with the customer's text consent.

If SMS credentials are unavailable, press Enter at the first setup prompt. The complete application stays installed with account access disabled. Finish setup by running the same command again; do not upload more files. Existing valid SMS settings are reused and never silently overwritten. Enrollment can create portal identity tables, an approved portal account, and its verified phone binding in the existing ledger. It does not modify booking, payment, calendar or CRM records.

## What This Package Fixes

- Public release paths resolve under `/home/sitesee/public_html/re`, including `manage-appointment.php`.
- Known files such as `pricing-assets/availability.js` receive owner/group and permission repair only after their contents match accepted source hashes. File bytes and line endings are preserved.
- Missing dependencies are restored from bundled reviewed bytes only when those bytes match the retained release record, including an accepted CRLF variant.
- Cell-number/code sign-in replaces email login. The complete package includes the SMS adapter, phone identity logic and staff enrollment tool.
- All independent source and metadata blockers are collected before application writes; a later unsafe edit is preserved.
- An interrupted install can resume using the same command and verified backups. Repeated successful runs do not duplicate files, orders or payments.

## Result And Focused Browser Check

The terminal prints either PHONE-LOGIN PORTAL ENABLED FOR TEST or PACKAGE INSTALLED; ACCESS DISABLED PENDING SETUP, with the remaining setup items. It saves `/home/sitesee/.sitesee-real-estate/portal-complete-report.json`, owned by sitesee and mode 0600. Runtime settings are inspected from the site's PHP-FPM pool; this is not proof of an active worker or external provider connection.

After TEST enablement, open https://re.sitesee.ai/account.php. Use the enrolled cell number, request a code and complete sign-in. Confirm My Orders opens; place one fresh controlled TEST order and check its signed-webhook deposit, staff review, appointment integration, approved balance and owned billing controls. Protect D32FFC7458 and do not resend old notices. Carry forward the completed appointment validation; test the changed account integration rather than repeating the entire old booking cycle.

An existing public navigation link has not been changed by this package. The direct account address above is the controlled test entry. Actual SMS delivery, active PHP-FPM behavior and TEST payment/calendar/CRM behavior must be observed on the server; isolated tests do not establish them.

## Recovery

Rerun `--deploy` after an interrupted installation. Unknown concurrent edits stop recovery rather than being overwritten. To inspect without any change, use `--check` with the same file.

To close account access:

```bash
python3 -B /home/sitesee/install-portal-complete.py --disable
```

Before this release has ever been activated, `--rollback` restores application bytes from verified backups and removes files that this package restored or created. It does not restore the database or undo phone enrollment. Verified permission tightening remains in place; prior metadata is recorded in `portal-complete-metadata.json`. After activation, destructive file rollback is blocked: disable access and repair forward, preserving signed-payment processing and the ledger. No broad recursive chmod/chown, cleanup or deletion is performed.

An already installed r1 portal is reported for migration review instead of silently replaced. The user's reported r1 attempts stopped during --check and therefore did not install or enable that release.
