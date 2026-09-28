# Appointment Management — 2026 09 28

Customer cancellation and rescheduling remain the priority. Customer portal work is deferred until the server and browser checks below pass.

This release is prepared for `feat/calendar-confirmation-20260925`, draft PR #38, starting from verified commit `044fa001af4d4775f0e88ac3d585103d052929d7`. Main is not merged. No branch is removed. There is no direct server access from this workspace.

## One Upload And One Command

1. Download `tools/install-appointment-management.py` from this release. In cPanel File Manager, upload it directly into `/home/sitesee`, outside `public_html`. Confirm the filename is exactly `install-appointment-management.py`, without an added number or brackets.
2. Open WHM Terminal as root and run:

   ```sh
   python3 /home/sitesee/install-appointment-management.py
   ```

3. Keep the complete **FINAL RESULTS**, the printed backup path, and any permission recovery record. Paste the results into the implementation thread. If it stops, do not upload individual application files or run older installers over it. The same installer supports recovery and unchanged reruns.

The installer contains all private PHP files, the public `manage-appointment.php` entry point, and a five-minute reconciliation job. There is no ZIP to extract, package to install, key to enter, or separate cron command to paste. Existing private and public roots remain `/home/sitesee/.sitesee-real-estate` and `/home/sitesee/public_html/re`.

Optional local inspection only:

```sh
python3 /home/sitesee/install-appointment-management.py --check
```

`--check` makes no file, permission, database or provider changes. A normal installation performs read-only Microsoft calendar, sender mailbox and Zoho CRM checks. It does not repeat an event creation probe, send email, edit an appointment, collect payment or rewrite existing credentials/settings. Additive lifecycle tables are created by the next application request; existing booking rows and original invitation records are retained.

## First Server Check

