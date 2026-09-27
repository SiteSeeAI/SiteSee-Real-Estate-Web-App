# Consolidated TEST booking workflow — 2026-09-27

## Why this update is needed

Microsoft scheduling was enabled and D32FFC7458 was confirmed on the Microsoft
calendar. Staff then encountered a missing CRM contact link only when attempting
to send the invitation. The one-booking helper has now verified and linked its
contact using the prior E4E51A0481 selection. At that stage the repair output
recorded `invitation_state=none` and zero communication records. The subsequent
installation and invitation validation below supersede that earlier state.

The permanent correction puts readiness, contact selection and communication
recovery in the existing authenticated staff page. Future bookings do not need
a separately generated terminal contact-link helper.

## Actual server validation — 2026-09-27

The operator supplied the installer FINAL RESULTS: installed in TEST mode,
Microsoft calendar/dedicated sender/Zoho CRM read preflight PASS, and active
release hashes PASS. Installation changed no booking row, event, invitation,
email, payment, credential content or integration setting. Preserve its backup:

`/home/sitesee/.sitesee-real-estate/deployment-backups/booking-workflow-2cn2dftl`

The uploaded desktop screenshot showed the new staff interface, Microsoft
assignment, saved CRM link, confirmed 95-minute private shoot, $128.45 deposit
and $128.45 balance. The customer arrival window remained September 30,
13:00–15:00 Central; the internal shoot interval was 13:00–14:35 Central.
No invitation attempt was recorded in that screenshot.

After the send/recovery instructions, the operator reported these actual
statuses for D32FFC7458:

| Evidence | Observed state |
|---|---|
| Calendar appointment | `confirmed` (Microsoft assignment previously shown) |
| Invitation | `sent` |
| Saved message | `sent_observed` |
| Recipient evidence | `recipient_copy_observed` |
| Zoho email history | `associated` |

This establishes the recorded confirmation, actual sent-copy evidence,
recipient mailbox copy and CRM association for this TEST booking. Do not resend
it or rerun the earlier E6E183EF8E probe. These states do not establish RSVP
acceptance, calendar-client rendering without duplicates, or lifecycle
cancellation/rescheduling. The operator has not separately confirmed acceptance
for D32FFC7458. The fresh combined-check calendar diagnostic text was not pasted;
the reported `confirmed` value is the saved appointment state.

## Staff behavior

- Paid TEST bookings for the authorized recipient show the assigned provider,
  staff review, saved CRM link, calendar confirmation, invitation attempt,
  sent-message evidence, recipient evidence and Zoho email history together.
- Saving staff review automatically runs the combined readiness check. Staff
  can repeat **Check Booking Readiness** without creating an event or message.
- Calendar checks use the booking's assigned provider and preserve old Zoho
  identities. Unconfirmed reviewed requests are checked against calendar busy
  time, notice and stored reservations; alternatives appear in the same result.
  Configuration errors are displayed as unavailable, never mislabeled as Zoho.
- Missing CRM links expose an exhaustive exact-primary-email candidate list.
  Staff explicitly verifies a displayed name/email/account/contact ID. Selection
  expires after 15 minutes, is bound to the staff session and booking snapshot,
  and is reverified against the current CRM organization, user and contact.
  Ambiguous matches are never automatically chosen. No CRM contact is created.
- Contact linking is available before confirmation. The invitation button is
  withheld for a missing link or any saved communication attempt. The actual
  send still performs the existing fresh recipient, CRM and calendar checks.
- **Recover Booking Status** checks the existing event, sent copy, recipient
  evidence and CRM association together. Independent outcomes remain visible.
  No calendar creation, mail draft creation or send API is reachable through
  recovery. No state is reset to allow a resend.
- A CRM failure cannot turn an accepted invitation into an unsent one. Recovery
  uses the actual sent Message-ID. Exact existing CRM history can be linked
  without another insertion; uncertain matching history is offered for explicit
  staff review on the same page. Missing message IDs or unverified drafts remain
  blocked for mailbox review; the workflow does not pretend all failures are
  safely repairable automatically.
