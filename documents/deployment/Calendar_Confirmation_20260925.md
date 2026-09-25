# Calendar confirmation and arrival-window invitations — 2026 09 25

This is the next **test stage** after the successful availability deployment. Live Stripe charges remain disabled. Uploading the files does not enable event creation or invitations.

## Verified starting point

PR #37 remains an open draft at e4ccb9c74353daafd8b0ad9c42ddc13c818273f4. Current main, 354ec6b4642b2fe5dabcf868c72561b3380970b3, already contains its deployed application files but omitted the calendar regression tests and setup tools. This change starts from current main, restores that regression coverage, and retains the existing pricing-market script and other main-branch changes. It does not merge or close #37.

The user verified both pricing forms, the full-day block, later-day suggestions, the partial busy interval, increased shoot duration, rapid changes, 72/12-hour notice, and Silver's duration-review message. The user then removed the integration test events. Do not rerun the older fixture-dependent probe after those events have been removed.

## Resulting workflow

1. The existing 50% base-price test deposit, staff duration review, explicit rush decision, $59 balance-only rush fee, decline and replacement-window flow remain intact.
2. Staff selects **Confirm Test Appointment** for a paid, reviewed booking assigned to David. The booking email must match the privately configured test recipient. The agreed arrival window is immutable in this action.
3. The server reads the photography calendar without using the availability cache. It fits the entire reviewed shoot duration within a start time inside the agreed arrival window. Notice is validated against the original customer request or replacement-window request, so staff review does not restart the 72/12-hour clock. A window that has already started is rejected.
4. A private Zoho event blocks the actual shoot interval. It contains no attendees, notifications, alarms, property access codes or payment links. A fresh event-detail read and conflict check must verify the result before the booking is marked confirmed.
5. Staff separately verifies the displayed recipient and selects **Send Test Calendar Invitation**. A fresh calendar check runs again. An email invitation through the existing SMTP/mail route covers the original two-hour arrival window. Internal planned arrival and access codes stay private. Calendar replies reach the configured sender mailbox; automated RSVP synchronization is not part of this release.
6. Repeated confirmation and send submissions reuse their durable state. A timeout or crash after an external attempt cannot trigger automatic recreation or resend. **Recheck Calendar Result** reads and reconciles the exact marked event. If no unique matching event is visible, staff must inspect Zoho; the local claim remains blocked.

## Uploads

The ZIP's `private/` directory is a destination label. Copy its contents into `/home/sitesee/.sitesee-real-estate/`, preserving the subdirectories. Do not create an additional `private` directory. No public document-root files or pricing assets change in this stage.

Upload in this order if cPanel requires individual files:

| ZIP-relative file | Private destination |
| --- | --- |
| private/server/booking-confirmation.php | server/booking-confirmation.php |
| private/server/booking-invitation.php | server/booking-invitation.php |
| private/server/booking-schedule.php | server/booking-schedule.php |
| private/server/booking-store.php | server/booking-store.php |
| private/server/booking-pay.php | server/booking-pay.php |
| private/server/booking-staff.php | server/booking-staff.php |
| private/tools/setup-zoho-calendar.py | tools/setup-zoho-calendar.py |
| private/tools/setup-zoho-confirmation.py | tools/setup-zoho-confirmation.py |
| private/tools/check-calendar-confirmation.php | tools/check-calendar-confirmation.php |
| private/calendar-confirmation-release.json | calendar-confirmation-release.json |

Keep the existing active `zoho-calendar.json`, payment settings, mail settings, staff password, pricing gate, private database and all pricing assets. The old setup helper is included only as a support module; do not run it to restart the existing read-only setup. No `.env`, credentials, SQLite database or runtime cache is included in the ZIP.

## Server verification and activation

Use one step at a time in WHM Terminal. Neither probe requires the old calendar fixtures.

