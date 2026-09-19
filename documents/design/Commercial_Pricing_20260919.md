# Commercial calculator and service pages — 2026 09 19

## Approved calculator revision

The quote page keeps its existing layout, photography, CSS, address gate, required request fields and residential pricing. Commercial photography now uses 1.5 minutes per 1,000 sq ft; residential photography remains at 35 minutes per 1,000 sq ft. Commercial client fees below supersede the earlier rate-sheet tiers and pricing proposals. Photography is automatic; no photography checkbox or creative-labor / half-day service is offered.

| Category | Calculator range | Photography fee |
| --- | --- | --- |
| Small commercial / retail | 1–10,000 sq ft | max($350, square feet × $0.075) |
| Warehouse / office | 10,000–50,000 sq ft | $750 + (square feet − 10,000) × $0.05 |
| Factory / industrial | 50,000–1,000,000 sq ft | $2,500 + (square feet − 50,000) × $0.05 |

The latest warehouse correction supersedes the earlier $1,200 maximum: 10,001 sq ft is $750.05 and 50,000 sq ft is $2,750. Factory starts at the separately approved $2,500 at 50,000 sq ft; the category boundary is intentionally not continuous. Do not silently replace either anchor. The existing 1,000,000 sq ft calculator ceiling remains; larger projects are quoted directly.

## Services and independent coverage

- SiteSee platform is a service immediately above Matterport, $49/month without setup fees. Its selected term defaults to six months and retains the existing 6–18 month controls. The full term is itemized in the estimate: six months is $294. Platform and independent website cannot be charged together.
- Matterport has its own square-footage number input and slider. Coverage must be 1 sq ft through the photography property size. Its fee is max($199, covered square feet × $0.10); reducing coverage changes its fee and scan-time estimate, not the photography charge. Increasing property size does not increase the selected scan area. Reducing property size below selected coverage clamps that coverage to the new property size.
- Single 360° photos are $25 each, default one, 1–100 photos. They are placed as views within a SiteSee Experience and require platform selection. The entire row is hidden until Matterport is selected. With Matterport selected, the row is disabled and grayed out until the platform is also selected. Removing either prerequisite deselects the add-on and removes its charge; removing Matterport also hides the row. The engine requires both prerequisites, including for calls outside the form.
- Aerial photographs are $42 per finished image; default one, 1–100 images.
- Cinematic B2B video uses the specified $8.333/second with a $500 minimum per video, 60–180 seconds, 1–20 videos. The rate is not shown in the video field; the price updates from minutes/seconds or the slider. Prices use the literal approved multiplier: 60 seconds is $500, 120 seconds $999.96 and 180 seconds $1,499.94. Each video's fee is rounded to cents before multiplying the video count.
- Schematic floor plans retain $150 per property layout set, 1–20 sets.
- Independent property website is $175.

## Licensing and hosting

The license base includes property photography, aerial images and videography. Matterport scans, individual 360° views, platform subscriptions, independent websites and floor plans are excluded.

Extended license: base × 0.30 ÷ 12 × max(0, total months − 6), maximum eighteen total months. The first six months are included. Unlimited media license: base × 0.50. Rounding occurs after the surcharge is calculated from the eligible cent-rounded media fees.

The supplied $1,834 media example gives $275.10 for a twelve-month term, $550.20 for eighteen months, or $917 for unlimited use. Video rights are part of the selected media license and no longer force a separate quote.

Matterport hosting includes the first six months. A separate slider and exact number input choose 6–18 total months. Additional months are $6.99 each when billed monthly, or $4.99 each with Pay In Advance checked. The estimate includes the complete selected extension cost as a separate hosting line, outside the media license base. At 12 months total this is $41.94 monthly-billed or $29.94 prepaid; at 18 months total it is $83.88 or $59.88. Six months adds no charge. Hosting controls hide and disable when Matterport is removed, and its hosting charge is removed. This form quotes the term; it does not collect payment. Hosting, media licensing and platform subscription retain separate term controls. The two requested explanatory descriptions below the license options have been removed without changing their calculations.

## Forms, duration and delivery

The address gate and required agent/appointment fields remain intact. Email actions prepare explicit mailto drafts using Commercial SiteSee Real Estate Quote. No automatic email delivery or confirmed booking is claimed. Quote lines and emails identify photography area, Matterport scanned area, individual view count, subscription term and licensing surcharge separately.

Commercial photography is property square feet × 1.5 / 1,000 minutes: 10,000 sq ft takes 15 minutes, 50,000 takes 75 minutes and 100,000 takes 150 minutes. Residential photography remains property square feet × 35 / 1,000 minutes. Both calculate Matterport as scanned square feet × 9 / 1,000 minutes. Commercial scan coverage remains independent. Video adds 15 minutes per finished minute (finished seconds ÷ 4), multiplied by the number of commercial videos. Residential packages use their included video length exactly once: Gold adds 15 minutes; Platinum adds 30 minutes. Drone capture adds one 20-minute allowance when selected or included, independent of image quantity and alongside any selected video capture. The combined total is rounded up to the next five minutes; the page and quote emails show photography, Matterport, video and drone breakdowns. Floor-plan and individual-view time remains to be confirmed; residential Zillow time likewise remains unestimated. No charges changed in this timing revision.

## Verification

All 19 commercial/residential Node tests pass. DOM interaction checks cover the warehouse increment from 10,001 sq ft, category-specific factory starting fee, independent Matterport slider/input, scan-area limits and clamping, platform position and term, Matterport-and-platform-dependent 360 visibility, disabled state and removal, separate commercial/residential photography rates and unchanged scan/video/drone timing, included package video lengths, fixed drone allowances, commercial video quantities, duplicate website prevention, video duration, included license months, request emails, required contact fields, address re-lock and market isolation. Native browser form primitives are simulated in the DOM harness; rendered browser review remains outstanding. The page retains its CSS and existing layout. This work updates PR #4; it does not deploy to the live server.
