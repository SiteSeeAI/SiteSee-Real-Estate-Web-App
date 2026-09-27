# Microsoft TEST calendar connection — 2026-09-27

## Scope and actual status

The user verified mailbox-scoped Exchange authorization and actual server reads
of the default `sales@re.sitesee.ai` calendar. This release prepares a separate
Microsoft calendar connection and verifies a temporary event's creation,
readback and removal. It does **not** activate Microsoft booking scheduling.
Real event writes and this installer's actual server run remain **unverified**
until the user's FINAL RESULTS are reviewed.

Existing booking confirmation and invitation code is Zoho-specific. Changing
only a calendar setting would not migrate those identities. Existing Zoho
events, stored reservations, invitation UIDs and CRM communication evidence are
therefore preserved. No database is opened by this installer or its test.

## One upload and command

Download `tools/install-microsoft-calendar-connection.py`, upload it with cPanel
File Manager to `/home/sitesee/install-microsoft-calendar-connection.py`, then run
in WHM Terminal as root:

```bash
python3 /home/sitesee/install-microsoft-calendar-connection.py
```

No key or ID is requested. The installer reuses
`/home/sitesee/.sitesee-graph-mail.json`, checks the existing TEST mail identity,
pins application `125a86f5-2a29-4b1e-8b8a-26026e3fa59c` and the default calendar
ID from the successful preflight. It refuses a changed default/owner/app rather
than choosing a different calendar.

The command installs and runs the test. It creates one five-minute event titled
`SiteSee TEST calendar connection <random operation ID>` about one day ahead,
marked private and free, with no attendees, reminders or online meeting. It
reads the event back, verifies its identity and empty attendee list, deletes
that exact event and verifies absence using its immutable mailbox event ID.
Do not edit this temporary event while the command runs. No customer invitation
or cancellation message is requested.

Paste the FINAL RESULTS and any preceding STOP lines. Do not enable Microsoft
scheduling based on installation alone. `--check` is an optional local-only
inspection mode: no writes and no Microsoft requests. It is not an additional
required step before the normal command.

## Installed files and protections

All writes are under `/home/sitesee/.sitesee-real-estate`:

- `server/booking-microsoft-calendar.php`: separate calendar adapter; the existing
  mail transport's mailbox endpoints and callers are unchanged.
- `tools/verify-microsoft-calendar-connection.php`: CLI-only test and reconciliation.
- `microsoft-calendar.json`: nonsecret, TEST-only configuration with scheduling
  and invitations disabled.
- `microsoft-calendar-connection-release.json`: separate manifest of new code.
- `microsoft-calendar-probe.json`: durable private operation record, created only
  when the write test starts. No credential, token, customer or booking data.

The installer verifies both existing calendar and branded-checkout manifests,
checks current files against their recorded hashes, checks ownership/private
permissions, lints both PHP files and requires PHP 8.2+, cURL and fsync. It
accepts only absent new files or byte-identical existing connection files.
Unknown content is preserved and reported. Current manifests and controllers,
Stripe/webhook settings, mail/CRM/Zoho configurations, PHP-FPM and credentials
are not rewritten. Payments remain in TEST mode.

The adapter requests immutable event IDs and UTC response times. Its calendar
view reader consumes every page of expanded occurrences/exceptions/single
events, validates next links against the same mailbox/calendar, and refuses
partial, malformed, repeated or excessive pages. Nonfree, noncancelled events
block conservatively, including tentative and unknown statuses. It currently
produces a provider-only snapshot; activation must merge active local booking
reservations before offering or confirming availability. The connection test
does not expose titles or private calendar details in terminal output.

No code route for general appointment changes, attendee updates, sendMail or
meeting cancellation is added in this package. The only create request the new
adapter accepts is the exact no-attendee test payload. Deletion is called only
after the test runner verifies its recorded event's identity and safeguards.

## Recovery and rerun

