# Appointment Worker Repair — 2026 09 28

The installed r2 worker starts on schedule but the operator's raw log reports a PHP failure at every run. Local regression reproduces a working-directory-dependent database safety-check failure. In CLI, an empty `DOCUMENT_ROOT` resolves to cron's home directory, which incorrectly places the private database inside the presumed public directory.

The repair supplies the actual public sibling path (`/home/sitesee/public_html/re`) to the worker's CLI context. It retains the existing shared database guard and tests that a database inside the actual public directory is still rejected. Customer/staff controllers, prices, payments, tokens, credentials, mail, calendars and reservation rules are unchanged.

## Install

1. Upload `tools/repair-appointment-worker.py` through cPanel to `/home/sitesee/repair-appointment-worker.py` with that exact filename.
2. Run as root in WHM Terminal:

   ```sh
   python3 /home/sitesee/repair-appointment-worker.py
   ```

3. Paste all output. The installer now runs its diagnostic as `sitesee` from the account home directory and checks actual database initialization and worker selection. It makes no provider requests or booking-row writes. Existing idempotent schema initialization may run; no financial/consent or appointment state is changed by this diagnostic.

The installer includes the worker, dependency hashes, backups, forward recovery, manifest updates and the read-only report for **3EB7F85259** and **B0AC5BEFEB**. It accepts the verified r2 release or its own known interrupted/r3 state. Unknown edits are preserved. The prior installers remain frozen for reproducibility. Do not run them over r3.

The repair journal is `appointment-worker-repair.json` in the private application. A busy installation/worker/calendar lock stops the attempt; rerun the same command after the active action finishes. An interrupted write is resumed after backup/hash checks; there is no rollback of active lifecycle code.

## Verify Scheduled Completion

A successful local diagnostic is not proof of a completed scheduled provider reconciliation. At the next five-minute boundary, allow the run to finish, then use the same uploaded file:

```sh
python3 /home/sitesee/repair-appointment-worker.py --report
```

Look for a new timestamped `Reconciled: N; review required: 0. No calendar or mail writes.` entry. An old failure entry remains historical evidence; do not clear the log. A new `Worker stopped` line or a nonzero review count requires review. Safe worker failure lines now appear in the report. Exceptions identify their stage (bootstrap/database/schema/selection/reconciliation) and a safe error category without exposing tokens, credentials, links or customer data.

Normal scheduled reconciliation reads provider events and updates local lifecycle evidence. It does not send notices, create/change/delete provider events, or call Stripe. **D32FFC7458 remains excluded.** Keep **3EB7F85259 cancelled**. Preserve legacy Zoho event identities.

Customer rescheduling/link retention/cancellation validation for **B0AC5BEFEB** is still awaiting the operator's observations. The read-only report will show actor/action evidence and preservation once those operations exist. Manual calendar move/delete validation also remains pending. Customer portal work stays deferred.

## Validation

Regression tests execute the old and repaired workers from a simulated account home, demonstrate the old guard failure and corrected completion, check the actual public-directory guard, reach database/selection in diagnostic mode, exclude the protected booking, and retain safe failure logs. Installer tests cover every changed-file interruption, repeated runs, unknown edits and preservation of database/credential bytes during deployment. These tests use isolated local data; actual Microsoft reads and cron completion still require server evidence.

## Actual Scheduled Completion And Legacy Follow-up

Operator output confirms the worker completed at 19:35 UTC with four reconciled bookings and one requiring review. Both target cancelled bookings had updated check timestamps and null diagnostics; all three communications for each were fully verified and associated with Zoho CRM. Customer management-link retention in the rescheduled Outlook appointment was explicitly confirmed. The remaining booking was E6E183EF8E on legacy Zoho; see [Legacy_Cancellation_Repair_20260928.md](Legacy_Cancellation_Repair_20260928.md) for its staff-confirmed deletion/cancellation evidence and the targeted r4 correction.
