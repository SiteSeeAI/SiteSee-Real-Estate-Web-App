# SiteSee Real Estate

Initial website design, 2026 09 13.

Responsive HTML and PHP pages implement the approved SiteSee Real Estate structure, with the existing SiteSee logo, Poppins and Inter, yellow accents, a shared header and footer, alternating content sections, and property photography. Platform-specific graphics remain for a later pass.

## View the design

Open `public/index.html` in a browser or Dreamweaver for static-page review. The verified pricing workflow requires PHP and cannot be tested through a static file preview.

For a local HTTP preview, from this repository run:

```bash
python3 -m http.server 8765 --directory public
```

Then open `http://localhost:8765`. On Windows, `py -m http.server 8765 --directory public` is the equivalent command.

Edit the HTML pages directly. Styling is in `public/assets/css/site.css`; menu, portfolio tabs and form preview behavior are in `public/assets/js/site.js`. The shared header and footer markup appears in each page so navigation remains available without JavaScript.

## Pages

| Page | File |
| --- | --- |
| Home | [public/index.html](public/index.html) |
| Platform | [public/platform.html](public/platform.html) |
| Services Provided | [public/services-provided.html](public/services-provided.html) |
| Our Work | [public/our-work.html](public/our-work.html) |
| Residential Photography | [public/residential-photography.html](public/residential-photography.html) |
| Commercial Photography | [public/commercial-photography.html](public/commercial-photography.html) |
| Contact | [public/contact.html](public/contact.html) |
| Request Pricing Access | [public/pricing-request.html](public/pricing-request.html) |
| Verified Residential & Commercial Pricing | [public/pricing.php](public/pricing.php) |

## Real estate platform features

Updated 2026 09 16. AI Decluttering & Redesign is now the second Platform capability section, with separate explanations and an original/concept comparison. Added interactive property details, guided highlights, preliminary measurements, exact-room links, private sharing, agent/brokerage presentation and multilingual content. The compact For Agents, Teams & Brokerages section also explains listing oversight. Meetings, notes and engagement remain part of the complete platform.

Home leads its capability links with AI Decluttering & Redesign. Services links directly to AI, meetings and notes. All page footers link to AI and the team section as well as the existing meeting and notes sections.

To review the current website in GitHub Desktop, select **main** and pull the latest changes. Open `public/platform.html` in a browser or Dreamweaver; also review `public/index.html` and `public/services-provided.html`. The local preview instructions above apply.

The current change record is [Real_Estate_Platform_Expansion_20260916.md](documents/design/Real_Estate_Platform_Expansion_20260916.md). The earlier meeting/notes update remains in [Real_Estate_Platform_Features_20260916.md](documents/design/Real_Estate_Platform_Features_20260916.md).

## Approved editorial revisions

On 2026 09 17, the approved Human Writing samples were applied to the homepage introduction, AI descriptions, meeting and note explanations, property website service copy, and the shared Why SiteSee paragraph. The feature additions from pull request #1 are included in main alongside these edits. Headings, paragraph structure, claims, pricing, forms and visual design are preserved from the reviewed version.

See [Real_Estate_Approved_Editorial_Revisions_20260917.md](documents/design/Real_Estate_Approved_Editorial_Revisions_20260917.md) for scope and verification.

## Commercial calculator and separate service pages — 2026 09 19

The protected `public/pricing.php` page begins with Residential / Commercial selection and keeps separate calculators, address gates and request fields. Commercial photography is automatic: Small $750, Warehouse / Office $1,200, Factory / Industrial $2,500. Additional photographs are the only quantity adjustment to those photography fees; property area does not reprice photography. The commercial Property Size field is completely removed. Its only range slider is photo quantity; Matterport coverage, video and term controls retain their number fields. Residential keeps its property-size and video-duration sliders. Matterport has independent coverage at $0.10/sqft with a $199 minimum. SiteSee platform is $49/month; individual 360° photos are $25, appear only after Matterport is selected and require the platform subscription. Aerials are $42/image, video is $8.333/second with a $500 minimum, and an independent website is $175, selectable as an add-on or via the synchronized delivery radio even with a platform subscription. Media licensing includes the first six months, then charges (photography + aerial + video) × 30% ÷ 12 per additional month through 18 months total. Unlimited use adds 50% of that same media base. Matterport hosting includes six months; its independent 6–18 month total-term number input adds $6.99 per additional month or $4.99 with Pay In Advance selected. Commercial photography time is shown as the selected category’s size range at 1.5 minutes per 1,000 sq ft, because exact property size is no longer collected. Residential photography remains 35 minutes per 1,000 sq ft. Both markets estimate scanning at 9 minutes per 1,000 covered sq ft, video at 15 minutes per finished minute and one 20-minute drone allowance per visit.

