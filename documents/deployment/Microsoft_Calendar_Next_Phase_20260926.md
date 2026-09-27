# Microsoft Calendar and Booking Changes — 2026 09 26

## Agreed direction

Use the Microsoft 365 calendar belonging to `sales@re.sitesee.ai` for new
SiteSee Real Estate scheduling. Retain Zoho CRM contact links and the established
API association of sent email with the correct CRM record. Zoho Calendar is to
be retired from the active scheduling path only after a controlled TEST
transition. The user approved this direction on September 26, 2026.

The Zoho work remains useful: verified customer identity, email association,
communication history, schedule fitting, staff review and duplicate protection
carry forward. This is not a replacement of Stripe checkout or the CRM.

The user also requested assessment of client cancellation/modification links
and service changes, including staff upsells in the field. Detailed customer
automation and money-handling policies are proposals awaiting direction, not
approved changes to the existing terms. No live payments are authorized.

## Current state and boundaries

Branded TEST checkout validation is complete as recorded in
`Branded_Checkout_20260926.md`. E4E51A0481 has a paid deposit, a confirmed
September 30, 9–11 AM Central arrival window and an invitation reported sent
and received. D32FFC7458 was the mobile/decline/3D Secure payment test and was
designated to remain awaiting staff review.

The original E6E183EF8E still has a stored reservation despite the reported
manual deletion of its Zoho event. Do not erase it, replay its invitation, or
copy all old appointments into Microsoft. Inventory and reconcile each existing
record, retaining its history and original invitation identity. Old invitations
cannot be assumed to become Microsoft-native meeting objects by creating new
events with the same title or reference.

The current Microsoft connection uses application credentials and permits mail
operations only in its application transport. The new calendar adapter must be
separate; do not broaden the global sendmail bridge or weaken its route guards.
The existing application may need narrowly scoped calendar authorization;
working mail delivery is not proof of calendar permissions. No new credentials,
application permissions, licensed CRM users, mailbox owners or global sender
settings have been changed or assumed necessary by this assessment.

Zoho's current application transport permits contact lookup and email
association, not CRM appointment creation. A linked CRM appointment record is
an additional capability requiring an explicit adapter and verification of the
appropriate scopes and fields. Native Zoho mailbox synchronization remains off.

## First morning step: one read-only Microsoft check

Upload `tools/check-microsoft-calendar.py` to `/home/sitesee/`, then run:

```bash
python3 /home/sitesee/check-microsoft-calendar.py
```

It reuses `/home/sitesee/.sitesee-graph-mail.json`, without displaying secrets,
and reads the current TEST booking-mail configuration. It identifies the
default calendar, displays the mailbox's calendar inventory and tests a bounded
default-calendar event read. Event titles, bodies, attendees and locations are
not requested. Calendar names, owners and calendar IDs are displayed for review.
The event read requests only one ID to check access; it is not an availability
report and does not claim to inventory all events.

The only POST obtains an OAuth access token. Microsoft resource requests are
GET-only, restricted to this mailbox and three calendar read endpoints. The
tool refuses redirects and foreign-host or other-mailbox continuations, checks
private-file permissions, limits response sizes and pagination, and prints only
fixed failure descriptions. It does not open the booking database, write files,
send email, create events, modify permissions or activate migration.

A PASS establishes read access only. The `canEdit` flag is reported, but effective
application write permission is explicitly untested. A 403 indicates permission
or mailbox-access review is needed; it must not be treated as an empty calendar.
If the returned owner differs from the requested address, verify the mailbox's
primary address/alias relationship before choosing the production identity.
Review the actual output before preparing any permission or installation step.

Ten local tests pass for GET-only resource requests, redacted authentication
errors, access-denied behavior, valid and unsafe pagination, private-file
checks, redirects and explicit separation of read success from write access.
Provider responses were mocked. No actual Microsoft calendar access has yet
been verified by this diagnostic.

## Microsoft permission setup after the observed 403

The server preflight on September 27 reached the default-calendar request and
received HTTP 403. Treat this as an authorization/access failure, not an empty
calendar. The exact missing permission or scope is not established by that
response alone. The user requested a consolidated setup instead of successive
manual portal steps and ID questions.

