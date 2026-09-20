# Commercial calculator and service pages — 2026 09 19

## Approved calculator revision

The quote page keeps its existing layout, photography, CSS, address gate, required request fields and residential pricing. Commercial photography now uses 1.5 minutes per 1,000 sq ft; residential photography remains at 35 minutes per 1,000 sq ft. The latest correction restores fixed commercial category fees and removes every area-based photography price adjustment, including Factory / Industrial. Only additional photo quantity increases the photography shoot fee. Photography is automatic; no photography checkbox or creative-labor / half-day service is offered.

| Category | Calculator range | Photography fee |
| --- | --- | --- |
| Small commercial / retail | 1–10,000 sq ft | $750 |
| Warehouse / office | 10,000–50,000 sq ft | $1,200 |
| Factory / industrial | 50,000–250,000 sq ft | $2,500 plus additional-photo charges |

Category selection determines the base fee. The commercial form has no property-size field; choosing a category and photo quantity determines photography pricing. Factory’s “+” now reflects additional photographs, not square-footage increments. Category changes reset the photo count to the selected included maximum (30 / 45 / 55) and recalculate the fee immediately. The Factory / Industrial area limit remains 250,000 sq ft for coverage and time estimates; larger projects are quoted directly.

## Services and independent coverage

- SiteSee platform is a service immediately above Matterport, $49/month without setup fees. Its selected term defaults to six months and retains the existing 6–18 month controls. The full term is itemized in the estimate: six months is $294. An independent property website can be added with the platform. Its service checkbox and delivery radio stay synchronized; the $175 website is charged once.
- Matterport retains its own scanned-area number input, from 1 sq ft through the selected category’s maximum (10,000 / 50,000 / 250,000). Its fee is max($199, covered square feet × $0.10). Changing category clamps an existing scan area if it exceeds the new category’s maximum; invalid manually entered coverage blocks the quote. Scan coverage does not alter the fixed photography fee.
- Single 360° photos are $25 each, default one, 1–100 photos. They are placed as views within a SiteSee Experience and require platform selection. The entire row is hidden until Matterport is selected. With Matterport selected, the row is disabled and grayed out until the platform is also selected. Removing either prerequisite deselects the add-on and removes its charge; removing Matterport also hides the row. The engine requires both prerequisites, including for calls outside the form.
- Aerial photographs are $42 per finished image; default one, 1–100 images.
- Cinematic B2B video uses the specified $8.333/second with a $500 minimum per video, 60–180 seconds, 1–20 videos. The rate is not shown in the video field; the price updates from the minutes/seconds fields. Prices use the literal approved multiplier: 60 seconds is $500, 120 seconds $999.96 and 180 seconds $1,499.94. Each video's fee is rounded to cents before multiplying the video count.
- Schematic floor plans retain $150 per property layout set, 1–20 sets.
- Independent property website is $175. It appears in Services & Additions as well as the delivery radio selection and remains available with a SiteSee platform subscription.

## Photography quantities

The fixed category fee is the base charge. It includes 25–30 photos for Small Commercial / Retail, 30–45 for Warehouse / Office and 45–55 for Factory / Industrial. A total-photograph number input and slider default to the upper included allowance (30 / 45 / 55), allow the included range, and cap the entire photography order at 100 photos.

Additional charges apply only above the upper included allowance: $30 each above 30 small-property photos; $26.70 each above 45 warehouse/office photos; $25 each above 55 factory/industrial photos. Small's $30 extra-image rate uses the user's $750 ÷ 25 example. At 100 photos, the respective extra-image charges are $2,100 / $1,468.50 / $1,125. Selecting a new category resets the quantity to its included maximum. Invalid quantities cannot produce a quote.

The estimate separately itemizes the base photography fee and added photos. Added photography is part of the media-license base. The website, Matterport, aerial, hosting and other service charges are not duplicated or changed. Quote emails include the total requested photo count, additional count and unit price. On-site timing remains based on the approved area/video/drone formulas; this revision does not invent a per-photo capture-time rule.

## Licensing and hosting

The license base includes property photography, aerial images and videography. Matterport scans, individual 360° views, platform subscriptions, independent websites and floor plans are excluded.

Extended license: base × 0.30 ÷ 12 × max(0, total months − 6), maximum eighteen total months. The first six months are included. Unlimited media license: base × 0.50. Rounding occurs after the surcharge is calculated from the eligible cent-rounded media fees.

