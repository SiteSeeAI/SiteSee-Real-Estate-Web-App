/* SiteSee Real Estate · Accessible media carousels and image previews. */
(() => {
  'use strict';

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  document.querySelectorAll('[data-carousel]').forEach(carousel => {
    const slides = [...carousel.querySelectorAll('[data-slide]')];
    const previous = carousel.querySelector('[data-carousel-previous]');
    const next = carousel.querySelector('[data-carousel-next]');
    const status = carousel.querySelector('[data-carousel-status]');
    const interval = Number(carousel.dataset.autoplay || 0);
    let current = 0;
    let timer;

    if (slides.length < 2) return;

    const show = (index, announce = true) => {
      current = (index + slides.length) % slides.length;
      slides.forEach((slide, slideIndex) => {
        const active = slideIndex === current;
        slide.hidden = !active;
        slide.setAttribute('aria-hidden', String(!active));
      });
      if (status) {
        const label = slides[current].dataset.carouselLabel;
        status.textContent = label
          ? `${label} · ${current + 1} of ${slides.length}`
          : `Slide ${current + 1} of ${slides.length}`;
        status.setAttribute('aria-live', announce ? 'polite' : 'off');
      }
    };

    const stop = () => window.clearInterval(timer);
    const start = () => {
      stop();
      if (interval > 0 && !reducedMotion.matches) {
        timer = window.setInterval(() => show(current + 1, false), interval);
      }
    };
    const move = direction => {
      show(current + direction);
      start();
    };

    previous?.addEventListener('click', () => move(-1));
    next?.addEventListener('click', () => move(1));
    carousel.addEventListener('mouseenter', stop);
    carousel.addEventListener('mouseleave', start);
    carousel.addEventListener('focusin', stop);
    carousel.addEventListener('focusout', event => {
      if (!carousel.contains(event.relatedTarget)) start();
    });
    reducedMotion.addEventListener('change', start);

    show(0, false);
    start();
  });

  const dialog = document.getElementById('media-lightbox');
  const dialogImage = dialog?.querySelector('img');
  const dialogCaption = dialog?.querySelector('[data-lightbox-caption]');

  document.querySelectorAll('[data-lightbox-src]').forEach(trigger => {
    trigger.addEventListener('click', event => {
      if (!dialog || !dialogImage || typeof dialog.showModal !== 'function') return;
      event.preventDefault();
      dialogImage.src = trigger.dataset.lightboxSrc;
      dialogImage.alt = trigger.dataset.lightboxAlt || '';
      if (dialogCaption) dialogCaption.textContent = trigger.dataset.lightboxCaption || '';
      dialog.showModal();
    });
  });

  dialog?.querySelector('[data-lightbox-close]')?.addEventListener('click', () => dialog.close());
  dialog?.addEventListener('click', event => {
    if (event.target === dialog) dialog.close();
  });
})();