Local installation uses the existing directory deployment lock, atomic writes
and a recovery record in
`deployment-backups/microsoft-calendar-connection-<timestamp>`. This is an
additive release: the record lists new files that were absent; no prior
application files need replacing. A local write failure removes completed new
writes and reports any rollback failure. It never restores an older Stripe
payment controller.

Once the Microsoft test starts, its files and journal are deliberately retained
on failure. Removing the journal after an uncertain request could create a
duplicate test on the next run. Preserve it.

- Before POST, the operation ID and `create_started` state are durably saved.
  The same ID is sent as Microsoft's `transactionId`.
- If a create response is lost, an unchanged rerun searches the original window
  for that transaction ID and reads the matching event. It never posts a second
  event after an ambiguous creation. Zero or multiple matches require review;
  a moved/edited unknown event is not guessed at or deleted.
- Explicit creation HTTP 400/401/403/404/422 responses allow a later retry with
  the same operation ID. Other uncertain responses stay blocked for reconciliation.
- Deletion intent is durably recorded after verified readback. A lost delete
  response is reconciled by the event's absence. Mailbox-wide immutable-ID lookup
  prevents a move out of the pinned calendar being called successful removal.
- Changed attendees, organizer, transaction, timing or safety flags stop cleanup.
- A completed journal is reused on unchanged reruns. The output prints the
  original verification time; this is not a claim of a new write test on rerun.

Rerun the same installer for recovery. Report unresolved diagnostics. Do not
create a fresh probe, delete unrelated events, change tenant permissions or
replace keys to bypass a stop. A 403 is reported with its operation; the earlier
read PASS alone does not establish write access.

## Local validation

PHP 8.3.6 passed 13 simulated-provider cases covering identity/gates, endpoint and
attendee guards, complete pagination, recurring occurrences, free/cancelled/busy
states, invalid times/statuses, normal create/read/delete/rerun, ambiguous create,
unresolved create, ambiguous delete, changed/moved events, explicit permission
denial and unsafe/tampered journals. File and directory fsync occur before
provider writes. Eleven Python installer cases passed with real PHP lint/runtime
checks, reproducible embedded payloads, unchanged rerun, preservation of every
existing fixture, rollback after each write, identity/config/permission guards,
missing PHP capability, recovery-state preservation and shared-lock enforcement.
Existing nonsecret code/manifests may retain normal cPanel `0644` upload modes;
group/world-writable files are refused. Credentials, connection files and journal
require private modes. Existing file permissions are not changed.

These are local checks with Microsoft responses simulated. They are not evidence
of actual event creation, calendar migration, cancellation or rescheduling.

## After the actual connection test

Review its FINAL RESULTS before the next consolidated scheduling deployment.
That release must preserve existing Zoho identities and custom invitation UIDs,
add provider-aware operations and local reservations, implement consistent
alternatives and controlled staff rescheduling/cancellation, and reconcile
external edits/deletions. Do not bulk-copy historical events or resend old
invitations. Keep the customer arrival window distinct from the private shoot
duration. CRM email association continues through the existing API flow.

Customer self-service and order/service amendments remain the subsequent work
described in `Microsoft_Calendar_Next_Phase_20260926.md`; this package changes
no refund, deposit, rush, upsell or live-payment policy.

## Official API references checked

- Calendar view and pagination: https://learn.microsoft.com/en-us/graph/api/calendar-list-calendarview?view=graph-rest-1.0
- Create event: https://learn.microsoft.com/en-us/graph/api/calendar-post-events?view=graph-rest-1.0
- Event fields and transaction ID: https://learn.microsoft.com/en-us/graph/api/resources/event?view=graph-rest-1.0
- Immutable IDs: https://learn.microsoft.com/en-us/graph/outlook-immutable-id
- Event removal and meeting notification behavior: https://learn.microsoft.com/en-us/graph/api/event-delete?view=graph-rest-1.0
