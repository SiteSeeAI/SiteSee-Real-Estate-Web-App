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
