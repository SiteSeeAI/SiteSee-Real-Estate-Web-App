# Commercial calculator and service pages — 2026 09 19

## Residential checkpoint

PR #3 was merged into main at cdde174474deb123b7fe64cb5800ef4a9f2e3d44. “Estimated Time On Site” is gold and the phrase “additional capture” was removed from customer-facing email wording. The residential calculator, packages and per-second video behavior are preserved. This follow-up adds a market choice and a separate commercial estimator. No live server upload is performed.

## Commercial client fees

Source: the attached commercial_real_estate_media_rates(1).pdf, using Retail Fee / Client Fee only. Internal outsourcing costs, margin calculations and production-business commentary are not copied into the public website.

| Service | Calculator treatment |
| --- | --- |
| Small commercial / retail photography | $750 per property under 10,000 sq ft |
| Warehouse / office photography | $1,200 per property at 10,000–49,999 sq ft |
| Factory / industrial photography | From $2,500 at 50,000+ sq ft; final scope requires a quote |
| Creative labor | $950 / 5 hours = $190 per hour; 1–5 hours in half-hour increments; a labor-only alternative to the photography tier |
| Drone / aerial stills | User override: $42 per finished image; 10 images = $420 |
| Commercial video | $1,500 per finished video, 1–3 minutes; quantity scales the fee |
| Matterport | User override: $0.10 per sq ft with a $199 minimum; six months of hosting included |
| Schematic floor plans | $150 per property layout set; quantity scales the fee |
| Property website | User override: $185 per listing website |

The source provides a single photography fee for each property tier and a single $1,500 fee for a 1–3 minute video. It does not provide within-tier endpoints or separate video-duration prices. The calculator therefore preserves these quoted units; it does not invent a per-square-foot photography slope or divide $1,500 by an assumed video length. Video duration is requested to the second but does not alter the supplied per-video fee. A time-scaled commercial video tariff and any within-tier photography interpolation need explicit endpoints.

Small/warehouse fees are property coverage estimates; industrial is a starting amount. Labor-only selections are marked for a custom quote because the PDF says on-site creative labor only. The engine prevents charging both a photography tier and alternative hourly labor. It does not claim that $190/hour includes all delivered media.

Commercial input bounds: property-size slider up to 1,000,000 sq ft, 1–100 aerial images, 1–20 finished videos and 1–20 layout sets. Larger work is quoted directly. The labels state the property-size limit; quantity bounds are input controls, not price guarantees for larger orders.

## Licensing and hosting

The default media term is six months. The term slider runs from six through eighteen months in one-month increments. The user did not supply the incremental licensing premium for months 7–18; those selections show Custom Quote and an explicit priced-services subtotal. No unapproved premium is silently set to zero.

Unlimited photography licensing adds 50% of the photography and aerial-still fees. This is the review implementation of “photography only”: Matterport, websites, layout sets and labor are excluded from that license base. For a photography-only quote plus Matterport, this equals 50% of the total minus Matterport. Mixed video licensing and industrial work with an unconfirmed base price require a custom quote. The customer label is an unlimited photography license; the calculator does not promise copyright ownership transfer.

Matterport always includes six months of hosting. Selecting a longer media term or an unlimited photography license never extends Matterport hosting. A separate “Discuss Matterport Hosting Beyond Six Months” option marks renewal pricing for conversation. No hosting renewal fee has been recommended or implemented yet.

Concrete example: $750 property photography + $420 aerial images + $500 Matterport at 5,000 sq ft = $1,670 at six months. Unlimited photography licensing adds $585, giving $2,255; Matterport hosting remains six months.

## Workflow and time estimates

The first choice is Residential or Commercial. Neither estimator appears before that choice. Each has its own required address fields, explicit Continue action, service state and contact form. Invalidating the address hides its estimator and request fields. Switching market preserves each form’s own state without mixing prices or licenses.

A property-type query parameter may preselect the corresponding form from its service landing page. It contains no personal information. Browser History restrictions in a local-file preview do not prevent the market choice.

Each quote email uses its own exact market subject. Commercial uses `Commercial SiteSee Real Estate Quote`. Email actions open a draft in the visitor’s mail app; no automatic delivery is claimed. Appointment date and time remain required only for appointment requests, not a self-copy. All contact/address fields and the explicit mailing-list preference are required. The appointment is not a reservation.

Commercial scan time uses the supplied scan-density estimate: square feet × 9 / 1,000 minutes. Five hours of selected creative labor displays five hours. Commercial photography duration is not extrapolated from the residential 35-minutes-per-1,000-sq-ft assumption. Other service times are named for confirmation; industrial layouts and access requirements affect the actual schedule.

## Service landing pages

Services Provided now has separate Residential Photography and Commercial Photography sections linking to:

- public/residential-services.html
- public/commercial-services.html

Both pages describe five steps: choose services, select the shoot, field capture, post-production and delivery within 24 hours of the completed shoot. The prose explains how the media helps buyers/tenants and how preparation affects the appointment. The commercial page also explains license terms and separate Matterport hosting. The existing image-only masonry galleries are preserved and linked independently. Footer links make both process pages accessible throughout the site.

## Validation and review

Fifteen Node tests pass across the residential and commercial engines. Commercial checks cover all client units, minimum Matterport fees, six-month hosting, quantity totals, licensing exclusions, 7–18 month quote status, incompatible selections and commercial email content. DOM interaction checks pass for market switching, state separation, address gate/re-lock, quantity changes, license changes, hosting requests, photography/labor exclusivity, required fields and correct email subject. Browser form primitives were simulated in the DOM checks.

JavaScript syntax, CSS parsing, unique IDs, label/ARIA targets and 508 local links/assets pass. Live visual browser review remains outstanding due to the previously documented browser-preview restriction. No messages have been sent and no production site has been deployed.

Review public/pricing.html, public/services-provided.html, public/residential-services.html and public/commercial-services.html. The remaining pricing decisions are the 7–18 month premium schedule, any commercial photography/video interpolation endpoints, the license base for mixed non-photography orders, and hosting renewal fees beyond six months.
