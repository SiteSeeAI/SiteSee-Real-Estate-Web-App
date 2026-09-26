# Calendar validation repair — 2026 09 26

Booking E6E183EF8E is a paid, reviewed test booking for cro@sitesee.ai, with the arrival window 2026 09 30, 07:00–09:00 America/Chicago. Its original calendar attempt remains uncertain without an event UID. A separately authorized diagnostic retry returned HTTP 400, cURL error 0, and ARRAY_SIZE_OUT_OF_RANGE: attendees array size out of range[1-50]. That is evidence of rejection for this retry; the first attempt's discarded response remains unavailable.

The creation request incorrectly included attendees as an empty array. The corrected request omits optional attendee and reminder arrays, while retaining isprivate=true, notify_attendee=0, calendar_alarm=false, conference=none and allowForwarding=false. No recipient is added. Zoho documents attendees/reminders as optional and notify_attendee=0 as no notifications: https://www.zoho.com/calendar/help/api/post-create-event.html

## Upload these three files

Extract the updated SiteSee_Calendar_Diagnostic_20260926.zip. Replace the exact filenames below, keeping the sitesee owner. Do not allow cPanel to add numbered suffixes.

| ZIP file | Server destination |
| --- | --- |
| booking-confirmation.php | /home/sitesee/.sitesee-real-estate/server/booking-confirmation.php |
| diagnose-calendar-confirmation.php | /home/sitesee/.sitesee-real-estate/tools/diagnose-calendar-confirmation.php |
| calendar-confirmation-release.json | /home/sitesee/.sitesee-real-estate/calendar-confirmation-release.json |

The JSON file updates the existing release checksum for the corrected PHP file; it contains no credentials or settings. Existing payment, availability and mail configuration remain in place. Keep invitations disabled. This document is for deployment, not a public website page.

## First run: read-only repair check

```bash
runuser -u sitesee -- /opt/cpanel/ea-php82/root/usr/bin/php /home/sitesee/.sitesee-real-estate/tools/diagnose-calendar-confirmation.php E6E183EF8E --check-empty-arrays-repair
```

This verifies the uploaded correction's checksum/syntax, the original payload hash, the exact saved HTTP 400 rejection and stopped-at-create audit sequence, and absence of any later diagnostic attempt. It checks paid test status, saved review, configured mailbox, future arrival window, reviewed duration, privacy and disabled invitations. It holds the existing confirmation lock and reads the calendar for 36 hours either side of the shoot, blocking booking markers, remote conflicts and other local claims. It opens the existing ledger read-only and does not create an event or change the booking. Share its output before the next step.

The existing --retry-once command remains consumed and blocked. Do not delete/reset the original claim or audit history, create another booking to bypass it, or repeat checkout.

## Separate one-time corrected creation

After the read-only repair check passes and the owning SiteSee Photography calendar has been manually checked for a matching event:

```bash
runuser -u sitesee -- /opt/cpanel/ea-php82/root/usr/bin/php /home/sitesee/.sitesee-real-estate/tools/diagnose-calendar-confirmation.php E6E183EF8E --repair-empty-arrays-once
```

The helper repeats the checks, then prompts for REPAIR E6E183EF8E within 60 seconds. This authorizes one corrected request for the same booking, title/marker and shoot interval. The wider scan supplements the manual check; it cannot rule out an event renamed and moved outside that range.

Before posting, the helper rechecks booking/configuration/audit state and commits diagnostic_empty_arrays_started_v1. That durable marker blocks another repair even after a crash, lost response or further rejection. The original claim and its event_json remain intact. The audit stores hashes of both original and submitted payloads; the only changes to this booking's submitted event are omission of attendees=[] and reminders=[].

HTTP status and bounded, credential-redacted error fields are printed and recorded. A returned event UID is retained even if subsequent verification fails. Only exact identity/privacy/interval verification and a fresh conflict check allow the original confirmation to become confirmed. Any failure retains protection; share the output and do not repeat. When a UID or matching event is available, use the existing read-only Recheck Calendar Result workflow.

The helper never calls Stripe or mail and never enables invitations. Uploading the package does not create an event. Live Zoho acceptance of the corrected request still needs the controlled server step.

## Validation

Mocked tests cover wire payload omission and notification controls, read-only database byte preservation, strict rejection eligibility, missing/ambiguous audit records, payload hash mismatch, acknowledgement, successful single creation, repeat/crash protection, credential redaction, lost reply, existing event markers, conflicts, malformed/paginated reads, event movement, racing edits, booking/configuration changes, payment/test-recipient checks, existing UID and lock contention. No real API requests or messages are sent during tests.

Source changes remain in draft PR #38, branch feat/calendar-confirmation-20260925; they have not been merged into main.
