# Microsoft calendar consolidated recovery — 2026-09-27

## Verified server connection — 2026-09-27

The user supplied the consolidated runner's actual FINAL RESULTS: Microsoft TEST
calendar connection PASS; six local permission repairs; temporary private TEST
event creation, readback and removal verified (a completed prior test is reused
when present). Scheduling migration was NOT ENABLED, and payments remain TEST.
No booking database, customer invitation, email, payment, credential content or
Zoho setting was changed by that connection run.

Keep the exact permission recovery record:
`/home/sitesee/.sitesee-real-estate/microsoft-calendar-recovery-7n7lklo6/permission-repairs.json`.

The next release is documented in `Microsoft_Scheduling_20260927.md`. Its installer
activates Microsoft for new TEST confirmations only after all local checks and a
fresh calendar read. Actual deployment and a real booking through that new flow
remain unverified until subsequent server output and the controlled booking test.

## Observed failure and scope

The r2 installer identified the actual local blocker: `server` and `tools` were
both owned by UID 1009/GID 1011 with mode `0777`. Ownership was correct. Its
earlier generic r1 error had not established ownership as the cause; the root-owner
compatibility correction was useful but did not resolve these observed modes.

A subsequent attempt reached `server/booking-schedule.php`, owned by the same
account, single linked, 8034 bytes, mode `0666`. Both attempts stopped before
installation or Microsoft event calls. The user then requested one consolidated
diagnose/repair/continue process instead of separate terminal actions per file.

The replacement is `tools/finish-microsoft-calendar-connection.py`, revision
`20260927-r3`. It embeds the reviewed r2 installer and unchanged r1 PHP/config
payload. No runtime download, dependency install, new credential or user prompt
is required. Microsoft scheduling remains disabled; this is the connection-test
stage, not the provider migration/cancellation/rescheduling release.

## One upload, one command

Upload the single supplied file to
`/home/sitesee/finish-microsoft-calendar-connection.py` and run in WHM as root:

```bash
python3 /home/sitesee/finish-microsoft-calendar-connection.py
```

This replaces the individual chmod commands and the r1/r2 installer command.
Already corrected permissions and already installed identical files are reused.
An existing temporary-event journal is retained. Optional `--check` performs
only the local inventory; it is not a required preliminary step.

## Combined local inventory

Independent checks continue after a finding so one report can include:

- Existing calendar and branded-checkout manifests, required entries and every
  recorded release-file hash; cross-manifest mismatches remain visible.
- Owner, real file/directory type, single-link files, permission modes and
  symlink-free paths for the named release directories/files.
- Existing mail TEST identity, fixed application ID, credential structure and
  pinned calendar configuration. Credential values never appear in output.
- Existing connection files against the embedded package, and the structure,
  identity and stage of any saved temporary-event journal.
- Available disk bytes/inodes, PHP syntax/capabilities, directory traversal and
  file readability as the actual SiteSee account.

The new connection's one existing PHP dependency, `booking-mail-client.php`, is
also pinned to its reviewed source hash independently of the local manifests.
No existing application PHP is executed by the inventory. The booking database,
Zoho events and customer communication records are not opened.

The report distinguishes planned permission repairs from findings needing
review. If a blocking finding exists, no permission repair, application install
or provider call occurs. Later checks depending on valid manifests or available
paths cannot manufacture missing evidence; their missing/invalid prerequisites
are reported. This is a full set of currently detectable independent local
findings, not a promise to predict every provider/network failure.

## Automatic repairs and continuation

Only verified, allowlisted metadata is repaired. The runner removes group/other
write bits from release directories and known nonsecret files. For known private
configuration/credential/journal files, it removes group/other access. Examples:
`0777 -> 0755`, `0666 -> 0644`, private `0666 -> 0600`. It never adds permissions,
changes ownership, recursively chmods a tree, changes credential contents or
overwrites a different application file. Unrelated owners, symlinks, hardlinks,
special mode bits, invalid identity, missing PHP or unknown content require review.

Before chmod, a root-private recovery record is written and synced under
`microsoft-calendar-recovery-<random>/permission-repairs.json` in the private app
directory. It records original/target modes and ownership, without credential
contents or their hashes. Each open target is rechecked for the same inode,
owner, original mode and contents before its mode changes. Completed steps are
recorded durably. A concurrent change stops that repair instead of guessing.

Safe reductions already completed remain in place after a later failure. They
are not automatically reverted to `0777`/`0666`. An unchanged rerun inventories
what remains and continues. Existing file bytes and ownership are preserved.
After repair, a complete second inventory must pass without outstanding repairs,
followed by the original installer's strict checks and atomic additive install.
Its existing application-install rollback remains in effect.

The runner then executes the unchanged CLI Microsoft test as SiteSee: one private
free no-attendee event is created, read back, removed and verified absent. Its
existing transaction ID/recovery state prevents duplicate events. A completed
prior test is reused, with its original verification time printed.

For recognized transient failures, the runner permits one additional attempt
after three seconds, using the same saved operation. Each child process has a
240-second limit. Missing/invalid journals, identity or attendee changes,
authentication failures and permission denial are not bypassed or repeatedly
retried. No permissions are granted and no keys are replaced. The original
uncertain-create logic still reconciles an existing event and refuses a second
POST when the result is ambiguous.

## Validation and limitations

Twelve recovery-runner cases passed locally. They cover the exact observed folder
and scheduling-file modes together with additional downstream mode issues;
simultaneous hash/configuration/journal/disk/PHP failures; read-only inventory;
preserving unknown files and unsafe links; concurrency checks; durable repair
records; interruption/rerun; byte/owner preservation; transient retry with the
same operation; refusal to retry identity/permission errors; bounded retry and
completed-journal evidence; and unchanged reuse of a prior completed test.
Embedded packages rebuild byte-for-byte. Runner and embedded installer syntax
also parse under Python 3.6 grammar.

Existing PHP provider tests are unchanged, and provider responses in local tests
are simulated. This execution namespace allows only UID/GID 0, so actual
SiteSee-account access remains a server check. Local validation does not establish
real event writes, successful cleanup or migration. Review the user's FINAL
RESULTS before treating the Microsoft connection as verified.

The next product release still needs provider-aware scheduling identities,
consistent alternatives, reservation reconciliation and controlled staff changes.
Do not copy old Zoho events, resend historical invitations, change payment policy
or enable live payments as part of this recovery.
