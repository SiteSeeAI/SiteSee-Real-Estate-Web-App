(() => {
  'use strict';

  let scriptPromise;
  let configPromise;

  const loadScript = () => {
    if (window.turnstile) return Promise.resolve(window.turnstile);
    if (scriptPromise) return scriptPromise;
    scriptPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
      script.async = true;
      script.defer = true;
      script.addEventListener('load', () => window.turnstile ? resolve(window.turnstile) : reject(new Error('Cloudflare Turnstile did not initialize.')));
      script.addEventListener('error', () => reject(new Error('Cloudflare Turnstile could not be loaded.')));
      document.head.append(script);
    });
    return scriptPromise;
  };

  const loadConfig = () => {
    if (!configPromise) {
      configPromise = fetch('turnstile-config.php', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      }).then(async response => {
        const data = await response.json().catch(() => null);
        if (!response.ok || !data || !data.ok || !data.sitekey) {
          throw new Error((data && data.message) || 'Secure form protection is unavailable.');
        }
        return data;
      });
    }
    return configPromise;
  };

  const protect = async form => {
    const container = form.querySelector('[data-turnstile]');
    const status = form.querySelector('.form-security-status');
    const submit = form.querySelector('[type="submit"]');
    if (!container || !status || !submit) throw new Error('The secure form controls are incomplete.');

    submit.disabled = true;
    status.textContent = 'Loading secure form protection…';
    const [api, config] = await Promise.all([loadScript(), loadConfig()]);
    let verified = false;
    const widgetId = api.render(container, {
      sitekey: config.sitekey,
      action: container.dataset.action,
      theme: 'light',
      size: 'flexible',
      callback: () => {
        verified = true;
        status.textContent = 'Secure form check complete.';
        submit.disabled = false;
      },
      'expired-callback': () => {
        verified = false;
        status.textContent = 'The secure form check expired. Please complete it again.';
        submit.disabled = true;
      },
      'error-callback': () => {
        verified = false;
        status.textContent = 'The secure form check could not be completed. Please try again.';
        submit.disabled = true;
      }
    });

    return {
      isVerified: () => verified,
      reset: () => {
        verified = false;
        submit.disabled = true;
        status.textContent = 'Complete the secure form check to continue.';
        api.reset(widgetId);
      }
    };
  };

  window.SiteSeeTurnstile = { protect };
})();
