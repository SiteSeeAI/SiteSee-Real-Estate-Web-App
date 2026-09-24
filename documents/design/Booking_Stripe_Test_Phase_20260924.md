# Booking and Stripe test phase for issue #27

This phase begins with the residential and commercial forms merged in PR #28. A preferred-date submission is validated and repriced by the server, then saved under its existing reference. It is still a request; no appointment is confirmed by the form, staff approval, Checkout redirect, or deposit webhook.

## Safe default and deployment

All new booking routes are disabled unless SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED is exactly 1. With the switch absent, the existing email request flow continues without requiring SQLite. There is no live Stripe key option and no Zoho invitation implementation in this phase. Do not upload the private application into the web root.

Upload the new public PHP entrypoints into the Real Estate document root and the private server files into /home/sitesee/.sitesee-real-estate/server/ along with the changed private pricing file. Keep database storage in /home/sitesee/.sitesee-real-estate/data/ (not publicly served). The PHP-FPM worker must be able to create/write this directory, its SQLite database, and WAL/shm companions. Require PHP 8.1+, PDO SQLite, cURL, HTTPS, persistent PHP sessions, and Stripe outbound HTTPS access. Back up the database and include its WAL state in backup procedures. Run the PHP and client CI checks before a controlled test deployment.

Set these values through the private PHP-FPM configuration or secret manager, never in Git or a public PHP file:

- SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED=1
- SITESEE_REAL_ESTATE_STAFF_PASSWORD_HASH: for PHP-FPM, use `base64:` followed by `base64_encode(password_hash(..., PASSWORD_DEFAULT))`. PHP-FPM treats a raw hash beginning with `$` as an environment-variable reference and resolves it to an empty value. The application decodes the prefix before verifying the password. Existing raw hashes remain supported outside PHP-FPM. Encoding is not encryption; keep the configuration private. Generate the hash on a trusted machine with interactive hidden password entry, never a plaintext password in shell history.
- SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET: an sk_test_ key only. sk_live_ keys fail closed.
- SITESEE_REAL_ESTATE_STRIPE_TEST_WEBHOOK_SECRET: the whsec_ signing secret for the dedicated Stripe test webhook.
- SITESEE_REAL_ESTATE_BOOKING_DB: optional absolute SQLite path in the private application; omit to use the default data/bookings.sqlite.

Existing SITESEE_REAL_ESTATE_SITE_URL must point at the canonical HTTPS origin. Add the test webhook endpoint https://re.sitesee.ai/booking-webhook.php in Stripe test mode, subscribed to checkout.session.completed and checkout.session.expired. Keep live-mode webhook destinations, API keys, billing, and invitation sending unconfigured for this phase.

## Test workflow

1. Use approved pricing access and submit Request My Preferred Date in either market. The request, property, PR #28 access details and cancellation agreement, Chicago slot plus UTC instant, server price and monthly residential platform selection are saved privately. The sales email and agent copy continue as before. Email My Quote To Me does not create a booking.
2. SiteSee staff signs in at /staff-bookings.php with the dedicated password. The reviewer sees the server quote, schedule, property access and contact details. They manually check photographer availability, duration, and final price; changes require a reason. Approval locks the one-time price, computes a 50% deposit rounded up to the cent, and displays an expiring test-only payer link once. No email or invitation is sent from this screen. A lost link can be replaced while no Checkout is open.
3. Send that link only to an authorized test payer. /booking-pay.php displays the agreed price, separate residential platform price if selected, deposit, unpaid balance and cancellation policy. The payer checks an explicit card reuse agreement before the server creates a Stripe Checkout Session with an idempotency key. Only a test Checkout URL is accepted; repeated submits reuse the open session.
4. Stripe posts a signed event to /booking-webhook.php. The handler checks the raw-body signature and timestamp, test-mode flags, active session ID, reference, currency, paid amount, customer and PaymentIntent IDs. Processing is idempotent in the SQLite ledger. A returned browser success page cannot mark a deposit paid. An expired session stays unconfirmed and can be retried.
5. On staff review, observe deposit_paid_test and the stored Stripe identifiers. There is deliberately no CRM event, invitation, shoot completion, final charge, publication subscription or sold cancellation. A manually entered CRM Contact ID is a note, not an automated match or CRM write.

## Remaining issue #27 stages

Before production activation: verify an exact match in the **production** SiteSee Zoho CRM (route ambiguous matches for review), integrate photographer/CRM Calendar availability with a second check after payment, reconcile CRM event creation and invitation failure with retries, finish final-charge/addition evidence and authentication recovery, publish-triggered residential subscription and sold cancellation, subscription renewal failures, refunds/credits and recovery, then exercise the full flow in test environments. A test deposit by itself never establishes an appointment.

The staging database contains property access information and payer consent timestamps. Limit file and staff account access, retain backups securely, and do not print access codes in Stripe metadata or public pages.