**After upload**, verify the release bytes, PHP syntax, PHP extensions and existing calendar connection:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php /home/sitesee/.sitesee-real-estate/tools/check-calendar-confirmation.php
```

Expected: upload and existing connection PASS; writer not configured; confirmation and invitations disabled. Report any STOP before proceeding.

**After that probe passes**, prepare the separate writer:

```bash
python3 /home/sitesee/.sitesee-real-estate/tools/setup-zoho-confirmation.py
```

The helper reuses the existing client ID and secret without displaying either, and leaves the active reader's refresh token and enabled flag byte-for-byte unchanged. It asks for David's authorized test mailbox, then for a freshly generated authorization code using the same Zoho Self Client and owning account. Enter the code only into the hidden Terminal prompt. Do not paste secrets into chat or command arguments.

Requested scopes: `ZohoCalendar.calendar.READ,ZohoCalendar.event.READ,ZohoCalendar.event.CREATE`. No calendar-update, event-update, event-delete, CRM or additional-seat access is requested. New credentials are saved as `zoho-confirmation.json`, sitesee-owned mode 0600, with both new controls disabled. A failed verification retains the new writer credentials for a retry and leaves the reader untouched.

Repeat the PHP probe. It must verify the writer identity and read access while both new flags remain disabled. Successful read checks alone do not prove event-create permission.

**After both probes pass**, enable only the staff calendar test control:

```bash
python3 /home/sitesee/.sitesee-real-estate/tools/setup-zoho-confirmation.py --enable-calendar
```

Use an existing eligible paid test booking whose email exactly matches the configured mailbox and whose future arrival window meets the request notice rule. If no such booking exists, prepare a new Stripe **test** booking through the established process; never enable live charges. Complete the existing staff review with David and the actual shoot duration. Select **Confirm Test Appointment** once. Verify one private event in SiteSee Photography, correct Chicago start/end, and no invitation. A second click must not create another event. Do not delete this new event before testing the invitation; the send action verifies it still exists unchanged.

**After calendar creation is verified**, enable invitation testing:

```bash
python3 /home/sitesee/.sitesee-real-estate/tools/setup-zoho-confirmation.py --enable-invitations
```

Verify the displayed mailbox and send the test invitation from that booking. Confirm receipt and calendar rendering: the invite must cover the agreed two-hour arrival window, not the private actual-duration interval. Check that a repeated click does not send a second message. Mail-server acceptance is not a claim of mailbox receipt or calendar acceptance.

## Rollback and limits

Disable both new controls without changing availability:

```bash
python3 /home/sitesee/.sitesee-real-estate/tools/setup-zoho-confirmation.py --disable
```

This disables future actions. It does not remove an event or retract an invitation already sent. Keep confirmation records so duplicate protection survives. Test event/booking cleanup must be coordinated after the invitation test; deleting a calendar event alone leaves its local reservation in place. Do not manually edit or cancel confirmed test events during the test sequence. Automated confirmed-booking reschedules, cancellations, invitation updates, refunds, final-balance collection and RSVP synchronization remain separate work.

Application confirmations are serialized, and uncertain local claims block their intervals. A second fresh calendar check catches conflicts that appear during creation. Zoho does not provide a transactional free/busy reservation with event creation; external edits after verification still need staff coordination.

## Validation

The focused test suite covers unpaid/unreviewed/disabled requests, the configured recipient, David-only assignment, full-duration fitting, full-day conflicts, malformed reads, existing and racing conflicts, repeated clicks, lost creation replies, read-only reconciliation, moved-event invitation rejection, uncertain mail delivery, UTF-8 iCalendar folding, and payment/rush preservation. Existing pricing, access, booking, availability and browser tests remain included. Setup tests verify separate credentials, 0600 permissions, resumability, independent switches and preservation of the active reader. Server CI exits on the first failed PHP suite.

API and format references: [Zoho event creation](https://www.zoho.com/calendar/help/api/post-create-event.html), [event details](https://www.zoho.com/calendar/help/api/get-event-details.html), [event listing](https://www.zoho.com/calendar/help/api/get-events-list.html), [RFC 5545](https://www.rfc-editor.org/rfc/rfc5545), [RFC 6047](https://www.rfc-editor.org/rfc/rfc6047).
