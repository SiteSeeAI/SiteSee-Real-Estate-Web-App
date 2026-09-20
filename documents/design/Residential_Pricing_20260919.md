# Residential pricing page — 2026 09 19

Review `public/pricing.html`. The existing Request Pricing Access URL redirects to the new page. All navigation and pricing links now point to Pricing & Quote. No production deployment is included.

The page uses the existing SiteSee logo, Poppins and Inter, yellow accents and shared header/footer. Residential gallery images 28 and 15 provide the twilight hero and kitchen sidebar photography; no new image assets are required.

## Form flow

1. Street address, city, state and ZIP are required. An explicit Continue button opens the estimator. This avoids showing dimmed, unusable controls and keeps the initial task short. Erasing or invalidating the address hides the estimator, summary and request form again. This is a UI progression, not an authentication or rate-sheet security boundary.
2. The agent selects one category and a package or individual services. Photography is mandatory, checked and disabled in the form, and enforced in the engine even for add-on-only input. Included services are checked and disabled; stored individual selections are restored when switching away from a package. Totals include each service once. Residential property-size and video-duration sliders remain available and synchronized with their number inputs. The property-size slider and number field are disabled and gray with Silver, Gold or Platinum and re-enabled for Individual Services; unused add-on inputs are disabled.
3. First name, last name, company, email, phone and mailing-list preference are all required before creating an email draft. The original optional opt-out checkbox becomes an explicit required Yes/No choice so all request fields are complete without forcing either marketing preference. A quote copy can be prepared without selecting a shoot date. Both date and time are required for an appointment request. Dates and times are validated against Central Time. The request does not reserve or confirm availability.

## Prices

Small photography retains the approved calculator: max($150, area × $0.0952), below 2,000 sq ft. Average photography is $245 + (area − 2,000) × $0.0175 across 2,000–4,000 sq ft. Exact shared category boundaries remain selectable in the adjacent category, as in the approved preview; this can create price steps. Luxury photography is $425 + (area − 5,000) × $0.1176 across 5,000–10,000 sq ft, using the recovered starting fee and per-foot rate. The rate applies above 5,000 so the starting price remains $425. Large Home remains a custom quote because its complete rate could not be recovered; the available history gives only its $350 starting price.

Matterport is max($69, area × $0.06). Website $65, drone $120, Zillow $95, 2D floor plan $50, virtual twilight $35 per image. Video is $225 + (seconds − 60) × $125 / 120 from 60 to 180 seconds. The video slider advances one second; paired minutes/seconds inputs accept exact durations and stay synchronized with it. Displayed prices round to cents only after calculation. The $225/$350 endpoints differ by $125, not $75. Examples: 1:01 = $226.04; 2:00 = $287.50; 2:59 = $348.96. Twilight quantities are whole images (1–100).

Silver $220, Gold $499 and Platinum $995 keep the supplied rate-sheet inclusions and prices. Package charges replace included à-la-carte charges. Gold includes one minute of video; Platinum includes two. Video duration is locked while included, since a package-upgrade price has not been authorized.

All quoted amounts use integer cents. Unfinalized photography is displayed as Custom Quote, with any priced-service subtotal explicitly separated. The quote can still be sent as a custom pricing request.

## Time estimate

Photography: area × 35 / 1,000 minutes. Matterport: area × 9 / 1,000 minutes, equivalent to 150 scans × 30 seconds plus 15 minutes at 10,000 sq ft. The combined known on-site estimate is rounded up to five minutes. Thus 1,000 sq ft photography = 35 minutes; 10,000 sq ft Matterport = 90 minutes. Package inclusions are counted once.

The linear photography assumption yields 350 minutes at 10,000 sq ft. It is intentionally not capped. Video adds 15 minutes per finished minute, calculated from exact seconds before rounding the total. Gold includes 15 minutes of video capture; Platinum includes 30 minutes. Drone capture adds a single 20-minute allowance when selected or included, regardless of media quantity. Included services are counted once. Commercial photography uses a separate rate of 1.5 minutes per 1,000 sq ft; residential photography retains 35 minutes per 1,000 sq ft. Both markets use the same scan, video and drone timing formulas; commercial video quantity multiplies the video time, while the drone allowance remains one 20-minute window. Zillow and floor-plan capture times have not been supplied and remain to be confirmed. Website and virtual-twilight editing do not add on-site time. Photography is mandatory, so all residential selections include an on-site visit.

## Email delivery boundary

The repository has no configured submission or transactional-email service. The page provides functioning mailto drafts to the entered agent email or sales@sitesee.ai with the exact subject `Residential SiteSee Real Estate Quote`. It clearly tells the visitor to press Send in their email app and never claims delivery. Copy Quote provides a fallback, including a selectable text area when clipboard access is unavailable. Personal details remain in memory until the visitor chooses an email/copy action; they are not persisted or sent to an endpoint.

Automatic email delivery remains a launch dependency: connect a server-side mail provider, repeat validation and price calculation server-side, protect against spam, persist preferences appropriately, and verify receipt. No test emails were sent. Calendar availability integration is not present; preferred dates are requests only.

## Validation

