const test=require('node:test'),assert=require('node:assert/strict'),Q=require('../public/assets/js/commercial-quote-engine.js');
const quote=overrides=>Q.calculate({category:'small',sqft:5000,selected:[],aerialImages:1,videos:1,videoSeconds:60,plans:1,views360:1,licenseType:'term',licenseMonths:6,delivery:'files',platformMonths:6,hostingMonths:6,hostingPrepaid:false,...overrides});
const fee=(q,key)=>q.lines.find(line=>line.key===key)?.cents;
test('commercial category fees are fixed and only extra photographs increase the shoot fee',()=>{
 for(const [category,cents] of [['small',75000],['mid',120000],['large',250000]]){
  const cat=Q.categories[category];
  for(const sqft of [cat.min,cat.min+1,Math.floor((cat.min+cat.max)/2),cat.max]){
   const q=quote({category,sqft});
   assert.equal(q.basePhotographyCents,cents);
   assert.equal(q.photographyCents,cents);
   assert.equal(q.totalCents,cents);
   const extra=quote({category,sqft,photoCount:cat.photosIncluded+1});
   assert.equal(extra.photographyCents,cents+cat.extraPhotoCents);
  }
 }
 assert.equal(quote({selected:['photo','photo']}).lines.filter(x=>x.key==='photo').length,1);
 assert.equal(Q.services.hourly,undefined);
});
test('licensing includes video and charges only months beyond the included six',()=>{
 for(const licenseMonths of [6,12,18]){
  const q=quote({selected:['drone','video'],aerialImages:4,licenseMonths});assert.equal(q.licenseBaseCents,141800); // $750 + $168 + $500
  assert.equal(q.licenseCents,licenseMonths===6?0:Math.round(141800*(licenseMonths-6)/40));assert.equal(q.pending,false);
 }
 const example=183400;assert.equal(Q.licenseFeeCents(example,'term',6),0);assert.equal(Q.licenseFeeCents(example,'term',12),27510);assert.equal(Q.licenseFeeCents(example,'term',18),55020);assert.equal(Q.licenseFeeCents(example,'unlimited',6),91700);
 const q=quote({selected:['drone','video','mp','floor','platform','views360'],licenseType:'unlimited'});assert.equal(q.licenseBaseCents,129200);assert.equal(q.licenseCents,64600);assert.equal(q.pending,false);
});
test('independent Matterport coverage controls fee and time without changing photography',()=>{
 const q=quote({category:'mid',sqft:40000,selected:['mp'],matterportSqft:20000});assert.equal(q.photographyCents,120000);assert.equal(fee(q,'mp'),200000);assert.equal(q.matterportSqft,20000);assert.equal(q.matterportMinutes,180);assert.equal(q.matterportIncludedMonths,6);
 const reduced=quote({category:'mid',sqft:40000,selected:['mp'],matterportSqft:5000});assert.equal(reduced.photographyCents,q.photographyCents);assert.equal(fee(reduced,'mp'),50000);assert.equal(reduced.matterportMinutes,45);
 for(const matterportSqft of [1,1000,1990])assert.equal(fee(quote({selected:['mp'],matterportSqft}),'mp'),19900);
 assert.equal(fee(quote({selected:['mp'],matterportSqft:1991}),'mp'),19910);
 assert.equal(quote({selected:['mp'],hostingMonths:18}).pending,false);
});
test('360 photos require both Matterport and platform and are excluded from license base',()=>{
 assert.throws(()=>quote({selected:['views360']}),/subscription/);
 assert.throws(()=>quote({selected:['platform','views360']}),/Matterport/);
 const q=quote({selected:['mp','platform','views360'],views360:4,licenseType:'unlimited'});assert.equal(fee(q,'views360'),10000);assert.equal(fee(q,'platform'),29400);assert.equal(q.licenseBaseCents,75000);assert.equal(q.matterportSqft,5000);assert.equal(q.totalCents,201900);
});
test('platform and independent website may be combined without charging the website twice',()=>{
 const base=quote().totalCents;
 for(const platformMonths of [6,12,18]){const q=quote({selected:['platform'],platformMonths});assert.equal(q.totalCents,base+platformMonths*4900);assert.equal(q.licenseMonths,6);assert.equal(q.lines.some(x=>x.key==='platform-setup'),false)}
 assert.equal(quote({delivery:'website'}).totalCents,base+17500);
 for(const selected of [['platform'],['platform','website'],['platform','website','website']]){const q=quote({selected,delivery:'website'});assert.equal(q.totalCents,base+29400+17500);assert.equal(q.lines.filter(l=>l.key==='website').length,1);assert.equal(q.websiteIncluded,true)}
 assert.equal(quote({selected:['website']}).totalCents,base+17500);
});
test('aerial and video unit prices preserve exact seconds and minimum',()=>{
 assert.equal(fee(quote({selected:['drone'],aerialImages:2}),'drone'),8400);
 for(const [videoSeconds,cents] of [[60,50000],[61,50831],[120,99996],[179,149161],[180,149994]])assert.equal(fee(quote({selected:['video'],videoSeconds}),'video'),cents);
 assert.equal(fee(quote({selected:['video'],videos:2,videoSeconds:120}),'video'),199992);
 assert.equal(fee(quote({selected:['floor'],plans:2}),'floor'),30000);
});
test('invalid selected-service inputs cannot yield a quote',()=>{
 for(const input of [{selected:['hourly']},{selected:['nope']},{sqft:10001},{category:'mid',sqft:50001},{category:'large',sqft:250001},{sqft:2.5},{selected:['mp'],matterportSqft:0},{selected:['mp'],matterportSqft:5001},{selected:['mp'],matterportSqft:''},{selected:['mp'],matterportSqft:2.5},{selected:['mp','platform','views360'],views360:0},{selected:['mp','platform','views360'],views360:1.5},{selected:['drone'],aerialImages:0},{selected:['video'],videoSeconds:59},{selected:['video'],videoSeconds:181},{selected:['video'],videos:0},{selected:['floor'],plans:0},{licenseMonths:5},{licenseMonths:19},{licenseMonths:6.5},{licenseType:'forever'},{delivery:'platform'},{selected:['platform'],platformMonths:5},{selected:['platform'],platformMonths:19}])assert.throws(()=>quote(input));
});
test('email identifies photography area, scanned area, platform term and view count',()=>{
 const q=quote({category:'mid',sqft:20000,matterportSqft:3000,selected:['mp','platform','views360'],views360:2,licenseType:'unlimited'});
 const body=Q.emailBody(q,{first:'Test',last:'Agent',company:'Example',email:'agent@example.com',phone:'5555550100',street:'123 Example Street',city:'Two Rivers',state:'WI',zip:'54241',optOut:'Yes'},{date:'2099-01-01',time:'10:00'});
 for(const text of ['Commercial SiteSee Real Estate Quote','Photography area: 20,000 sq ft','3,000 sq ft scanned','2 photos','Unlimited Media License','$49 per month','six months included','10:00 Central Time','requested, not confirmed'])assert.ok(body.includes(text),text);
 assert.ok(body.includes('Estimated Time On Site:'));assert.ok(body.includes('Photography:'));assert.ok(!body.includes('proposed for review'));assert.ok(!body.includes('Additional capture'));
});

