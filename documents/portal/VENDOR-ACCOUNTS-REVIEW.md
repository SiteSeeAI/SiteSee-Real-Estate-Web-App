# Vendor accounts and assigned onsite work — 2026 10 05

Release: `vendor-accounts-20261005-r1`. Baseline: operator-installed `onsite-services-20261005-r1`, branch checkpoint `805d8ecd5d675e4c8945c0fa1efcd2200f07b062`. This release is prepared for installation; no installed vendor login or provider-connected closeout has been verified.

## Result

The onsite video pick-list title is **Property Video** in both markets. Saved additional video descriptions keep their duration and commercial quantity. Original orders and previously fixed bills are preserved.

Vendors use `/vendor.php`, with cell phone and a one-time text code. They have separate identities, sessions and assigned-job permissions. Staff create or edit accounts at `/staff-vendors.php` using the existing manager sign-in, then grant access under **Vendor Access** on an approved, confirmed unfinished booking. Assignments are explicit identifiers; a name match never grants access.

The vendor sees their assigned jobs, the current scheduled arrival window, ordered services, agent contact, property access, must-have shots, special requests and provided onsite contacts. The same service selector, canonical prices, eligible 18% commission and verbal-approval **Job Complete** are available. Property access instructions are hidden after completion. Production stays with staff.

The onsite selector script uses a versioned URL, so a previously cached staff-only picker cannot send vendor price checks to the manager route. The server accepts only preview, save additions and complete job from an authenticated vendor. Customer account functions, account administration, booking review, calendar changes, payment recovery and Production editing are not vendor actions. Direct requests to manager/customer routes still require their own sessions. Unassigned and nonexistent job references produce the same denial.

Disabling or editing a vendor account rotates its identity revision and invalidates previous sessions and outstanding codes. Reassigning or removing a job revokes its previous grant; granting it back creates a new revision. A later change to the booking's reviewed photographer also suspends the old grant until the manager confirms it again. Draft and closeout writes recheck account and assignment authorization inside the existing SQLite write transaction. The final bill records the authenticated vendor ID, name, account revision and assignment revision alongside the exact verbal-approval statement and timestamp.

The original collection, verification, recovery and Production functions remain byte-identical after `booking_job_complete`. Duplicate closeout still reuses the fixed final bill and saved TEST payment attempt. Declined cards may require customer action; vendors see the saved status, and managers retain recovery controls. Commission is recorded separately, excludes subscriptions/hosting/licensing and original/package services, and is not an automatic payout or an extra customer fee.

## Enrollment and installation

The existing Twilio Verify configuration and TEST recipient allowlist are reused unchanged. Creating a vendor account does not send a text or expand that allowlist. The management page displays whether the number is already approved for TEST texts. Use an existing approved TEST cell number for initial acceptance; a number may independently belong to both a customer and a vendor without sharing either role's session or permissions.

The new tables (`vendor_accounts`, `vendor_assignments`, `vendor_audit`, `vendor_challenges`, `vendor_phone_attempts`) initialize additively on first use. Vendor sessions live privately outside the document root. The installer performs no database, provider, messaging or configuration operations. It installs eleven application files, checks forty-one unchanged dependencies against exact reviewed LF/CRLF hashes and refreshes five existing release manifests. It preserves unknown edits, prior deployment journals, backup ownership/modes and all prior backups. Interrupted installation resumes only from its exact journal and backed-up bytes. Stripe LIVE configuration blocks deployment.

Download `tools/install-re-vendor-accounts-20261005-r1.py` to `/home/sitesee/`, then run:

```sh
python3 -B /home/sitesee/install-re-vendor-accounts-20261005-r1.py --deploy
```

Installer SHA-256: `816fabc8e30c3f7136ede46c387a530b213399dbcf13affd99b5cea597913b13` (143893 bytes).

