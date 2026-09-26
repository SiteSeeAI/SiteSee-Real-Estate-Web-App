# Calendar confirmation: complete lookup correction — 2026 09 26

The paid and reviewed test booking E6E183EF8E, for cro@sitesee.ai, is scheduled for 2026 09 30, 07:00–09:00 America/Chicago. Its original confirmation claim must be retained.

## Established causes and current state

1. The original request included empty optional attendees/reminders arrays. The diagnostic retry returned HTTP 400 with ARRAY_SIZE_OUT_OF_RANGE for attendees. Omitting those arrays produced HTTP 200 and an event UID, which is now saved.
2. Reading that event with an encoded %40 in its UID returned a non-JSON HTTP 404. The same request with a literal @ returned HTTP 200 and passed exact saved-event identity, privacy and interval verification.
3. The booking is still uncertain because that detail lookup failed during automatic verification. Another event creation is neither needed nor permitted.

The shared event path builder now validates IDs and preserves a literal @. Creation verification, staff Recheck Calendar Result, invitation pre-send verification, and the diagnostic helper all use it. Existing no-attendee/no-notification creation controls remain in place.

## Deploy the package together

Upload SiteSee_Calendar_Diagnostic_20260926.zip to:

/home/sitesee/.sitesee-real-estate/

Extract it there, replacing matching files and retaining sitesee ownership. This version has server/ and tools/ directories so that the files land in their correct locations:

- server/booking-calendar-client.php
- server/booking-confirmation.php
- server/booking-invitation.php
- tools/diagnose-calendar-confirmation.php
- tools/audit-calendar-confirmation.php
- calendar-confirmation-release.json

The README is deployment guidance, not a public web page. The release JSON contains checksums, not credentials. No credential, pricing, payment, mail or invitation-enable setting is included.

## One combined server command

Run in WHM Terminal:

```bash
runuser -u sitesee -- /opt/cpanel/ea-php82/root/usr/bin/php /home/sitesee/.sitesee-real-estate/tools/audit-calendar-confirmation.php E6E183EF8E --reconcile-existing
```

The command collects independent results rather than stopping at the first failed check. It checks the entire 12-file release and PHP syntax; reader/writer configuration, calendar identity and requested scopes; disabled invitations; ledger permissions and the existing confirmation lock; paid test status and staff review; saved event UID and diagnostic history; arrival window and reviewed duration; original privacy controls; other local booking claims; fresh reader and writer authorization; direct event details through both identities; privacy, guests, reminders and conference settings; complete calendar lists across 36 hours either side of the shoot; uniqueness, duplicate booking references and remote conflicts.

It reports sanitized HTTP status/errors and does not print credentials, property access codes, attendee email addresses or raw provider responses. Where one check has missing prerequisites, its dependent checks cannot run; other independent checks continue.

Only when all required checks pass does it revalidate file/configuration hashes, unchanged booking/claim state, fresh calendar evidence and local conflicts in a database transaction. It then marks the ORIGINAL booking confirmed using the already saved event UID and records calendar_confirmed. It does not change the original event_json, deposit, review, amount, invitation state or credentials.

The command makes calendar GET requests only. OAuth token refresh is its only HTTP POST. It has no calendar creation, update/delete, mail or Stripe operation. It does not enable invitations. A repeated successful run is idempotent and does not repeat the confirmation audit.

Success ends with:

```text
RESULT: CONFIRMED. Existing event verified; booking confirmed.
Calendar creations: 0. Invitations: 0. Payment calls: 0.
```

If any required check fails, it prints the collected failures and leaves the booking unchanged. Share the complete report. Do not reset claims/audits, run the consumed creation retries, create another booking, or repeat checkout.

Omit --reconcile-existing to collect the same checks with the ledger opened read-only.

## Validation and scope

The tests reproduce the observed provider behavior: encoded %40 detail routes return 404; literal @ routes return the event. They exercise new confirmation, reconciliation by saved UID and by marker, invitation verification with mocked mail, no-guest payloads, and path injection rejection. Combined-audit tests cover both reader/writer paths, read-only database preservation, successful existing-event reconciliation, idempotence, collecting multiple failures, credential redaction, missing/moved events, duplicate markers, conflicts, incomplete calendar lists, stale releases, changed booking/configuration, unpaid bookings, absent UID and lock contention.

All requests in local tests are mocked. The live corrected detail probe already passed; the combined deployed check and local reconciliation still need the command above.

Changes remain in draft PR #38 on feat/calendar-confirmation-20260925. Main has not been merged. Completing this command resolves the existing test calendar confirmation; invitation delivery/rendering and live payment activation are separate stages and remain disabled.
