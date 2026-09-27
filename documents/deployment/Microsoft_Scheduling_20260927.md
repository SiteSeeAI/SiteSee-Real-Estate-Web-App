# Microsoft TEST scheduling — 2026-09-27

## What is verified

The user's actual consolidated connection output reports PASS, six permission
repairs, and verified creation, readback and removal of the temporary private
TEST event. A prior completed test may have been reused. Microsoft scheduling
was explicitly NOT enabled by that run. Keep:

`/home/sitesee/.sitesee-real-estate/microsoft-calendar-recovery-7n7lklo6/permission-repairs.json`

This scheduling release has local automated coverage. The user has now supplied actual installer FINAL RESULTS: Microsoft scheduling
ENABLED IN TEST MODE, active release hashes PASS, existing Zoho reservations
preserved, and no booking row, payment, event, invitation, email, credential or
Zoho setting changed. Backup: `/home/sitesee/.sitesee-real-estate/deployment-backups/microsoft-scheduling-7ug3cjsf`.
Permission record: `/home/sitesee/.sitesee-real-estate/microsoft-calendar-recovery-qeejp11x/permission-repairs.json`.
The real booking/invitation flow is still being verified; installation alone does
not establish successful customer invitation delivery or duplicate-free rendering. Existing branded Stripe TEST payment validation remains as
recorded in the checkout deployment document; it is not reset by this work.

## One upload and one command

Upload `tools/install-microsoft-scheduling.py` as:

`/home/sitesee/install-microsoft-scheduling.py`

Run in WHM Terminal as root:

```sh
python3 /home/sitesee/install-microsoft-scheduling.py
```

No packages, keys, tenant IDs, PowerShell setup or additional files are needed.
`--check` performs a local inspection without file/permission changes or provider
calls. An unchanged rerun verifies the installation and does not recreate events.
Review the actual FINAL RESULTS before treating this scheduling installation as
successful. If it stops, retain the combined diagnostics and recovery files.

## Installer behavior

- Reuses the complete audited r3 local inventory and permission-repair helpers.
  Independent local blockers are collected before any installation. Only known
  permission reductions are automatic; owners, secrets and unknown edits are
  preserved. No recursive chmod or credential rewrite is used.
- Verifies both the active calendar and branded checkout manifests, the completed
  Microsoft probe journal, connection identity, old/new source hashes, PHP syntax,
  disk capacity, existing Zoho configuration files and application-user access.
  The SQLite ledger is opened read-only to inspect reservation structure/identity;
  no booking or payment rows are written.
- Acquires the shared confirmation lock, rechecks the exact default Microsoft
  calendar, and completes a fresh paginated 15-day availability read. OAuth token
  acquisition is required; there is no Graph event mutation or Stripe call.
- Backs up changed files, journals the intended writes, installs dependencies and
  controllers, and updates calendar and Microsoft scheduling manifests.
  `microsoft-scheduling.json` is written last and is the TEST activation switch.
  The old connection configuration remains unchanged, including its disabled
  connection-only scheduling/invitation flags; the new switch controls scheduling.
- Before activation, a failed write restores prior files. After a process
  interruption, rerunning this installer restores an incomplete inactive release
  before repeating preflight. Restored controllers precede removal of additive
  dependencies. Concurrent unrelated edits stop recovery for review.
- Once the activation switch exists, recovery verifies the installed release and
  never restores older controllers. A Microsoft event might already depend on
  the new code. A failure after the switch is reported explicitly as potentially
  activated; rerun the same installer to verify it.
- No event, customer invitation, email, payment or appointment is created during
  installation. Existing invitation sender, CRM association, account permissions,
  secret credentials, PHP-FPM settings, Stripe configuration and Zoho settings
  are unchanged. Main remains unmerged and the PR remains a draft.

## Scheduling behavior

