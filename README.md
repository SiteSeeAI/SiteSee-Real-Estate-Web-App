# SiteSee Real Estate

Initial website design, 2026 09 13.

Six responsive HTML pages implement the approved SiteSee Real Estate structure, with the existing SiteSee logo, Poppins and Inter, yellow accents, a shared header and footer, alternating content sections, and intentional image placeholders.

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
| Contact | [public/contact.html](public/contact.html) |
| Request Pricing Access | [public/request-pricing-access.html](public/request-pricing-access.html) |

## Real estate platform features

Updated 2026 09 16. AI Decluttering & Redesign is now the second Platform capability section, with separate explanations and an original/concept comparison. Added interactive property details, guided highlights, preliminary measurements, exact-room links, private sharing, agent/brokerage presentation and multilingual content. The compact For Agents, Teams & Brokerages section also explains listing oversight. Meetings, notes and engagement remain part of the complete platform.

Home leads its capability links with AI Decluttering & Redesign. Services links directly to AI, meetings and notes. All page footers link to AI and the team section as well as the existing meeting and notes sections.

To review the current website in GitHub Desktop, select **main** and pull the latest changes. Open `public/platform.html` in a browser or Dreamweaver; also review `public/index.html` and `public/services-provided.html`. The local preview instructions above apply.

The current change record is [Real_Estate_Platform_Expansion_20260916.md](documents/design/Real_Estate_Platform_Expansion_20260916.md). The earlier meeting/notes update remains in [Real_Estate_Platform_Features_20260916.md](documents/design/Real_Estate_Platform_Features_20260916.md).

## Approved editorial revisions

On 2026 09 17, the approved Human Writing samples were applied to the homepage introduction, AI descriptions, meeting and note explanations, property website service copy, and the shared Why SiteSee paragraph. The feature additions from pull request #1 are included in main alongside these edits. Headings, paragraph structure, claims, pricing, forms and visual design are preserved from the reviewed version.

See [Real_Estate_Approved_Editorial_Revisions_20260917.md](documents/design/Real_Estate_Approved_Editorial_Revisions_20260917.md) for scope and verification.

## Current behavior

The pricing form has exactly five contact fields: First Name, Last Name, Company Name, Email Address and Phone Number. Only Company Name is required. An optional, initially unchecked checkbox reads “Please exclude me from all mailing lists.” Its state is included in the request preview. The subject is exactly `Real Estate Div. - Pricing request.`

Contact includes one optional Preferred Communication radio group: Call, Text, Email. Discovery Call has been removed. This initial Contact layout uses the same five contact fields plus a project message; no additional Contact validation requirements have been approved.

Both forms validate and display a clearly labeled request preview. They do not send email, post submissions, store personal data or grant pricing access. Submit buttons remain disabled until the preview handler is installed. No-JavaScript users can still navigate and read the site. The portfolio includes keyboard-accessible Photography and Virtual Experiences tabs.

## Documents

All recovered website documents are indexed in [documents/README.md](documents/README.md), including the current brief, updated Word hierarchy, customer rate sheet, supplied pricing sources and imagery guidance. The supplied source files are preserved, and the design brief tracks subsequent revisions. A checksum manifest records the six retained project/source documents.

The website root is **public/**. Documents are stored separately and are not linked from public pages. The design does not contain a public copy of the protected rate sheet or an imitation access gate.

## Production status

This commit begins the design stage. It does not deploy the site, configure a subdomain, modify the corporate website or activate submission handling. `noindex` and `robots.txt` discourage indexing during design review; they are not access controls.

Before launch, supply approved imagery and experience URLs; implement and verify server-side submission handling, opt-out persistence and response delivery; implement the independent protected pricing workflow; confirm the production host and subdomain; and complete browser review at desktop and mobile widths. Serve only `public/`, then deliberately remove design-preview messages and indexing restrictions when the operational site is ready.

Validation and the remaining review limitation are recorded in [documents/design/Initial_Design_Record_20260913.md](documents/design/Initial_Design_Record_20260913.md).
