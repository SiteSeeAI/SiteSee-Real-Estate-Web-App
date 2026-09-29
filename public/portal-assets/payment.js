(() => {
  'use strict';
  const form=document.getElementById('portal-payment');if(!form)return;
  let busy=false,checkout=null;
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy||!form.reportValidity())return;busy=true;
    const button=form.querySelector('button'),error=document.getElementById('payment-error');button.disabled=true;error.hidden=true;
    try{
      const response=await fetch('/account.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},body:new FormData(form)});
      if(!(response.headers.get('content-type')||'').includes('application/json'))throw Error('Please refresh this payment page and sign in again if needed.');
      const result=await response.json();if(!response.ok)throw Error(result.error||'Payment is temporarily unavailable. Please try again.');
      if(result.mode==='hosted'){
        const url=new URL(result.url);if(url.protocol!=='https:'||url.hostname!=='checkout.stripe.com'||url.username||url.password)throw Error('The payment link could not be verified.');
        location.assign(url.href);return;
      }
      if(result.mode==='paid'||result.mode==='pending'){location.assign('/account.php?view=payment&result=return&reference='+encodeURIComponent(form.elements.reference.value));return;}
      if(result.mode==='expired')throw Error('Your previous payment session expired. Select Continue again to open a fresh payment form.');
      if(result.mode!=='embedded'||typeof result.clientSecret!=='string'||typeof window.Stripe!=='function')throw Error('The secure payment form could not load. Refresh and try again.');
      const stripe=window.Stripe(form.dataset.key);
      if(checkout)checkout.destroy();
      checkout=await stripe.createEmbeddedCheckoutPage({fetchClientSecret:async()=>result.clientSecret});checkout.mount('#stripe-checkout');form.hidden=true;
      document.getElementById('checkout-status').textContent='Complete your deposit securely below.';
    }catch(e){if(checkout){checkout.destroy();checkout=null;}form.hidden=false;error.textContent=e.message;error.hidden=false;error.tabIndex=-1;error.focus();}
    finally{busy=false;button.disabled=false;}
  });
})();
