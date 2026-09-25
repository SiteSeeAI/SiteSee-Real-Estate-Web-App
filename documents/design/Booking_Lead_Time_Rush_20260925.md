# Booking notice and approval-gated rush service

Standard requests require at least 72 elapsed hours before the beginning of the selected arrival window. Rush requests require at least 12 elapsed hours. The server supplies and enforces the current instant; Central Time is used to interpret/display the six approved arrival windows. Epoch arithmetic ensures the notice remains 72/12 actual hours across DST or a different PHP/server timezone. Existing stored requests are not retroactively rejected.

Both pricing forms provide an optional, unchecked rush checkbox. They disable earlier dates/windows using the server timestamp plus monotonic elapsed browser time. The server revalidates on submission, including old-client version-1 standard submissions. A malformed rush flag is rejected. Version-1 rush submissions must refresh to the new form. The optional fee is disclosed in copied/emailed quotes and the payment page but never added to the initial deposit.

## Rush decisions

The deposit remains 50% of the base one-time job price. After verified test payment, staff must explicitly approve rush service or decline it. An approval records exactly 5900 cents as an addition to the remaining job balance. It does not initiate a Stripe charge. Final balance collection is not yet implemented in issue #27; live charging remains disabled.

A decline records zero rush fee and requires a replacement standard window. The authenticated payer can request a new 72-hour window on the existing booking page, retaining the paid deposit and original reference. Staff can issue a replacement private rescheduling link if the original was lost. New window submissions are bearer-authorized and CSRF protected, revalidated against server time, and cannot overwrite a completed replacement request. The original request_json is retained; a separate effective appointment is displayed. No automatic invitation or notification email is sent by these new staff actions in this test phase.

The additive booking_scheduling and booking_schedule_events tables store rush decisions, approved fee, current replacement window and decision history. Existing booking records and Stripe identifiers are preserved. Transactions prevent conflicting staff decisions, duplicate fees or duplicate replacement actions.

## Upload order

From the approved main commit, upload to the corresponding paths beneath /home/sitesee/.sitesee-real-estate/:

1. server/booking-schedule.php (new dependency; upload first)
2. real-estate-pricing.php
3. server/booking-store.php
4. server/booking-pay.php
5. server/booking-staff.php
6. server/quote-receipts.php
7. views/pricing.php
8. pricing-assets/scheduling.js
9. pricing-assets/quote-engine.js
10. pricing-assets/commercial-quote-engine.js
11. pricing-assets/pricing.js
12. pricing-assets/commercial-pricing.js

Keep the existing private database backup procedure, including WAL state. No PHP-FPM, Graph mail, webhook or credential configuration changes are required. Do not submit forms during upload. Compare every installed file to the exact approved commit before functional testing; CRLF-only differences are acceptable.

## Checks

Client tests cover the server-clock cutoff, passage of time, rush-to-standard changes and DST. PHP tests cover exact boundaries and one-second-under rejection, malformed flags, pending-fee exclusion from the actual Stripe transport, explicit rush approval, duplicate/concurrent decision protection, declined-request rescheduling without another deposit, token authorization and retention of the original request.

Next controlled tests: verify both form dropdowns respect standard/rush limits; submit one rush test request; confirm the deposit excludes $59; exercise staff decision and the paid booking page. No live card charges or invitations are enabled.