- `node --test tests/quote-engine.test.cjs`: eleven tests pass, covering price boundaries, per-foot rates, packages and duplication, video duration, quantities, invalid inputs, capture times, package video duration, fixed drone allowance and email content.
- DOM interaction checks using Linkedom pass for the address gate/re-lock, package locks, live estimates, video changes, required agent fields, exact-subject email drafts, and date/time requirements. Browser validation primitives were simulated; this is not a rendered-browser test.
- JavaScript syntax, CSS parsing, required field attributes, duplicate IDs, and local link/asset checks pass.
- Visual desktop/mobile browser review remains outstanding. Local Chromium installation timed out, and the cloud browser rejected the local preview with `net::ERR_BLOCKED_BY_CLIENT`. No claim of browser visual verification is made.

## Review locally

Open `public/pricing.html` directly in a browser or Dreamweaver. No build is required. Alternatively run `python3 -m http.server 8765 --directory public`, then open `http://localhost:8765/pricing.html`.

Try a complete address; Average / 2,680 sq ft; add Matterport; switch to Gold and Platinum; switch back to individual services; change the address to an invalid ZIP; and complete the required agent fields and preferred appointment. Email actions prepare drafts and require the reviewer to press Send; sending is unnecessary for layout and calculator review.

## Hero and video refinement — 2026 09 19

The desktop hero now uses the existing image across the full section behind a broad, continuous gradient: fully opaque at the left, 10% opacity (90% transparency) at the section midpoint, and transparent by 65%. The copy remains above the overlay. The stacked mobile layout retains its solid text area, with the image transition expanded from 30% to 37.5% (25% wider).

Standalone video supports every second from 1:00 through 3:00 through the slider and minutes/seconds number fields. Gold/Platinum inclusions remain locked to their included durations, without a duplicate standalone charge. Timing copy explicitly names services whose time is not included; Matterport is already included when selected.

## Category and control correction — 2026 09 20

The Small/Average photography formulas and Silver/Gold/Platinum prices are unchanged. Service-row prices now read the individual line fee, avoiding duplicate photography when showing an add-on price. The saved preview and original residential code used a null photography rate for Large and Luxury before the slider removal. A subsequent thread search recovered Luxury’s $425 starting fee and $0.1176 per-square-foot instruction, implemented in the recovery below. Large’s complete formula remains unresolved.

## Residential slider restoration — 2026 09 20

Slider removal applies to commercial pricing only. Residential property-size and video-duration sliders are restored from the prior implementation. The property-size slider stays disabled and gray with Silver, Gold or Platinum and active for Individual Services. Photography stays mandatory. Pricing formulas, capture-time calculations, and commercial behavior are unchanged by this restoration.

## Luxury rate recovery — 2026 09 20

Branch: `fix/residential-pricing-recovery-20260920`, based on the restored residential slider commit `e98bd5d5ff79ccfd213004fb8aec16e762372792`. Each subsequent repair must start on a new branch while this pricing repair is ongoing, as requested by the user. Preserve prior repair branches for comparison and recovery.

The recovered thread specifies Luxury at 5,000–10,000 sq ft, starting at $425, with a rate of $0.1176 per square foot. Applying the rate to area above the starting point gives $425 at 5,000; $719 at 7,500; and $1,013 at 10,000. Prices round to cents after calculation. This implements the recovered numbers; the saved older code did not contain a working Luxury formula.

Large’s $350 starting price was recovered, but no additional-foot rate or upper price was recovered. The next category’s $425 starting fee has not been assumed to be Large’s endpoint. Large remains the only residential photography category requiring a custom quote.

The Small/Average formulas, all bundle fees, service rates, timing calculations, mandatory photography, restored residential sliders, bundle size locks, and commercial code are unchanged.

## Photo counts and interpolation — 2026 09 20

Branch: `fix/residential-photo-counts-20260920`, based on the preceding Luxury recovery branch. Residential category choices, selected photography details, quote summaries and emails now include these ranges:

| Category | Photos |
| --- | --- |
| Small Home / Condo | 25–30 |
| Average Home | 30–50 |
| Large Home | 50–60 |
| Luxury Home | 50+ |

Silver, Gold and Platinum retain their respective 25 HDR / 35 HDR / 50+ HDR photo inclusions. Category ranges describe individual photography coverage; they do not introduce a residential per-photo charge or replace the existing area-based pricing.

The user reconfirmed the interpolation method: starting fee plus actual area above the category start multiplied by (ending fee − starting fee) / (ending area − starting area). Average is $245 + (sqft − 2,000) × $0.0175, giving $256.90 at 2,680 sq ft and $266.70 at 3,240 sq ft. Small retains its $150 minimum. The quoted instructions repeat the $350 Large and $425 Luxury starting prices but ask for their endpoints; they do not supply a Large endpoint. This revision preserves the existing formulas, including the preceding Luxury recovery, and leaves Large explicitly unresolved.

The property-size slider is absent from commercial only. Residential size/video sliders stay operational for individual services; its size controls remain disabled for fixed bundles. Commercial photo quantity is retained. The photo-count correction does not change commercial controls or fees.