Commercial photography includes 25–30 / 30–45 / 45–55 photos by category. The total-photo slider caps at 100 and adds $30 / $26.70 / $25 for each photograph beyond 30 / 45 / 55 respectively. These extra charges enter the media-license base. Factory / Industrial is limited to 250,000 sq ft.

Services Provided now has separate Residential Photography and Commercial Photography sections. They link to `public/residential-services.html` and `public/commercial-services.html`. Both process pages describe choosing services, selecting a shoot, field production, post-production and delivery within 24 hours of the completed shoot. The existing image-only galleries remain separate.

Review [Commercial_Pricing_20260919.md](documents/design/Commercial_Pricing_20260919.md) for pricing sources, unit calculations and the remaining commercial pricing decisions. Run `node --test tests/*.test.cjs` for both calculators. The new work does not configure automatic mail delivery, confirm calendar availability or deploy to the live server.

## Current behavior

The public `public/pricing-request.html` page is the pricing-access request form. It follows the corporate manual-review workflow: the requester supplies required business contact information, sales receives a signed review link, and no pricing is released until sales explicitly approves the request. Approval sends the requester a signed link that expires after 36 hours. Opening it creates a verified browser session lasting up to 12 hours and redirects to `public/pricing.php`.

The protected calculator retains every approved layout, field and pricing rule. All address and agent-detail fields remain required. Its residential and commercial forms submit quote actions to `public/quote-submit.php`, which also requires the verified pricing session. The handler validates the complete address, agent details, mailing-list preference, requested date and time, and every market-specific pricing input before recalculating the estimate on the server. **Email My Quote To Me** delivers the validated quote to the agent. **Request My Preferred Date** delivers the request to `sales@sitesee.ai` and attempts a confirmation copy to the agent. The exact subjects remain `Residential SiteSee Real Estate Quote` and `Commercial SiteSee Real Estate Quote`. **Copy Quote** remains a local fallback. A preferred date is never presented as a confirmed appointment.

Residential photography is always selected and disabled. The square-footage slider and number field are disabled for Silver, Gold and Platinum and enabled for Individual Services. Residential video retains its duration slider. Luxury photography uses $425 + (sqft − 5,000) × $0.1176. Read [Residential_Pricing_20260919.md](documents/design/Residential_Pricing_20260919.md) for formulas, required fields, email behavior, time estimates and validation. Large Home uses the proposed $350-to-$425 scale across 4,000–5,000 sq ft ($0.075 per additional sq ft); its upper endpoint still needs confirmation. Run `node --test tests/*.test.cjs` for the browser calculators and integration contract, and `php tests/pricing-request.test.php` for server parity.

## Documents

All recovered website documents are indexed in [documents/README.md](documents/README.md), including the current brief, updated Word hierarchy, customer rate sheet, supplied pricing sources and imagery guidance. The supplied source files are preserved, and the design brief tracks subsequent revisions. A checksum manifest records the six retained project/source documents.

The website root is **public/**. Documents are stored separately and are not linked from public pages. The source rate sheet and browser calculator scripts remain outside `public/`. The property-address step remains a calculator workflow inside the separately verified pricing session.

## Production status

This commit begins the design stage. It does not deploy the site, configure a subdomain, modify the corporate website or activate submission handling. `noindex` and `robots.txt` discourage indexing during design review; they are not access controls.

Before launch, supply approved imagery and experience URLs; confirm the proposed Large Home upper endpoint; confirm the production host and subdomain; complete browser review at desktop and mobile widths; and run a production SMTP delivery test. Serve only `public/` while keeping `_private/` available to PHP outside the document root. The host must provide PHP 8.1 or later, writable rate-limit and pending-request storage, `SITESEE_REAL_ESTATE_SITE_URL`, a strong `SITESEE_REAL_ESTATE_PRICING_GATE_SECRET`, and either the existing SiteSee SMTP environment or a working PHP mail transport. See [Server_Form_Integration_20260921.md](documents/design/Server_Form_Integration_20260921.md) for the exact deployment contract. Deliberately remove design-preview messages and indexing restrictions only when the operational site is ready.

Validation and the remaining review limitation are recorded in [documents/design/Initial_Design_Record_20260913.md](documents/design/Initial_Design_Record_20260913.md).


## Photography galleries and image placement — 2026 09 19

Our Work links to separate Residential Photography and Commercial Photography pages, containing all 50 residential and 35 commercial images. Galleries display images only, without captions, lightboxes or image click actions, with a contained hover zoom. Selected photography now appears on Home, Platform, Services Provided and Our Work. See [the photography record](documents/design/Photography_Galleries_20260919.md) for placements, deferred graphics and validation.

Review `public/our-work.html`, follow both gallery links, then review Home, Platform and Services Provided on desktop and mobile. This change does not deploy the website.