New staff confirmations use the default `Calendar` of `sales@re.sitesee.ai`,
restricted to the same TEST recipient `cro@sitesee.ai`, paid TEST deposit, saved
staff review and David photographer gate. The private event blocks the full
reviewed shoot duration. It has no attendees, online meeting or reminders.
The customer arrival window remains two hours; a full shoot may end later but
must start before that window ends. Standard/rush notice rules remain unchanged.

New confirmations record `calendar_uid` as `microsoft:` followed by the pinned
calendar ID. This is explicit durable provider identity using the existing
column; it requires no schema rewrite. Existing Zoho identities remain unchanged
and continue through their original verification/recovery/invitation code.
No old event is copied or reinvited. Legacy diagnostic tools accept the reviewed
old and new application hashes and still reject an unrelated calendar identity.

Every new operation records its transaction ID, expected event and reservation
before POST. Microsoft requests use immutable event IDs. A duplicate confirmed
click is a no-op. Lost or uncertain responses retain the reservation and block a
second create; Recheck Calendar Result performs read-only reconciliation of the
same stored event/transaction. The event is verified on readback and in the fresh
calendar view, including its exact interval, private status and no-attendee shape.
External conflicts prevent finalization. No automatic event deletion is exposed.

Customer availability and staff alternatives combine complete Microsoft busy
intervals with ALL stored reservations, including legacy Zoho and uncertain
creation holds. Malformed, incomplete or failed provider reads never become
available windows. Private event titles and customer details are not returned
by the customer availability endpoint.

Staff review includes **Check Available Alternatives**. A failed confirmation
with no existing calendar attempt also loads alternatives when the connection
is available. Suggestions are not reservations. A staff member must record the
customer's agreement before selecting a new window. Selection rechecks current
availability, rejects a stale booking form, preserves deposits/payment identifiers
and fees, updates both window endpoints, and resets staff review. It supports
unconfirmed requests only. A confirmed or uncertain appointment cannot be changed
with this control. New proposals use notice measured from the new request time;
confirmation of an unchanged request retains its original notice anchor.

Invitation delivery remains the established separate staff action through the
Microsoft mail route, with verified CRM contact linkage and Zoho CRM association
of the sent message. Calendar verification now uses the booking's recorded
provider. The customer receives the existing arrival-window ICS, including its
stable UID and duplicate-send safeguards. The internal Microsoft event never
also sends a native meeting invitation. Property access codes stay private.

## Controlled server verification after installation

1. Review the installer's FINAL RESULTS and diagnostics first.
2. D32FFC7458 was previously recorded as TEST-paid and awaiting staff review with
   no invitation. If it is still unconfirmed, use that booking for this Microsoft
   test; otherwise select a new authorized TEST booking with a new reference.
   Do not resend E4E51A0481, E6E183EF8E or 3AC663B079 invitations.
3. Open staff review and save the photographer/duration review first. The Calendar
   Confirmation section and provider label appear only after that save. Verify
   the Microsoft label, check alternatives if needed, then explicitly confirm
   the TEST appointment. Verify one matching
   private block in the `sales@re.sitesee.ai` default calendar, the reviewed
   duration and the customer arrival window. Refresh/repeat confirmation to
   verify there is no duplicate event.
4. Verify/link the CRM contact, then explicitly send the one TEST invitation to
   `cro@sitesee.ai`. Check receipt, arrival-window rendering and CRM email history.
   The installer does not perform these person-directed actions.

## Next work and recovery boundaries

Confirmed-booking cancellation/rescheduling, synchronization of manual provider
edits/deletions, customer management links and service/order revisions are not
implemented by this release. In particular, E6E183EF8E's old stored reservation
is deliberately retained until controlled reconciliation; its missing Zoho event
is not permission to delete booking history or release its time automatically.
A moved/deleted Microsoft event blocks a stale invitation and remains a held
reservation for review. The next lifecycle release must retain the old hold
until a replacement/cancellation is verified, preserve ICS identities/sequences,
update customer notices and CRM history, and expose recovery without duplication.

