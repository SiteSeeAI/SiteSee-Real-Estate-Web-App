# Residential pricing page — 2026 09 19

Review `public/pricing.html`. The existing Request Pricing Access URL redirects to the new page. All navigation and pricing links now point to Pricing & Quote. No production deployment is included.

The page uses the existing SiteSee logo, Poppins and Inter, yellow accents and shared header/footer. Residential gallery images 28 and 15 provide the twilight hero and kitchen sidebar photography; no new image assets are required.

## Form flow

1. Street address, city, state and ZIP are required. An explicit Continue button opens the estimator. This avoids showing dimmed, unusable controls and keeps the initial task short. Erasing or invalidating the address hides the estimator, summary and request form again. This is a UI progression, not an authentication or rate-sheet security boundary.
2. The agent selects one category and a package or individual services. Numeric inputs and sliders stay in sync. Included services are checked and disabled; stored individual selections are restored when switching away from a package. Totals include each service once. A selected service is required; unused add-on inputs are disabled.
3. First name, last name, company, email, phone and mailing-list preference are all required before creating an email draft. The original optional opt-out checkbox becomes an explicit required Yes/No choice so all request fields are complete without forcing either marketing preference. A quote copy can be prepared without selecting a shoot date. Both date and time are required for an appointment request. Dates and times are validated against Central Time. The request does not reserve or confirm availability.

## Prices

Small photography retains the approved calculator: max($150, area × $0.0952), below 2,000 sq ft. Average photography is $245 + (area − 2,000) × $0.0175 across 2,000–4,000 sq ft. Exact shared category boundaries remain selectable in the adjacent category, as in the approved preview; this can create price steps. Large and Luxury standalone photography remains a custom quote because their endpoint prices have not been finalized. No missing rates are invented.

Matterport is max($69, area × $0.06). Website $65, drone $120, Zillow $95, 2D floor plan $50, virtual twilight $35 per image. Video is $225 + (minutes − 1) × $62.50 from one to three minutes. The slider and duration field use 0.1-minute increments. Twilight quantities are whole images (1–100).

Silver $220, Gold $499 and Platinum $995 keep the supplied rate-sheet inclusions and prices. Package charges replace included à-la-carte charges. Gold includes one minute of video; Platinum includes two. Video duration is locked while included, since a package-upgrade price has not been authorized.

All quoted amounts use integer cents. Unfinalized photography is displayed as Custom Quote, with any priced-service subtotal explicitly separated. The quote can still be sent as a custom pricing request.

## Time estimate

Photography: area × 35 / 1,000 minutes. Matterport: area × 9 / 1,000 minutes, equivalent to 150 scans × 30 seconds plus 15 minutes at 10,000 sq ft. The combined known on-site estimate is rounded up to five minutes. Thus 1,000 sq ft photography = 35 minutes; 10,000 sq ft Matterport = 90 minutes. Package inclusions are counted once.

The linear photography assumption yields 350 minutes at 10,000 sq ft. It is intentionally not capped. Drone, video, Zillow and floor-plan capture times have not been supplied, so the page identifies additional capture time to be confirmed instead of fabricating a complete appointment duration. Website and virtual-twilight editing do not add on-site time. Digital-only selections change the preferred date/time labels to completion date and contact time.

## Email delivery boundary

The repository has no configured submission or transactional-email service. The page provides functioning mailto drafts to the entered agent email or sales@sitesee.ai with the exact subject `Residential SiteSee Real Estate Quote`. It clearly tells the visitor to press Send in their email app and never claims delivery. Copy Quote provides a fallback, including a selectable text area when clipboard access is unavailable. Personal details remain in memory until the visitor chooses an email/copy action; they are not persisted or sent to an endpoint.

Automatic email delivery remains a launch dependency: connect a server-side mail provider, repeat validation and price calculation server-side, protect against spam, persist preferences appropriately, and verify receipt. No test emails were sent. Calendar availability integration is not present; preferred dates are requests only.

## Validation

- `node --test tests/quote-engine.test.cjs`: eight tests pass, covering price boundaries, per-foot rates, packages and duplication, video duration, quantities, invalid inputs, capture times and email content.
- DOM interaction checks using Linkedom pass for the address gate/re-lock, package locks, live estimates, video changes, required agent fields, exact-subject email drafts, and date/time requirements. Browser validation primitives were simulated; this is not a rendered-browser test.
- JavaScript syntax, CSS parsing, required field attributes, duplicate IDs, and local link/asset checks pass.
- Visual desktop/mobile browser review remains outstanding. Local Chromium installation timed out, and the cloud browser rejected the local preview with `net::ERR_BLOCKED_BY_CLIENT`. No claim of browser visual verification is made.

## Review locally

Open `public/pricing.html` directly in a browser or Dreamweaver. No build is required. Alternatively run `python3 -m http.server 8765 --directory public`, then open `http://localhost:8765/pricing.html`.

Try a complete address; Average / 2,680 sq ft; add Matterport; switch to Gold and Platinum; switch back to individual services; change the address to an invalid ZIP; and complete the required agent fields and preferred appointment. Email actions prepare drafts and require the reviewer to press Send; sending is unnecessary for layout and calculator review.
