# Required quote fields — 2026 09 25

Both residential and commercial pricing forms mark active required fields with a red asterisk. Optional fields stay unmarked. Conditional access/contact requirements follow the selected choices.

“Request My Preferred Date” checks visible enabled controls in document order before sending. The first invalid field or radio group receives a pale-red background, red outline and text message. Focus moves to it without an immediate jump, then it scrolls into the center of the viewport; reduced-motion preference uses immediate scrolling. Corrected or hidden fields lose their error styling. Date/time and existing scheduling checks also use this presentation.

“Exclude You From Mailing Lists?” is optional and initially selects “No — updates are welcome.” The server treats an omitted or blank preference as No while preserving an explicit Yes. Unknown supplied values remain invalid.

## Deployment standard

Always upload approved files from one exact main commit, verify all uploaded files against that same commit, correct mismatches, and only then test a form. Line-ending-only differences are acceptable. A successful upload report does not replace this comparison. This standard was explicitly agreed on 2026 09 25 after a stale residential script prevented confirmation navigation.

For this change, upload these six files:
- public/assets/css/pricing.css → /home/sitesee/public_html/re/assets/css/pricing.css
- _private/real-estate-pricing.php → /home/sitesee/.sitesee-real-estate/real-estate-pricing.php
- _private/views/pricing.php → /home/sitesee/.sitesee-real-estate/views/pricing.php
- _private/pricing-assets/scheduling.js → /home/sitesee/.sitesee-real-estate/pricing-assets/scheduling.js
- _private/pricing-assets/pricing.js → /home/sitesee/.sitesee-real-estate/pricing-assets/pricing.js
- _private/pricing-assets/commercial-pricing.js → /home/sitesee/.sitesee-real-estate/pricing-assets/commercial-pricing.js

Upload scheduling.js before the two calculator scripts, which use its shared validator. No mail, access-token, PHP-FPM, staff approval or Stripe configuration changes are required.

After file verification, test a missed required field on mobile: request submission must scroll to the first missed field and highlight it without submitting. Correct it, check the highlight clears, confirm optional mailing preference defaults to No, and confirm a complete request still opens the dedicated receipt page. Browser-level mobile verification remains a post-deployment step.
