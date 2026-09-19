# Photography galleries and image placement — 2026 09 19

The initial gallery work was recovered from local files; the existing `photography-masonry-galleries` branch still matched `main` at `a88d4f68cbe9d4826964547fb8e26ed75ec851d2`. This update commits the recovered assets and the requested separate galleries together.

The existing Our Work filename is `public/our-work.html`. It now contains image cards linking to `residential-photography.html` (50 images) and `commercial-photography.html` (35 images). Both galleries include links back to Our Work and to each other. Services also links directly to both galleries.

All 85 prepared WebP files from the prior gallery work are preserved byte for byte. Galleries contain images only: no visible captions, title attributes, lightboxes, links or click handlers on individual photographs. Accessible image descriptions remain. Masonry uses three desktop columns, two below 991px and one below 601px; alternating frame proportions provide the masonry rhythm. Desktop crops use centered cover placement; mobile retains complete original proportions. A 3.5% contained hover zoom respects reduced-motion preferences. Galleries work without JavaScript.

## Photography placements

| Page | Section | Image |
| --- | --- | --- |
| index.html | Featured Property Photography | residential-36.webp |
| index.html | Property Experience & Connected Media | residential-08.webp |
| index.html | Featured Photography | residential-16.webp |
| index.html | Featured Virtual Experience | residential-26.webp |
| platform.html | The SiteSee Property Platform | residential-03.webp |
| platform.html | Property Experience & Media | commercial-20.webp |
| services-provided.html | Residential & Commercial Property Media | commercial-05.webp |
| services-provided.html | Interior & Exterior Photography | residential-15.webp |
| services-provided.html | Interactive Property Experience | residential-05.webp |
| services-provided.html | Property & Surrounding Area | commercial-34.webp |
| services-provided.html | Property Video | residential-22.webp |
| services-provided.html | Daylight To Virtual Twilight | residential-28.webp |
| services-provided.html | Property Media Collection | commercial-17.webp |
| our-work.html | Residential gallery link | residential-05.webp |
| our-work.html | Commercial gallery link | commercial-33.webp |

The homepage pairs a lakeside evening exterior with bright interiors and a woodland property. Services uses a commercial exterior in the hero, a kitchen for photography, an aerial distribution-center view for the surrounding-area section, a pool terrace for video, a stone residence at twilight, and a restaurant for custom commercial coverage. Platform uses a residential interior and commercial lobby as supporting property photography. These are still images, not operational platform captures or playable media.

## Deferred graphics

No new graphics or generated images were created. Existing placeholders remain for the AI original/concept pair, floor plans and measurement tools, in-platform meetings, notes and collaboration, engagement, a dedicated property website screenshot, and the interactive experience on Our Work. Contact and Pricing have no existing image slots and retain their layout and form content.

## Validation

Eight pages and 407 local links/assets pass. All 85 images decode and match the recovered source files byte for byte. Gallery counts, category membership, unique IDs, alt text, dimensions and absence of image interactions pass. Shared headers, footers and forms match the repository baseline. CSS parsing and JavaScript syntax pass; DOM checks verify portfolio tab switching, arrow-key navigation and older residential/commercial fragment links.

Visual browser review remains pending: the cloud browser rejected the local preview with `net::ERR_BLOCKED_BY_CLIENT` during the resumed session. The responsive layout and cropping rules have not been visually verified in a browser. Review in a desktop and mobile browser or Dreamweaver before merge/deployment. Nothing has been deployed to AWS.
