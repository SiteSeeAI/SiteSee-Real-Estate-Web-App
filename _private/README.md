# Private server application

This directory must be deployed outside the web document root. The `.htaccess` denial is defense in depth, not a substitute for keeping the directory private.

- `server/` contains pricing-access, approval, confirmation, logout, protected-asset and quote-submission implementations.
- `views/` contains the protected residential and commercial calculator template.
- `pricing-assets/` contains calculator JavaScript released only through the verified-session asset endpoint.
- `real-estate-form-config.php` contains shared session, token, rate-limit and mail delivery functions.
- `real-estate-pricing.php` contains authoritative server-side validation and pricing calculations.

## Production environment

- `SITESEE_REAL_ESTATE_SITE_URL` — canonical HTTPS origin for the real-estate site.
- `SITESEE_REAL_ESTATE_PRICING_GATE_SECRET` — persistent random value of at least 32 characters.
- `SITESEE_REAL_ESTATE_SALES_EMAIL` and `SITESEE_FROM_EMAIL` — delivery and sender addresses.
- PHP `sendmail_path` — the installed Corporate Microsoft Graph bridge.
- PHP 8.1 or later with cURL enabled.

The production installer writes these values to the `realestate.sitesee.ai` PHP-FPM configuration, preserving an existing pricing-signing secret during later deployments. SMTP settings remain empty so PHP `mail()` uses the same Microsoft Graph bridge as the Corporate site.

## Corporate Cloudflare protection

The Real Estate forms reuse the installed Corporate **SiteSee Audit** Turnstile configuration at `/home/sitesee/.sitesee-audit-guard/config.json`. No second production site key or secret is created. Add `realestate.sitesee.ai` to that managed widget's allowed hostnames before activation.

The server installer reads the working public site key from the Corporate contact script and embeds it in `public/assets/js/turnstile.js`; the secret and HMAC salt remain in the existing private Corporate configuration. The Pricing Request and Contact forms use isolated actions, `real_estate_pricing` and `real_estate_contact`, with shared attempt limits and independent per-action IP and email delivery limits.

Both endpoints fail closed when verification is missing, invalid, unavailable, issued for another action or issued for another hostname. Run `SiteSee_Real_Estate_Forms_Cloudflare_20260922.sh` after the public and private trees have been deployed. It performs source and server preflight, PHP syntax checks, staged configuration, backups, installation, live missing/invalid-token rejection tests, a Microsoft Graph delivery test and automatic rollback on failure.

The corresponding PHP files under `public/` are intentionally minimal web entrypoints. Browser-facing `site.js`, `pricing-access.js` and `turnstile.js` remain under `public/assets/js/` because they must be downloadable.