- Recipient-copy evidence is separate from meeting acceptance. A readiness read
  proves neither effective send permission nor successful invitation delivery.

Staff review, creating the appointment and sending its invitation remain
explicit actions. The private Microsoft event has no attendees; the established
custom ICS invitation carries the customer arrival window. Recovery never sends
a competing native Microsoft invitation.

## One-file installation

Download `tools/install-booking-workflow.py`, upload it with cPanel File Manager
to `/home/sitesee/install-booking-workflow.py`, then run in WHM Terminal as root:

```sh
python3 /home/sitesee/install-booking-workflow.py
```

No keys, module installation, permission commands or additional helper uploads
are needed. `--check` performs local inspection only; it reports planned safe
permission reductions without applying them and makes no provider calls.

The installer checks the active calendar and branded checkout manifests,
independently pins the application dependencies, verifies the Microsoft TEST
switch, existing mail/CRM/legacy-calendar configuration, database schema and
stored reservation identities, available disk space, PHP syntax and actual
SiteSee-user file access. It collects independent local blockers and applies
all recognized permission reductions together. Unknown code, ownership,
symlinks, hard links or configuration changes stop the update without being
overwritten. Permission changes have a separate recovery record.

Once local checks pass, read-only provider preflight verifies the assigned
Microsoft calendar and availability read, dedicated sender mailbox read, and
Zoho organization/current user/exact-email lookup. All three outcomes are
reported even if a connection fails. This does not create another test event.
OAuth token requests use the existing credentials and do not change them.

The installer backs up changed files and journals writes before installing the
additive helper, updating the active calendar and Microsoft release manifests,
and replacing the staff controller last. It does not write a booking row,
payment, event, invitation, email, credential content or integration setting.
The SQLite ledger is opened read-only for structural checks. No schema migration
is required; the existing communication tables are reused.

An unchanged rerun is supported. The new files are:

- `_private/server/booking-workflow.php`
- `_private/server/booking-staff.php`

The previous Microsoft scheduling installer remains byte-reproducible using
the frozen pre-workflow staff fixture. Do not run that older installer over
this update: its old-release hash gate will correctly reject the newer files.

## Recovery

`booking-workflow-install.json` and the printed `deployment-backups/booking-workflow-*`
directory must be retained. A failed write restores the immediately preceding
Microsoft-capable controller and manifests. A process interruption is recovered
by rerunning this same installer. Recovery checks current and backup hashes
before any restoration, preserving unrelated concurrent edits for review.

The additive helper is retained after rollback so a request already executing
the new controller can finish. It has no entry point or effect when the prior
controller is active. Booking rows, contact links and communication evidence
are compatible with that prior controller and are never rolled back.

Never restore a pre-Microsoft calendar controller or an older hosted-only
payment controller. Keep the earlier cleanup archive and all prior deployment
and permission recovery records.

## Verification and limits

Automated checks cover contact ambiguity, exact identity, stale/session-bound
selection, concurrent changes, combined independent failures, blocked-window
alternatives, uncertain-send recovery, missing message identity, independent
delivery/CRM outcomes, no resend, precise/ambiguous CRM history, HTML escaping,
authentication, CSRF and origin enforcement. Installer tests cover each write
failure, interrupted recovery, unchanged reruns, configuration preservation,
permission batching, unknown edits and grouped preflight failures. Provider
responses in automated tests are simulated.

Installation, desktop staff rendering and the reported invitation/delivery/CRM
states are now verified by operator evidence above. Acceptance remains a
separate observation. A later new paid TEST booking can verify the new inline
contact-selection path: D32FFC7458 was already linked by the earlier helper.
Do not reuse a paid booking to manufacture a new payment.

This release does not implement cancellation,
rescheduling of already-confirmed appointments, automatic reconciliation of
manual calendar moves/deletes, customer management links or order/upsell edits.
In particular, a deleted calendar event does not automatically release its
stored reservation. Those lifecycle changes remain the next planned release.

Stripe remains TEST only. Pricing, the 50% base deposit, balance-only $59 rush
fee, consent, verified-webhook authority and account-wide settings are unchanged.
