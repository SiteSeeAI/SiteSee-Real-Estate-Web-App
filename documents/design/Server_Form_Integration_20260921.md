# SiteSee Real Estate Server Form Integration — 2026 09 21

## Integration result

The residential and commercial pricing forms now submit to `public/pricing-request.php`. Browser calculations and page layouts remain unchanged. The server accepts the selected market and its raw pricing inputs, validates them, and independently recalculates every quoted amount. It does not accept a browser-supplied total, subject or email body.

**Email My Quote To Me** sends the validated quote to the requester. **Request My Preferred Date** sends the validated request to the configured SiteSee Real Estate sales address and attempts a confirmation copy to the requester. **Copy Quote** remains local and does not contact the server. Neither action confirms appointment availability.

The handler preserves the exact email subjects:

- `Residential SiteSee Real Estate Quote`
- `Commercial SiteSee Real Estate Quote`

## Reused corporate functionality

The implementation reuses the compatible delivery and security behavior from `SiteSeeAI/SiteSee-3.01-CORP-Site`: same-origin request checks, a honeypot, per-IP/email rate limiting, no-store response headers, multipart plain-text and HTML email, authenticated SMTP, and PHP `mail()` fallback.

The corporate pricing-access token and approval workflow is intentionally excluded. The Real Estate calculator is public and submits a completed quote or preferred-date request; it does not release access to a protected pricing page.

## Server validation

The server requires the complete property address, first and last name, company, email, phone, mailing-list preference, requested date, and requested time for both actions. Dates and 15-minute time increments are checked in `America/Chicago`, and the requested appointment must be in the future.

Residential validation preserves category ranges, fixed packages, required photography, service dependencies, video and image quantities, the $795 Luxury photography cap, the $499 Matterport cap, and uncapped time calculations.

Commercial validation preserves fixed category photography fees, PR #13 photograph maxima of 35 / 55 / 65, additional-photograph rates, the 20,000 sq ft Matterport capture limit used by the current calculator, platform and 360° dependencies, licensing, hosting and time-range calculations.

## Deployment contract

The application has not been deployed by this change. Production needs:

1. A PHP 8.1-or-later origin. Static S3/CloudFront hosting alone cannot execute `pricing-request.php`; that environment needs a PHP-capable origin or an equivalent API/Lambda implementation.
2. The web document root set to `public/`, with the sibling `_private/` directory deployed but not publicly addressable.
3. A writable `_private/real-estate-rate/` directory for hashed rate-limit counters. A multi-instance or read-only serverless deployment should replace this file store with shared expiring storage before launch.
4. Mail configuration. The handler reuses the corporate environment names:
   - `SITESEE_FROM_EMAIL`
   - `SITESEE_SMTP_HOST`
   - `SITESEE_SMTP_PORT` (default `587`)
   - `SITESEE_SMTP_USERNAME`
   - `SITESEE_SMTP_PASSWORD`
   - `SITESEE_SMTP_ENCRYPTION` (default `tls`)
   - `SITESEE_REAL_ESTATE_SALES_EMAIL` (optional; defaults to `sales@sitesee.ai`)
5. Valid sender-domain SPF/DKIM/DMARC alignment for the configured From address.
6. Deployment access to the Real Estate production origin and one end-to-end test using a controlled requester address. Test both requester delivery and the sales mailbox before changing the site’s email-delivery notice in production.

If SMTP is not configured, the handler falls back to PHP `mail()`. Production should use authenticated SMTP unless the origin’s mail transport is already verified.

## Verification

- `node --test tests/*.test.cjs` runs the browser calculator regression suites and server-form contract checks.
- `php tests/pricing-request.test.php` verifies server-side price parity, required fields, Central Time validation, appointment increments, and core caps.
- `.github/workflows/server-form-ci.yml` runs both suites and lints all PHP files on the integration branch and pull requests.

