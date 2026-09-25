# Quote button states and repeat protection — 2026 09 24

## Approved behavior

The “Email My Quote To Me” and “Copy Quote” buttons must clearly show progress and completion. Use SiteSee gold while working, then an ink background, white text, gold border and a checkmark after completion. Completed states must remain readable on phones.

Email changes from “Sending Email…” to “Email Sent ✓” and stays disabled for the same quote. Copy changes from “Copying…” to “Copied ✓”; copying again is allowed because it sends nothing. Changes to quote details restore the relevant action, while reverting to an already-emailed version restores its sent state. Request completion also marks an already-sent customer copy, avoiding another unnecessary email.

## Repeat handling

Only one quote action runs at a time in each form. Back/Forward cache restoration reapplies completed states. The confirmation page replaces the submitted form’s history entry. Errors remain retryable, and clipboard rejection offers manual copying without claiming success.

The server stores confirmed responses in the existing verified PHP pricing session, keyed by normalized action, market, contact/property details, appointment and calculated quote. Repeated successful submissions return the original reference before rate limiting, booking capture or email. A successful customer copy for a preferred-date request also satisfies the corresponding email-quote action.

Receipts last up to twelve hours within the existing session, capped at 128 recent entries. The native PHP session lock remains held through processing to serialize simultaneous requests sharing that session. Cached metadata contains references/status messages, not the underlying quote or property access data.

This protects newly recorded successful requests in the same verified pricing session, including unchanged repeats from an older open form after the first recorded success. It is not cross-device deduplication or a durable delivery queue. Expired/lost sessions, outcomes recorded before deployment, process termination after an external send, and a booking saved before a failed staff email remain outside this change. Pricing-access handlers, mail routing, staff approval, Stripe settings and invitations are unchanged.

## Morning deployment

The complete current change set, including PR #31’s confirmation page, contains seven website files:

| Repository file | Installed location |
| --- | --- |
| public/request-received.html | Real Estate public folder / request-received.html |
| public/assets/js/request-received.js | Real Estate public folder / assets/js/request-received.js |
| public/assets/css/pricing.css | Real Estate public folder / assets/css/pricing.css |
| _private/server/quote-receipts.php | /home/sitesee/.sitesee-real-estate/server/quote-receipts.php |
| _private/server/quote-submit.php | /home/sitesee/.sitesee-real-estate/server/quote-submit.php |
| _private/pricing-assets/pricing.js | /home/sitesee/.sitesee-real-estate/pricing-assets/pricing.js |
| _private/pricing-assets/commercial-pricing.js | /home/sitesee/.sitesee-real-estate/pricing-assets/commercial-pricing.js |

Upload the new receipt helper before the changed quote-submit handler. Upload the confirmation page/assets before the two calculator scripts. Preserve installed credentials, pending leads, SQLite data and configuration files. No PHP-FPM configuration change is required. Tests and this document are not public uploads.

## Verification

Node tests execute each form’s actual action-state and submission functions: working/complete labels, rapid-click exclusion, changes during sending, edited/reverted quotes, restored button state, clipboard rejection, retryable errors, confirmation navigation and suppression of a second email after an accepted request.

The PHP integration test runs the actual quote-submit handler and receipt helper on localhost with synthetic verified sessions and stubbed mail/booking capture. It checks reference reuse, mail and booking counts, changed submissions, session separation, expired access, retry after a failed standalone email, and honest handling when only the customer copy fails. It cannot send real email, contact Stripe or create real bookings. Existing CI also covers server validation, pricing parity and access protection.

Actual mobile-browser layout and Back-button behavior still need a production check after upload. The earlier mobile completion issue is not independently confirmed resolved.
