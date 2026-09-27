# Move an unconfirmed paid TEST request — 2026 09 26

The window diagnostic can show a clear Zoho calendar while a confirmed local
reservation still blocks the requested time. Retain that older booking and its
invitation history. Absence from the current provider list is not permission to
delete a local reservation.

The current customer replacement-window form is limited to declined rush
requests. `tools/move-test-booking-window.php` provides an explicit CLI operation
for a standard paid TEST request with a saved staff review and **no calendar
confirmation attempt**. It is not a confirmed-appointment rescheduling tool.

Before running it, agree the proposed window with the test recipient/operator.
Upload the single file to `/home/sitesee/`. The command specifies the booking,
same date, expected current window start, proposed new start, and explicit apply:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php /home/sitesee/move-test-booking-window.php REFERENCE YYYY-MM-DD 07:00 09:00 --apply
```

This moves the requested arrival window from 7–9 AM to 9–11 AM Central only when
the original window still matches, the proposed window meets the current
72-hour notice rule, and a fresh provider read plus local claims allow the full
reviewed duration. Existing matching calendar identities, enabled TEST controls,
the configured test recipient, paid TEST status and private-file ownership are
verified. The existing confirmation lock prevents concurrent calendar creation.

Only the selected request's scheduling override, requested UTC start, review
timestamp and availability-check timestamp are changed. The review is cleared
so staff must review the new window before confirming it. The original request,
photographer, duration, all quote/deposit fields, payment identifiers and bearer
token remain unchanged. Two scheduling audit entries preserve the transition
and new notice origin. A transaction rolls back partial changes. An unchanged
rerun is a no-op.

No Stripe call, calendar write, invitation, email, global setting, credential,
other booking or existing calendar reservation is changed. There is no database
schema migration. No calendar slot is reserved by the move. Final confirmation
still makes its own fresh check through the established staff workflow.

Review FINAL RESULTS, reopen the selected staff booking, save its review again,
then proceed with Confirm Test Appointment and the separate invitation action.
Do not use this helper for a creating, uncertain or confirmed calendar booking.

Local validation: 15 checks cover retained payment/booking fields, unchanged
older reservations, derived window/UTC values, audit entries, idempotent reruns,
confirmed/unpaid rejection, fresh calendar conflicts, notice limits and rollback
on audit-write failure. Provider reads are simulated in those tests. Server
execution remains pending until the operator runs the supplied command.
