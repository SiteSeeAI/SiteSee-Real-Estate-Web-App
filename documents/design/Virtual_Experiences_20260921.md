# Virtual Experiences — 2026 09 21

The four placeholder cards on `public/virtual-experiences.html` were replaced with six embedded SiteSee property experiences. Two matching cards were added so the approved two-column layout now presents three rows on desktop and one column on smaller screens.

## Experience order

1. Residential Experience 01
2. Residential Experience 02
3. Commercial Experience 01
4. Commercial Experience 02
5. Industrial Experience 01
6. Industrial Experience 02

Every embed uses a responsive 16:9 container, lazy loading, a descriptive accessibility title, fullscreen permission and the existing SiteSee card caption treatment. No pricing, navigation, server-form or page-layout behavior outside this gallery was changed.

Production deployment and origin configuration remain separate. Before launch, confirm that `apps.sitesee.ai` permits framing from the Real Estate production origin through its `Content-Security-Policy frame-ancestors` response policy.
## Category introductions

The gallery is divided into Residential, Commercial and Industrial groups. Each pair now has a short customer-facing explanation and a specific capability list.

- Residential explains how buyers can understand the home and decide whether it is a realistic fit before requesting a showing, helping agents reserve more time for buyers who are ready to proceed.
- Commercial explains how buyers, tenants and decision-makers can narrow the field and prepare better questions before a site visit.
- Industrial explains how remote stakeholders can understand a complex facility, its key areas and supporting information before an onsite walkthrough.

The capability lists use the approved platform language for property or facility hubs, navigation, floor plans, preliminary measurements, guided highlights, AI visualization, notes, documents, in-platform meetings, direct links, engagement and controlled sharing.
