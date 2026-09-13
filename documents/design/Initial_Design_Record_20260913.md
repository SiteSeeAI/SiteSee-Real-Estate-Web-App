# SiteSee Real Estate Initial Design Record

2026 09 13

## Design delivered

The initial six-page design brings property media and the SiteSee platform into the approved Real Estate Division navigation. The visual system uses the existing white/yellow SiteSee logo, Poppins headings, Inter body text, #0B0F14, #FFC107, #F7F7F7 and neutral borders. All headings use Title Case. Local fonts and assets keep the design independent of third-party asset loading.

Home introduces property media and the connected platform. Platform has its own page covering connected presentation, floor plans, AI decluttering/redesign, online meetings and engagement. Five alternating content sections are separated by the two approved, attributed property-marketing statistics and calls to action. Services Provided contains the eight concise service descriptions. Our Work provides Photography and Virtual Experiences views. Contact and Request Pricing Access implement the revised field layout.

Image areas deliberately remain placeholders in accordance with the approved design brief. No AI-generated library imagery is represented as completed SiteSee client work. The AI comparison area distinguishes Original Space from AI Design Concept. An experience embed is not invented.

## Form behavior

Pricing contains the five approved contact fields, with only Company Name required. Blank optional fields remain valid. A whitespace-only Company Name is rejected. An entered email must have valid browser email syntax; an omitted email remains valid. The mailing-list opt-out is initially unchecked and its state appears in the request preview. The subject preserves the exact requested capitalization and final period.

The Contact Preferred Communication group contains Call, Text and Email radio buttons, with no default choice and one selection at a time. Contact contains no Discovery Call option. Contact field validation beyond the communication choice has not been specified; this initial design does not add mandatory Contact fields.

Both forms display a preview and state explicitly that nothing has been sent or stored. They do not use a mailto submission substitute, network submission, local storage or cookies. Entered text is rendered with textContent rather than interpreted as HTML. The future server must enforce the approved field contract, set the subject itself, and persist the opt-out with the request. A checked opt-out must exclude the sender from all mailing lists while allowing a response to the requested inquiry.

## Repository organization

Public page files and assets are in `public/`. The six retained project/source documents remain outside that directory, with a document index and SHA-256 manifest. Protected prices are not embedded in public HTML, JavaScript or CSS. No production hosting or DNS change was made.

## Verification

All six pages have one H1 and the complete division navigation. Local asset paths, page links and section anchors were checked. The pricing field order, sole required field, unchecked opt-out, exact subject and Contact radio choices were checked against the approved contract. JavaScript syntax was checked.

Browser rendering and click-through review remain pending: the available Cloud Browser blocks both localhost and shared-file URLs. No browser screenshots or successful visual or interaction test results are claimed. The design is responsive in source, with navigation and layout breakpoints at 1240, 990, 740 and 420 pixels; actual desktop/mobile rendering still requires review.

## Next implementation work

Add approved property imagery and experience embeds. Review the design in desktop and mobile browsers, including keyboard navigation and form previews. Connect independent real estate submission, acknowledgment, opt-out persistence and protected pricing access before enabling live requests. Confirm the final host and subdomain before publication.

## Sources

The current content/design brief and Word hierarchy control the six-page scope and form behavior. The supplied media rate documents and customer rate sheet are retained unchanged. Branding assets were copied from `SiteSeeAI/SiteSee-3.01-CORP-Site`: `assets/images/logo/SiteSee_SpatialLink_Primary_WhiteYellow_Transparent.png` and `assets/images/logo/sitesee-favicon.png`. Corporate links follow the source repository’s `assets/js/site.js` footer destinations and the brief’s new-tab rule. The two published statistics retain the source links and scope already approved in the brief.

Poppins and Inter use the SIL Open Font License. License files were obtained from the official `google/fonts` repository and are included with the local font files.