`tools/setup-microsoft-calendar-access.ps1` performs that setup in a normal
Windows PowerShell window under the operator's own Windows account. It installs
Microsoft.Graph.Authentication and ExchangeOnlineManagement for CurrentUser
from the official PowerShell Gallery when needed. It makes no permanent
execution-policy change. Microsoft sign-in and the organization's permission
policies remain mandatory; use an administrator able to read application grants
and manage Exchange application roles. Do not run this PowerShell file in WHM.

The existing tenant/application IDs are nonsecret identifiers, obtained from the
server's existing credential file. No secret is requested or copied. Microsoft
Graph PowerShell requests delegated `Application.Read.All` for inspecting the
existing application and its assigned permissions. That administrator sign-in is
separate from the application's existing client-credentials connection.

The script verifies both signed-in tenants, resolves the enterprise service
principal object ID automatically, and checks the approved mailbox primary
addresses. It rejects existing Entra calendar/full-mailbox grants or different
Exchange calendar scopes for review rather than silently modifying them or
claiming they are restricted by the new scope. Unrelated mail grants are retained.

It creates or reuses an Exchange pointer to the **existing** application, an exact
`EmailAddresses -eq 'smtp:sales@re.sitesee.ai'` recipient scope, and the scoped
`Application Calendars.ReadWrite` role assignment. It does not add an Entra
tenant-wide Calendars.ReadWrite grant. Before authorization, the scope preview
must resolve exactly one recipient, with the verified sales mailbox object ID.
The final RBAC checks require calendar access in sales and exclusion of cro.
An unchanged rerun validates matching objects without creating duplicates.

Only configuration is verified by these RBAC tests. They do not exercise a real
calendar read/write, confirm that cached Graph access is ready, or activate the
SiteSee Microsoft calendar adapter. No event, invitation, email, booking,
payment, application secret, Zoho setting or existing mail permission is changed.

### Consolidated run sequence

1. Save `setup-microsoft-calendar-access.ps1` in the Windows Downloads folder.
2. In WHM Terminal, run this single command. It prints a complete Windows command
   with the existing IDs already filled in. It prints no secret or token.

```bash
python3 - <<'PY'
import json, re
from pathlib import Path
config = json.loads(Path('/home/sitesee/.sitesee-graph-mail.json').read_text())
ids = [str(config.get(k, '')) for k in ('tenant_id', 'client_id')]
if not all(re.fullmatch(r'[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}', v) for v in ids):
    raise SystemExit('STOP: Existing application IDs could not be verified.')
print("& { $f = Join-Path $env:USERPROFILE 'Downloads\\setup-microsoft-calendar-access.ps1'; Unblock-File -LiteralPath $f; powershell.exe -NoProfile -ExecutionPolicy RemoteSigned -File $f -TenantId '" + ids[0] + "' -ApplicationId '" + ids[1] + "' }")
PY
```

3. Open **Windows PowerShell** from the Windows Start menu, paste the generated
   command and complete the Microsoft administrator sign-ins. Module installation
   and all supported setup/verification steps are automatic. `RemoteSigned`
   applies only to the child process, and only the downloaded setup file is
   unblocked. Do not bypass an organizational execution or sign-in policy.
4. If the script reports permission configuration PASS, run its printed WHM
   command: `python3 /home/sitesee/check-microsoft-calendar.py`. Review both final
   result blocks. A real Graph read PASS remains required before migration work
   can treat the mailbox as accessible; event writes remain untested.

Microsoft documents a 30-minute to two-hour application permission cache window;
the RBAC test bypasses that cache. A continued immediate 403 does not justify
replacing keys or adding tenant-wide permission. If setup passed, allow that
window before retrying the existing read-only check; persistent failure needs
review of the actual results and policies, not repeated permission changes.

### Failure and recovery

The setup stops on mismatched identities, existing conflicting authorization,
ambiguous/missing directory results, unexpected pagination and invalid scopes.
On failure it attempts to remove only the new role assignment and scope whose
creation this invocation confirmed. A newly registered Exchange service-principal
pointer may remain; it does not grant access on its own. Existing objects are
never rewritten or removed.

