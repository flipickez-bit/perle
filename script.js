/* ============================================================
   PERLE — Script partagé
   ============================================================ */

let currentLang = localStorage.getItem('perle-lang') || 'fr';

function setLang(lang) {
  currentLang = lang;
  localStorage.setItem('perle-lang', lang);
  document.documentElement.lang = lang;
  document.querySelectorAll('[data-fr]').forEach(el => {
    const text = el.getAttribute('data-' + lang);
    if (text === null) return;
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
      el.placeholder = text;
    } else {
      el.textContent = text;
    }
  });
  document.querySelectorAll('.lang-toggle').forEach(tg => {
    tg.querySelectorAll('button').forEach((btn, i) => {
      const active = (i === 0 && lang === 'fr') || (i === 1 && lang === 'en');
      btn.classList.toggle('active', active);
      btn.setAttribute('aria-pressed', active);
    });
  });
  const fs = document.getElementById('form-success');
  if (fs && fs.style.display !== 'none' && fs.textContent) {
    fs.textContent = lang === 'fr' ? 'Merci ! Nous vous répondrons sous 24h. 🙏' : "Thank you! We'll get back to you within 24 hours. 🙏";
  }
  window.dispatchEvent(new CustomEvent('perlelang', { detail: lang }));
}

function closeMobile() {
  const menu = document.getElementById('mobileMenu');
  const burger = document.getElementById('burger');
  if (menu) menu.classList.remove('open');
  if (burger) burger.setAttribute('aria-expanded', 'false');
  document.body.style.overflow = '';
}

function handleSubmit(e) {
  e.preventDefault();
  const form = e.target;
  const success = document.getElementById('form-success');
  const btn = form.querySelector('button[type=submit]');
  if (btn) btn.disabled = true;
  setTimeout(() => {
    form.reset();
    if (success) {
      success.style.display = 'block';
      success.textContent = currentLang === 'fr' ? 'Merci ! Nous vous répondrons sous 24h. 🙏' : "Thank you! We'll get back to you within 24 hours. 🙏";
    }
    if (btn) btn.disabled = false;
  }, 800);
}

function handleNewsletter(e) {
  e.preventDefault();
  const btn = e.target.querySelector('button');
  const original = btn.textContent;
  btn.textContent = currentLang === 'fr' ? 'Inscrit(e) !' : 'Subscribed!';
  btn.style.background = '#E6D7B8';
  setTimeout(() => { btn.textContent = original; btn.style.background = ''; e.target.reset(); }, 3000);
}

document.addEventListener('DOMContentLoaded', () => {
  /* Animation d'arrivée : on retire l'élément une fois la perle sortie de l'écran,
     pour qu'il ne puisse jamais rester devant la page. */
  const intro = document.getElementById('pageIntro');
  if (intro) setTimeout(() => intro.remove(), 5200);

  /* Langue mémorisée */
  if (currentLang !== 'fr') setLang(currentLang);

  /* Nav scroll */
  const nav = document.getElementById('nav');
  if (nav) window.addEventListener('scroll', () => {
    nav.classList.toggle('scrolled', window.scrollY > 60);
  }, { passive: true });

  /* Menu mobile */
  const burger = document.getElementById('burger');
  const menu = document.getElementById('mobileMenu');
  const closeBtn = document.getElementById('mobileClose');
  if (burger && menu) {
    burger.addEventListener('click', () => {
      menu.classList.add('open');
      burger.setAttribute('aria-expanded', 'true');
      document.body.style.overflow = 'hidden';
    });
    if (closeBtn) closeBtn.addEventListener('click', closeMobile);
    menu.addEventListener('click', e => { if (e.target === menu) closeMobile(); });
  }

  /* Apparitions au scroll */
  const obs = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } });
  }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
  document.querySelectorAll('.fade-in').forEach(el => obs.observe(el));

  /* Hero au chargement */
  document.querySelectorAll('.hero .fade-in').forEach((el, i) => {
    setTimeout(() => el.classList.add('visible'), 150 + i * 120);
  });

  /* Pré-remplissage formule via ?f=elegante */
  const f = new URLSearchParams(location.search).get('f');
  if (f) {
    const sel = document.getElementById('formule');
    if (sel) { const opt = [...sel.options].find(o => o.value.startsWith(f)); if (opt) sel.value = opt.value; }
  }
});
