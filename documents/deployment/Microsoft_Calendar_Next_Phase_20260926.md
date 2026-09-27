# Microsoft Calendar and Booking Changes — 2026 09 26

## Agreed direction

Use the Microsoft 365 calendar belonging to `sales@re.sitesee.ai` for new
SiteSee Real Estate scheduling. Retain Zoho CRM contact links and the established
API association of sent email with the correct CRM record. Zoho Calendar is to
be retired from the active scheduling path only after a controlled TEST
transition. The user approved this direction on September 26, 2026.

The Zoho work remains useful: verified customer identity, email association,
communication history, schedule fitting, staff review and duplicate protection
carry forward. This is not a replacement of Stripe checkout or the CRM.

The user also requested assessment of client cancellation/modification links
and service changes, including staff upsells in the field. Detailed customer
automation and money-handling policies are proposals awaiting direction, not
approved changes to the existing terms. No live payments are authorized.

## Current state and boundaries

Branded TEST checkout validation is complete as recorded in
`Branded_Checkout_20260926.md`. E4E51A0481 has a paid deposit, a confirmed
September 30, 9–11 AM Central arrival window and an invitation reported sent
and received. D32FFC7458 was the mobile/decline/3D Secure payment test and was
designated to remain awaiting staff review.

The original E6E183EF8E still has a stored reservation despite the reported
manual deletion of its Zoho event. Do not erase it, replay its invitation, or
copy all old appointments into Microsoft. Inventory and reconcile each existing
record, retaining its history and original invitation identity. Old invitations
cannot be assumed to become Microsoft-native meeting objects by creating new
events with the same title or reference.

The current Microsoft connection uses application credentials and permits mail
operations only in its application transport. The new calendar adapter must be
separate; do not broaden the global sendmail bridge or weaken its route guards.
The existing application may need narrowly scoped calendar authorization;
working mail delivery is not proof of calendar permissions. No new credentials,
application permissions, licensed CRM users, mailbox owners or global sender
settings have been changed or assumed necessary by this assessment.

Zoho's current application transport permits contact lookup and email
association, not CRM appointment creation. A linked CRM appointment record is
an additional capability requiring an explicit adapter and verification of the
appropriate scopes and fields. Native Zoho mailbox synchronization remains off.

## First morning step: one read-only Microsoft check

Upload `tools/check-microsoft-calendar.py` to `/home/sitesee/`, then run:

```bash
python3 /home/sitesee/check-microsoft-calendar.py
```

It reuses `/home/sitesee/.sitesee-graph-mail.json`, without displaying secrets,
and reads the current TEST booking-mail configuration. It identifies the
default calendar, displays the mailbox's calendar inventory and tests a bounded
default-calendar event read. Event titles, bodies, attendees and locations are
not requested. Calendar names, owners and calendar IDs are displayed for review.
The event read requests only one ID to check access; it is not an availability
report and does not claim to inventory all events.

The only POST obtains an OAuth access token. Microsoft resource requests are
GET-only, restricted to this mailbox and three calendar read endpoints. The
tool refuses redirects and foreign-host or other-mailbox continuations, checks
private-file permissions, limits response sizes and pagination, and prints only
fixed failure descriptions. It does not open the booking database, write files,
send email, create events, modify permissions or activate migration.

A PASS establishes read access only. The `canEdit` flag is reported, but effective
application write permission is explicitly untested. A 403 indicates permission
or mailbox-access review is needed; it must not be treated as an empty calendar.
If the returned owner differs from the requested address, verify the mailbox's
primary address/alias relationship before choosing the production identity.
Review the actual output before preparing any permission or installation step.

Ten local tests pass for GET-only resource requests, redacted authentication
errors, access-denied behavior, valid and unsafe pagination, private-file
checks, redirects and explicit separation of read success from write access.
Provider responses were mocked. No actual Microsoft calendar access has yet
been verified by this diagnostic.

## Calendar implementation and transition

1. Verify the mailbox/calendar and appropriate application access. Keep Microsoft
   scheduling disabled until TEST creation, change, cancellation, conflict and
   notification checks pass. Review mailbox-scoped access rather than granting
   unrelated tenant-wide access by default.
