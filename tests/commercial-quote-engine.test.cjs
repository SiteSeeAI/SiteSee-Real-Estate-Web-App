const test=require('node:test'),assert=require('node:assert/strict'),Q=require('../public/assets/js/commercial-quote-engine.js');
const quote=overrides=>Q.calculate({category:'small',sqft:5000,selected:['photo'],aerialImages:10,hours:5,videos:1,videoSeconds:60,plans:1,licenseType:'term',licenseMonths:6,extendedHosting:false,...overrides});
test('commercial photography keeps the supplied client tiers and industrial starting price',()=>{
 assert.equal(quote().totalCents,75000);assert.equal(quote({category:'mid',sqft:10000}).totalCents,120000);
 const large=quote({category:'large',sqft:50000});assert.equal(large.totalCents,null);assert.equal(large.subtotalCents,250000);assert.equal(large.lines[0].from,true);
});
test('Matterport minimum, exact area rate and six-month hosting',()=>{
 for(const sqft of [1000,1990])assert.equal(quote({sqft,selected:['mp']}).totalCents,19900);
 assert.equal(quote({sqft:1991,selected:['mp']}).totalCents,19910);
 const mp=quote({category:'mid',sqft:20000,selected:['mp']});assert.equal(mp.totalCents,200000);assert.equal(mp.matterportIncludedMonths,6);assert.equal(mp.knownMinutes,180);
 const long=quote({selected:['mp'],extendedHosting:true});assert.equal(long.totalCents,null);assert.equal(long.subtotalCents,50000);
});
test('client unit fees and website override',()=>{
 assert.equal(quote({selected:['drone'],aerialImages:10}).totalCents,42000);
 assert.equal(quote({selected:['drone'],aerialImages:3}).totalCents,12600);
 assert.equal(quote({selected:['website']}).totalCents,18500);
 assert.equal(quote({selected:['floor'],plans:2}).totalCents,30000);
 assert.equal(quote({selected:['video'],videos:2,videoSeconds:179}).totalCents,300000);
 const labor=quote({selected:['hourly'],hours:2.5});assert.equal(labor.subtotalCents,47500);assert.equal(labor.knownMinutes,150);assert.equal(labor.pending,true);
});
test('six-month license is included; 7–18 month premium is not invented',()=>{
 assert.equal(quote().licenseCents,0);
 for(const licenseMonths of [7,12,18]){const q=quote({licenseMonths});assert.equal(q.licenseCents,null);assert.equal(q.totalCents,null);assert.equal(q.subtotalCents,75000);}
});
test('unlimited photography surcharge excludes Matterport and is charged once',()=>{
 const q=quote({selected:['photo','drone','mp','photo'],licenseType:'unlimited'});
 assert.equal(q.licenseCents,58500);assert.equal(q.totalCents,225500);assert.equal(q.matterportIncludedMonths,6);
 assert.equal(quote({selected:['photo','mp'],licenseType:'unlimited'}).totalCents,162500);
 assert.equal(quote({selected:['mp'],licenseType:'unlimited'}).totalCents,50000);
 assert.equal(quote({selected:['photo','video'],licenseType:'unlimited'}).totalCents,null);
 assert.equal(quote({selected:['video'],licenseType:'unlimited'}).totalCents,null);
});
test('invalid input, duplicates and incompatible photography labor cannot yield a quote',()=>{
 for(const input of [{selected:[]},{selected:['photo','hourly']},{selected:['nope']},{sqft:10000},{sqft:2.5},{selected:['drone'],aerialImages:0},{selected:['drone'],aerialImages:1.5},{selected:['hourly'],hours:6},{selected:['video'],videoSeconds:181},{selected:['video'],videos:0},{selected:['floor'],plans:0},{licenseMonths:5},{licenseMonths:19},{licenseMonths:6.5},{licenseType:'forever'}])assert.throws(()=>quote(input));
 assert.equal(quote({selected:['mp','mp']}).totalCents,50000);
});
test('commercial subject, licensing and separate hosting survive the email quote',()=>{
 const q=quote({selected:['photo','mp'],licenseType:'unlimited'});
 const body=Q.emailBody(q,{first:'Test',last:'Agent',company:'Example',email:'agent@example.com',phone:'5555550100',street:'123 Example Street',city:'Two Rivers',state:'WI',zip:'54241',optOut:'Yes'},{date:'2099-01-01',time:'10:00'});
 for(const text of ['Commercial SiteSee Real Estate Quote','Unlimited Photography License','Photography Only','$1,625.00','six months included','10:00 Central Time','requested, not confirmed'])assert.ok(body.includes(text));
 assert.ok(!body.includes('Residential SiteSee'));assert.ok(!body.toLowerCase().includes('outsource'));assert.ok(!body.includes('Additional capture'));
});
