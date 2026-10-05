# Onsite service selection and commission — 2026 10 05

Release: `onsite-services-20261005-r1`. This upgrades the operator-confirmed `job-closeout-20261002-r1.1` installation. Baseline branch checkpoint: `5efe122cc940be25ce349ab214df35f1521d2311`. The original server backup remains `/home/sitesee/.sitesee-real-estate/deployment-backups/job-closeout-1gte4ywn`.

## Requested behavior

Onsite Closeout now offers every service in the applicable ordering catalog: nine residential services or eight commercial services. Each added row removes its selected service from the other pick lists. **Add Additional Service Item** creates another row until all services are selected. Removing or changing a row restores the available choices.

Matterport shows coverage in square feet. Video shows minutes and optional seconds, plus the number of finished videos for commercial work. Other services show their existing quantity, hosting or licensing controls as applicable. Prices are calculated by the same PHP functions used when placing an order; the browser cannot submit its own fee. Commercial Single 360° Views require Matterport and SiteSee Platform on the original order or in the additions.

The vendor sees **Total Additional Service Fee**, **Final Job Total**, **Remaining To Collect** and **Photographer Commission (18%)**. Commission uses only added service types absent from the original order, including package inclusions. As explicitly directed on 2026 10 05, subscriptions and licensing fees earn no commission; hosting fees are also excluded. Commission is rounded once to cents and recorded in the separate final bill. It is neither added to the customer charge nor automatically paid to the vendor.

Residential platform billing remains $49/month separately after publication; this update does not create subscriptions or add the monthly fee to the final payment. Commercial platform terms, licensing and hosting retain the existing ordering calculator's charges, with none of those charges included in commission.

The photographer checks that onsite work is finished, the agent verbally approved the displayed additional services/fees, and the final total is correct, then presses **Job Complete**. This atomically saves the exact service scope and a staff-attested verbal authorization record before attempting the existing TEST saved-card collection. Customer portal approval is optional; it is no longer required for this vendor flow. The record identifies the assigned photographer and timestamp without misrepresenting staff attestation as a customer portal approval. Any changed quantity, amount or concurrent edit invalidates the preview. JavaScript clears the checkbox and disables closeout while prices are pending or invalid.

The original booking, deposit, earlier payments, calendar, CRM and consent remain intact. Saved-card permission and provider verification remain mandatory. A declined card or bank authentication still uses the existing recovery path; onsite completion and Production remain recorded. Repeat submissions retain one final bill and PaymentIntent. Previously closed jobs retain their original bills. Old free-text additions retain their exact amounts but receive no inferred commission; staff can replace them with catalog items before closeout.

## Review evidence

1. Source/catalog pass: checked both canonical ordering calculators, all service keys, package-included lines, original order shape, recurring charges, commercial quantities and dependency rules.
2. Billing/consent pass: tested exact service amounts, server-controlled prices, original/package commission exclusions, subscription/license/hosting exclusions, rounding, stale scopes, concurrent edits, no provider calls on rejected closeout, verbal authorization, immutable original records and single-charge retries.
3. Installer/preservation pass: six application files and 40 unchanged dependencies are pinned to exact reviewed bytes. Eleven new installer checks pass, including all write interruption boundaries and recovery, LF/CRLF compatibility, unknown edits, symlinks, prior incomplete installation, LIVE rejection, backups and protected original data/configuration. The previous 31 closeout installer tests and historical staff source checks pass. The original closeout installer remains byte-identical (SHA-256 `b8abe2d55101176082e0497013a112650228cc96721ae9e40934f1687ba21378`).
4. Interface/regression pass: the HTTPS browser suite passes all 17 residential/commercial picker choices, remaining-choice filtering, quantity pricing, saving, vendor closeout without portal approval, one create/confirm, declined-card recovery, private drafts, unpaid withholding, paid links, refund review and preserved original records. It verifies subscription/license/hosting commission exclusions, Unlimited-license controls, checkbox invalidation and 320/390/736/1200 widths. The mobile picker was visually inspected. Existing staff browser checks preserve all 19 baseline form contracts plus three external-agent cases. Existing closeout, portal purchase/service and phone/SMS behavioral suites pass. Final CI evidence will be recorded after the saved checkpoint runs.

The independent `onsite_catalog_audit` agent reviewed the catalog, application, transaction boundaries, pricing, commission and installer. It found one hidden Unlimited-license field edge, which was corrected and covered by a regression check. Its final review reports no blocker. It independently ran the catalog suite and ten installer tests without modifying files, and reconstructed the package bytes in memory to verify the manifest, prior inputs, current outputs and all 40 dependencies.

The inherited repository-wide Server Form CI failures documented in `JOB-CLOSEOUT-REVIEW.md` are outside this change; no guard or assertion has been weakened to hide them. No actual provider-connected TEST payment is claimed by isolated tests.

## Installation

Upload `tools/install-re-onsite-services-20261005-r1.py` to `/home/sitesee/`, then run once in root WHM Terminal:

```sh
python3 -B /home/sitesee/install-re-onsite-services-20261005-r1.py --deploy
```

The installer is file-only, backs up existing files and resumes safely after interruption. It does not open databases, execute application logic, call providers, send messages or modify credentials/configuration. Stripe remains TEST. A prior incomplete closeout installation must finish first. Unknown edits are preserved.

After installation, use one fresh TEST job to check the selections, automatic total and eligible commission, then complete the onsite work and verify the final payment status and Production delivery. Do not reuse protected historical orders `5D99D336572661A00885`, `8D20B4EBFCD0BADC4DE5` or booking `D32FFC7458`. No main merge or live activation is authorized. Finish the current build's acceptance before the planned Codex replacement app.