2. Add provider-aware event identities and a durable operation history. Preserve
   existing Zoho records with their provider identity. Use stable retry keys,
   immutable Microsoft IDs where supported, and version checks. A timeout remains
   an uncertain operation requiring reconciliation, not permission to duplicate
   an event or release its time.
3. Use one availability calculation for customer alternatives and final staff
   confirmation: fresh Microsoft busy intervals plus active local reservations,
   the reviewed duration and existing notice rules. Exclude the same booking's
   own event when checking a proposed move. Recheck before committing a choice.
4. Track Microsoft edits/deletions and reconcile the corresponding local record.
   Change notifications can prompt reads; periodic reconciliation covers missed
   notifications. Verify the specific event and calendar identity. A network
   error or empty range is not a cancellation. Record external edits for staff
   review when they conflict with customer agreement, duration or pricing.
5. Add staff cancellation/rescheduling controls and availability alternatives.
   Hold the old reservation until the move is verified; reserve a proposed new
   interval durably while the provider operation is unresolved. Release obsolete
   time after verified completion, retaining the historical record.
6. Update the linked CRM appointment/history through a recoverable, separate
   operation. A CRM outage must show a pending synchronization state, not cause
   another appointment or another invitation. Review CRM automation so it does
   not produce a duplicate customer invitation.

The two-hour customer arrival window and the private shoot-duration block must
remain distinct. A single Microsoft event cannot have different start/end
times for its organizer and attendees. Evaluate linked internal and customer
events/calendars, or retain the controlled invitation representation where
needed. Never place access codes in the customer event. The availability reader
must count the intended shoot block without counting a second copy of the
arrival-window invitation as another reservation.

Microsoft sends invitations automatically when attendees are added to a created
meeting. Preserve separate staff confirmation and invitation authorization:
internal confirmation must not silently add the customer as an attendee.
Do not run the existing custom invitation sender and a native meeting invite
for the same action. Preserve evidence and CRM association of the actual sent
message, and test update/cancellation behavior in the customer's mailbox.

## Client cancellation and modification link

Recommended first version: a branded **Manage My Booking** page with **Request
a Date Change**, **Request a Cancellation**, and **Request Service Changes**.
Requests are immediately acknowledged and visible to staff; requested changes
are clearly distinguished from completed calendar changes or refunds. The
customer can choose from current alternatives, with a final fresh check before
staff approval. Showing alternatives alone does not reserve them.

Use a separate revocable management token/session, not the booking reference as
a password or the existing payment token with its seven-day expiry. An email
scanner opening a link must never cancel a booking: GET displays the page;
authenticated, CSRF-protected POST with explicit confirmation requests a change.
Limit repeated attempts, redact tokens from logs and use email verification for
renewal or stronger identity checks as appropriate. The scheduling contact or
person opening the property is not automatically authorized to approve charges.

The current accepted copy says cancellation at least 24 hours before the
confirmed appointment refunds the deposit; within 24 hours the deposit becomes
a credit toward one rescheduled shoot. Preserve that rule. The operational
cutoff should be clearly defined against the customer-visible confirmed time;
the private internal start must not silently change the customer's deadline.
Clarify this with the user before implementation. Record the original request
time so staff processing delay does not change which side of the cutoff applies.

Calendar cancellation and the refund/credit are separate recorded operations.
A completed cancellation may release availability while a refund is pending;
do not call the refund complete until Stripe confirms it. A rescheduling credit
needs its own tracked usage, so one credit cannot be applied to several jobs.
Automatic refunds, cancellation fees, credit expiry and automatic customer
rescheduling are not approved by this plan.

## Product/service changes and field upsells

Recommended shared workflow for customers and staff:

1. Propose additions/removals or quantity changes against the current approved
   order version. Use the existing residential/commercial pricing functions,
   package rules, limits and duration calculations. A staff price exception
   needs a reason and an audit entry.
2. Show the changes, revised scope, price difference, revised total, deposit
   already paid, remaining balance and added time. Keep recurring hosting or
   platform costs separately visible from one-time services.
3. Obtain approval from the authorized customer for the exact version and amount.
   Staff can prepare a proposal from a phone; the customer can approve on their
   own device using a secure link or QR code. QR display requires no new SMS
   service. Staff entry of a proposal is not customer authorization to charge.
