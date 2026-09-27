# Branded SiteSee Checkout — 2026 09 26

This release replaces the plain deposit page with a responsive SiteSee page and
Stripe's embedded Checkout. It remains strictly in TEST mode. It does not enable
live charges or change the existing Stripe secret, webhook destination, account
branding, global mail bridge, Zoho credentials, staff review or invitation gates.

The page uses the existing public SiteSee logo, Poppins and Inter fonts. It shows
the property, requested arrival window, selected services, 50% deposit, remaining
balance, rush conditions, existing future card-use consent and cancellation terms.
The matching return page distinguishes a verified deposit from an appointment.

## Install in one server session

1. Upload `install-branded-checkout.py` to `/home/sitesee/`.
2. In WHM Terminal, run:

```bash
python3 /home/sitesee/install-branded-checkout.py
```

The installer first verifies the current release hashes, PHP syntax and existing
public logo/font assets. On the first installation it asks for the **TEST
publishable key**, beginning `pk_test_`, from the same Stripe account/environment
as the existing test payments. Enter it in WHM Terminal. No secret key is requested.

It installs five private application files and saves the publishable key in
`/home/sitesee/.sitesee-real-estate/booking-checkout.json` with owner `sitesee` and
mode `0600`. It updates the calendar release manifest for the payment controller
and session helper. A separate `branded-checkout-release.json` covers all five
payment files, including the view and assets. A private backup is made before replacement. The entry point is installed
last, after its dependencies; completed writes are restored if installation fails.
An unchanged rerun is harmless. `--check` inspects without installing.

Installation performs no Stripe API calls and does not open or modify the booking
database. The first authenticated embedded payment request creates an additive
`booking_checkout_ui` table; existing booking rows retain their references.

## Exact application files

| Private path | Change |
| --- | --- |
| `server/booking-pay.php` | Branded controller, authenticated embedded request and payment-status endpoints |
| `server/booking-checkout.php` | Test-only Stripe session creation/retrieval and existing-session protection |
| `views/booking-payment.php` | Shared summary, payment, error and confirmation page rendering |
| `payment-assets/booking-payment.css` | Private, inlined responsive styles using existing public fonts/logo |
| `payment-assets/booking-payment.js` | Consent submission, Stripe mount, retry states and bounded payment-status polling |

Public PHP wrappers, public assets and routing remain unchanged.

## Payment behavior

- Existing open hosted Checkout links are reused. An ambiguous in-progress hosted
  request retries the original payload and original idempotency key.
- New embedded sessions occupy the same authoritative `bookings.stripe_session_id`
  field used by the existing webhook. They cannot coexist with another active
  payable session for that booking through this flow.
- Repeated clicks are guarded by a creation lease. A timeout retries the same
  embedded idempotency key. A new attempt is permitted only after expiry is known.
- Returning to a success URL never records payment. Only the existing verified
  Stripe webhook records the correct session, currency and deposit amount.
- The server calculates the deposit. No client-submitted amount is accepted.
- Card details are collected in Stripe's iframe. Client secrets are returned only
  to the authenticated page; they are not persisted in the database or logs.
- Account-wide Stripe API and branding settings are unchanged. Embedded requests
  alone use `Stripe-Version: 2026-08-26.dahlia`, `ui_mode=embedded_page`, and
  per-session SiteSee/Inter/white/gold/rectangular branding. Browser code loads
  `https://js.stripe.com/dahlia/stripe.js` and `createEmbeddedCheckoutPage`.
- The existing calendar and CRM email-history flow remains a separate staff
  workflow after payment and review.

## Validation and remaining server check

Automated checks cover embedded/legacy session reuse, ambiguous timeouts, duplicate
clicks, expiry, rejection of live keys, authentication, CSRF, missing consent,
cross-origin requests, amount/reference validation, webhook authority, escaping,
installer rollback and preservation of the existing credentials/database.

Provider calls are simulated in local unit tests. HTTP controller tests run the
actual PHP page against a disposable local database. The available browser could
not open local preview files, so desktop/mobile appearance and the real Stripe
iframe are not claimed as browser-verified.

After installation, create **one fresh test booking** with a new reference. Use the
same existing test recipient, `cro@sitesee.ai`, so the established invitation guard
continues to apply. Complete the deposit with a Stripe test card. Confirm that:

1. The branded summary displays the correct locked price, 50% deposit and balance.
2. The card fields mount on the SiteSee page and the deposit is recorded once.
3. Refreshing or reopening the paid link shows payment status, not a second charge.
4. The established staff review → calendar confirmation → invitation workflow passes.

Also exercise a declined test card and a 3D Secure test card before live activation.
No live payment setting should be enabled by this release.

## Recovery

The installer prints its exact backup directory. Its `restore-paths.json` identifies
which paths existed before installation; `calendar-confirmation-release.json` and
the old payment controller are backed up there. Do not blindly restore an older
payment controller while an embedded session is still open or creating: that
controller does not understand embedded sessions. First reconcile or let those
sessions expire, or keep the current controller and disable new embedded sessions
through the private configuration. The current controller refuses to convert an
active embedded attempt into a second hosted charge when embedding is disabled.

The older cleanup recovery archive remains separate and should be retained.

## Stripe references

- https://docs.stripe.com/payments/accept-a-payment?payment-ui=checkout&ui=embedded-page
- https://docs.stripe.com/api/checkout/sessions/create
- https://docs.stripe.com/js/embedded_checkout
- https://docs.stripe.com/payments/checkout/customization/appearance?payment-ui=stripe-hosted
- https://docs.stripe.com/security/guide
- https://docs.stripe.com/upgrades
