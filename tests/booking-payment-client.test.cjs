const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const script = fs.readFileSync(require('node:path').join(__dirname,'../_private/payment-assets/booking-payment.js'),'utf8');

function setup(overrides={}) {
  const events={}, navigations=[], errors=[];
  const button={disabled:false,textContent:'Continue To Secure Payment'};
  const form={hidden:false,reportValidity:()=>true,addEventListener:(name,fn)=>events[name]=fn,querySelector:()=>button};
  const error={hidden:true,textContent:'',focus:()=>errors.push(error.textContent)};
  const status={textContent:''};
  const nodes={'payment-consent':form,'payment-error':error,'checkout-status':status,'stripe-checkout':{scrollIntoView(){}}};
  let created=0,mounted=0,requests=0,replaced='';
  const window={siteSeeCheckout:{enabled:true,publishableKey:'pk_test_fixture',reference:'AABBCC0011'},
    history:{replaceState:(_,__,url)=>{replaced=url;}},
    location:{href:'https://re.sitesee.ai/booking-pay.php?reference=AABBCC0011&token=fixture',assign:url=>navigations.push(url),reload:()=>navigations.push('reload')},
    Stripe:()=>({createEmbeddedCheckoutPage:async options=>{
      created++;assert.equal(await options.fetchClientSecret(),'cs_test_fixture_secret_value');
      return {mount:target=>{assert.equal(target,'#stripe-checkout');mounted++;},destroy(){}};
    }}),setTimeout:()=>{}};
  if(overrides.stripe===false)delete window.Stripe;
  const fetch=async()=>{
    requests++;
    if(overrides.wait)await overrides.wait;
    return {ok:overrides.ok??true,headers:{get:()=>overrides.contentType??'application/json'},json:async()=>overrides.result??{mode:'embedded',clientSecret:'cs_test_fixture_secret_value'}};
  };
  vm.runInNewContext(script,{window,document:{getElementById:id=>nodes[id]},fetch,FormData:class{},URL,URLSearchParams,Error,encodeURIComponent});
  return {submit:()=>events.submit({preventDefault(){}}),form,button,error,status,navigations,errors,
    counts:()=>({created,mounted,requests}),replaced:()=>replaced};
}
test('embedded form mounts once after an authorized response and hides consent',async()=>{
 const c=setup();await c.submit();assert.equal(c.form.hidden,true);assert.deepEqual(c.counts(),{created:1,mounted:1,requests:1});assert.equal(c.button.disabled,false);
 assert(!c.replaced().includes('token='));
});
test('double click does not submit a second payment request',async()=>{
 let finish;const wait=new Promise(r=>finish=r);const c=setup({wait});const first=c.submit();await c.submit();finish();await first;assert.equal(c.counts().requests,1);
});
test('already-paid and webhook-pending responses navigate to status without mounting',async()=>{
 for(const mode of ['paid','pending']){const c=setup({result:{mode}});await c.submit();assert.equal(c.navigations.length,1);assert(c.navigations[0].includes('result=success'));assert.equal(c.counts().created,0);}
});
test('legacy hosted session uses only the saved Stripe URL',async()=>{
 const c=setup({result:{mode:'hosted',url:'https://checkout.stripe.com/c/pay/existing'}});await c.submit();assert.equal(c.navigations[0],'https://checkout.stripe.com/c/pay/existing');assert.equal(c.counts().created,0);
 const bad=setup({result:{mode:'hosted',url:'https://checkout.stripe.com.evil.test/pay'}});await bad.submit();assert.equal(bad.navigations.length,0);assert.equal(bad.error.hidden,false);
});
test('expiry and server failures stay retryable without mounting',async()=>{
 for(const args of [{result:{mode:'expired'}},{ok:false,result:{error:'Please retry.'}},{contentType:'text/html'}]){
  const c=setup(args);await c.submit();assert.equal(c.form.hidden,false);assert.equal(c.button.disabled,false);assert.equal(c.error.hidden,false);assert.equal(c.counts().mounted,0);
 }
});
test('blocked Stripe JavaScript cannot create a payment session',async()=>{
 const c=setup({stripe:false});await c.submit();assert.equal(c.counts().requests,0);assert.equal(c.error.hidden,false);assert.match(c.error.textContent,/could not load/);
});