The supplied $1,834 media example gives $275.10 for a twelve-month term, $550.20 for eighteen months, or $917 for unlimited use. Video rights are part of the selected media license and no longer force a separate quote.

Matterport hosting includes the first six months. A separate number input chooses 6–18 total months. Additional months are $6.99 each when billed monthly, or $4.99 each with Pay In Advance checked. The estimate includes the complete selected extension cost as a separate hosting line, outside the media license base. At 12 months total this is $41.94 monthly-billed or $29.94 prepaid; at 18 months total it is $83.88 or $59.88. Six months adds no charge. Hosting controls hide and disable when Matterport is removed, and its hosting charge is removed. This form quotes the term; it does not collect payment. Hosting, media licensing and platform subscription retain separate term controls. The two requested explanatory descriptions below the license options have been removed without changing their calculations.

## Forms, duration and delivery

The address gate and required agent/appointment fields remain intact. Email actions prepare explicit mailto drafts using Commercial SiteSee Real Estate Quote. No automatic email delivery or confirmed booking is claimed. Quote lines and emails identify the property category, Matterport scanned area, individual view count, subscription term and licensing surcharge separately. No exact property area is invented or printed when the form does not collect one.

Commercial photography retains 1.5 minutes per 1,000 sq ft. With no exact property-size entry, it displays a range based on the selected category: Small approximately 5–15 minutes, Warehouse / Office 15–75 minutes, Factory / Industrial 75–375 minutes. The engine still accepts an optional exact size for callers that provide one; otherwise it does not fabricate a size. Known Matterport, video and drone times are added to both range bounds. Residential photography remains property square feet × 35 / 1,000 minutes. Both calculate Matterport as scanned square feet × 9 / 1,000 minutes. Commercial scan coverage remains independent. Video adds 15 minutes per finished minute (finished seconds ÷ 4), multiplied by the number of commercial videos. Residential packages use their included video length exactly once: Gold adds 15 minutes; Platinum adds 30 minutes. Drone capture adds one 20-minute allowance when selected or included, independent of image quantity and alongside any selected video capture. The combined total is rounded up to the next five minutes; the page and quote emails show photography, Matterport, video and drone breakdowns. Floor-plan and individual-view time remains to be confirmed; residential Zillow time likewise remains unestimated. No charges changed in this timing revision.

## Verification

All 24 commercial/residential Node tests pass. DOM interaction checks cover repeated category switching, fixed photography fees throughout each category’s area range, independent Matterport input, scan-area limits and clamping, platform position and term, Matterport-and-platform-dependent 360 visibility, disabled state and removal, separate commercial/residential photography rates and unchanged scan/video/drone timing, included package video lengths, fixed drone allowances, commercial video quantities, website checkbox/radio synchronization with a platform subscription and single charging, photo-quantity allowances and unit fees, 100-photo limit, 250,000-sq-ft factory limit, video duration, included license months, request emails, required contact fields, address re-lock and market isolation. Native browser form primitives are simulated in the DOM harness; rendered browser review remains outstanding. The page retains its layout; disabled controls are gray. The only remaining commercial range slider is photo quantity. The entire commercial property-size field is removed. Matterport coverage, video duration, licensing, hosting and platform terms retain their separate number inputs. Residential property-size and video-duration sliders are retained. Residential photography is checked and disabled, and residential size entry is disabled with Silver, Gold or Platinum. The residential recovery branch implements Luxury from the recovered $425 starting fee and $0.1176 per-foot rate; only Large remains unresolved. Commercial work remains in PR #4; the subsequent residential rate recovery is isolated on `fix/residential-pricing-recovery-20260920`. Neither branch deploys to the live server.

## Entire commercial property-size field removed — 2026 09 20

Branch: `fix/remove-commercial-property-size-20260920`. The user clarified that the entire “Property Size · Square Feet” field must be removed from commercial pricing, not merely its range slider. The label, number input, range labels, handlers and validation dependency are removed. No hidden property-size substitute is supplied. Residential markup and scripts are unchanged, including its size slider, required photography and photo counts.

Category-only commercial quotes preserve all prices and additional-photo calculations. Matterport retains its explicitly selected coverage; photography time uses the category range, and the total range adds known scan/video/drone capture times. Quote emails omit exact photography area when none was supplied.

Validation includes category-only quote tests, scan limits, time ranges, category switching, photo quantity, hosting, website/platform combinations, invalid scan input, 360 dependencies and email totals. Residential interactions and its photo-count displays were also checked.
