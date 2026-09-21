# SiteSee Real Estate Server Form Integration — 2026 09 21

## Integration result

The public `public/pricing-request.html` page now requests verified pricing access. It mirrors the corporate workflow: the prospect submits required business contact information; SiteSee sales receives a signed internal review link; and opening that link does not release pricing. An explicit approval action emails the prospect a signed access link. The link expires after 36 hours and creates a verified browser session lasting up to 12 hours.

The unchanged residential and commercial calculator URL remains `public/pricing.php`, but that public file is now only an entrypoint for `_private/views/pricing.php`. Its calculator scripts are stored under `_private/pricing-assets/` and delivered only through the minimal `public/pricing-asset.php` entrypoint and its session-protected `_private/server/pricing-asset.php` implementation. Direct requests without a verified session return to the public access form.

The protected forms submit to the public `public/quote-submit.php` route, which immediately loads `_private/server/quote-submit.php`. The private server implementation accepts the selected market and its raw pricing inputs, validates them, and independently recalculates every quoted amount. It does not accept a browser-supplied total, subject or email body.

**Email My Quote To Me** sends the validated quote to the requester. **Request My Preferred Date** sends the validated request to the configured SiteSee Real Estate sales address and attempts a confirmation copy to the requester. **Copy Quote** remains local and does not contact the server. Neither action confirms appointment availability.

The handler preserves the exact email subjects:

- `Residential SiteSee Real Estate Quote`
- `Commercial SiteSee Real Estate Quote`

## Reused corporate functionality

The implementation reuses the compatible delivery and security behavior from `SiteSeeAI/SiteSee-3.01-CORP-Site`: manual approval before pricing release, HMAC-signed approval and access tokens, expiring verified sessions, protected pricing assets, same-origin request checks, a honeypot, per-IP/email rate limiting, no-store response headers, multipart plain-text and HTML email, authenticated SMTP, and PHP `mail()` fallback.

## Server validation

The server requires the complete property address, first and last name, company, email, phone, mailing-list preference, requested date, and requested time for both actions. Dates and 15-minute time increments are checked in `America/Chicago`, and the requested appointment must be in the future.

Residential validation preserves category ranges, fixed packages, required photography, service dependencies, video and image quantities, the $795 Luxury photography cap, the $499 Matterport cap, and uncapped time calculations.

Commercial validation preserves fixed category photography fees, PR #13 photograph maxima of 35 / 55 / 65, additional-photograph rates, the 20,000 sq ft Matterport capture limit used by the current calculator, platform and 360° dependencies, licensing, hosting and time-range calculations.

## Deployment contract

The application has not been deployed by this change. Production needs:

1. A PHP 8.1-or-later origin with PHP sessions enabled. Static S3/CloudFront hosting alone cannot execute the access gate or quote handler; that environment needs a PHP-capable origin or an equivalent API/Lambda implementation.
2. The web document root set to `public/`, with `_private/` deployed as its sibling outside the document root. On a conventional account, upload the contents of `public/` into `public_html/` and place `_private/` beside `public_html/`. Public PHP files must remain as the small route entrypoints; do not move them, and never place `_private/` below the served directory.
3. Writable `_private/real-estate-rate/` and `_private/real-estate-pricing-pending/` directories, plus write access for the pricing lead and mail logs. A multi-instance or read-only serverless deployment should replace these file stores with shared expiring storage before launch.
4. Pricing-gate configuration:
   - `SITESEE_REAL_ESTATE_SITE_URL` — the exact HTTPS production origin used in approval emails, without a trailing slash.
   - `SITESEE_REAL_ESTATE_PRICING_GATE_SECRET` — a deployment secret of at least 32 unpredictable characters. This must not be committed to the repository.
5. Mail configuration. The handler reuses the corporate environment names:
   - `SITESEE_FROM_EMAIL`
   - `SITESEE_SMTP_HOST`
   - `SITESEE_SMTP_PORT` (default `587`)
   - `SITESEE_SMTP_USERNAME`
   - `SITESEE_SMTP_PASSWORD`
   - `SITESEE_SMTP_ENCRYPTION` (default `tls`)
   - `SITESEE_REAL_ESTATE_SALES_EMAIL` (optional; defaults to `sales@sitesee.ai`)
6. Valid sender-domain SPF/DKIM/DMARC alignment for the configured From address.
7. Deployment access to the Real Estate production origin and one end-to-end test using controlled requester and sales addresses. Test request acknowledgement, internal review, explicit approval, pricing-link delivery, protected-page access, quote delivery and preferred-date delivery before launch.

If SMTP is not configured, the handler falls back to PHP `mail()`. Production should use authenticated SMTP unless the origin’s mail transport is already verified.

## Verification

- `node --test tests/*.test.cjs` runs the browser calculator regression suites and server-form contract checks.
- `php tests/pricing-request.test.php` verifies server-side price parity, required fields, Central Time validation, appointment increments, and core caps.
- `php tests/pricing-gate.test.php` verifies signed access and approval tokens, tamper rejection, expiration and verified-session enforcement.
- `.github/workflows/server-form-ci.yml` runs both suites and lints all PHP files on the integration branch and pull requests.
