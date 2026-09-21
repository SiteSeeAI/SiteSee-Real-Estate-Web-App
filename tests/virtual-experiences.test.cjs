const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const page = fs.readFileSync(path.join(root, 'public/virtual-experiences.html'), 'utf8');
const css = fs.readFileSync(path.join(root, 'public/assets/css/site.css'), 'utf8');
const experiences = [
  ['https://apps.sitesee.ai/tour/residential-demo-01', 'Residential Property Experience 01', 'Residential Experience 01'],
  ['https://apps.sitesee.ai/tour/residential-demo-02', 'Residential Property Experience 02', 'Residential Experience 02'],
  ['https://apps.sitesee.ai/tour/commercial-demo-01', 'Commercial Property Experience 01', 'Commercial Experience 01'],
  ['https://apps.sitesee.ai/tour/commercial-demo-02', 'Commercial Property Experience 02', 'Commercial Experience 02'],
  ['https://apps.sitesee.ai/tour/industrial-demo-01', 'Industrial Property Experience 01', 'Industrial Experience 01'],
  ['https://apps.sitesee.ai/tour/industrial-demo-02', 'Industrial Property Experience 02', 'Industrial Experience 02'],
];

test('the gallery embeds all six supplied property experiences', () => {
  assert.equal((page.match(/<iframe\b/g) || []).length, 6);
  assert.equal((page.match(/class="media-placeholder wide-media experience-card"/g) || []).length, 6);
  for (const [url, title, label] of experiences) {
    assert.ok(page.includes(`src="${url}"`), url);
    assert.ok(page.includes(`title="${title}"`), title);
    assert.ok(page.includes(`<strong>${label}</strong>`), label);
  }
  assert.equal(page.includes('Coming Soon'), false);
});

test('experience embeds preserve responsive 16:9 presentation and fullscreen access', () => {
  assert.equal((page.match(/allowfullscreen/g) || []).length, 6);
  assert.equal((page.match(/loading="lazy"/g) || []).length, 6);
  assert.match(css, /\.experience-embed\{[^}]*aspect-ratio:16\/9/);
  assert.match(css, /\.experience-embed iframe\{[^}]*width:100%[^}]*height:100%[^}]*border:0/);
});
test('each property category explains its purpose and specific platform features', () => {
  assert.equal((page.match(/class="experience-group"/g) || []).length, 3);
  assert.equal((page.match(/class="experience-feature-list"/g) || []).length, 3);
  for (const heading of [
    'Help Buyers Decide Before They Schedule A Showing.',
    'Narrow The Field Before A Site Visit.',
    'Make A Complex Facility Easier To Understand.',
  ]) {
    assert.ok(page.includes(heading), heading);
  }
  for (const feature of [
    'Branded Property Hub',
    'Floor Plans &amp; Preliminary Measurements',
    'In-Platform Meetings &amp; Guided Review',
    'Location-Based Notes &amp; Supporting Files',
    'Controlled Access &amp; Sharing',
  ]) {
    assert.ok(page.includes(feature), feature);
  }
  assert.ok(page.includes('spend less time on showings with people who are still browsing'));
  assert.match(css, /\.experience-group-header\{[^}]*grid-template-columns:/);
  assert.match(css, /@media\(max-width:520px\)\{\.experience-feature-list\{grid-template-columns:1fr\}/);
});
