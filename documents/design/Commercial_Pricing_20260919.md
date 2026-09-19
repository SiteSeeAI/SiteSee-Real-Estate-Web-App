# Commercial calculator and service pages — 2026 09 19

## Current revision

User corrections supersede the original photography tiers and term-license placeholders. Residential is unchanged. This commercial revision remains in PR #4 for review, including proposed factory continuation and platform pricing.

## Photography and square footage

Photography is automatic in every commercial estimate. Removed the Commercial Property Photography checkbox and Hourly Creative Labor option. Category cards show size ranges only. A separate photography estimate and the full summary update from either the slider or exact square-footage input.

| Category | Calculator range | Photography fee before licensing |
| --- | --- | --- |
| Small commercial / retail | 1–10,000 sq ft | max($350, square feet × $0.075) |
| Warehouse / office | 10,000–50,000 sq ft | $750 + (square feet − 10,000) × $0.00875 |
| Factory / industrial | 50,000–1,000,000 sq ft | $1,100 + (square feet − 50,000) × $0.00875 |

The user supplied the $350 small-property minimum, $750 warehouse minimum and $1,100 at 50,000 sq ft. The warehouse increment is ($1,100 − $750) / (50,000 − 10,000) = $0.00875 per added square foot, not $0.24. A direct $0.24 multiplier would contradict those endpoints.

Small-property scaling is a proposed $0.075/sq ft with the required $350 floor, meeting $750 at 10,000 sq ft without a price jump. Below approximately 4,667 sq ft the minimum holds. Shared endpoints are valid in either adjacent category with identical fees. Factory pricing provisionally continues the warehouse increment beyond 50,000 sq ft because no later price endpoint was supplied. The existing 1,000,000 sq ft limit is an input bound, not a newly supplied factory rate-card maximum; larger projects require a quote. The factory slope and small-property scaling can be revised after review.

## Optional services

| Service | Client fee |
| --- | --- |
| Aerial photographs | $42 per finished image; default one; input 1–100 |
| Matterport | $0.10/sq ft; $199 minimum; six months hosting |
| Finished commercial video | $1,500 per 1–3 minute video; input 1–20 videos |
| Schematic floor plans | $150 per property layout set; input 1–20 sets |

Video and layout fees remain from the attached commercial rate sheet. No internal production costs or margins are shown. There is no commercial per-second video slope because the source supplies one fee for the 1–3 minute deliverable.

## Licensing

Term license = (still photography + aerial image fees) × 0.30 / 12 × selected months. Default six, maximum eighteen. This replaces the prior included-six-month photography license. Surcharges are 15% at six months, 30% at twelve and 45% at eighteen. The number input and slider stay synchronized. Rounding occurs once to cents after multiplying the combined eligible base.

Unlimited license = (still photography + aerial image fees) × 0.50. Matterport, video, floor plans, website and platform charges are excluded from both license bases. Video licensing is discussed separately; adding video preserves the calculated photography license but marks the final combined total for confirmation.

Matterport includes six months hosting regardless of the photography license or platform term. Requesting hosting beyond six months flags a separate quote. This revision does not invent a Matterport renewal fee or promise unlimited Matterport use.

## Delivery platform proposal

The user requested a selectable platform and invited a price proposal. Exactly one delivery option is selected:

| Delivery | Fee |
| --- | --- |
| Media files only | No delivery platform fee |
| Dedicated property website | $185 |
| SiteSee platform | Proposed $185 setup + $25/month per property |

Platform defaults to six months ($335), with its own synchronized number/slider controls through eighteen months ($635). Twelve months is $485. Full setup and selected-term fees are included in the estimate and email, separately itemized. This is proposed pricing for review, not an existing approved rate. No automatic renewal or billing is configured. Platform selection does not also charge a separate website. Its term is independent of photography licensing and Matterport hosting; capture is not bundled into it.

## Forms and service pages

Residential/commercial selection, separate required address gates and contact forms remain intact. Invalidating an address hides its estimator and request fields. Scheduling requests need a future date/time in Central Time; self-email does not require scheduling. Emails use the selected market's subject and prepare explicit mailto drafts; nothing claims automatic delivery or confirmed booking.

The Services page links separate residential-services.html and commercial-services.html process pages. Each covers selection, scheduling, field capture, post-production and delivery within 24 hours of the completed shoot. Existing image galleries remain separate. Commercial service copy now reflects calculated photography and term licensing.

Commercial on-site time remains a partial estimate: Matterport uses square feet × 9 / 1,000 minutes; photography and other service timing are confirmed with the appointment. The residential photography time model is not applied to warehouses/factories.

## Verification

Sixteen Node tests pass, covering both markets, minimums, exact sizes, shared boundaries, increasing factory pricing, unit fees, every license endpoint, excluded license charges, independent platform terms, duplicate prevention, invalid input and quote email contents. DOM interaction checks exercise sliders and number inputs in all three categories, license/platform controls, service selections, required fields, address re-lock and market isolation. Browser primitives in that harness are simulated; rendered desktop/mobile visual review remains outstanding. This PR does not deploy to the live server.