A remote timeout may occur after a write succeeded. The script does not claim
complete rollback in that case. Keep STOP and REVIEW REQUIRED output. A rerun
with the same IDs inspects the deterministic names and reuses verified objects.
Do not delete other applications, mail grants, tenant policies or scopes to
recover. Do not roll back working mail or branded checkout for a calendar error.

### Windows prerequisite correction after the first run

The first administrator run stopped in module installation: PackageManagement
reported that `Find-Package`, `Install-Package` and `Uninstall-Package` were
already present and required `-AllowClobber`. This occurred before administrator
connections or calendar authorization writes. Some local dependencies may have
installed before the stop; rerunning the setup checks for installed modules.

Revision 2 added `-AllowClobber` to the Microsoft-tool `Install-Module`
calls, allowing the official dependencies to supply the overlapping commands.
It retains `CurrentUser`, the verified official PSGallery URL and normal
publisher checks. It does not set a global installation default, mark other
repositories trusted, remove existing modules or change execution policies.

The user reported the same error again. That output did not identify which
downloaded revision or module-installation stage was running; do not infer that
the replacement file was used or that the additional parameter fixed the actual
Windows environment. The user independently reported Windows PowerShell
5.1.26100.9549, a supported host version.

Reference: https://learn.microsoft.com/en-us/powershell/module/powershellget/install-module?view=powershellget-2.x

### Consolidated recovery revision 20260927-r3

Revision 3 checks Windows PowerShell 5.1, a 64-bit process and .NET Framework
4.7.2 or newer. It prints its revision, exact file path and SHA256 on startup and
the phase, failing command/line and error ID on failure. No credentials are
requested beyond the existing nonsecret application/tenant IDs and Microsoft's
own administrator sign-in screens.

The prerequisite phase explicitly prepares PackageManagement 1.4.8.1 and
PowerShellGet 2.2.5, the stable versions documented by Microsoft. It then exits
that process. A fresh process imports those exact versions and verifies that
`Install-Module` actually resolves to PowerShellGet 2.2.5 before installing the
two required Microsoft modules. The installer verifies available versions after
installation and restores the default PSGallery registration only if absent;
it rejects an existing PSGallery entry pointing elsewhere.

Microsoft's PowerShellGet 2.2.5 source shows that `-Force` suppresses the list of
already installed dependencies sent to the NuGet provider. That can trigger
reinstallation of loaded package managers. Revision 3 therefore installs only
missing versions, specifies exact versions for side-by-side installation, and
uses `-AllowClobber` **without** `-Force` for modules. NuGet provider bootstrap
retains its separate force flag. Normal publisher checks and repository trust
settings are preserved. If PowerShell asks to install from the official
PSGallery, the operator must answer Y; no policy is silently weakened.

Graph directory review and Exchange administration also run in separate fresh
processes, avoiding shared authentication assemblies. A temporary handoff holds
only revision, tenant, application and enterprise-principal IDs. Both parent and
Exchange phase validate the handoff; it contains no secret/token and is removed
when the parent finishes. A failed phase prevents every subsequent phase.

For recovery, the downloadable copy has a unique filename,
`sitesee-calendar-setup-20260927-r3.ps1`. Save it to Downloads and run the following
block in the same Windows PowerShell window. It verifies the exact file bytes
and extracts only GUIDs from the prior setup command in the current window's
history; it never re-executes history text or reads persistent shell history.
If the original command is no longer in that window's history, the installer
prompts for the two IDs already printed in WHM; no ID or key is sent to chat.

