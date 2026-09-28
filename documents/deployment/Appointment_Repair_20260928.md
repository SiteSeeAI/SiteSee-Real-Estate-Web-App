# Appointment Management Repair — 2026 09 28

This forward repair applies to the installed appointment-management `20260928-r1` release. It preserves Stripe TEST mode, existing booking/event identities, prices, deposits, consent and credentials. It does not send mail or make provider requests. The layout remains unchanged. Main remains unmerged; PR #38 remains a draft.

## One Upload And One Command

1. Download `tools/repair-appointment-management.py` and upload through cPanel to `/home/sitesee/repair-appointment-management.py`, keeping the exact filename.
2. Run in WHM Terminal as root:

   ```sh
   python3 /home/sitesee/repair-appointment-management.py
   ```

3. Paste the complete output, including the read-only booking report. No separate diagnostic upload is required.

The installer verifies r1 dependencies and hashes, backs up the three changed PHP modules, release manifests and cron entry, then installs r2. It verifies an actual PHP CLI executable before assigning the cron command. Existing cron must match the installed r1 job or this repair. Unknown edits, credentials and configuration are preserved. No cron service is started or reconfigured. An inactive service is reported for review.

The release adds clear worker startup output and an isolated `--diagnose` bootstrap check that does not open the booking database or contact providers. A successful bootstrap is not proof of scheduled execution. The worker continues to make only provider reads; normal scheduled runs may update local lifecycle evidence. D32FFC7458 is excluded from scheduled TEST reconciliation. A successfully checked cancelled booking now updates its check timestamp, so it does not repeatedly occupy the oldest-check slot.

## What Is Corrected

Reschedule email and calendar DESCRIPTION both retain the booking's current unexpired, unrevoked management link. Valid links are reused without invalidating the current customer session. If no valid saved link remains, a replacement is issued transactionally with the new notice. This does not edit or resend an already-sent notice; validate with a fresh TEST reschedule. Cancelled notices do not offer further management.

Original booking recovery checks that a cancelled event is absent using the existing exact-ID verification. An existing/reappeared event or a provider failure remains a warning. It never recreates an event or resends the original invitation. The upper Calendar appointment value reflects lifecycle cancellation; original confirmation and invitation evidence remain stored.

The embedded report compares every base booking column other than the intentionally changed `requested_utc` to each operation's saved snapshot. It ignores joined scheduling fields that caused the earlier false result. It prints changed column names only, never financial identifiers, tokens, private links or access codes. Expect `base_booking_fields_preserved: true` for both completed operations on 3EB7F85259. A false result requires review; do not assume it is harmless.

## Recovery

The installer uses the existing installation/calendar locks and a worker lock. A busy lock stops it; rerun this same command once the active action finishes. The journal is `/home/sitesee/.sitesee-real-estate/appointment-management-repair.json`. Backups are under the printed `deployment-backups/appointment-repair-*` directory.

If interrupted, rerun the same repair. It verifies backup hashes and current files, then finishes the known new release. It does not roll an active lifecycle release back. Unknown concurrent changes stop the repair. Do not run older installers or manually restore old reservation code.

## Remaining Server Validation

After a scheduled five-minute interval, the same uploaded file can report fresh evidence without installing again:

```sh
python3 /home/sitesee/repair-appointment-management.py --report
```

A timestamped completed `Reconciled` line establishes a worker run. `review required` must be investigated; a started line alone is insufficient. The installer report identifies whether the cron service is active, without printing unrelated scheduler jobs or credentials.

Keep 3EB7F85259 cancelled and D32FFC7458 untouched. After installation and preservation checks pass, use one fresh paid TEST appointment for customer-page rescheduling followed by customer-page cancellation. Accept the updated invitation in Outlook and confirm the saved appointment body retains a functioning management link before cancelling through that link. Verify the cancellation notice, both calendars, available time and Zoho association. Do not substitute staff cancellation or Outlook Decline for the customer-page check.

Manual calendar move/delete reconciliation remains a separate controlled server check on a disposable TEST booking. Confirm that a moved event releases the old local interval and holds the new one; confirm deleted-event reconciliation uses independent missing observations before releasing the stale hold. Preserve legacy Zoho identities and use the existing staff reconciliation process for them. No portal implementation begins before the priority lifecycle checks are complete.

## Local Validation

The repair has PHP coverage for valid/expired link handling, calendar-description links, cancelled recovery, provider failure, reappeared events, released holds and the lifecycle-aware status text. Installer tests cover every write interruption, repeat runs, unknown edits, corrupt backups, PHP CLI selection and report accuracy. Existing customer authentication and staff workflow HTTP checks also pass. These are simulated/local checks; actual cron and Outlook behavior require the operator checks above.