test('commercial photography uses 1.5 minutes per thousand while residential retains 35',()=>{
 const R=require('../public/assets/js/quote-engine.js');
 for(const sqft of [1000,2680,10000]){
  const r=R.calculate({category:sqft<2000?'small':sqft<=4000?'average':'luxury',package:'custom',sqft,selected:['photo','mp','video','drone'],videoSeconds:90,images:1});
  const c=quote({sqft,selected:['mp','video','drone'],videoSeconds:90});
  assert.equal(c.photographyMinutes,sqft*1.5/1000);assert.equal(r.photographyMinutes,sqft*35/1000);
  for(const key of ['matterportMinutes','videoMinutes','droneMinutes'])assert.equal(c[key],r[key],key);
  assert.equal(c.knownMinutes,Math.ceil((c.photographyMinutes+c.matterportMinutes+c.videoMinutes+c.droneMinutes)/5)*5);
  assert.deepEqual(c.additionalCapture,[]);
 }
 for(const [category,sqft,minutes] of [['small',10000,15],['mid',50000,75],['large',100000,150]]){const q=quote({category,sqft});assert.equal(q.photographyMinutes,minutes);assert.equal(q.knownMinutes,minutes)}
 const c=quote({category:'large',sqft:60000,matterportSqft:2000,selected:['mp','video','drone'],videoSeconds:120,videos:2,aerialImages:100});
 assert.equal(c.photographyMinutes,90);assert.equal(c.matterportMinutes,18);assert.equal(c.videoMinutes,60);assert.equal(c.droneMinutes,20);assert.equal(c.knownMinutes,190);
 assert.equal(quote({selected:['video'],videoSeconds:61}).videoMinutes,15.25);
});

