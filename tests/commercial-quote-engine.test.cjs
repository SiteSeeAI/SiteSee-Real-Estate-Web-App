const test=require('node:test'),assert=require('node:assert/strict'),Q=require('../public/assets/js/commercial-quote-engine.js');
const quote=overrides=>Q.calculate({category:'small',sqft:5000,selected:[],aerialImages:1,videos:1,videoSeconds:60,plans:1,licenseType:'term',licenseMonths:6,delivery:'files',platformMonths:6,extendedHosting:false,...overrides});
const fee=(q,key)=>q.lines.find(line=>line.key===key)?.cents;
test('photography minimum, continuous boundaries and exact warehouse endpoints',()=>{
 for(const sqft of [1,1000,4000])assert.equal(quote({sqft}).photographyCents,35000);
 assert.equal(quote().photographyCents,37500);
 assert.equal(quote({sqft:10000}).photographyCents,75000);
 assert.equal(quote({category:'mid',sqft:10000}).photographyCents,75000);
 assert.equal(quote({category:'mid',sqft:30000}).photographyCents,92500);
 assert.equal(quote({category:'mid',sqft:32401}).photographyCents,94601);
 for(const category of ['mid','large'])assert.equal(quote({category,sqft:50000}).photographyCents,110000);
 assert.equal(quote({category:'large',sqft:100000}).photographyCents,153750);
 for(const [category,c] of Object.entries(Q.categories)){
  let last=0;for(let sqft=c.min;sqft<=c.max;sqft+=997){const price=quote({category,sqft}).photographyCents;assert.ok(price>=last);last=price;}
 }
});
test('term licensing uses combined still and aerial fees at 6, 12 and 18 months',()=>{
 for(const [licenseMonths,cents] of [[6,6255],[12,12510],[18,18765]]){
  const q=quote({selected:['drone'],licenseMonths});assert.equal(q.licenseBaseCents,41700);assert.equal(q.licenseCents,cents);assert.equal(q.totalCents,41700+cents);assert.equal(q.pending,false);
 }
 const q=quote({selected:['drone','mp','floor'],delivery:'platform',licenseMonths:12});assert.equal(q.licenseCents,12510);
});
test('unlimited applies 50% once to still and aerial only even with other services',()=>{
 const q=quote({selected:['photo','photo','drone','mp','floor'],delivery:'platform',licenseType:'unlimited'});
 assert.equal(q.licenseCents,20850);assert.equal(q.totalCents,161050);assert.equal(q.lines.filter(x=>x.key==='photo').length,1);
 const video=quote({selected:['drone','video'],licenseType:'unlimited'});assert.equal(video.licenseCents,20850);assert.equal(video.pending,true);assert.match(video.pendingReasons.join(' '),/Video licensing/);
});
test('Matterport retains minimum, square footage rate and separate six-month hosting',()=>{
 for(const sqft of [1000,1990])assert.equal(fee(quote({sqft,selected:['mp']}),'mp'),19900);
 assert.equal(fee(quote({sqft:1991,selected:['mp']}),'mp'),19910);
 const q=quote({category:'mid',sqft:20000,selected:['mp'],licenseMonths:18});assert.equal(fee(q,'mp'),200000);assert.equal(q.matterportIncludedMonths,6);assert.equal(q.knownMinutes,180);assert.equal(q.pending,false);
 assert.equal(quote({selected:['mp'],extendedHosting:true}).pending,true);
});
test('per-unit add-ons, automatic photography and removed creative labor',()=>{
 assert.equal(fee(quote({selected:['drone']}),'drone'),4200);
 assert.equal(fee(quote({selected:['drone'],aerialImages:3}),'drone'),12600);
 assert.equal(fee(quote({selected:['floor'],plans:2}),'floor'),30000);
 assert.equal(fee(quote({selected:['video'],videos:2,videoSeconds:179}),'video'),300000);
 assert.equal(quote().lines[0].key,'photo');assert.equal(Q.services.hourly,undefined);
});
test('delivery choices charge website or platform once, with independent terms',()=>{
 const base=quote().totalCents;
 assert.equal(quote({delivery:'website'}).totalCents,base+18500);
 for(const platformMonths of [6,12,18]){
  const q=quote({delivery:'platform',platformMonths});assert.equal(q.totalCents,base+18500+platformMonths*2500);assert.equal(q.lines.some(x=>x.key==='website'),false);assert.equal(q.licenseMonths,6);
 }
});
test('invalid sizes, units, licenses and platform terms cannot yield a quote',()=>{
 for(const input of [{selected:['hourly']},{selected:['website']},{selected:['nope']},{sqft:10001},{category:'mid',sqft:50001},{category:'large',sqft:1000001},{sqft:2.5},{selected:['drone'],aerialImages:0},{selected:['drone'],aerialImages:1.5},{selected:['video'],videoSeconds:181},{selected:['video'],videos:0},{selected:['floor'],plans:0},{licenseMonths:5},{licenseMonths:19},{licenseMonths:6.5},{licenseType:'forever'},{delivery:'unknown'},{delivery:'platform',platformMonths:5},{delivery:'platform',platformMonths:19},{delivery:'platform',platformMonths:6.5}])assert.throws(()=>quote(input));
});
test('email carries image license, platform proposal and separate Matterport hosting',()=>{
 const q=quote({selected:['mp'],licenseType:'unlimited',delivery:'platform'});
 const body=Q.emailBody(q,{first:'Test',last:'Agent',company:'Example',email:'agent@example.com',phone:'5555550100',street:'123 Example Street',city:'Two Rivers',state:'WI',zip:'54241',optOut:'Yes'},{date:'2099-01-01',time:'10:00'});
 for(const text of ['Commercial SiteSee Real Estate Quote','Unlimited Photography License','SiteSee Platform','$1,397.50','six months included','10:00 Central Time','requested, not confirmed','proposed for review'])assert.ok(body.includes(text),text);
 assert.ok(!body.includes('Residential SiteSee'));assert.ok(!body.includes('Additional capture'));
});
