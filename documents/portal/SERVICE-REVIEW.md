# Appointment and billing source review

Baseline: `fac155c27f583c315d8125f80a0985bf23f16303` on the existing draft PR 38 branch. Source only; portal remains uninstalled and disabled by default. This review does not reopen completed calendar or cleanup verification.

## Four primary passes

1. Dependencies: fixed private includes resolve; new portal functions are defined; existing lifecycle, checkout, signed-event, account and session functions are reused. New additive SQLite tables hold balance attempts, processed balance events and the restricted billing configuration.
2. Complete content and differences: reviewed every changed source and fixture against the exact baseline. Amounts, account identity, provider customer, session and invoice IDs come from server records. No client price or ownership claims are accepted. The full diff is reviewable through the branch; the source manifest records exact SHA-256 values and changed flags.
3. Entrypoints, configuration, jobs and permissions: the existing account route retains HTTPS, default-off TEST gates, active-account checks, CSRF and same-origin validation. Service POSTs have a session rate limit. Only the legacy webhook dispatcher changes, after its unchanged signature verifier; ordinary deposit events still use the original processor. The webhook's original line endings are preserved. No installed configuration, credentials, cron, database or filesystem permissions change. Fixtures are CLI-only and never belong in the installation payload.
4. Final source candidate: the inventory and hashes are recorded in `service-source-manifest.json`. The only changed legacy application file is `booking-webhook.php`; all other legacy application bytes and the accepted preview match the baseline. CI checks PHP syntax, account/session/purchase regression, service assertions and real local HTTPS browser routes. No incomplete installation archive is supplied.

## Behavior and recovery

Appointment adapters require active ownership before calling the completed lifecycle functions. Existing TEST recipient and Microsoft event restrictions remain. Customers can search windows, move or cancel their owned appointment, and read back an interrupted change. They cannot adopt calendar moves or use staff recovery controls. Saved changes use existing notice safeguards; retries do not resend old notices. Notice recovery after a provider interruption remains a staff responsibility.

Balance consent names the exact approved amount and order. The amount includes an approved rush fee and subtracts the recorded deposit. A durable immutable request and stable attempt key recover provider acceptance with a lost response. Only verified expiry permits another attempt. Unknown creation older than 23 hours requires staff reconciliation before any retry, avoiding a fresh charge after provider idempotency retention. Signed balance events are recorded even if account access is later disabled; browser returns never mark payment paid. Refunds and disputes are shown with paid/net totals and block new balance collection pending staff review; they never automatically create another amount due.

Owned receipts and any available paid invoices are verified through exact Checkout session, PaymentIntent, charge and invoice evidence. Billing-address/payment-method access additionally rejects unknown or differently owned local/provider records, incomplete list coverage, or any subscriptions. The dedicated TEST configuration allows only payment methods and name/address/phone changes. Email, public login, invoice history and subscription controls remain disabled. The configuration is validated each time. Customer payment methods are never selected by email. Provider lists above 100 records require staff reconciliation rather than partial authorization.

## Validation record

Candidate `3a0f4fbc13807a17908e17e79aaeb0aa38a5ae92`: run [36583404418](https://github.com/SiteSeeAI/SiteSee-Real-Estate/actions/runs/36583404418), job 109457223240, contains six explicit PASS markers: access, session, purchase, service, preview and HTTP browser. Earlier failures were a synthetic fixture using an unauthorized photographer label and an ambiguous HTML label around the appointment selector; both were corrected. The CI completion guard prevents false success from an early test exit.

The browser used the actual controller and forms with three isolated customers, TLS, captured local mail and blocked external provider traffic. New provider replacements exist only in a temporary fixture copy. Billing, balance and appointment mobile screenshots were inspected. Actual Stripe SDK/provider, Graph/CRM and inbox behavior require the complete TEST integration release check.

Independent audit: no blocking findings. The separate reviewer checked all 75 source-manifest hashes and changed flags, the original 56-file baseline, ownership boundaries, payment evidence, immutable attempts, webhook races and the restricted billing configuration. The only legacy application edit is the disclosed webhook dispatcher. The latest UI hides appointment changes while previous notices remain unresolved; the lost-response fixture now models provider acceptance before interruption. Final source `5726d6a2a17ba70fa67fa432947b5739a592c010` passed run [36583982555](https://github.com/SiteSeeAI/SiteSee-Real-Estate/actions/runs/36583982555), job 109459250660, with all six explicit PASS markers verified. The final appointment screenshot was reviewed after the notice-state correction.

## Primary provider references

- https://docs.stripe.com/api/checkout/sessions/retrieve
- https://docs.stripe.com/api/checkout/sessions/create
- https://docs.stripe.com/api/checkout/sessions/expire
- https://docs.stripe.com/api/charges/object
- https://docs.stripe.com/api/customer_portal/configurations/create
- https://docs.stripe.com/api/customer_portal/sessions/object
- https://docs.stripe.com/api/payment_intents/list
- https://docs.stripe.com/api/subscriptions/list
