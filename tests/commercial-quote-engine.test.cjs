const test=require('node:test'),assert=require('node:assert/strict'),Q=require('../public/assets/js/commercial-quote-engine.js');
const quote=overrides=>Q.calculate({category:'small',sqft:5000,selected:[],aerialImages:1,videos:1,videoSeconds:60,plans:1,views360:1,licenseType:'term',licenseMonths:6,delivery:'files',platformMonths:6,extendedHosting:false,...overrides});
const fee=(q,key)=>q.lines.find(line=>line.key===key)?.cents;
test('category prices include photography and increase from 10,001 square feet',()=>{
 for(const sqft of [1,1000,4000])assert.equal(quote({sqft}).photographyCents,35000);
 assert.equal(quote().photographyCents,37500);
 assert.equal(quote({sqft:10000}).photographyCents,75000);
 for(const [sqft,cents] of [[10000,75000],[10001,75005],[20000,125000],[31250,181250],[50000,275000]])assert.equal(quote({category:'mid',sqft}).photographyCents,cents);
 // Category identity matters at 50,000; factory uses its own $2,500 starting fee.
 for(const [sqft,cents] of [[50000,250000],[50001,250005],[100000,500000]])assert.equal(quote({category:'large',sqft}).photographyCents,cents);
 assert.equal(quote({selected:['photo','photo']}).lines.filter(x=>x.key==='photo').length,1);
 assert.equal(Q.services.hourly,undefined);
});
test('licensing includes video and charges only months beyond the included six',()=>{
 for(const licenseMonths of [6,12,18]){
  const q=quote({selected:['drone','video'],aerialImages:4,licenseMonths});assert.equal(q.licenseBaseCents,104300); // $375 + $168 + $500
  assert.equal(q.licenseCents,licenseMonths===6?0:Math.round(104300*(licenseMonths-6)/40));assert.equal(q.pending,false);
 }
 const example=183400;assert.equal(Q.licenseFeeCents(example,'term',6),0);assert.equal(Q.licenseFeeCents(example,'term',12),27510);assert.equal(Q.licenseFeeCents(example,'term',18),55020);assert.equal(Q.licenseFeeCents(example,'unlimited',6),91700);
 const q=quote({selected:['drone','video','mp','floor','platform','views360'],licenseType:'unlimited'});assert.equal(q.licenseBaseCents,91700);assert.equal(q.licenseCents,45850);assert.equal(q.pending,false);
});
test('independent Matterport coverage controls fee and time without changing photography',()=>{
 const q=quote({category:'mid',sqft:40000,selected:['mp'],matterportSqft:20000});assert.equal(q.photographyCents,225000);assert.equal(fee(q,'mp'),200000);assert.equal(q.matterportSqft,20000);assert.equal(q.matterportMinutes,180);assert.equal(q.matterportIncludedMonths,6);
 const reduced=quote({category:'mid',sqft:40000,selected:['mp'],matterportSqft:5000});assert.equal(reduced.photographyCents,q.photographyCents);assert.equal(fee(reduced,'mp'),50000);assert.equal(reduced.matterportMinutes,45);
 for(const matterportSqft of [1,1000,1990])assert.equal(fee(quote({selected:['mp'],matterportSqft}),'mp'),19900);
 assert.equal(fee(quote({selected:['mp'],matterportSqft:1991}),'mp'),19910);
 assert.equal(quote({selected:['mp'],extendedHosting:true}).pending,true);
});
test('360 photos require both Matterport and platform and are excluded from license base',()=>{
 assert.throws(()=>quote({selected:['views360']}),/subscription/);
 assert.throws(()=>quote({selected:['platform','views360']}),/Matterport/);
 const q=quote({selected:['mp','platform','views360'],views360:4,licenseType:'unlimited'});assert.equal(fee(q,'views360'),10000);assert.equal(fee(q,'platform'),29400);assert.equal(q.licenseBaseCents,37500);assert.equal(q.matterportSqft,5000);assert.equal(q.totalCents,145650);
});
test('platform bills $49 per month without setup and prevents duplicate website selection',()=>{
 const base=quote().totalCents;
 for(const platformMonths of [6,12,18]){const q=quote({selected:['platform'],platformMonths});assert.equal(q.totalCents,base+platformMonths*4900);assert.equal(q.licenseMonths,6);assert.equal(q.lines.some(x=>x.key==='platform-setup'),false)}
 assert.equal(quote({delivery:'website'}).totalCents,base+17500);
 assert.throws(()=>quote({selected:['platform'],delivery:'website'}),/already presents/);
});
test('aerial and video unit prices preserve exact seconds and minimum',()=>{
 assert.equal(fee(quote({selected:['drone'],aerialImages:2}),'drone'),8400);
 for(const [videoSeconds,cents] of [[60,50000],[61,50831],[120,99996],[179,149161],[180,149994]])assert.equal(fee(quote({selected:['video'],videoSeconds}),'video'),cents);
 assert.equal(fee(quote({selected:['video'],videos:2,videoSeconds:120}),'video'),199992);
 assert.equal(fee(quote({selected:['floor'],plans:2}),'floor'),30000);
});
test('invalid selected-service inputs cannot yield a quote',()=>{
 for(const input of [{selected:['hourly']},{selected:['nope']},{sqft:10001},{category:'mid',sqft:50001},{category:'large',sqft:1000001},{sqft:2.5},{selected:['mp'],matterportSqft:0},{selected:['mp'],matterportSqft:5001},{selected:['mp'],matterportSqft:''},{selected:['mp'],matterportSqft:2.5},{selected:['mp','platform','views360'],views360:0},{selected:['mp','platform','views360'],views360:1.5},{selected:['drone'],aerialImages:0},{selected:['video'],videoSeconds:59},{selected:['video'],videoSeconds:181},{selected:['video'],videos:0},{selected:['floor'],plans:0},{licenseMonths:5},{licenseMonths:19},{licenseMonths:6.5},{licenseType:'forever'},{delivery:'platform'},{selected:['platform'],platformMonths:5},{selected:['platform'],platformMonths:19}])assert.throws(()=>quote(input));
});
test('email identifies photography area, scanned area, platform term and view count',()=>{
 const q=quote({category:'mid',sqft:20000,matterportSqft:3000,selected:['mp','platform','views360'],views360:2,licenseType:'unlimited'});
 const body=Q.emailBody(q,{first:'Test',last:'Agent',company:'Example',email:'agent@example.com',phone:'5555550100',street:'123 Example Street',city:'Two Rivers',state:'WI',zip:'54241',optOut:'Yes'},{date:'2099-01-01',time:'10:00'});
 for(const text of ['Commercial SiteSee Real Estate Quote','Photography area: 20,000 sq ft','3,000 sq ft scanned','2 photos','Unlimited Media License','$49 per month','six months included','10:00 Central Time','requested, not confirmed'])assert.ok(body.includes(text),text);
 assert.ok(body.includes('Estimated Time On Site:'));assert.ok(body.includes('Photography:'));assert.ok(!body.includes('proposed for review'));assert.ok(!body.includes('Additional capture'));
});

test('both markets use the same photography, scan, video and drone timing',()=>{
 const R=require('../public/assets/js/quote-engine.js');
 for(const sqft of [1000,2680,10000]){
  const r=R.calculate({category:sqft<2000?'small':sqft<=4000?'average':'luxury',package:'custom',sqft,selected:['photo','mp','video','drone'],videoSeconds:90,images:1});
  const c=quote({sqft,selected:['mp','video','drone'],videoSeconds:90});
  for(const key of ['photographyMinutes','matterportMinutes','videoMinutes','droneMinutes','knownMinutes'])assert.equal(c[key],r[key],key);
  assert.deepEqual(c.additionalCapture,[]);
 }
 const c=quote({category:'large',sqft:60000,matterportSqft:2000,selected:['mp','video','drone'],videoSeconds:120,videos:2,aerialImages:100});
 assert.equal(c.photographyMinutes,2100);assert.equal(c.matterportMinutes,18);assert.equal(c.videoMinutes,60);assert.equal(c.droneMinutes,20);assert.equal(c.knownMinutes,2200);
 assert.equal(quote({selected:['video'],videoSeconds:61}).videoMinutes,15.25);
});