```powershell
& {
    $ErrorActionPreference = 'Stop'
    $f = Join-Path $env:USERPROFILE 'Downloads\sitesee-calendar-setup-20260927-r3.ps1'
    if ((Get-FileHash -LiteralPath $f -Algorithm SHA256).Hash -ne '0C63B053A3287F26133344EC4EA02E115E3F9CBE4527C8DEABC9CCC0787E8634') { throw 'STOP: This is not the verified r3 installer. Download the new r3 file linked in chat.' }
    $pattern = "-TenantId\s+'(?<tenant>[0-9a-fA-F-]{36})'\s+-ApplicationId\s+'(?<app>[0-9a-fA-F-]{36})'"
    $previous = Get-History | Where-Object { $_.CommandLine -like '*setup-microsoft-calendar-access.ps1*' -and $_.CommandLine -match $pattern } | Select-Object -Last 1
    $ids = @{}
    if ($previous) { $m = [regex]::Match($previous.CommandLine, $pattern); $ids = @{ TenantId = $m.Groups['tenant'].Value; ApplicationId = $m.Groups['app'].Value } }
    Unblock-File -LiteralPath $f
    & powershell.exe -NoLogo -NoProfile -ExecutionPolicy RemoteSigned -File $f @ids
}
```

Complete the Microsoft sign-ins. If the final permission configuration passes,
run the existing WHM read-only check once and review its output. A configuration
PASS is still distinct from actual Graph access or an event-write test. Never
repeat permissions blindly if another failure occurs; collect the revision,
stage and FINAL RESULTS now printed by this installer.

References:

- https://learn.microsoft.com/en-us/powershell/gallery/powershellget/install-powershellget?view=powershellget-3.x
- https://learn.microsoft.com/en-us/powershell/exchange/exchange-online-powershell-v2?view=exchange-ps
- https://learn.microsoft.com/en-us/powershell/microsoftgraph/installation?view=graph-powershell-1.0

### Tenant verification recovery revision 20260927-r4

The user's r3 run completed Windows prerequisites, Microsoft-tool installation
and directory review, then stopped in Exchange before any authorization write:
`The Exchange sign-in belongs to a different tenant.` Its code compared only
`Get-OrganizationConfig.ExternalDirectoryOrganizationId` with the expected ID.
Because an absent field also failed that comparison, the message alone cannot
establish that the operator selected the wrong account. Neither the actual
tenant values nor the actual cause of the reported stop is known yet.

Revision 4 uses Microsoft's documented `Get-ConnectionInformation.TenantID`
from exactly one connected, direct Exchange Online session. It disallows
delegated routing and Security & Compliance sessions. The active tenant must
be a valid GUID matching the server tenant. An organization tenant ID, when
returned, must also be valid and match; a missing organization field is reported
explicitly rather than mislabeled as a mismatch. Missing active identity,
multiple connections and conflicting IDs still stop before any permission write.

The Graph context's administrator account is included in the temporary handoff
and passed to `Connect-ExchangeOnline -UserPrincipalName`, keeping account
selection tied to the already verified directory sign-in. This is a sign-in
hint, not a replacement for verifying the actual Exchange tenant afterward.
FINAL RESULTS now includes the expected tenant, Exchange account and both
returned tenant fields on an identity failure, without exposing tokens or secrets.

The download is `sitesee-calendar-setup-20260927-r4.ps1`; use its supplied hash-
checked launch command, not the old r3 command. Prerequisite phases retain their
existing-version checks and do not reinstall modules already available. Enter
the existing tenant/application IDs if requested. No new credentials are needed.

Official references:

- https://learn.microsoft.com/en-us/powershell/module/exchangepowershell/get-connectioninformation?view=exchange-ps
- https://learn.microsoft.com/en-us/powershell/module/exchangepowershell/connect-exchangeonline?view=exchange-ps

### Local verification

PowerShell 7.4.13 parsed the script and passed 18 offline behavioral cases in
`tests/microsoft_calendar_access_test.ps1`: new setup and unchanged rerun,
tenant/mailbox mismatches, broader existing grants, scope membership, negative
mailbox access, cleanup on known failures, ambiguous write recovery, conflicting
scope preservation, directory pagination, empty/missing results, input guards,
dependency-command overlap with rerun, and rejection of a redirected repository.
The additional tenant cases cover an absent organization field with a verified
direct connection, absent/conflicting/invalid IDs, multiple connections and
delegated routing; no tenant mismatch is bypassed.
Ten additional cases in `tests/microsoft_calendar_bootstrap_test.ps1` verify
old-package-manager recovery, missing gallery/NuGet, no forced dependency
overwrite, install failure, installer command resolution, phase order, stopping
after each failed phase, handoff mismatch/cleanup, invalid-ID diagnostics and
verified administrator-account handoff and real local child-process argument/exit handling. The child-process fixture is
inert and performs no setup or Microsoft calls.
All Microsoft responses were simulated. The administrator sign-ins, module
installation on the user's Windows system, tenant configuration and actual
Microsoft calendar calls have **not** been verified by these local tests.