test('hosting includes six months and prices only the extension at the selected payment rate',()=>{
 const baseline=quote({selected:['mp']});
 for(const [hostingMonths,regular,advance] of [[6,0,0],[7,699,499],[12,4194,2994],[18,8388,5988]]){
  for(const [hostingPrepaid,expected] of [[false,regular],[true,advance]]){
   const q=quote({selected:['mp'],hostingMonths,hostingPrepaid});assert.equal(q.hostingCents,expected);assert.equal(fee(q,'hosting'),expected);assert.equal(q.totalCents,baseline.totalCents+expected);assert.equal(q.licenseBaseCents,baseline.licenseBaseCents);assert.equal(q.knownMinutes,baseline.knownMinutes);assert.equal(q.hostingExtraMonths,hostingMonths-6);assert.equal(q.pending,false);
  }
 }
 const prepaid=quote({selected:['mp'],hostingMonths:18,hostingPrepaid:true,licenseType:'unlimited',platformMonths:12});assert.equal(prepaid.licenseCents,37500);assert.equal(prepaid.hostingCents,5988);
 const off=quote({selected:[],hostingMonths:18,hostingPrepaid:true});assert.equal(off.hostingCents,0);assert.equal(off.hostingMonths,0);assert.equal(fee(off,'hosting'),undefined);
 for(const hostingMonths of [0,5,19,6.5,'',NaN])assert.throws(()=>quote({selected:['mp'],hostingMonths}));
 assert.throws(()=>quote({selected:['mp'],hostingPrepaid:'true'}));
 const body=Q.emailBody(prepaid,{first:'Test',last:'Agent',company:'Example',email:'agent@example.com',phone:'5555550100',street:'123 Example Street',city:'Two Rivers',state:'WI',zip:'54241',optOut:'Yes'});
 for(const part of ['18 months total','12 additional months','$4.99/month paid in advance','$59.88','does not collect payment'])assert.ok(body.includes(part),part);
});

test('commercial photography allowances and additional-image prices apply by category',()=>{
 for(const [category,sqft,min,included,unit] of [['small',10000,25,30,3000],['mid',10000,30,45,2670],['large',50000,45,55,2500]]){
  const base=quote({category,sqft});assert.equal(base.photoCount,included);assert.equal(base.extraPhotos,0);
  for(const photoCount of [min,included])assert.equal(quote({category,sqft,photoCount}).totalCents,base.totalCents);
  for(const photoCount of [included+1,100]){const q=quote({category,sqft,photoCount});const extra=(photoCount-included)*unit;assert.equal(q.extraPhotoCents,extra);assert.equal(q.photographyCents,base.photographyCents+extra);assert.equal(q.totalCents,base.totalCents+extra);assert.equal(q.licenseBaseCents,base.licenseBaseCents+extra);assert.equal(q.knownMinutes,base.knownMinutes);assert.equal(q.lines.filter(l=>l.key==='extraPhotos').length,1);}
  for(const photoCount of [min-1,101,50.5,''])assert.throws(()=>quote({category,sqft,photoCount}));
 }
 const q=quote({category:'mid',sqft:10000,photoCount:46,licenseType:'unlimited',selected:['website','platform']});assert.equal(q.extraPhotoCents,2670);assert.equal(q.licenseCents,61335);assert.equal(q.photographyCents,122670);
 assert.equal(quote({category:'large',sqft:250000}).basePhotographyCents,250000);assert.equal(Q.categories.large.max,250000);
});

test('commercial quotes work without a property-size field and give category-based photography time ranges',()=>{
 for(const [category,cents,minimum,maximum] of [['small',75000,5,15],['mid',120000,15,75],['large',250000,75,375]]){
  const q=quote({category,sqft:undefined});assert.equal(q.sqft,null);assert.equal(q.totalCents,cents);assert.equal(q.knownMinutes,minimum);assert.equal(q.knownMinutesMax,maximum);assert.equal(q.pending,false);
  const extra=quote({category,sqft:undefined,photoCount:100});assert.equal(extra.totalCents,cents+(100-Q.categories[category].photosIncluded)*Q.categories[category].extraPhotoCents);
 }
 const q=quote({category:'mid',sqft:undefined,selected:['mp','video','drone'],matterportSqft:20000});
 assert.equal(fee(q,'mp'),200000);assert.equal(q.photographyMinutes,15);assert.equal(q.photographyMinutesMax,75);assert.equal(q.matterportMinutes,180);assert.equal(q.videoMinutes,15);assert.equal(q.droneMinutes,20);assert.equal(q.knownMinutes,230);assert.equal(q.knownMinutesMax,290);
 const body=Q.emailBody(q,{first:'Test',last:'Agent',company:'Example',email:'agent@example.com',phone:'5555550100',street:'123 Example Street',city:'Two Rivers',state:'WI',zip:'54241',optOut:'Yes'});
 assert.ok(!body.includes('Photography area:'));assert.ok(body.includes('20,000 sq ft scanned'));assert.ok(body.includes('Photography: 15 min–1 hr 15 min'));assert.ok(body.includes('Estimated Time On Site: 3 hrs 50 min–4 hrs 50 min'));
});
test('independent scan area is bounded by category when property size is not entered',()=>{
 const q=quote({category:'small',sqft:undefined,selected:['mp'],matterportSqft:10000});assert.equal(fee(q,'mp'),100000);
 assert.throws(()=>quote({category:'small',sqft:undefined,selected:['mp'],matterportSqft:10001}));
 assert.throws(()=>quote({category:'large',sqft:undefined,selected:['mp'],matterportSqft:250001}));
 const exact=quote({category:'mid',sqft:20000});assert.equal(exact.photographyMinutesMax,exact.photographyMinutes);assert.equal(exact.knownMinutesMax,exact.knownMinutes);assert.equal(Q.durationRange(exact.knownMinutes,exact.knownMinutesMax),'30 min');
});
