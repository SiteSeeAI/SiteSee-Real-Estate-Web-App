const test = require('node:test');
const assert = require('node:assert/strict');
const Q = require('../public/assets/js/quote-engine.js');
const fee = (quote, key) => quote.lines.find(line => line.key === key)?.cents;
const quote = overrides => Q.calculate({category:'average',package:'custom',sqft:2680,selected:['photo'],videoSeconds:60,images:1,...overrides});
test('photography interpolates within Average and respects the Small minimum', () => {
  assert.equal(quote({sqft:2000}).totalCents,24500);
  assert.equal(quote({sqft:2680}).totalCents,25690);
  assert.equal(quote({sqft:3240}).totalCents,26670);
  assert.equal(quote({sqft:4000}).totalCents,28000);
  for (const sqft of [1000,1200,1500]) assert.equal(quote({category:'small',sqft}).totalCents,15000);
  assert.equal(quote({category:'small',sqft:1999}).totalCents,19030);
});
test('Matterport minimum and per-foot prices', () => {
  for(const sqft of [1000,1150]) assert.equal(fee(quote({category:'small',sqft,selected:['mp']}),'mp'),6900);
  assert.equal(fee(quote({category:'small',sqft:1151,selected:['mp']}),'mp'),6906);
  assert.equal(fee(quote({selected:['mp']}),'mp'),16080);
  assert.equal(fee(quote({category:'luxury',sqft:10000,selected:['mp']}),'mp'),60000);
});
test('video endpoints, midpoint and quantity-based twilight', () => {
  for (const [videoSeconds,total] of [[60,22500],[61,22604],[90,25625],[120,28750],[179,34896],[180,35000]]) assert.equal(fee(quote({selected:['video'],videoSeconds}),'video'),total);
  for(let videoSeconds=61;videoSeconds<=180;videoSeconds++) assert.ok(quote({selected:['video'],videoSeconds}).totalCents > quote({selected:['video'],videoSeconds:videoSeconds-1}).totalCents);
  assert.equal(quote({selected:['video'],videoSeconds:179}).lines.find(line=>line.key==='video').label,'Property Video · 2:59');
  assert.equal(fee(quote({selected:['twilight'],images:3}),'twilight'),10500);
});
test('all packages suppress duplicate charges for included services', () => {
  for(const [name,p] of Object.entries(Q.packages).filter(([name])=>name!=='custom')) {
    assert.equal(quote({package:name,selected:[]}).totalCents,p.cents);
    assert.equal(quote({package:name,selected:[...p.includes,...p.includes]}).totalCents,p.cents);
    assert.equal(quote({package:name,selected:['twilight'],images:3}).totalCents,p.cents+10500);
  }
  assert.equal(quote({package:'gold',selected:['mp','photo','video']}).totalCents,65980);
  assert.equal(quote({package:'platinum',selected:['zillow']}).totalCents,109000);
});
test('Large photography remains pending until its complete rate is recovered', () => {
  for(const [category,sqft] of [['large',4000],['large',4500],['large',5000]]) {
    const result=quote({category,sqft,selected:['photo','mp']});
    assert.equal(result.totalCents,null);assert.equal(result.pending,true);
    assert.equal(result.subtotalCents,sqft*6);
  }
});
test('Luxury starts at $425 and adds $0.1176 per square foot above 5000', () => {
  for (const [sqft,cents] of [[5000,42500],[5001,42512],[6000,54260],[7500,71900],[9999,101288],[10000,101300]]) {
    const q=quote({category:'luxury',sqft});
    assert.equal(q.pending,false);assert.equal(fee(q,'photo'),cents);assert.equal(q.totalCents,cents);
  }
  const withScan=quote({category:'luxury',sqft:7500,selected:['photo','mp']});
  assert.equal(withScan.totalCents,116900);assert.equal(fee(withScan,'mp'),45000);
  for(const [packageName,cents] of [['silver',22000],['gold',49900],['platinum',99500]]) {
    const q=quote({category:'luxury',sqft:7500,package:packageName});
    assert.equal(q.totalCents,cents);assert.equal(fee(q,'photo'),0);
  }
});
test('on-site timing adds capture only and does not duplicate package services', () => {
  assert.equal(quote({category:'small',sqft:1000}).knownMinutes,35);
  assert.equal(quote({category:'luxury',sqft:10000,selected:['mp']}).knownMinutes,440);
  const mixed=quote({category:'small',sqft:1000,selected:['photo','mp','website','twilight']});
  assert.equal(mixed.knownMinutes,45);assert.deepEqual(mixed.additionalCapture,[]);
  assert.equal(quote({selected:['website','twilight']}).hasOnSite,true);
  const bundled=quote({category:'luxury',sqft:10000,package:'platinum',selected:['photo','mp']});
  assert.equal(bundled.knownMinutes,490);assert.deepEqual(bundled.additionalCapture,['floor']);
  assert.equal(bundled.videoMinutes,30);assert.equal(bundled.droneMinutes,20);
  assert.equal(quote({package:'gold',selected:['video'],videoSeconds:180}).videoMinutes,15);
});
test('invalid selections cannot yield a usable total', () => {
  for(const input of [{sqft:1999},{sqft:4001},{sqft:2680.5},{sqft:NaN},{selected:['unknown']},{selected:['video'],videoSeconds:59},{selected:['video'],videoSeconds:181},{selected:['video'],videoSeconds:61.5},{selected:['video'],videoSeconds:NaN},{selected:['twilight'],images:0},{selected:['twilight'],images:1.5},{selected:['twilight'],images:101}]) assert.throws(()=>quote(input));
});
test('quote email contains exact subject, address, prices and requested appointment', () => {
  const text=Q.emailBody(quote({package:'gold',selected:['mp']}),{first:'Test',last:'Agent',company:'Example Realty',email:'test@example.com',phone:'5555550100',street:'123 Example Street',city:'Two Rivers',state:'WI',zip:'54241',optOut:'Yes'},{date:'2027-01-20',time:'10:00'});
  assert.ok(text.startsWith('Residential SiteSee Real Estate Quote\n'));
  assert.ok(text.includes('Estimated Time On Site:'));assert.ok(text.includes('Video: 15 min'));
  for(const value of ['123 Example Street, Two Rivers, WI 54241','$659.80','Gold','Included','2027-01-20','10:00 Central Time','not confirmed','Exclude from mailing lists: Yes'])assert.ok(text.includes(value));
});

test('video time follows finished seconds and drone time is counted once', () => {
  for (const [videoSeconds, minutes] of [[60,15],[61,15.25],[90,22.5],[180,45]]) {
    const q=quote({selected:['video'],videoSeconds});assert.equal(q.videoMinutes,minutes);assert.equal(q.knownMinutes,Math.ceil((q.photographyMinutes+minutes)/5)*5);assert.deepEqual(q.additionalCapture,[]);
  }
  const q=quote({category:'small',sqft:1000,selected:['photo','mp','video','drone','drone'],videoSeconds:60});
  assert.equal(q.knownMinutes,80);assert.equal(q.droneMinutes,20);assert.equal(q.photographyMinutes,35);assert.equal(q.matterportMinutes,9);assert.deepEqual(q.additionalCapture,[]);
});

test('photography is mandatory, including empty and add-on-only selections', () => {
  for (const selected of [[], ['website'], ['photo','photo']]) {
    const q=quote({selected});
    assert.equal(q.lines.filter(line=>line.key==='photo').length,1);
    assert.equal(fee(q,'photo'),25690);
    assert.equal(q.photographyMinutes,93.8);
    assert.equal(q.totalCents,25690+(selected.includes('website')?6500:0));
  }
});
