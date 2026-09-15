/* ============================================================
   PERLE — Comptes & crédits (prototype front-end, localStorage)
   NB : démo. Une vraie mise en prod nécessite un serveur sécurisé.
   ============================================================ */
(function () {
  const USERS_KEY = 'perle-users';
  const SESSION_KEY = 'perle-session';

  const T = {
    fr: {
      login: 'Se connecter', logout: 'Déconnexion', credits: 'crédits', profile: 'Mon profil',
      welcome: 'Bonjour', signupTitle: 'Créer un compte', loginTitle: 'Connexion',
      name: 'Prénom', email: 'Email', pass: 'Mot de passe',
      signupBtn: "S'inscrire", loginBtn: 'Se connecter',
      haveAccount: 'Déjà un compte ?', noAccount: 'Pas encore de compte ?',
      errFields: 'Merci de remplir tous les champs.', errExists: 'Un compte existe déjà avec cet email.',
      errLogin: 'Email ou mot de passe incorrect.', errPass: 'Le mot de passe doit faire 4 caractères minimum.',
      okSignup: 'Compte créé, bienvenue chez Perle !', switchLog: 'Se connecter', switchSign: 'Créer un compte'
    },
    en: {
      login: 'Log in', logout: 'Log out', credits: 'credits', profile: 'My profile',
      welcome: 'Hello', signupTitle: 'Create an account', loginTitle: 'Log in',
      name: 'First name', email: 'Email', pass: 'Password',
      signupBtn: 'Sign up', loginBtn: 'Log in',
      haveAccount: 'Already have an account?', noAccount: 'No account yet?',
      errFields: 'Please fill in all fields.', errExists: 'An account already exists with this email.',
      errLogin: 'Incorrect email or password.', errPass: 'Password must be at least 4 characters.',
      okSignup: 'Account created, welcome to Perle!', switchLog: 'Log in', switchSign: 'Create an account'
    }
  };
  const lang = () => (localStorage.getItem('perle-lang') || 'fr');
  const t = (k) => (T[lang()] || T.fr)[k];

  const getUsers = () => { try { return JSON.parse(localStorage.getItem(USERS_KEY)) || {}; } catch (e) { return {}; } };
  const saveUsers = (u) => localStorage.setItem(USERS_KEY, JSON.stringify(u));
  const session = () => localStorage.getItem(SESSION_KEY);
  let pendingHref = null;

  function getUser() {
    const e = session(); if (!e) return null;
    return getUsers()[e] || null;
  }
  function persist(user) { const u = getUsers(); u[user.email] = user; saveUsers(u); }

  function signup(name, email, pass) {
    email = (email || '').trim().toLowerCase();
    if (!name || !email || !pass) return { ok: false, msg: t('errFields') };
    if (pass.length < 4) return { ok: false, msg: t('errPass') };
    const users = getUsers();
    if (users[email]) return { ok: false, msg: t('errExists') };
    users[email] = { name: name.trim(), email, pass: btoa(pass), credits: 0, lots: [], bookings: [] };
    saveUsers(users);
    localStorage.setItem(SESSION_KEY, email);
    renderNav();
    return { ok: true, msg: t('okSignup') };
  }

  function login(email, pass) {
    email = (email || '').trim().toLowerCase();
    const u = getUsers()[email];
    if (!u || u.pass !== btoa(pass || '')) return { ok: false, msg: t('errLogin') };
    localStorage.setItem(SESSION_KEY, email);
    renderNav();
    return { ok: true };
  }

  function logout() { localStorage.removeItem(SESSION_KEY); renderNav(); }

  /* ── Fidélité : paliers cadeaux (seuil = total de cours achetés) ──
     ✏️ Modifier/ajouter un palier : éditer simplement ce tableau. */
  var REWARDS = [
    { threshold: 10, title: 'Bouteille floquée Perle offerte', detail: 'À récupérer lors de votre prochain cours' },
    { threshold: 20, title: '1 cours offert', detail: 'Crédit ajouté automatiquement', bonusCredits: 1 },
    { threshold: 40, title: 'Cadeau surprise Perle', detail: 'Notre façon de dire merci' }
  ];

  /* ── LOTS DE CRÉDITS ──
     Chaque pack acheté crée un lot avec sa propre date d'expiration.
     On consomme toujours le lot qui expire le plus tôt. */

  function lots(u) {
    if (!u.lots) {
      // Ancien compte : on transforme le solde existant en un lot sans expiration
      u.lots = (u.credits > 0) ? [{ credits: u.credits, expire: null, achat: null }] : [];
    }
    return u.lots;
  }
  function estValide(l) { return !l.expire || new Date(l.expire) > new Date(); }

  /* Retire les lots périmés et recalcule le solde */
  function nettoyer(u) {
    const avant = lots(u).reduce((s, l) => s + l.credits, 0);
    u.lots = lots(u).filter(l => estValide(l) && l.credits > 0);
    const apres = u.lots.reduce((s, l) => s + l.credits, 0);
    u.credits = apres;
    return avant - apres;          // nombre de crédits perdus
  }

  function soldeValide() {
    const u = getUser(); if (!u) return 0;
    nettoyer(u);
    return u.credits;
  }

  /* Prochaine échéance : { credits, expire, jours } ou null */
  function prochaineExpiration() {
    const u = getUser(); if (!u) return null;
    nettoyer(u);
    const avecDate = u.lots.filter(l => l.expire).sort((a, b) => new Date(a.expire) - new Date(b.expire));
    if (!avecDate.length) return null;
    const l = avecDate[0];
    const jours = Math.ceil((new Date(l.expire) - new Date()) / 86400000);
    return { credits: l.credits, expire: l.expire, jours: jours };
  }

  /* n crédits, valables moisValidite mois (null = sans limite) */
  function addCredits(n, moisValidite) {
    const u = getUser(); if (!u) return 0;
    nettoyer(u);
    const before = u.purchased || 0;
    const after = before + n;
    let bonus = 0;
    REWARDS.forEach(function (t) {
      if (t.bonusCredits && before < t.threshold && after >= t.threshold) bonus += t.bonusCredits;
    });
    let expire = null;
    if (moisValidite) {
      const d = new Date();
      d.setMonth(d.getMonth() + moisValidite);
      expire = d.toISOString();
    }
    u.purchased = after;
    lots(u).push({ credits: n + bonus, expire: expire, achat: new Date().toISOString() });
    u.credits = u.lots.reduce((s, l) => s + l.credits, 0);
    persist(u); renderNav();
    return bonus;
  }

  function book(cls) {
    const u = getUser(); if (!u) return { ok: false, reason: 'auth' };
    if (cls.id && hasBooked(cls.id)) return { ok: false, reason: 'already' };
    nettoyer(u);
    if (u.credits < 1) return { ok: false, reason: 'credits' };
    // on entame le lot dont la date tombe en premier
    const tri = u.lots.slice().sort(function (a, b) {
      if (!a.expire) return 1; if (!b.expire) return -1;
      return new Date(a.expire) - new Date(b.expire);
    });
    tri[0].credits -= 1;
    u.lots = u.lots.filter(l => l.credits > 0);
    u.credits = u.lots.reduce((s, l) => s + l.credits, 0);
    u.bookings.unshift({ id: cls.id || null, title: cls.title, lieu: cls.lieu, date: cls.date, bookedAt: new Date().toISOString() });
    persist(u); renderNav();
    return { ok: true };
  }

  function hasBooked(id) {
    const u = getUser(); if (!u) return false;
    return (u.bookings || []).some(b => b.id === id);
  }

  /* ── NAV WIDGET ── */
  function renderNav() {
    const u = getUser();
    document.querySelectorAll('.nav-account').forEach(box => {
      if (u) {
        /* Dans le menu : uniquement « Mon profil ».
           La déconnexion est proposée sur la page profil (compte.html). */
        box.innerHTML = '<a class="acct-user" href="compte.html">' + t('profile') + '</a>';
      } else {
        box.innerHTML = '<button class="acct-btn" type="button">' + t('login') + '</button>';
        box.querySelector('.acct-btn').addEventListener('click', () => openAuth('login'));
      }
    });
  }

  function escapeHtml(s) { return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

  /* ── INJECTION NAV + MODALE ── */
  function injectNavAccount() {
    document.querySelectorAll('.nav-links, .mobile-menu').forEach(nav => {
      if (nav.querySelector('.nav-account')) return;
      const box = document.createElement('div');
      box.className = 'nav-account';
      const lt = nav.querySelector('.lang-toggle');
      if (lt) nav.insertBefore(box, lt); else nav.appendChild(box);
    });
  }

  function buildModal() {
    if (document.getElementById('authModal')) return;
    const el = document.createElement('div');
    el.className = 'modal-overlay'; el.id = 'authModal';
    el.innerHTML =
      '<div class="auth-card" role="dialog" aria-modal="true">' +
        '<button class="auth-close" type="button" aria-label="Fermer">✕</button>' +
        '<div class="auth-logo"><img src="Logo.PNG" alt="Perle"></div>' +
        '<div class="auth-tabs">' +
          '<button class="auth-tab" data-tab="login" type="button"></button>' +
          '<button class="auth-tab" data-tab="signup" type="button"></button>' +
        '</div>' +
        '<div class="auth-msg" id="authMsg"></div>' +
        '<form class="auth-form" data-form="login" novalidate>' +
          '<div class="form-group"><label data-k="email"></label><input type="email" name="email" autocomplete="email" required></div>' +
          '<div class="form-group"><label data-k="pass"></label><input type="password" name="pass" autocomplete="current-password" required></div>' +
          '<button class="btn btn-dark" type="submit" style="justify-content:center" data-k="loginBtn"></button>' +
          '<p class="auth-switch"><span data-k="noAccount"></span> <button type="button" data-goto="signup" data-k="switchSign"></button></p>' +
        '</form>' +
        '<form class="auth-form" data-form="signup" novalidate>' +
          '<div class="form-group"><label data-k="name"></label><input type="text" name="name" autocomplete="given-name" required></div>' +
          '<div class="form-group"><label data-k="email"></label><input type="email" name="email" autocomplete="email" required></div>' +
          '<div class="form-group"><label data-k="pass"></label><input type="password" name="pass" autocomplete="new-password" required></div>' +
          '<button class="btn btn-dark" type="submit" style="justify-content:center" data-k="signupBtn"></button>' +
          '<p class="auth-switch"><span data-k="haveAccount"></span> <button type="button" data-goto="login" data-k="switchLog"></button></p>' +
        '</form>' +
      '</div>';
    document.body.appendChild(el);

    el.querySelector('.auth-close').addEventListener('click', closeAuth);
    el.addEventListener('click', e => { if (e.target === el) closeAuth(); });
    el.querySelectorAll('.auth-tab').forEach(tab => tab.addEventListener('click', () => showTab(tab.dataset.tab)));
    el.querySelectorAll('[data-goto]').forEach(b => b.addEventListener('click', () => showTab(b.dataset.goto)));

    el.querySelector('[data-form="login"]').addEventListener('submit', e => {
      e.preventDefault();
      const f = e.target;
      const r = login(f.email.value, f.pass.value);
      const msg = document.getElementById('authMsg');
      if (r.ok) { afterAuthSuccess(); }
      else { msg.className = 'auth-msg'; msg.textContent = r.msg; }
    });
    el.querySelector('[data-form="signup"]').addEventListener('submit', e => {
      e.preventDefault();
      const f = e.target;
      const r = signup(f.name.value, f.email.value, f.pass.value);
      const msg = document.getElementById('authMsg');
      if (r.ok) { msg.className = 'auth-msg ok'; msg.textContent = r.msg; setTimeout(afterAuthSuccess, 700); }
      else { msg.className = 'auth-msg'; msg.textContent = r.msg; }
    });
  }

  function afterAuthSuccess() {
    closeAuth();
    if (pendingHref) { const h = pendingHref; pendingHref = null; location.href = h; return; }
    if (window.onPerleAuth) window.onPerleAuth();
  }

  /* Liens/boutons protégés : class="needs-auth" (utilise href ou data-href) */
  document.addEventListener('click', e => {
    const el = e.target.closest('.needs-auth');
    if (!el) return;
    if (getUser()) return; // connecté → navigation normale
    e.preventDefault();
    pendingHref = el.getAttribute('href') || el.getAttribute('data-href') || null;
    openAuth('signup');
  });

  function localizeModal() {
    const el = document.getElementById('authModal'); if (!el) return;
    el.querySelector('[data-tab="login"]').textContent = t('loginTitle');
    el.querySelector('[data-tab="signup"]').textContent = t('signupTitle');
    el.querySelectorAll('[data-k]').forEach(n => { n.textContent = t(n.dataset.k); });
  }

  function showTab(tab) {
    const el = document.getElementById('authModal');
    el.querySelectorAll('.auth-tab').forEach(t2 => t2.classList.toggle('active', t2.dataset.tab === tab));
    el.querySelectorAll('.auth-form').forEach(f => f.classList.toggle('active', f.dataset.form === tab));
    document.getElementById('authMsg').textContent = '';
  }

  function openAuth(tab) {
    buildModal(); localizeModal(); renderNav();
    showTab(tab || 'login');
    document.getElementById('authModal').classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeAuth() {
    const el = document.getElementById('authModal');
    if (el) el.classList.remove('open');
    document.body.style.overflow = '';
  }

  /* Re-render on language change */
  window.addEventListener('perlelang', () => { renderNav(); localizeModal(); });

  document.addEventListener('DOMContentLoaded', () => { injectNavAccount(); buildModal(); localizeModal(); renderNav(); });

  window.Perle = { getUser, isLoggedIn: () => !!getUser(), signup, login, logout, addCredits, soldeValide, prochaineExpiration, book, hasBooked, openAuth, closeAuth, renderNav, REWARDS };
})();
