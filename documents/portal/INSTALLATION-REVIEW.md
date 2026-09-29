# TEST Portal Installation Review

Release: `portal-20260929-r1`. Baseline: `6a3e5cc32c55b75e110a63e89c8973103fe5a4ee`. Tested final source: `902f1380bd4fa67a571e3338b570092605f9d3be`.

## Four Verification Passes

1. Dependencies: all 19 payload files are enumerated by SHA-256 in installation-source.json. All 46 retained private dependencies match reviewed baseline bytes; LF/CRLF variants are explicitly accepted without rewriting installed bytes. Literal PHP include closure is complete. Three existing font files are pinned to repository Git blob hashes. No tests, fixtures, data or credentials are embedded.
2. Content: 17 payload files match the source baseline exactly. The two intentional changes are the new private release-gate helper and the account entry point using that helper. The already-reviewed balance webhook is preserved exactly from the service checkpoint. Its intentional difference from the pre-portal installed webhook remains described in SERVICE-REVIEW.md. No other existing application file is replaced. Affected release manifests retain all entries and change only the webhook hash.
3. Entrypoints and operation: default-off private activation is independent of the existing booking TEST gate; disabling overrides inherited portal environment flags. Existing pricing, calendar, mail, CRM, credentials, database and cron are untouched. Public assets use readable 0755 directories and 0644 files; private files and backups use 0600. Unknown edits, symlinks, unsafe directory modes, mismatched release hashes and invalid checkout configuration stop the operation. The installer makes no provider calls or database writes.
4. Final artifact: the single-upload installer rebuilds byte-for-byte from its template and pinned payload. Python 3.6 grammar passes. Thirteen installer tests cover first-install permissions under umask 077, each write interruption, retry, unchanged rerun, known rollback, unknown-edit preservation, symlink rejection, backup corruption, checkout preflight parity and permanent rollback blocking after activation. Backup directory and journal writes are durable before replacement; disable durably removes the flag. Rollback restores the original webhook before removing its new dependencies.

## Independent Audit

The separately authorized reviewer found no unresolved blocking issue after corrections to public asset directory permissions, exact checkout preflight validation, durable disable, and backup-parent persistence. The reviewer independently verified all 19 payload files, 46 dependency records, include closure, generated artifact reproduction and all 13 installer tests.

## Automated Evidence

Final run [36592621821](https://github.com/SiteSeeAI/SiteSee-Real-Estate/actions/runs/36592621821), job 109489338713, passed on source `902f1380bd4fa67a571e3338b570092605f9d3be`. The account, session, purchase, service, release-gate, preview and HTTPS browser suites passed; all 13 Python installer tests and byte-for-byte regeneration passed. The HTTP fixture now executes a temporary path-adjusted copy of the actual public account entry point and proves that disabling the private flag rejects account requests even with an inherited portal TEST flag.

## Remaining Evidence

The portal is not installed. Local PHP-FPM configuration, actual Stripe SDK/provider behavior, email receipt, Microsoft/CRM association and historical reconciliation remain installation checks. Source tests use isolated synthetic customers with captured mail and blocked or mocked providers. The operator guide provides one upload and a combined preflight/install/TEST-enable command, followed by a fresh controlled order. No real customer notice, charge, calendar event or CRM write occurred during package preparation.
