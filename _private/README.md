# Private server application

This directory must be deployed outside the web document root. The `.htaccess` denial is defense in depth, not a substitute for keeping the directory private.

- `server/` contains pricing-access, approval, confirmation, logout, protected-asset and quote-submission implementations.
- `views/` contains the protected residential and commercial calculator template.
- `pricing-assets/` contains calculator JavaScript released only through the verified-session asset endpoint.
- `real-estate-form-config.php` contains shared session, token, rate-limit and mail delivery functions.
- `real-estate-pricing.php` contains authoritative server-side validation and pricing calculations.

The corresponding PHP files under `public/` are intentionally minimal web entrypoints. Browser-facing `site.js` and `pricing-access.js` remain under `public/assets/js/` because they must be downloadable.
