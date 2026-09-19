# SiteSee Real Estate

Initial website design, 2026 09 13.

Eight responsive HTML pages implement the approved SiteSee Real Estate structure, with the existing SiteSee logo, Poppins and Inter, yellow accents, a shared header and footer, alternating content sections, and property photography. Platform-specific graphics remain for a later pass.

## View the design

Open `public/index.html` in a browser or Dreamweaver. All website assets are local; there is no installation or build step.

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
| Residential Pricing & Quote | [public/pricing.html](public/pricing.html) |

## Real estate platform features

Updated 2026 09 16. AI Decluttering & Redesign is now the second Platform capability section, with separate explanations and an original/concept comparison. Added interactive property details, guided highlights, preliminary measurements, exact-room links, private sharing, agent/brokerage presentation and multilingual content. The compact For Agents, Teams & Brokerages section also explains listing oversight. Meetings, notes and engagement remain part of the complete platform.

Home leads its capability links with AI Decluttering & Redesign. Services links directly to AI, meetings and notes. All page footers link to AI and the team section as well as the existing meeting and notes sections.

To review the current website in GitHub Desktop, select **main** and pull the latest changes. Open `public/platform.html` in a browser or Dreamweaver; also review `public/index.html` and `public/services-provided.html`. The local preview instructions above apply.

The current change record is [Real_Estate_Platform_Expansion_20260916.md](documents/design/Real_Estate_Platform_Expansion_20260916.md). The earlier meeting/notes update remains in [Real_Estate_Platform_Features_20260916.md](documents/design/Real_Estate_Platform_Features_20260916.md).

## Approved editorial revisions

On 2026 09 17, the approved Human Writing samples were applied to the homepage introduction, AI descriptions, meeting and note explanations, property website service copy, and the shared Why SiteSee paragraph. The feature additions from pull request #1 are included in main alongside these edits. Headings, paragraph structure, claims, pricing, forms and visual design are preserved from the reviewed version.

See [Real_Estate_Approved_Editorial_Revisions_20260917.md](documents/design/Real_Estate_Approved_Editorial_Revisions_20260917.md) for scope and verification.

## Current behavior

The residential pricing page is now `public/pricing.html`. All address and agent-detail fields are required. The estimator appears after a complete property address is entered and the agent selects Continue. The page supports live square-footage estimates, media bundles with duplicate-service locks, video-duration pricing, twilight quantities, estimated capture time and preferred appointment requests. Both date and time are required for appointment requests. The original pricing-access URL redirects to this page.

The page prepares email drafts to the agent or sales@sitesee.ai with subject `Residential SiteSee Real Estate Quote`. The visitor must press Send in their own email app. Copy Quote provides a fallback. No automatic email delivery or booking confirmation is claimed. The separate Contact form remains a design preview.

Read [Residential_Pricing_20260919.md](documents/design/Residential_Pricing_20260919.md) for formulas, required fields, email behavior, time estimates and validation. Large/Luxury standalone photography endpoint prices and automatic email delivery remain open launch items. Run `node --test tests/quote-engine.test.cjs` to verify calculator behavior.

## Documents

All recovered website documents are indexed in [documents/README.md](documents/README.md), including the current brief, updated Word hierarchy, customer rate sheet, supplied pricing sources and imagery guidance. The supplied source files are preserved, and the design brief tracks subsequent revisions. A checksum manifest records the six retained project/source documents.

The website root is **public/**. Documents are stored separately and are not linked from public pages. The source rate sheet remains outside public/. The approved residential rates are now in the customer-facing calculator; the property-address step is a form workflow, not an access-control mechanism.

## Production status

This commit begins the design stage. It does not deploy the site, configure a subdomain, modify the corporate website or activate submission handling. `noindex` and `robots.txt` discourage indexing during design review; they are not access controls.

Before launch, supply approved imagery and experience URLs; implement and verify server-side submission handling, opt-out persistence and response delivery; finalize Large/Luxury photography endpoints; confirm the production host and subdomain; and complete browser review at desktop and mobile widths. Serve only `public/`, then deliberately remove design-preview messages and indexing restrictions when the operational site is ready.

Validation and the remaining review limitation are recorded in [documents/design/Initial_Design_Record_20260913.md](documents/design/Initial_Design_Record_20260913.md).


## Photography galleries and image placement — 2026 09 19

Our Work links to separate Residential Photography and Commercial Photography pages, containing all 50 residential and 35 commercial images. Galleries display images only, without captions, lightboxes or image click actions, with a contained hover zoom. Selected photography now appears on Home, Platform, Services Provided and Our Work. See [the photography record](documents/design/Photography_Galleries_20260919.md) for placements, deferred graphics and validation.

Review `public/our-work.html`, follow both gallery links, then review Home, Platform and Services Provided on desktop and mobile. This change does not deploy the website.
