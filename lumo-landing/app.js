(function () {
  'use strict';

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------------- Header state ---------------- */
  var header = document.getElementById('siteHeader');

  function onScroll() {
    if (!header) return;
    header.classList.toggle('is-scrolled', window.scrollY > 8);
  }

  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------------- Mobile menu ---------------- */
  var navToggle = document.getElementById('navToggle');
  var mobileMenu = document.getElementById('mobileMenu');

  if (navToggle && mobileMenu) {
    navToggle.addEventListener('click', function () {
      var open = navToggle.getAttribute('aria-expanded') === 'true';
      navToggle.setAttribute('aria-expanded', String(!open));
      navToggle.setAttribute('aria-label', open ? '打开菜单' : '关闭菜单');
      mobileMenu.classList.toggle('is-open', !open);
    });

    mobileMenu.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        navToggle.setAttribute('aria-expanded', 'false');
        navToggle.setAttribute('aria-label', '打开菜单');
        mobileMenu.classList.remove('is-open');
      });
    });
  }

  /* ---------------- Reveal on scroll ---------------- */
  var reveals = document.querySelectorAll('.reveal');

  if (!reduceMotion && 'IntersectionObserver' in window) {
    var revealObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          revealObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });

    reveals.forEach(function (el) { revealObserver.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add('is-visible'); });
  }

  /* ---------------- Animated counters ---------------- */
  var metrics = document.querySelector('.metrics');

  function animateCounter(el) {
    var target = parseFloat(el.getAttribute('data-count'));
    var decimals = parseInt(el.getAttribute('data-decimals') || '0', 10);
    var suffix = el.getAttribute('data-suffix') || '';
    var duration = 1400;
    var start = null;

    function format(value) {
      var text;
      if (decimals > 0) {
        text = value.toFixed(decimals);
      } else if (value >= 1000) {
        text = Math.round(value).toLocaleString('en-US');
      } else {
        text = String(Math.round(value));
      }
      return text + suffix;
    }

    function step(timestamp) {
      if (start === null) start = timestamp;
      var progress = Math.min((timestamp - start) / duration, 1);
      var eased = 1 - Math.pow(1 - progress, 3);
      el.textContent = format(target * eased);
      if (progress < 1) {
        window.requestAnimationFrame(step);
      }
    }

    if (reduceMotion) {
      el.textContent = format(target);
      return;
    }
    window.requestAnimationFrame(step);
  }

  if (metrics) {
    var counterEls = metrics.querySelectorAll('[data-count]');

    if ('IntersectionObserver' in window) {
      var counterObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          counterEls.forEach(animateCounter);
          counterObserver.disconnect();
        });
      }, { threshold: 0.4 });
      counterObserver.observe(metrics);
    } else {
      counterEls.forEach(animateCounter);
    }
  }

  /* ---------------- Pricing billing toggle ---------------- */
  var billingButtons = document.querySelectorAll('.billing-toggle__btn');
  var priceAmounts = document.querySelectorAll('.price-card__price .amount[data-monthly]');
  var billingNotes = document.querySelectorAll('.price-card__billing');

  billingButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      var billing = button.getAttribute('data-billing');

      billingButtons.forEach(function (btn) {
        var active = btn === button;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', String(active));
      });

      priceAmounts.forEach(function (amount) {
        amount.textContent = amount.getAttribute(
          billing === 'yearly' ? 'data-yearly' : 'data-monthly'
        );
      });

      billingNotes.forEach(function (note) {
        note.textContent = note.getAttribute(
          billing === 'yearly' ? 'data-yearly-text' : 'data-monthly-text'
        );
      });
    });
  });

  /* ---------------- FAQ: accordion behaviour ---------------- */
  var faqItems = document.querySelectorAll('.faq-item');

  faqItems.forEach(function (item) {
    item.addEventListener('toggle', function () {
      if (!item.open) return;
      faqItems.forEach(function (other) {
        if (other !== item) other.open = false;
      });
    });
  });

  /* ---------------- Footer year ---------------- */
  var yearEl = document.getElementById('year');
  if (yearEl) yearEl.textContent = String(new Date().getFullYear());
})();
