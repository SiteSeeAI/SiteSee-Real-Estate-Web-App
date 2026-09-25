# Preferred-date confirmation — 2026 09 24

## Approved customer experience

After a successful residential or commercial “Request My Preferred Date” submission, open a dedicated confirmation page in the same tab. A small message beneath the form is insufficient. Use a prominent heading, a readable request reference, and the exact response-time promise: “We’ll be in contact in less than two hours.”

Receipt acknowledges the request; the requested appointment remains subject to availability and SiteSee confirmation. Preserve the phone number (800) 222-2053, SiteSee typography and colors. Show the email-copy status only when the submission response provides it.

## Implementation and deployment

Upload the new public `request-received.html` and `assets/js/request-received.js` before replacing private `pricing-assets/pricing.js` and `pricing-assets/commercial-pricing.js`. The private asset endpoint already serves both updated scripts without caching; its map is unchanged. The reference and email-copy result are the only query parameters. No property, access, contact or payment information appears in the URL.

Only a successful response identifying `request_appointment` and providing a valid reference triggers navigation. Quote-copy actions and rejected submissions retain their existing behavior. No pricing-access, email transport, booking approval, Stripe or invitation settings are changed.

## Verification and current evidence

The user confirmed a successful desktop request, reference B638F1E8E9, on 2026 09 24. Mobile completion has not been independently confirmed. This confirmation page improves visibility on desktop and mobile; it does not establish that the earlier mobile submission problem is resolved.

Node regression tests exercise each form’s actual submission function with simulated server responses, covering successful navigation, email-copy states, server/network rejection and the email-only action. The page renderer rejects absent or malformed references and writes reference text without HTML interpolation. Production deployment and a real mobile-browser check remain separate steps.