Do not restore older confirmation/invitation code after a Microsoft appointment
exists. Do not restore an older payment controller over embedded Stripe sessions.
Retain all deployment/permission journals and the existing cleanup recovery
archive. Do not remove the previously retained cleanup candidates. Refunds,
additional charges, service changes and live-payment activation are not authorized
by this scheduling install.

## Local validation

- 89 Microsoft scheduling checks: explicit legacy holds, new private confirmation,
  immutable-ID transport restrictions, no attendee/mail/delete/PATCH operations,
  unchanged pricing/payment data, duplicate clicks, external edits, invitation
  reuse, alternatives, stale forms, review reset, timeout recovery and conflicts.
- 10 installer tests: all known permission issues together, simultaneous blockers,
  dry inventory, unknown edits, read-only database preservation, checksum-pinned
  payloads, unchanged rerun, every pre-activation write failure, interrupted
  process recovery and refusal to roll back after activation.
- All existing PHP suites pass, including checkout, booking, availability,
  legacy confirmation/invitation, communications, CRM-related paths and diagnostics.
  The full Python run completes 112 tests: 107 pass and five environment-dependent
  cases are skipped.
  The current local namespace cannot exercise another real UID/GID; the installer
  checks access under the actual SiteSee account on the server.
- 91 Node tests pass. PHP syntax and Python 3.6 grammar pass. Installer payloads
  rebuild byte-for-byte. Provider responses in automated tests are simulated.
  These results do not claim real deployment, browser verification or a real
  Microsoft booking/invitation has passed.

Official Microsoft contracts rechecked during implementation:

- https://learn.microsoft.com/en-us/graph/api/calendar-post-events?view=graph-rest-1.0
- https://learn.microsoft.com/en-us/graph/api/calendar-list-calendarview?view=graph-rest-1.0
- https://learn.microsoft.com/en-us/graph/outlook-immutable-id

## Booking-specific CRM link encountered during the real test

The user reached “Verify and link the correct CRM contact before sending” for
D32FFC7458. This guard is before draft creation and invitation submission; an
existing calendar entry does not establish that a customer invitation was sent.
The contact association is per booking. E4E51A0481's previously verified CRM
selection is reusable evidence for the same expressly authorized test recipient,
but D32FFC7458 needs its own local association.

`tools/link-microsoft-test-contact.php` provides a narrow one-run repair. Upload
to `/home/sitesee/link-microsoft-test-contact.php`, then run:

```sh
runuser -u sitesee -- /opt/cpanel/ea-php82/root/usr/bin/php /home/sitesee/link-microsoft-test-contact.php
```

It requires the expected paid/reviewed Microsoft booking and the prior sent
invitation for E4E51A0481, both addressed to cro@sitesee.ai. It reuses only that
booking's previously verified contact ID, validates the current CRM organization,
authorized user, complete exact-email candidate search and contact record, and
saves the target's local CRM link. It preserves conflicting links, refuses any
recorded invitation/communication attempt, and never creates a contact or sends
mail. Payment data, events, provider settings and the source booking stay intact.
It reports saved invitation state and communication count; an unchanged rerun
before sending remains safe. Any changed send state directs review, not resend.

32 focused checks passed with mocked CRM reads, including wrong organization,
changed contact email, missing prior selection, conflicting target links, payment
and calendar preservation, unchanged rerun and previously attempted invitations.
PHP syntax passed. Actual CRM repair remains unverified until server output.
The staff interface still needs contact lookup/linking integrated before broader
use so each booking does not require a terminal-only prerequisite.

## Consolidated staff workflow follow-up

The one-booking CRM repair passed for D32FFC7458, with Microsoft confirmation,
a verified contact link, no saved invitation attempt and zero communication
records in the latest operator output. The permanent inline contact and combined
recovery update is documented in [Booking_Workflow_20260927.md](Booking_Workflow_20260927.md).
Its installation and actual Microsoft invitation receipt/acceptance still need
operator verification. Use its installer for the newer staff page; preserve this
Microsoft scheduling release and its existing appointment identities.