Official permission references:

- https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac
- https://learn.microsoft.com/en-us/graph/api/serviceprincipal-list-approleassignments?view=graph-rest-1.0
- https://learn.microsoft.com/en-us/powershell/exchange/recipientfilter-properties?view=exchange-ps
- https://learn.microsoft.com/en-us/powershell/exchange/connect-to-exchange-online-powershell?view=exchange-ps
- https://learn.microsoft.com/en-us/powershell/microsoftgraph/authentication-commands?view=graph-powershell-1.0

## Calendar implementation and transition

### Actual permission and server-read results — September 27, 2026

The user reported a PASS from permission setup revision `20260927-r4`:

- Application: `125a86f5-2a29-4b1e-8b8a-26026e3fa59c`.
- Enterprise application object: `8d26b7a9-b7c1-4a31-be76-68447935edbf`.
- Application calendar read/write scope: `sales@re.sitesee.ai` only.
- Excluded mailbox `cro@sitesee.ai`: PASS.
- Existing mail grants, application secrets, Stripe and Zoho unchanged.

The subsequent WHM read-only preflight also passed actual Microsoft calls:
default calendar owner `sales@re.sitesee.ai`, name `Calendar`, `canEdit: YES`,
calendar metadata/inventory reads PASS and default event read PASS. The other
visible calendars were United States holidays and Birthdays. The selected ID is:

```text
AAkALgAAAAAAHYQDEapmEc2byACqAC-EWg0AhxzFTH7h2UC_-_f2aEjbcQAAAAA0UAAA
```

That preflight did not calculate availability or write an event. Effective event
creation/removal is still unverified; migration is disabled. The earlier
permission/setup failures do not require another Windows setup or new key now.

The next package, documented in `Microsoft_Calendar_Connection_20260927.md`,
installs a separate, inactive PHP connection and runs one recoverable private
event test from the server. It does not replace Zoho booking controllers. Their
confirmation and invitation checks currently use Zoho-specific event identities;
an actual provider-aware scheduling release is required before switching them.

The first connection-installer run then stopped locally at the `server` directory
check, before installing files or making Microsoft requests. Its generic message
did not identify the directory's owner/mode. Installer r2 corrects rejection of
safe root-owned code directories, preserves all existing permissions, and adds
an actual application-user PHP access check plus detailed metadata diagnostics.
The unchanged PHP/config payload and any existing probe journal remain intact.
Actual event creation/removal and migration are still unverified/disabled.

R2's actual output then confirmed both `server` and `tools` were SiteSee-owned
(UID 1009/GID 1011) with mode `0777`, followed by `booking-schedule.php` at `0666`.
This establishes excess write permissions as the observed blocker. At the user's
request, the r3 consolidated finish-and-repair runner now checks the full local
release, collects independent findings, records/applies only verified permission
reductions, rechecks, installs and continues the same temporary-event test with
one bounded transient recovery retry. See `Microsoft_Calendar_Recovery_20260927.md`.
No actual Microsoft write success has been reported yet.

1. Verify the mailbox/calendar and appropriate application access. Keep Microsoft
   scheduling disabled until TEST creation, change, cancellation, conflict and
   notification checks pass. Review mailbox-scoped access rather than granting
   unrelated tenant-wide access by default.
2. Add provider-aware event identities and a durable operation history. Preserve
   existing Zoho records with their provider identity. Use stable retry keys,
   immutable Microsoft IDs where supported, and version checks. A timeout remains
   an uncertain operation requiring reconciliation, not permission to duplicate
   an event or release its time.
3. Use one availability calculation for customer alternatives and final staff
   confirmation: fresh Microsoft busy intervals plus active local reservations,
   the reviewed duration and existing notice rules. Exclude the same booking's
   own event when checking a proposed move. Recheck before committing a choice.
