# Microsoft 365 booking mail readiness

Target sender: `sales@re.sitesee.ai`. Existing authorized test booking: `E6E183EF8E`, recipient `cro@sitesee.ai`.

The mailbox has been created. Its screenshot identifies a licensed Exchange Online Plan 1 user. The booking's Zoho event has already been reconciled and confirmed. The previous invitation is recorded as accepted by the mail service, but recipient delivery was not established.

The earlier SiteSee corporate deployment package contains `/usr/local/bin/sitesee-graph-sendmail`, using `/home/sitesee/.sitesee-graph-mail.json`. PHP `mail()` may already invoke that Graph bridge. It overwrites the message From header with the configured sender; the invitation's iCalendar organizer is independently generated from `SITESEE_FROM_EMAIL`. The bridge's existence in a prior package does not establish which route is currently active on the real estate site. No Exim log match establishes neither delivery nor nondelivery.

## One server run

Upload `tools/check-booking-mail-integration.py` to:

`/home/sitesee/.sitesee-real-estate/tools/check-booking-mail-integration.py`

Run in WHM Terminal as root:

```bash
python3 /home/sitesee/.sitesee-real-estate/tools/check-booking-mail-integration.py
```

The script has no write or send mode. It collects independent results even if a section fails. It reads existing credentials privately and issues temporary OAuth access tokens without changing credential files. It does not display tokens, client secrets, message bodies, or unrelated email subjects.

The report covers:

- Current booking, invitation, and saved CRM contact state.
- PHP-FPM/INI mail settings and the installed Graph bridge's static markers.
- Existing Graph application authentication and read access to the new sender mailbox. Advertised token roles and successful reads do not prove effective send permission.
- The previous invitation's exact subject and recipient in the configured sender's Sent Items, including MIME calendar metadata and sender/organizer consistency.
- Same-booking incoming notices, with delivery-status correlation by original message ID.
- Recipient inbox, junk, and deleted-folder copies when Graph can resolve the authorized recipient as a mailbox. A Graph user lookup failure can occur for aliases and is not evidence of nondelivery.
- Public MX, SPF-related TXT, DKIM selector CNAMEs, and subdomain/parent DMARC records; cPanel domain routing membership.
- Fresh reader and writer verification of the existing Zoho event's identity, privacy, interval, and guests through the corrected literal-`@` lookup.
- Whether the deployed invitation code is the audited version lacking CRM email association, and any declared CRM scopes in the booking application's private Zoho configurations.

## Limits and next implementation

The script does not change the corporate Graph sender, PHP settings, DNS, cPanel routing, booking ledger, CRM records, calendar, payments, or mail queue. It creates no email or draft. It does not replay the previous invitation or reset its sent state.

Sent Items is provider evidence, not proof of recipient inbox delivery. The mailbox scans use bounded time windows and fail explicitly if pagination is incomplete. DNS records do not prove that DKIM signing is active. FPM settings are read from disk; live worker configuration is not directly queried. CRM account identity and native mailbox synchronization require their own authenticated integration context. Calendar scheduling conflicts must be checked again by the send gate immediately before a later authorized invitation recovery.

Use the combined report to choose the existing Graph application's appropriate scope or a separate application, set a dedicated real estate sender without altering corporate mail, align From/Reply-To/iCalendar organizer, implement explicit Zoho CRM contact/email association with message-ID deduplication, and preserve the current Zoho event. Only then perform the controlled end-to-end invitation test, recording provider submission separately from delivery and CRM association.

Local validation: 14 tests cover the read-only HTTP boundary, redirects, private file permissions, redaction, complete pagination, exact recipient matching, sender/organizer mismatch, nondelivery report correlation, missing databases, and continuing after independent failures. Network calls are mocked; this is not a production mailbox test.
