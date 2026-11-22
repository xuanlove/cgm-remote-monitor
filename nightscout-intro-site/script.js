(function () {
  'use strict';

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var header = document.querySelector('.site-header');
  var navToggle = document.getElementById('navToggle');
  var nav = document.getElementById('nav');

  /* ---------------------------------------------------------------
     Internationalisation
     --------------------------------------------------------------- */

  var dictionaries = window.SITE_I18N || { en: {} };
  var defaultLang = 'en';
  var storageKey = 'nightscout-intro-lang';
  var langSwitch = document.getElementById('langSwitch');
  var langBtn = document.getElementById('langBtn');
  var langMenu = document.getElementById('langMenu');
  var langCurrent = document.getElementById('langCurrent');
  var langOptions = langMenu ? Array.prototype.slice.call(langMenu.querySelectorAll('[data-lang]')) : [];
  var currentLang = defaultLang;

  var shortNames = {
    en: 'EN',
    'zh-CN': '简中',
    'zh-TW': '繁中',
    es: 'ES',
    de: 'DE',
    fr: 'FR',
    'pt-BR': 'PT',
    ja: 'JA'
  };

  function readSavedLang() {
    try {
      return window.localStorage.getItem(storageKey);
    } catch (error) {
      return null;
    }
  }

  function saveLang(lang) {
    try {
      window.localStorage.setItem(storageKey, lang);
    } catch (error) {
      /* storage unavailable — language still applies for this page */
    }
  }

  function detectLang() {
    var saved = readSavedLang();
    if (saved && dictionaries[saved]) return saved;

    var locales = navigator.languages && navigator.languages.length
      ? navigator.languages
      : [navigator.language || 'en'];

    for (var i = 0; i < locales.length; i += 1) {
      var tag = String(locales[i]).toLowerCase();
      if (tag.indexOf('zh') === 0) {
        return /tw|hk|hant/.test(tag) ? 'zh-TW' : 'zh-CN';
      }
      if (tag.indexOf('pt') === 0) return 'pt-BR';
      if (tag.indexOf('es') === 0) return 'es';
      if (tag.indexOf('de') === 0) return 'de';
      if (tag.indexOf('fr') === 0) return 'fr';
      if (tag.indexOf('ja') === 0) return 'ja';
      if (tag.indexOf('en') === 0) return 'en';
    }
    return defaultLang;
  }

  function t(key) {
    var dict = dictionaries[currentLang] || {};
    if (dict[key] !== undefined) return dict[key];
    var fallback = dictionaries[defaultLang] || {};
    return fallback[key] !== undefined ? fallback[key] : key;
  }

  function updateNavToggleLabel() {
    if (!navToggle) return;
    var open = header.classList.contains('nav-open');
    navToggle.setAttribute('aria-label', t(open ? 'nav.close' : 'nav.open'));
  }

  function applyLanguage(lang) {
    if (!dictionaries[lang]) lang = defaultLang;
    currentLang = lang;
    document.documentElement.lang = lang;

    document.querySelectorAll('[data-i18n]').forEach(function (el) {
      el.textContent = t(el.getAttribute('data-i18n'));
    });

    document.querySelectorAll('[data-i18n-html]').forEach(function (el) {
      el.innerHTML = t(el.getAttribute('data-i18n-html'));
    });

    document.querySelectorAll('[data-i18n-aria]').forEach(function (el) {
      el.setAttribute('aria-label', t(el.getAttribute('data-i18n-aria')));
    });

    document.title = t('meta.title');
    var meta = document.querySelector('meta[name="description"]');
    if (meta) meta.setAttribute('content', t('meta.description'));

    if (langCurrent) langCurrent.textContent = shortNames[lang] || lang.toUpperCase();
    if (langMenu) langMenu.setAttribute('aria-label', t('lang.label'));
    if (langBtn) langBtn.setAttribute('title', t('lang.label'));

    langOptions.forEach(function (option) {
      option.setAttribute('aria-selected', option.getAttribute('data-lang') === lang ? 'true' : 'false');
    });

    updateNavToggleLabel();
    saveLang(lang);
  }

  /* ---------------------------------------------------------------
     Language menu
     --------------------------------------------------------------- */

  function closeLangMenu(returnFocus) {
    if (!langSwitch || !langBtn) return;
    langSwitch.classList.remove('open');
    langBtn.setAttribute('aria-expanded', 'false');
    if (returnFocus) langBtn.focus();
  }

  function openLangMenu() {
    if (!langSwitch || !langBtn) return;
    langSwitch.classList.add('open');
    langBtn.setAttribute('aria-expanded', 'true');
    var selected = langOptions.filter(function (option) {
      return option.getAttribute('aria-selected') === 'true';
    })[0] || langOptions[0];
    if (selected) selected.focus();
  }

  if (langSwitch && langBtn && langMenu) {
    langBtn.addEventListener('click', function () {
      if (langSwitch.classList.contains('open')) {
        closeLangMenu(false);
      } else {
        openLangMenu();
      }
    });

    langOptions.forEach(function (option) {
      option.addEventListener('click', function () {
        applyLanguage(option.getAttribute('data-lang'));
        closeLangMenu(true);
      });
    });

    document.addEventListener('click', function (event) {
      if (langSwitch.classList.contains('open') && !langSwitch.contains(event.target)) {
        closeLangMenu(false);
      }
    });

    langMenu.addEventListener('keydown', function (event) {
      var index = langOptions.indexOf(document.activeElement);
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        var step = event.key === 'ArrowDown' ? 1 : -1;
        var next = (index + step + langOptions.length) % langOptions.length;
        langOptions[next].focus();
      } else if (event.key === 'Home') {
        event.preventDefault();
        langOptions[0].focus();
      } else if (event.key === 'End') {
        event.preventDefault();
        langOptions[langOptions.length - 1].focus();
      } else if (event.key === 'Escape') {
        event.preventDefault();
        closeLangMenu(true);
      }
    });
  }

  applyLanguage(detectLang());

  /* ---------------------------------------------------------------
     Sticky header state
     --------------------------------------------------------------- */

  function onScroll() {
    if (window.scrollY > 8) {
      header.classList.add('scrolled');
    } else {
      header.classList.remove('scrolled');
    }
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------------------------------------------------------------
     Mobile navigation
     --------------------------------------------------------------- */

  if (navToggle && nav) {
    navToggle.addEventListener('click', function () {
      var open = header.classList.toggle('nav-open');
      navToggle.setAttribute('aria-expanded', String(open));
      updateNavToggleLabel();
    });

    nav.addEventListener('click', function (event) {
      if (event.target.closest('a')) {
        header.classList.remove('nav-open');
        navToggle.setAttribute('aria-expanded', 'false');
        updateNavToggleLabel();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && header.classList.contains('nav-open')) {
        header.classList.remove('nav-open');
        navToggle.setAttribute('aria-expanded', 'false');
        updateNavToggleLabel();
        navToggle.focus();
      }
    });
  }

  /* ---------------------------------------------------------------
     Reveal on scroll
     --------------------------------------------------------------- */

  var revealItems = Array.prototype.slice.call(document.querySelectorAll('.reveal'));

  function show(item) {
    var delay = parseInt(item.getAttribute('data-delay') || '0', 10);
    if (reduceMotion) {
      item.classList.add('in');
      return;
    }
    window.setTimeout(function () {
      item.classList.add('in');
    }, delay);
  }

  if ('IntersectionObserver' in window && !reduceMotion) {
    var revealObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          show(entry.target);
          revealObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

    revealItems.forEach(function (item) {
      revealObserver.observe(item);
    });
  } else {
    revealItems.forEach(function (item) {
      item.classList.add('in');
    });
  }

  /* ---------------------------------------------------------------
     Animated stat counters
     --------------------------------------------------------------- */

  var counters = Array.prototype.slice.call(document.querySelectorAll('[data-count]'));

  function animateCounter(el) {
    var target = parseInt(el.getAttribute('data-count'), 10);
    if (reduceMotion || isNaN(target)) {
      el.textContent = String(target);
      return;
    }
    var duration = 1100;
    var start = null;

    function step(timestamp) {
      if (start === null) start = timestamp;
      var progress = Math.min((timestamp - start) / duration, 1);
      var eased = 1 - Math.pow(1 - progress, 3);
      el.textContent = String(Math.round(target * eased));
      if (progress < 1) {
        window.requestAnimationFrame(step);
      }
    }
    window.requestAnimationFrame(step);
  }

  if (counters.length) {
    if ('IntersectionObserver' in window) {
      var counterObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            animateCounter(entry.target);
            counterObserver.unobserve(entry.target);
          }
        });
      }, { threshold: 0.6 });
      counters.forEach(function (el) { counterObserver.observe(el); });
    } else {
      counters.forEach(animateCounter);
    }
  }

  /* ---------------------------------------------------------------
     Active section in navigation
     --------------------------------------------------------------- */

  var sections = Array.prototype.slice.call(document.querySelectorAll('main section[id]'));
  var navLinks = Array.prototype.slice.call(document.querySelectorAll('.nav a'));

  function setActive(id) {
    navLinks.forEach(function (link) {
      var active = link.getAttribute('href') === '#' + id;
      if (active) {
        link.setAttribute('aria-current', 'true');
      } else {
        link.removeAttribute('aria-current');
      }
    });
  }

  if ('IntersectionObserver' in window && sections.length) {
    var sectionObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          setActive(entry.target.id);
        }
      });
    }, { rootMargin: '-40% 0px -55% 0px', threshold: 0 });

    sections.forEach(function (section) {
      sectionObserver.observe(section);
    });
  }
})();
