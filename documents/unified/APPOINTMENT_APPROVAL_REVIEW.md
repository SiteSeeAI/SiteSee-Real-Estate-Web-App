# Manager-approved reschedule correction

The installed TEST acceptance found that customer rescheduling immediately moved the calendar and sent an RSVP, without a division request notification. The user requires the requested window to wait for real estate manager approval.

Both customer-account and signed management-link paths now save one pending request. The existing confirmed window, calendar/event identity, deposit, consent and original invitation evidence remain unchanged. The dedicated real estate mailbox sales@re.sitesee.ai receives a separately tracked plain request notification showing the confirmed and requested windows, with a staff review link and no calendar attachment. Existing sender and TEST recipient restrictions remain intact. Failed/ambiguous notifications stay visible to staff and are not automatically replayed.

Staff Bookings displays pending review and explicit Approve/Decline actions. Approval rechecks the current booking, event version and availability, applies one update to the existing event, and then sends the saved revised customer RSVP through the existing sent-copy/inbox/CRM safeguards. Decline preserves the original appointment. Lost provider responses retain an applying request linked to the durable operation; recovery reads its existing result. Known-refusal bookkeeping is one transaction. An unchanged scheduled/calendar read does not invalidate a pending request. Cancellation semantics remain unchanged and a pending request blocks conflicting actions.

## Compatibility and update boundary

The installer accepts the exact already-installed bd026c77d596 predecessor, verifies its full release record and actual retained host hashes, and preserves every existing unknown-edit/ownership/mode/backup check. No installer database migration occurs. On first normal application initialization, the existing additive schema setup creates only booking_change_requests and its unique pending/applying index; no existing table/row is rewritten. Repeated initialization is idempotent.

Normal new web requests and the reconciliation worker take a shared lock on the private root directory; deployment holds its exclusive lock. The installer persists a maintenance marker, installs the guarded bootstrap first, and holds the existing calendar-operation lock while replacing files. An interruption leaves maintenance active until the same package resumes and verifies. New customer UI/routes also explicitly require the request implementation and refuse partial old-core combinations instead of falling back to immediate rescheduling. No Apache directives, provider settings, session files or schedules are changed.

Code-only rollback retains financial/provider data and all added request rows. It is refused while pending/applying requests exist, because the predecessor has no manager-request workflow. Repair forward in that case; never restore an old database over later TEST operations. A rollback after resolved requests restores the predecessor's earlier behavior and is an explicit recovery decision, not a way to finish acceptance. Keep all backups and maintenance journals.

## Validation status

Dedicated request tests cover customer immutability, division-only plain MIME and inbox evidence, explicit approval, unavailable-window recheck, decline, duplicate submissions/approvals, exact ledger/event preservation, lost calendar/mail responses, atomic refusal interruption and unchanged-calendar reconciliation. Customer and staff HTTPS tests cover request presentation, role/CSRF/origin/consent enforcement and 320/390/736/1200 layouts. Frozen artifact and final independent release-boundary review are pending at this checkpoint. Installed connected verification remains pending and Stripe stays TEST.
