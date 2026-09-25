# Deposit-first test bookings and two-hour arrival windows

Issue #27 now collects the test deposit before staff schedule review for new version-2 requests. Both markets let the agent select the beginning of a two-hour arrival window, show its end immediately, and explain that this is not the shoot duration or a confirmed appointment. No operating-hour restrictions are assumed. Window starts retain 15-minute increments; windows cannot cross midnight or a daylight-saving transition.

The server derives the end, ignores any supplied end, calculates the one-time price and 50% deposit, and stores an awaiting_deposit_test record with an expiring payment token. The form navigates to the private payment page. Monthly residential platform charges remain separate. Only the signed webhook can record deposit_paid_test. Staff can then record a photographer assignment and duration after checking availability. This review does not create a confirmed appointment or send an invitation. Price changes and alternative-date acceptance are not automated in this phase.

The public wording requires agreement to another proposed date before confirmation and promises a refund if no mutually acceptable date exists. Refund execution is still manual and this remains test-only. The sole current approver remains the staff account, and the photographer defaults to David J Cro.

Existing version-1 requests, payment links, and paid records keep their original flow; no database migration or secret change is required. A saved request receipt is recorded before email attempts in the new flow, so mail failures do not make the browser create a second deposit request. This is session-based retry protection, not cross-device deduplication. Mail acceptance is not proof of inbox delivery.

The Stripe return page reads the actual paid state using a private payment-session token. A return alone never claims payment succeeded. When verified it displays the reference and the less-than-two-hours follow-up message; otherwise it offers a status check. A canceled return can resume the same Checkout. Old Stripe return URLs retain their generic message. The staff-issued link now has an Open Test Payment Page action.

## Deployment

Upload these private application files from the approved main commit to the corresponding paths beneath /home/sitesee/.sitesee-real-estate/:

- real-estate-pricing.php
- views/pricing.php
- views/scheduling-fields.php
- pricing-assets/scheduling.js
- pricing-assets/pricing.js
- pricing-assets/commercial-pricing.js
- pricing-assets/quote-engine.js
- pricing-assets/commercial-quote-engine.js
- server/booking-store.php
- server/booking-pay.php
- server/booking-staff.php
- server/quote-submit.php
- server/quote-receipts.php

Upload server files before browser controllers. Do not submit forms mid-upload. Compare all installed files with Git blobs at the exact approved commit before functional testing, accepting line-ending-only differences. No public entrypoint, PHP-FPM, mail bridge or credential change is needed.

## Verification

Automated checks cover both markets, derived windows, clock changes, unpaid review rejection, test-only Checkout, verified webhook transitions, duplicate webhook/payment/review rejection, legacy bookings, payment-link origin validation, and retry after failed mail.

After deployment verification, conduct one new controlled request: confirm the displayed window and quote, continue directly to a test deposit without staff approval, verify the paid reference, then record the staff test review. Do not repeat the previous paid booking test unnecessarily.

Remaining work includes alternative schedule proposal/acceptance, identified CRM photographer/contact records, availability integration, confirmation and invitations, refunds, final payment, and publication/subscription lifecycle. Live payments and invitations remain disabled.
