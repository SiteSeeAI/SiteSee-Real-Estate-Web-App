# Private server application

This directory must be deployed outside the web document root. The `.htaccess` denial is defense in depth, not a substitute for keeping the directory private.

- `server/` contains pricing-access, approval, confirmation, logout, protected-asset and quote-submission implementations.
- `views/` contains the protected residential and commercial calculator template.
- `pricing-assets/` contains calculator JavaScript released only through the verified-session asset endpoint.
- `real-estate-form-config.php` contains shared session, token, rate-limit and mail delivery functions.
- `real-estate-pricing.php` contains authoritative server-side validation and pricing calculations.

## Required production environment

- `SITESEE_REAL_ESTATE_SITE_URL` — canonical HTTPS origin for the real-estate site.
- `SITESEE_REAL_ESTATE_PRICING_GATE_SECRET` — random value of at least 32 characters.
- `SITESEE_TURNSTILE_SITE_KEY` — public Cloudflare Turnstile widget key authorized for the real-estate hostname.
- `SITESEE_TURNSTILE_SECRET_KEY` — private Cloudflare Turnstile verification key; never expose it in HTML or JavaScript.
- `SITESEE_REAL_ESTATE_SALES_EMAIL` and `SITESEE_FROM_EMAIL` — delivery and sender addresses.
- `SITESEE_SMTP_HOST`, `SITESEE_SMTP_PORT`, `SITESEE_SMTP_USERNAME`, `SITESEE_SMTP_PASSWORD`, and `SITESEE_SMTP_ENCRYPTION` — authenticated mail transport when PHP `mail()` is not used.

Both the Pricing Request and Contact endpoints fail closed when Turnstile cannot be verified. Create the widget in Cloudflare for the production hostname, then set both Turnstile variables on the server before testing live submissions.

The corresponding PHP files under `public/` are intentionally minimal web entrypoints. Browser-facing `site.js` and `pricing-access.js` remain under `public/assets/js/` because they must be downloadable.
