# Legacy Cancellation Reconciliation Repair — 2026 09 28

The r3 worker completed at 19:35 UTC on 2026-09-28 with `Reconciled: 4; review required: 1`. Both 3EB7F85259 and B0AC5BEFEB were cancelled, had no pending operations, retained all compared base booking fields, and released their local holds. All three communications for each were verified in the sender and recipient mailboxes and associated with Zoho CRM. The operator separately confirmed the management link remained in the saved Outlook appointment after rescheduling. B0AC5BEFEB proves customer rescheduling and customer cancellation; 3EB7F85259 proves customer rescheduling and staff cancellation.

The remaining warning was E6E183EF8E, a legacy Zoho calendar booking. The operator confirmed deletion from the original Zoho calendar, used Verify Deleted Legacy Zoho Event / Release Verified Stale Reservation, and reported `calendar_missing`. After the staff cancellation instructions, the operator reported its cancellation notice as `sent_observed`, `recipient_copy_observed`, and `associated`.

Review exposed a mismatch: legacy staff cancellation requires exact-event structured 404 plus a complete successful calendar read, but subsequent generic observation rejected the same 404 even after an applied cancellation. This would repeatedly flag a completed cancellation. The r4 correction requires the applied staff cancellation journal at the current lifecycle revision to prove the same reference, calendar ID, event ID and saved `legacy_deleted` flag. Only then may structured 404 plus a fresh successful calendar read establish absence. Unverified deletions, different identities, incomplete operations, denied access, outages and an event that reappears are not accepted as verified absence. Microsoft behavior is unchanged.

## One Upload And One Command

1. Upload `tools/repair-legacy-cancellation.py` to `/home/sitesee/repair-legacy-cancellation.py` through cPanel, retaining the exact filename.
2. Run in WHM Terminal as root:

   ```sh
   python3 /home/sitesee/repair-legacy-cancellation.py
   ```

3. Paste the full output. No booking, event, payment, credential, invitation or communication row is changed by installation. Existing schema and worker selection are checked as the account owner from its home directory. The read-only report includes all three references above.

The installer accepts the known installed r3 source or its own r4/interrupted source, backs up changes, checks dependencies and manifests, and uses journal `legacy-cancellation-repair.json` for forward recovery. A busy lock or unrelated file change stops it. Rerun this same installer to finish an interrupted installation; do not run older installers over this release. Historical r1/r2/r3 packages remain reproducible.

At the next scheduled interval, normal provider-read-only reconciliation should verify the previously cancelled legacy event and clear its old diagnostic if Zoho reads succeed. It preserves its cancelled state and provider identity, changes no payment or consent fields and sends no message. To view that evidence without reinstalling:

```sh
python3 /home/sitesee/repair-legacy-cancellation.py --report
```

Do not resend any original invitation or cancellation notice. Keep D32FFC7458 protected. Stripe remains TEST; PR #38 remains draft and main is untouched. Manual Microsoft event move/delete recovery still needs controlled server validation before portal work begins.

Local tests cover scheduled and original-recovery observation after verified Zoho cancellation, clearing old diagnostic state, field/notice preservation, mismatched identities, unverified 404, incomplete cancellation, active legacy state, provider denial and calendar-read failure. Forward-installer interruption, repeat-run and unknown-edit checks also pass. Actual scheduled r4 completion remains pending installation and server evidence.