After installation, create the TEST vendor at `https://re.sitesee.ai/staff-vendors.php`, assign one unfinished eligible TEST job from Staff Bookings, then use `https://re.sitesee.ai/vendor.php` to finish that job. Confirm the displayed additional fee/commission, Production state and verified final collection. No accounts or assignments are automatically created by deployment. Do not reuse protected historical orders `5D99D336572661A00885`, `8D20B4EBFCD0BADC4DE5` or booking `D32FFC7458` for destructive scenarios.

## Four review passes

1. **Source and scope:** inspected the shared staff login, free-text photographer review and fixed-bill boundaries. Added explicit grants rather than treating photographer text as identity. Canonical pricing, phone provider adapters and original payment/Production functions are preserved. Both historical closeout and onsite installer hashes reproduce exactly through the new source-chain step.
2. **Authentication and authorization:** isolated primitive tests cover phone/SID binding, opaque unknown/disabled responses, throttling, expiry, wrong-code limits, single use, replacement, revocation during provider verification, uncertain responses, separate cookie/storage, idle/absolute session expiry and phone-change revocation. PASS.
3. **Workflow and presentation:** real HTTPS browser checks cover manager enrollment, a first vendor with no assignments, assigned-only job access, manager/customer role isolation, direct forbidden actions, CSRF, reassignment and same-vendor regrant, current schedule overlay, contact/capture instructions, server fees and commission, named verbal closeout, duplicate-charge protection, Production handoff and disabled sessions. Original booking/calendar/CRM history remains identical. Existing staff suite retains all nineteen baseline form contracts and three external-agent cases. Original closeout suite passes the full service catalogs, declines/recovery, paid release and refund gates. Responsive widths 320/390/736/1200 pass; the mobile vendor closeout was visually inspected. All providers were synthetic.
4. **Packaging and recovery:** eleven new installer scenarios pass, including interruption at every application/manifest write, resume/rerun, CRLF inputs, exact dependencies, unknown and concurrent edits, tampered journal/backup, symlinks, prior incomplete update and LIVE rejection. Prior onsite installer tests and historical staff source checks pass. No app/provider configuration is changed.

An independent agent reviewed the access and collection boundaries and ran the authentication tests. It found operational omissions in the first draft (rescheduled list time, first-use schema, video duration/quantity and onsite contact/capture instructions); all were corrected before the final package. Final independent package audit: **PASS / no blocker**. The reviewer independently reconstructed the exact installer, checked all eleven outputs and five pre-update sources, verified all forty-one dependencies and both historical source chains, and ran ten installer scenarios plus the vendor auth, catalog and closeout PHP suites. Confirmed package SHA-256 `816fabc8e30c3f7136ede46c387a530b213399dbcf13affd99b5cea597913b13`. Final-commit Customer Portal Checks and Calendar Notice Checks pass; see the repository checkpoint below.


## Repository checkpoint

Implementation commit: `0d732cf4dca2ac3d8511a114b9bec3b2276df846`. Its full Customer Portal Checks passed for push run `37396802853` and PR run `37396808129`; Calendar Notice Checks passed run `37396807882`. The final cache-version change is commit `1f5247847a45722ba9ed185ff6800c9854dccb9b`. Its focused vendor HTTPS flow and all eleven installer tests pass locally, and the independent reviewer reconfirmed the final package hash after that one-line application change. Final-commit Customer Portal Checks: **PASS**, PR run `37397048266`, job `112055379311` (https://github.com/SiteSeeAI/SiteSee-Real-Estate/actions/runs/37397048266). This includes all private primitive/installer/reproducibility checks and six HTTPS/preview browser suites on PHP 8.2. Calendar Notice Checks: **PASS**, run `37397048141`.

The inherited Server Form CI failure remains in the unchanged legacy test-recipient fixture: run `37396809189`, job `112054626699`, `tests/booking-communication.test.php:76` invokes the preserved recipient guard at `_private/server/booking-communication.php:57` with an obsolete test recipient. This is the same previously documented baseline failure; this release changes neither that test nor its guard. Do not describe the entire repository as green.