4. Track Microsoft edits/deletions and reconcile the corresponding local record.
   Change notifications can prompt reads; periodic reconciliation covers missed
   notifications. Verify the specific event and calendar identity. A network
   error or empty range is not a cancellation. Record external edits for staff
   review when they conflict with customer agreement, duration or pricing.
5. Add staff cancellation/rescheduling controls and availability alternatives.
   Hold the old reservation until the move is verified; reserve a proposed new
   interval durably while the provider operation is unresolved. Release obsolete
   time after verified completion, retaining the historical record.
6. Update the linked CRM appointment/history through a recoverable, separate
   operation. A CRM outage must show a pending synchronization state, not cause
   another appointment or another invitation. Review CRM automation so it does
   not produce a duplicate customer invitation.

The two-hour customer arrival window and the private shoot-duration block must
remain distinct. A single Microsoft event cannot have different start/end
times for its organizer and attendees. Evaluate linked internal and customer
events/calendars, or retain the controlled invitation representation where
needed. Never place access codes in the customer event. The availability reader
must count the intended shoot block without counting a second copy of the
arrival-window invitation as another reservation.

Microsoft sends invitations automatically when attendees are added to a created
meeting. Preserve separate staff confirmation and invitation authorization:
internal confirmation must not silently add the customer as an attendee.
Do not run the existing custom invitation sender and a native meeting invite
for the same action. Preserve evidence and CRM association of the actual sent
message, and test update/cancellation behavior in the customer's mailbox.

## Client cancellation and modification link

Recommended first version: a branded **Manage My Booking** page with **Request
a Date Change**, **Request a Cancellation**, and **Request Service Changes**.
Requests are immediately acknowledged and visible to staff; requested changes
are clearly distinguished from completed calendar changes or refunds. The
customer can choose from current alternatives, with a final fresh check before
staff approval. Showing alternatives alone does not reserve them.

Use a separate revocable management token/session, not the booking reference as
a password or the existing payment token with its seven-day expiry. An email
scanner opening a link must never cancel a booking: GET displays the page;
authenticated, CSRF-protected POST with explicit confirmation requests a change.
Limit repeated attempts, redact tokens from logs and use email verification for
renewal or stronger identity checks as appropriate. The scheduling contact or
person opening the property is not automatically authorized to approve charges.

The current accepted copy says cancellation at least 24 hours before the
confirmed appointment refunds the deposit; within 24 hours the deposit becomes
a credit toward one rescheduled shoot. Preserve that rule. The operational
cutoff should be clearly defined against the customer-visible confirmed time;
the private internal start must not silently change the customer's deadline.
Clarify this with the user before implementation. Record the original request
time so staff processing delay does not change which side of the cutoff applies.

Calendar cancellation and the refund/credit are separate recorded operations.
A completed cancellation may release availability while a refund is pending;
do not call the refund complete until Stripe confirms it. A rescheduling credit
needs its own tracked usage, so one credit cannot be applied to several jobs.
Automatic refunds, cancellation fees, credit expiry and automatic customer
rescheduling are not approved by this plan.

## Product/service changes and field upsells

Recommended shared workflow for customers and staff:

1. Propose additions/removals or quantity changes against the current approved
   order version. Use the existing residential/commercial pricing functions,
   package rules, limits and duration calculations. A staff price exception
   needs a reason and an audit entry.
2. Show the changes, revised scope, price difference, revised total, deposit
   already paid, remaining balance and added time. Keep recurring hosting or
   platform costs separately visible from one-time services.
3. Obtain approval from the authorized customer for the exact version and amount.
   Staff can prepare a proposal from a phone; the customer can approve on their
   own device using a secure link or QR code. QR display requires no new SMS
   service. Staff entry of a proposal is not customer authorization to charge.
4. Check capacity again if the shoot becomes longer, including the following
   appointment. An on-site upsell uses remaining time and actual schedule impact;
   it must not incorrectly apply a new-booking 72-hour notice rule to the job
   already underway. If the added work does not fit, offer a separate visit or
   decline/defer the addition rather than overlap another booking.
