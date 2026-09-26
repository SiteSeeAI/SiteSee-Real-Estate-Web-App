# SiteSee Booking Mail And CRM Integration — 2026 09 26

Prepared against PR #38, branch `feat/calendar-confirmation-20260925`, verified parent `136483e4f6c2f534bca034db330898db61e6ef17`. This is a test-stage implementation. It is not deployed or enabled by a GitHub commit. Do not merge main as part of this update.

## What this release changes

Customer booking invitations use Microsoft Graph directly through `sales@re.sitesee.ai`. From, Reply-To and the iCalendar organizer match. Existing Graph credentials are read from the established private credential file; its sender and contents are never rewritten. Corporate mail, pricing-access mail, PHP-FPM, DNS and cPanel routing remain unchanged.

Before sending, staff must verify the CRM organization and select an existing contact with the booking's exact primary email address. Search results are paginated and compared exactly. A contact is never created automatically. The verified organization/contact/email binding is saved separately and the booking's existing `crm_contact_id` is populated.

A communication is claimed locally before creating its Microsoft draft. Its immutable Graph ID is saved before the send call. The submission response, observed sent copy, recipient-mailbox evidence and CRM association have separate fields. A timeout never authorizes a resend. CRM recovery uses the actual sent copy's Internet Message-ID and the same verified contact. Provider errors and credentials are not copied into messages or output.

Zoho history is inspected before insertion. Native mailbox synchronization and API-managed history are distinct modes, checked separately for the new sender and original sender. Matching history whose original Message-ID is not exposed requires staff review; it does not trigger a duplicate insertion. CRM stores the customer-facing body and message identity; the invitation MIME and calendar linkage remain in the private communication ledger. Property access codes and internal shoot times are excluded.

## Upload and install

Upload **SiteSee_Booking_Communications_20260926.zip** to `/home/sitesee/` in cPanel File Manager, outside every public document root. Extract it there. It creates `/home/sitesee/booking-communications-20260926/`.

Run in WHM Terminal as root:

```bash
python3 /home/sitesee/booking-communications-20260926/install-booking-communications.py --install
```

The installer checks every payload checksum, syntax, existing changed-file hashes and the preserved calendar/pricing baseline before writing. A mismatch stops installation; send that specific output instead of forcing replacement. It backs up changed files in the private deployment-backups directory, installs dependencies first, and keeps the existing calendar release checker manifest current. No database is replaced. New tracking tables are additive and are created when the staff page or CLI opens them.

The exact source-to-server map and old/new hashes are in `Booking_Communications_Manifest_20260926.json` (and `manifest.json` inside the ZIP). This package contains eight runtime/setup files, plus the installer and instructions. Do not upload repository `_private` as a nested directory inside the private application.

## Authorize CRM privately

Run:

```bash
python3 /home/sitesee/.sitesee-real-estate/tools/setup-booking-communications.py
```

Use a **separate US-region Zoho API Self Client**, authorized by the intended CRM administrator. Do not change or revoke either Calendar client's grants. Open [Zoho API Console](https://api-console.zoho.com/) in your browser. The script requests the client ID, secret and grant code without echo. Never put these values in chat or command arguments.

Requested scopes:

```text
ZohoCRM.org.READ,ZohoCRM.users.READ,ZohoCRM.modules.contacts.ALL,ZohoCRM.modules.emails.ALL,ZohoSearch.securesearch.READ
```

The Contacts ALL scope is the documented requirement for Associate Email; implementation exposes no contact-create or contact-update endpoint. The script reads the organization and current user, shows their identifiers, and asks you to confirm each. Use the intended email-history owner. Inspect CRM email integrations for **both** `sales@re.sitesee.ai` and `cro@sitesee.ai`: choose `native` for already-synchronized mailboxes, or `api` only after verifying they are not synchronized. In native mode, this integration never inserts competing email history.

Refresh credentials are checkpointed privately before organization checks, so a later failed check does not require another grant. Existing final configuration is never overwritten. The dedicated booking path starts disabled. No email or CRM record is created by setup.

## Consolidated verification and deliberate test

Define this helper in the same WHM Terminal session. It runs database commands as `sitesee` so SQLite sidecar files retain the correct owner:

```bash
booking_comms() {
  runuser -u sitesee -- /opt/cpanel/ea-php82/root/usr/bin/php /home/sitesee/.sitesee-real-estate/tools/booking-communications.php "$@"
}
booking_comms lookup E6E183EF8E
```

Read the returned name, email, company and contact ID. If no exact candidate appears, inspect CRM; do not create a duplicate or choose a similar email. If several records appear, select the intended record. Enter its ID when prompted by this command:

```bash
read -r -p 'Verified CRM contact ID: ' sitesee_booking_contact_id
booking_comms link E6E183EF8E "$sitesee_booking_contact_id"
```

Import the proven original invitation, record its recipient copy from Deleted Items, and associate its actual communication with the verified contact:

```bash
booking_comms import-original E6E183EF8E
booking_comms delivery original:E6E183EF8E
booking_comms crm original:E6E183EF8E
```

This uses only the exact original Internet Message-ID and preserves its actual `cro@sitesee.ai` sender. It does not reset invitation state, resend mail, move the message, recreate the event or replay payment. A folder match establishes where the message was observed, not why it moved there.