Refresh [Staff Bookings](https://re.sitesee.ai/staff-bookings.php) and sign in. A confirmed TEST booking for `cro@sitesee.ai` now has **Manage Appointment** controls.

D32FFC7458 is a protected reference for this deployment. Its verified evidence remains: Microsoft appointment confirmed, original invitation sent, saved copy `sent_observed`, recipient evidence `recipient_copy_observed`, and Zoho email history associated. Do not resend, move or cancel it for this test. RSVP acceptance remains separately unverified. The installer does not operate on it.

Use a fresh paid TEST booking for the following controlled check. It must have its own booking reference and test deposit, a saved staff review, a Microsoft confirmation, and a verified CRM contact. Send its original TEST invitation once using the established workflow. New original invitations include the private management link. Staff can also use **Create / Replace Private Management Link**, copy the displayed link, and open it in another browser tab. Creating a link does not send email or change the appointment. Replacing a link invalidates earlier links and their current sessions.

## One End-To-End Check

1. In the fresh booking's customer management page, select a starting date and **Check Available Alternatives**. Two-hour arrival windows are shown in Central Time. The full reviewed shoot duration must fit the calendar. A blocked day can produce alternatives on following days. If none fit, choose a later starting date.
2. Select a different window, tick the confirmation checkbox, and confirm. Check that the customer page and staff page show the same new window. Microsoft should contain the same private event at its new shoot interval, with no duplicate event. The old reservation should stop blocking availability.
3. Check the change email in `cro@sitesee.ai`. It must update the existing customer calendar entry using the same UID and a larger sequence number. Confirm the displayed arrival window and check Zoho email history. Provider acceptance, actual sent-copy evidence, recipient-copy evidence, and RSVP are separate facts.
4. If the notice is still being verified, use **Recover Notice & Zoho History** on the staff page. Recovery does not resend it. If its state is `prepared`, **Send Saved Change Notice** makes its first send attempt. Missing verified contact linkage is resolved with the existing readiness/contact-selection controls. If Zoho reports an existing matching email, recovery either links its exact Message-ID or asks staff to verify a displayed candidate.
5. Once the first notice has a verified sent copy, cancel the same fresh appointment from the customer page. Confirm that the page shows **Cancelled**, the private Microsoft event is absent, and its time is available again. Verify the cancellation email removes/cancels the existing customer calendar entry and appears in Zoho history. Refreshing or repeating the request must not create another event, delete another event, send a duplicate notice or create a payment.
6. Compare pricing, paid deposit, remaining-balance calculation, Stripe identifiers, original request and consent before and after. They must be unchanged. The cancelled appointment retains its payment history; no refund or fee decision is made by this feature.

Installation success alone does not complete this check. Actual Microsoft version enforcement, updates/deletion, customer-calendar rendering, notice receipt, Zoho association and the scheduled job still require operator evidence. The source tests use simulated provider responses.

## Manual Calendar Changes

The installer adds `/etc/cron.d/sitesee-booking-reconcile`. Every five minutes it runs as `sitesee`, with a separate non-overlapping worker lock. It reads existing events and adjusts local lifecycle evidence. It never sends mail, writes a provider event, creates a replacement or calls Stripe. Staff can run the same read-only check for one booking using **Reconcile Calendar**.

| Observation | Application behavior | Staff next step |
| --- | --- | --- |
| Microsoft event moved within its existing calendar | The actual shoot interval becomes the local hold; the stale interval is released. The customer-agreed arrival window stays unchanged and the booking is flagged. | Agree a new two-hour window, then **Adopt Calendar Move**, or choose a fitting replacement through the staff controls. Send the saved change notice. |
| Microsoft event missing | The first authoritative not-found read keeps the hold. A second successful check at least 60 seconds later must also find it absent at both calendar and mailbox scope. The stale hold is then released and the booking is flagged. | Confirm cancellation and send its notice, or restore the original event for further rescheduling. The application does not silently invent a replacement identity. |
| Event found in another Microsoft calendar | It is not treated as deleted. The hold remains for staff review. | Return the same event to its assigned calendar, then reconcile. |
| Provider outage, denied access, invalid page or uncertain create | No absence/free-window conclusion is drawn. Existing holds remain. | Restore the connection and reconcile; do not delete booking history. |
| Legacy Zoho event manually moved | Its saved Zoho identity is retained; a verified moved interval replaces the stale local hold. | Agree a window and use **Adopt Calendar Move**. Duration, provider conflicts and other local holds are checked. |
| Legacy Zoho event deleted with a structured 404 | It is not automatically equated with a verified deletion. | After verifying deletion in the original Zoho calendar, use **Verify Deleted Legacy Zoho Event → Release Verified Stale Reservation**. The application requires a working complete calendar read and the exact event's not-found result. Then confirm cancellation and send its notice. |

The current legacy Zoho writer was granted reading/creation access. This release does not expand OAuth scopes or move legacy appointments to Microsoft. Direct automatic Zoho update/delete is therefore unavailable with those existing credentials. Staff must perform those legacy provider changes in Zoho, then reconcile here. This is an explicit limitation, not a claim that legacy provider writes were tested.

The worker processes up to 20 eligible bookings per run, oldest checks first, and stops taking more work after its time budget. Its private log is `/home/sitesee/.sitesee-real-estate/lifecycle-reconcile.log`. To verify it is running after a scheduled interval, use:

```sh
tail -n 5 /home/sitesee/.sitesee-real-estate/lifecycle-reconcile.log
```

A `review required` count is a diagnostic, not success evidence. Use the affected booking's reconciliation controls. A stopped cron service or a provider outage requires correction; installing a cron file does not prove execution.

## Interrupted Appointment Changes

Every provider edit has a durable operation record before the call. A reschedule reserves the old and proposed shoot intervals until readback verifies the result. The same provider event ID is used for PATCH/DELETE; no create endpoint is available to this lifecycle writer. Microsoft operations include the fresh event version in `If-Match`.

**Recover Change** reads the saved event without repeating a calendar write. It finishes a verified move/cancellation and prepares the corresponding notice in the same local transaction. A later competing event leaves the change unresolved until the conflict is corrected. The scheduled job also attempts this read-only recovery. It never sends the prepared notice.

A known provider rejection retains the existing appointment and records the rejected attempt. For an unacknowledged attempt whose original provider version remains unchanged, staff may use **Resolve Unapplied Change** after two minutes. It must prove the original interval and exact version are still unchanged before releasing only the proposed hold. If that proof is unavailable, the operation remains held for review.

A mail attempt is also durable before submission. Unknown mail results never reset to `prepared`. Recovery checks the saved provider message ID; a lost draft ID still needs mailbox review and is not permission to resend. CRM recovery operates on the actual sent Message-ID and cannot turn a sent notice into an unsent one.

## Security And Financial Boundaries

Management access uses random 256-bit, booking-specific secrets stored as hashes. The secret travels in the link fragment, is exchanged by POST, and is removed from the visible URL. It is not sent as a URL query or Referer. Links expire in seven days; management sessions expire in 30 minutes and are checked against the current link hash on every request. POSTs require CSRF and origin checks, are rate-limited, and bind the submitted reference to the authenticated booking. GETs never cancel or reschedule appointments.

A customer must explicitly confirm each change. A started arrival window directs the customer to staff. Replacement windows reuse the existing standard/approved-rush notice rules; no new rush fee or refund is calculated. Test gating and the authorized `cro@sitesee.ai` recipient remain enforced. Changes preserve reviewed duration, photographer, prices, deposit, payment identifiers, original request, consent and existing rush decision. Staff verifies customer agreement before adopting a manual move or cancelling on the customer's behalf.

Customer notices use the established `sales@re.sitesee.ai` sender and verified Zoho contact. The private Microsoft event retains no attendees, preventing a second native Microsoft invitation. ICS updates retain `sitesee-arrival-test-REFERENCE@re.sitesee.ai`, increase sequence numbers, and use REQUEST or CANCEL appropriately. Original invitation evidence remains separate. No new CRM deal/event field mapping is invented; the existing contact's email history records the actual change notices.

## Installation Recovery

Retain `appointment-management-install.json`, `appointment-management-release.json` and the printed `deployment-backups/appointment-management-*` directory. Also keep all earlier Microsoft scheduling, staff workflow, checkout, cleanup and permission recovery records, including the previously verified backups ending `microsoft-scheduling-7ug3cjsf` and `booking-workflow-2cn2dftl`.

Before activation, an interrupted installation is recovered by rerunning this same installer. Backups and current hashes are checked before restoration. Unknown edits, symlinks, unsafe owners and configuration differences stop the process for review. Safe permission reductions are collected together. Public/cron entries are restored or removed as appropriate; additive PHP helpers are retained so an already-executing request can finish.

Once `booking-lifecycle.json` exists, the installer verifies the active release and never restores old scheduling code: old code would ignore released reservations and lifecycle operations. Repair must move forward from the active release. Do not run the older calendar/workflow installer or restore the database to undo an appointment change. Do not restore old payment code over the branded Stripe checkout.

## Validation Record

Local validation passed: 21 PHP suites (including 228 lifecycle assertions), 91 Node tests, and 132 Python tests (127 passed; five environment-dependent cases skipped). PHP syntax passed for 86 files; installer syntax is compatible with Python 3.6. The rendered fragment-exchange script also passed a JavaScript execution check. A browser binary was unavailable locally, so this does not claim a desktop/mobile browser rendering check.

Coverage includes lifecycle orchestration, versioned writes, lost responses, duplicate actions, stable calendar identities/ICS sequences, legacy identity preservation, unchanged finance/consent, local availability, token rotation/expiry, customer authentication, CSRF/origin/rate limits, independent mail/CRM recovery, installer interruption at each write, protected activation and unchanged reruns. Historical installers rebuild from frozen pre-lifecycle sources so the previous recovery packages remain reproducible.

Provider responses are simulated. No actual event, invitation, message, payment or credential has been changed in this workspace. Browser rendering and real provider behavior remain part of the controlled server check. Customer portal development stays deferred until that check is complete.

API contracts reviewed for this implementation:

- https://learn.microsoft.com/en-us/graph/api/event-update?view=graph-rest-1.0
- https://learn.microsoft.com/en-us/graph/api/event-delete?view=graph-rest-1.0
- https://learn.microsoft.com/en-us/graph/outlook-immutable-id
- https://www.zoho.com/calendar/help/api/put-update-event.html
- https://www.zoho.com/calendar/help/api/delete-event.html