4. Check capacity again if the shoot becomes longer, including the following
   appointment. An on-site upsell uses remaining time and actual schedule impact;
   it must not incorrectly apply a new-booking 72-hour notice rule to the job
   already underway. If the added work does not fit, offer a separate visit or
   decline/defer the addition rather than overlap another booking.
5. Apply an approved revision once, preserve earlier versions and the original
   Stripe deposit, and update the customer summary, schedule and CRM history.
   Pending proposals must not silently overwrite confirmed scope or paid amounts.

The database currently stores the original request and a deposit payment, not
versioned order changes or a final-balance payment ledger. Final balance
collection is explicitly not enabled in the current TEST release. Scope changes
therefore require new order-version and payment/refund records, not a direct
edit of `quote_cents`, `deposit_cents` or the original payment intent.

Illustrative balance-only proposal, subject to the user's decision: the current
$256.90 job plus an approved $100 addition becomes $356.90; its existing $128.45
deposit remains credited and the balance becomes $228.45. This example does not
authorize changing the 50% base-deposit rule or decide whether revised orders
need a deposit top-up. Keep the $59 approved rush fee on the balance only.

Decide whether additions are added to the final balance, paid immediately, or
require a revised deposit top-up. Service removals need clear treatment of
already performed work and any credit/refund; never create a negative balance
and silently convert it into a refund. Recurring services remain subject to
their separate publication and billing terms.

Existing TEST card consent already mentions separately approved on-site
services, and Checkout requests future off-session usage. That is a foundation,
not permission for unapproved additions or proof every later charge will succeed.
Record approval of each change, identify the new payment purpose/version, retain
idempotency protection, and provide an authenticated customer payment step if
Stripe requires further authentication. New charges/refunds require their own
verified provider events; the original deposit's return URL cannot authorize
them. Changes before deposit payment must also handle any open Checkout session
without leaving two different amounts payable for the same order.

## Complexity and recommended sequence

| Feature | Relative effort | Main work |
| --- | --- | --- |
| Branded management link and request forms | Moderate | Scoped access, clear request states, staff queue and confirmations |
| Microsoft scheduling with staff cancel/reschedule | Substantial | Provider transition, durable reservations, native notifications and reconciliation |
| Consistent available alternatives | Moderate after calendar work | Shared availability rules, duration fitting and fresh final check |
| Customer/staff service proposals | Substantial | Versioned scope, existing pricing, schedule impact and approval evidence |
| Immediate add-on/final-balance payment and refunds | Substantial | Separate payment ledger, policy decisions, authentication and retry recovery |
| Fully automatic customer changes | Later phase | All preceding rules plus eligibility limits and robust exception handling |

Start with the read-only Microsoft check. Then implement Microsoft TEST
scheduling, staff cancellation/rescheduling, consistent alternatives and staff
CRM contact linking. Add the management page and staff-assisted service proposals
next, followed by the chosen balance/refund behavior. Customer automation can
then expand without rebuilding the underlying order and scheduling records.

No fixed delivery estimate is promised before the mailbox check and the open
policy decisions. All implementation/deployment should remain in the existing
draft branch, with consolidated installers where practical. Do not merge main,
delete branches or activate live payments without instructions.

## Official references reviewed

- Microsoft calendar listing: https://learn.microsoft.com/en-us/graph/api/user-list-calendars?view=graph-rest-1.0
- Microsoft calendar access: https://learn.microsoft.com/en-us/graph/api/calendar-get?view=graph-rest-1.0
- Microsoft event reads: https://learn.microsoft.com/en-us/graph/api/calendar-list-calendarview?view=graph-rest-1.0
- Microsoft event creation/invitation behavior: https://learn.microsoft.com/en-us/graph/api/user-post-events?view=graph-rest-1.0
- Microsoft event identity, retries and change tracking: https://learn.microsoft.com/en-us/graph/api/resources/event?view=graph-rest-1.0
- Microsoft cancellation: https://learn.microsoft.com/en-us/graph/api/event-cancel?view=graph-rest-1.0
- Zoho CRM linked event records: https://www.zoho.com/crm/developer/docs/api/v8/insert-records.html
- Stripe saved-card authentication: https://support.stripe.com/questions/saving-payment-methods-for-subscriptions-after-strong-customer-authentication-%28sca%29-regulations-take-effect?locale=en-GB
- Stripe refunds: https://docs.stripe.com/api/refunds/create