**The following command deliberately sends ONE plain test email from `sales@re.sitesee.ai` to `cro@sitesee.ai`. It has no iCalendar attachment and changes no appointment. Run it only once:**

```bash
booking_comms send-probe E6E183EF8E cro@sitesee.ai
```

Then collect the independent results together:

```bash
booking_comms recheck probe:E6E183EF8E
booking_comms delivery probe:E6E183EF8E
booking_comms crm probe:E6E183EF8E
booking_comms report E6E183EF8E
```

Microsoft's Sent Items copy or native CRM synchronization may lag. If necessary, repeat only `recheck`, `delivery`, or `crm`. The send command refuses a second attempt. No negative lookup is treated as proof that a send failed.

If CRM reports `existing_candidate_review` or `provider_duplicate`, inspect matching candidates:

```bash
booking_comms history probe:E6E183EF8E
```

Review the displayed candidate in Zoho CRM against the sender, recipient, unique subject, time and body. After confirming the actual corresponding email, use `accept-existing KEY CRM_MESSAGE_ID` with that returned ID. For the original invitation, use `original:E6E183EF8E` instead of the probe key. An unconfirmed or ambiguous candidate blocks insertion. `awaiting_native_sync` waits for existing synchronization; do not change to API mode simply to bypass it.

Once the probe report shows `sent_observed`, `recipient_copy_observed` and `associated`, enable the new invitation path:

```bash
booking_comms enable probe:E6E183EF8E
```

The existing Calendar invitation switch remains as configured. No invitation is sent by enabling this path.

## Fresh invitation acceptance test

Keep `E6E183EF8E` intact. Create one NEW test booking for `cro@sitesee.ai` through the existing scheduling flow, complete its Stripe TEST deposit, and record staff review. Agree a fresh available arrival window. Use `lookup NEW_REFERENCE` and `link NEW_REFERENCE CONTACT_ID` to verify its CRM contact. Confirm its calendar appointment once using the existing staff action, then click **Send Test Calendar Invitation** once.

Check the new staff communication section. Use **Recheck Saved Message** and **Recover CRM Association** if a sent-copy or CRM result is pending. These never resend. Use `delivery invitation:NEW_REFERENCE` to collect recipient evidence. Check the received invitation's From, Reply-To, organizer and two-hour window in Outlook; verify CRM email history and the existing SiteSee Photography event. Internal planned shoot time and access codes must remain private. Provider acceptance alone is not delivery or RSVP acceptance.

## Failure and rollback behavior

- `uncertain`: never reset the invitation or submit again. Recheck the stored provider ID. If draft creation timed out before its ID was saved, inspect the mailbox using the stored communication marker; this release intentionally has no automatic draft/re-send recovery for that case.
- `draft_blocked`: a saved draft failed identity validation; it was not submitted by this path. Inspect it without sending or deleting it blindly.
- `retry_pending`: recover CRM only. It reuses the original provider Message-ID, which Zoho documents as duplicate-protected for that record.
- `provider_duplicate`: the provider reports existing association; verify its history rather than inventing a CRM email ID.
- No cron job is installed. Recovery is explicit through the authenticated staff screen or private CLI.
- To stop new invitations, set only `booking-mail.json`'s `enabled` field to `false`, preserving owner `sitesee` and mode `0600`. Do not revert to the old mismatched sender to work around an error.
- Preserve both tracking tables and all existing bookings during rollback. File backups do not undo provider effects. Do not restore an older booking database after a message has been attempted.

## Validation and remaining live checks

Local tests exercise the actual invitation orchestration with mocked provider clients, including accepted send plus CRM failure and recovery. They cover sender/organizer consistency, duplicate claims, draft/send timeouts, sent-copy lag, exact contact matching, wrong organization/contact, incomplete history, native synchronization, provider duplicates and private setup/installer behavior. Validation passed: 475 PHP checks across five suites, 29 Python setup tests, syntax checks on 45 PHP files, and a complete eight-file installer rehearsal with backup and preservation checks. The existing booking and calendar regressions are included. These tests do not prove live Graph send permission or CRM entitlement.

Only the server-controlled probe can establish effective sender permission, recipient delivery and live CRM association. The fresh booking test additionally verifies Outlook rendering and the complete paid booking-to-calendar-to-invitation path. DKIM enablement, domain routing, RSVP synchronization, live Stripe activation and a future custom CRM are outside this release.

Provider references: [Microsoft MIME drafts](https://learn.microsoft.com/en-us/graph/api/user-post-messages?view=graph-rest-1.0), [immutable message IDs](https://learn.microsoft.com/en-us/graph/outlook-immutable-id), [send existing draft](https://learn.microsoft.com/en-us/graph/api/message-send?view=graph-rest-1.0), [Zoho Associate Email](https://www.zoho.com/crm/developer/docs/api/v8/associate-email.html), [email history](https://www.zoho.com/crm/developer/docs/api/v8/get-email-rel-list.html), [contact search](https://www.zoho.com/crm/developer/docs/api/v8/search-records.html), [organization identity](https://www.zoho.com/crm/developer/docs/api/v8/get-org-data.html).
