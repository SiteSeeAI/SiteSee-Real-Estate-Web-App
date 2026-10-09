# UX iterations

## UX-001 — Application workspace

Date: October 9, 2026, America/Chicago. Status: implemented locally and under review; no redesign code installed. Record implementation, validation and acceptance in [CHANGELOG.md](../../CHANGELOG.md).

The user rejects the current long, stacked layouts. The intended result is a recognizable software interface that guides an agent or manager through the next relevant action while preserving the accepted booking, payment and provider behavior. Start with management Bookings/Booking Review and the Agent Account/Orders pages, then apply the same components to Production and Vendors where appropriate.

### Page structure

| Component | Intended behavior |
| --- | --- |
| Application navigation | Stable role-specific navigation; current section visibly selected. Desktop sidebar with a compact header; accessible responsive navigation on small screens. Keep SiteSee branding and Poppins/Inter. |
| Page header | Clear task title, concise status and one primary action when the saved state permits it. Account, sign-out and secondary actions have consistent positions. |
| Orders workspace | Open on the left, Previous on the right; independent pages and compact 5/25/All controls below the lists. Selections refresh automatically without an Update Lists button; changing one group preserves the other group’s page. Stack columns on mobile. Preserve explicit staff closure rules and ownership-scoped totals/rows. |
| Order row | Property address is the main label, followed by the readable order number, arrival window, amount and a text-labelled status. Use compact rows and consistent spacing. |
| Booking summary | Property, customer, confirmed arrival window and payment/review status stay easy to find without exposing every form. A proposed window is separately labelled as awaiting review. |
| Booking steps | Four numbered stages describe Review → Prepare → Confirm → Closeout for staff and Deposit → Review → Appointment → Delivery for customers. Future stages are labels. A state-derived primary action leads to a focused screen; a task selection reveals one existing form. Required recovery comes first. Saved Vendor management and existing Job/Deliverables remain relevant secondary context after their recorded triggers. Users can return to the overview without losing saved information. |
| Onsite Closeout | Open content on its selected screen; collapsed shortcut in the overview. Retain pricing, verbal approval, completion and payment semantics. Remove no required consent or verification. |
| Account and Billing | Profile prerequisites have a clear next action; existing orders and billing remain accessible. Billing uses consistent amounts and verified receipt links. |
| Secondary information | Communication/recovery evidence belongs in a labelled drawer or disclosure. Keep blockers visible with a useful next action; hide irrelevant diagnostic detail. |

### Interaction rules

- Use Jakob's Law: familiar navigation, tables/lists, tabs, toolbars and feedback instead of page-specific control arrangements.
- Apply Hick's Law and progressive disclosure: reduce visible choices to those relevant to the current task and state.
- Apply Miller's Law and common-region grouping: keep related information in small, coherent sections.
- Apply Fitts's Law: make primary controls easy to reach and activate; separate destructive actions from routine navigation.
- Use consistent typography, spacing, contrast, status wording and control sizing. A polished surface must accurately show requested, approved, confirmed, completed and cancelled states.
- Keep keyboard navigation, visible focus, labelled controls and text status indicators. Do not communicate a status through colour alone.
- Keep context when moving between steps; selecting an available window never manufactures customer consent or manager approval.
- Keep operation feedback beside the action and show recovery guidance for a saved or uncertain result. Browser success does not prove a payment or provider outcome.

### Acceptance for this iteration

1. Management and agent pages look and behave as one application, with consistent software components and navigation.
2. The requested Open/Previous columns and independent pagination remain correct; small screens remain usable without endless scrolling or clipped controls.
3. Booking Review exposes one clear next action, while an agent sees only actions appropriate to approval, proposals, completion or cancellation.
4. Requested and confirmed appointment windows remain clearly distinct; consent is explicit and never defaulted to an unrelated window.
5. Onsite Closeout remains open only on the selected closeout screen; the overview stays compact.
6. Relevant existing interaction/role/workflow checks pass after implementation, including responsive 320/390/736/1200/1600 checks. Preserve every original authorization, financial, provider-identity and recovery guard.
7. The user reviews representative screens and confirms the UX before a TEST server update. A future LIVE release is a separate scope and decision.

Do not add new financial automation or production-mode support as an incidental UI change. The user asked whether automatic refunds and credits are possible; that answer does not mean they are already implemented. Keep Stripe TEST throughout this iteration.

### Reference and delivery

CTC reference supplied by the user: https://citytabernaclechurch.org/publishing. Its workspace is behind sign-in and was not inspected. The existing Codex project was not available as a selected repository. Corporate palette checked from https://sitesee.ai/assets/css/v1-overrides.css: charcoal `#0B0F14`, yellow `#FFC107`, off-white `#F7F7F7`, gray surfaces/lines. No CTC styling or exact visual parity is claimed.

Downloadable review captures the real implemented screens from isolated synthetic HTTPS journeys. It is a visual review artifact, not an installation package. User acceptance and any approved server update are recorded separately; Stripe remains TEST.
