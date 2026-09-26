# Calendar diagnostic and single-use recovery — 2026 09 26

The controlled booking E6E183EF8E reached an uncertain creation state without a saved event UID. Reconciliation and the user's manual check found no matching event. Original code replaced the underlying error without retaining a diagnostic. Invalid-date probes returned PATTERN_NOT_MATCHED for both POST-body and URL-parameter formats, so a body/query mismatch was not established and the production transport is not changed by this package.

This package adds one standalone CLI helper. Existing application files, credentials, booking/payment amounts, staff review, email configuration and controls are not replaced. Do not delete the uncertain confirmation record or create another booking to get around it.

## First step: default read-only check

Upload `diagnose-calendar-confirmation.php` to:

`/home/sitesee/.sitesee-real-estate/tools/diagnose-calendar-confirmation.php`

Keep ownership as sitesee. Run in WHM Terminal:

```bash
runuser -u sitesee -- /opt/cpanel/ea-php82/root/usr/bin/php /home/sitesee/.sitesee-real-estate/tools/diagnose-calendar-confirmation.php E6E183EF8E
```

The default mode opens the existing database read-only, takes the existing confirmation lock, verifies the saved paid test booking and recipient, validates privacy and the exact saved shoot interval, obtains a temporary writer access token, and reads the configured calendar across the shoot plus 36 hours on either side. It checks event markers, calendar conflicts, and other local reservations. It does not create an event, change a booking, call Stripe or send mail. Send the complete output back before continuing.

The broad scan is evidence for operator review, not a guarantee that a remotely moved/renamed event cannot exist. The owning SiteSee Photography calendar must also have been manually checked. Do not authorize a retry if any matching event is found or the original result remains unresolved for another reason.

## Separate, explicit recovery step

Only after the default check and manual calendar inspection support absence, the operator may run:

```bash
runuser -u sitesee -- /opt/cpanel/ea-php82/root/usr/bin/php /home/sitesee/.sitesee-real-estate/tools/diagnose-calendar-confirmation.php E6E183EF8E --retry-once
```

The helper repeats all checks. It then asks the operator to type `RETRY E6E183EF8E` to attest to the manual calendar check and explicitly authorize one retry. It holds the same application confirmation lock throughout. A durable `diagnostic_retry_started_v1` audit record is committed before the POST. No automatic retry is possible, including after a lost response or repeated command. The original claim stays in place; it is never deleted or reset.

The retry uses the original saved event payload and original marker. The payload is private, has no attendees or reminders, disables notifications and calendar alarms, and creates no conference. The helper captures bounded, credential-redacted error fields, HTTP status and cURL error number. The original discarded response cannot be recovered; these diagnostics describe this newly authorized attempt.

A successful create must return an event UID, pass an event-detail check for exact identity/privacy/interval, and pass a fresh conflict check before the existing confirmation record is marked confirmed. Any failure retains the protected state and any UID obtained. A network/database failure after an attempt must be reviewed; do not reset the audit or repeat the retry. Existing read-only Recheck Calendar Result remains the recovery route when a UID or matching remote event exists.

No invitation or payment is sent by this helper. Invitations must remain disabled. The tool does not activate any control, and does not solve the original failure until the actual provider response is observed.

## Validation

PHP syntax and the targeted mock suite cover default read-only database preservation; explicit acknowledgement; one successful verified creation; repeat-command prevention; redacted provider rejection; lost reply; broad-range existing-event detection; remote/local conflicts; malformed and paginated reads; event movement; a racing calendar conflict; booking/config changes; paid state; test recipient; existing UID; invitation switch; and lock contention. No real API request is made by the test suite.

Source and tests are maintained with the SiteSee Real Estate repository. Upload only the PHP helper to the private tools directory; this README is not a public website page.