5. Apply an approved revision once, preserve earlier versions and the original
   Stripe deposit, and update the customer summary, schedule and CRM history.
   Pending proposals must not silently overwrite confirmed scope or paid amounts.

The database currently stores the original request and a deposit payment, not
versioned order changes or a final-balance payment ledger. Final balance
collection is explicitly not enabled in the current TEST release. Scope changes
therefore require new order-version and payment/refund records, not a direct
edit of `quote_cents`, `deposit_cents` or the original payment intent.

Illustrative balance-only proposal, subject to the user's decision: the current
$256.90 job plus an approved $100 addition becomes $356.90; its existing $128.45
deposit remains credited and the balance becomes $228.45. This example does not
authorize changing the 50% base-deposit rule or decide whether revised orders
need a deposit top-up. Keep the $59 approved rush fee on the balance only.

Decide whether additions are added to the final balance, paid immediately, or
require a revised deposit top-up. Service removals need clear treatment of
already performed work and any credit/refund; never create a negative balance
and silently convert it into a refund. Recurring services remain subject to
their separate publication and billing terms.

Existing TEST card consent already mentions separately approved on-site
services, and Checkout requests future off-session usage. That is a foundation,
not permission for unapproved additions or proof every later charge will succeed.
Record approval of each change, identify the new payment purpose/version, retain
idempotency protection, and provide an authenticated customer payment step if
Stripe requires further authentication. New charges/refunds require their own
verified provider events; the original deposit's return URL cannot authorize
them. Changes before deposit payment must also handle any open Checkout session
without leaving two different amounts payable for the same order.

## Complexity and recommended sequence

| Feature | Relative effort | Main work |
| --- | --- | --- |
| Branded management link and request forms | Moderate | Scoped access, clear request states, staff queue and confirmations |
| Microsoft scheduling with staff cancel/reschedule | Substantial | Provider transition, durable reservations, native notifications and reconciliation |
| Consistent available alternatives | Moderate after calendar work | Shared availability rules, duration fitting and fresh final check |
| Customer/staff service proposals | Substantial | Versioned scope, existing pricing, schedule impact and approval evidence |
| Immediate add-on/final-balance payment and refunds | Substantial | Separate payment ledger, policy decisions, authentication and retry recovery |
| Fully automatic customer changes | Later phase | All preceding rules plus eligibility limits and robust exception handling |

Start with the read-only Microsoft check. Then implement Microsoft TEST
scheduling, staff cancellation/rescheduling, consistent alternatives and staff
CRM contact linking. Add the management page and staff-assisted service proposals
next, followed by the chosen balance/refund behavior. Customer automation can
then expand without rebuilding the underlying order and scheduling records.

No fixed delivery estimate is promised before the mailbox check and the open
policy decisions. All implementation/deployment should remain in the existing
draft branch, with consolidated installers where practical. Do not merge main,
delete branches or activate live payments without instructions.

## Official references reviewed

- Microsoft calendar listing: https://learn.microsoft.com/en-us/graph/api/user-list-calendars?view=graph-rest-1.0
- Microsoft calendar access: https://learn.microsoft.com/en-us/graph/api/calendar-get?view=graph-rest-1.0
- Microsoft event reads: https://learn.microsoft.com/en-us/graph/api/calendar-list-calendarview?view=graph-rest-1.0
- Microsoft event creation/invitation behavior: https://learn.microsoft.com/en-us/graph/api/user-post-events?view=graph-rest-1.0
- Microsoft event identity, retries and change tracking: https://learn.microsoft.com/en-us/graph/api/resources/event?view=graph-rest-1.0
- Microsoft cancellation: https://learn.microsoft.com/en-us/graph/api/event-cancel?view=graph-rest-1.0
- Zoho CRM linked event records: https://www.zoho.com/crm/developer/docs/api/v8/insert-records.html
- Stripe saved-card authentication: https://support.stripe.com/questions/saving-payment-methods-for-subscriptions-after-strong-customer-authentication-%28sca%29-regulations-take-effect?locale=en-GB
- Stripe refunds: https://docs.stripe.com/api/refunds/create
