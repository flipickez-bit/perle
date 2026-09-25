/* ============================================================
   PERLE — Dialogue avec le serveur
   Partagé par toutes les pages publiques.
   ============================================================ */

'use strict';

/**
 * Appelle l'API. L'en-tête maison est obligatoire : le serveur refuse
 * toute requête qui ne vient pas du site.
 */
async function perleApi(chemin, options = {}) {
  const r = await fetch('/api' + chemin, {
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-Perle': '1' },
    ...options,
  });
  let donnees = {};
  try { donnees = await r.json(); } catch { /* réponse vide */ }
  if (!r.ok) {
    const e = new Error(donnees.erreur || 'Une erreur est survenue.');
    e.statut = r.status;
    e.donnees = donnees;
    throw e;
  }
  return donnees;
}

const perlePoste = (chemin, corps) =>
  perleApi(chemin, { method: 'POST', body: JSON.stringify(corps) });

/** Échappe le texte venant du serveur avant de l'insérer dans la page. */
function perleTexte(v) {
  return String(v ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/* ── Dates ─────────────────────────────────────────────────── */

const PERLE_MOIS = ['Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin',
                    'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];

function perleJour(iso) { return new Date(iso).getDate(); }

function perleMoisAnnee(iso) {
  const d = new Date(iso);
  return PERLE_MOIS[d.getMonth()] + ' ' + d.getFullYear();
}

function perleHeure(iso) {
  const d = new Date(iso);
  return String(d.getHours()).padStart(2, '0') + 'h' + String(d.getMinutes()).padStart(2, '0');
}

function perleDateLongue(iso, langue) {
  return new Date(iso).toLocaleDateString(langue === 'en' ? 'en-GB' : 'fr-FR',
    { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

function perleEuros(centimes) {
  return (centimes % 100 === 0 ? centimes / 100 : (centimes / 100).toFixed(2)) + ' €';
}

/** Langue choisie par la visiteuse, partagée avec le reste du site. */
function perleLangue() {
  return localStorage.getItem('perle-lang') || 'fr';
}

/* ── Compte ────────────────────────────────────────────────── */

let perleCompte = null;
let perleCompteCharge = false;

/** Renvoie le compte connecté, ou null. Le résultat est mis en mémoire. */
async function perleMoi(forcer = false) {
  if (perleCompteCharge && !forcer) return perleCompte;
  try {
    const { compte } = await perleApi('/moi');
    perleCompte = compte;
  } catch {
    perleCompte = null;
  }
  perleCompteCharge = true;
  return perleCompte;
}
