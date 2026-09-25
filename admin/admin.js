/* ============================================================
   PERLE — Administration
   ============================================================ */

'use strict';

const API = '/api';

/** Toute requête porte l'en-tête maison : le serveur refuse les autres. */
async function api(chemin, options = {}) {
  const r = await fetch(API + chemin, {
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-Perle': '1' },
    ...options,
  });
  let donnees = {};
  try { donnees = await r.json(); } catch { /* réponse vide */ }
  if (!r.ok) throw new Error(donnees.erreur || 'Erreur inattendue.');
  return donnees;
}

const poste = (chemin, corps) =>
  api(chemin, { method: 'POST', body: JSON.stringify(corps) });

/* ── Affichage ─────────────────────────────────────────────── */

const $ = (s) => document.querySelector(s);
const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin',
              'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

/** Échappe tout texte venant de la base avant de l'insérer dans la page. */
function txt(v) {
  return String(v ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function dateCourte(iso) {
  const d = new Date(iso);
  return { jour: d.getDate(), mois: MOIS[d.getMonth()], annee: d.getFullYear() };
}

function heure(iso) {
  const d = new Date(iso);
  return String(d.getHours()).padStart(2, '0') + 'h' + String(d.getMinutes()).padStart(2, '0');
}

function dateLongue(iso) {
  return new Date(iso).toLocaleDateString('fr-FR',
    { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

let minuteurMessage;
function message(texte, echec = false) {
  const m = $('#message');
  m.textContent = texte;
  m.classList.toggle('echec', echec);
  m.hidden = false;
  clearTimeout(minuteurMessage);
  minuteurMessage = setTimeout(() => { m.hidden = true; }, echec ? 6000 : 3500);
}

/* ── Connexion ─────────────────────────────────────────────── */

async function verifierSession() {
  try {
    const { compte } = await api('/moi');
    if (compte && compte.admin) { montrerAdmin(); return; }
    if (compte) {
      $('#erreurConnexion').textContent = "Ce compte n'est pas administrateur.";
    }
  } catch { /* hors ligne ou non connectée */ }
  montrerConnexion();
}

function montrerConnexion() {
  $('#vueConnexion').hidden = false;
  $('#vueAdmin').hidden = true;
}

function montrerAdmin() {
  $('#vueConnexion').hidden = true;
  $('#vueAdmin').hidden = false;
  routerHash();
}

$('#formConnexion').addEventListener('submit', async (e) => {
  e.preventDefault();
  const bouton = e.target.querySelector('button');
  const err = $('#erreurConnexion');
  err.textContent = '';
  bouton.disabled = true;
  try {
    const { compte } = await poste('/connexion', {
      email: $('#email').value.trim(),
      motdepasse: $('#motdepasse').value,
    });
    if (!compte.admin) {
      err.textContent = "Ce compte n'est pas administrateur.";
      return;
    }
    $('#motdepasse').value = '';
    montrerAdmin();
  } catch (ex) {
    err.textContent = ex.message;
  } finally {
    bouton.disabled = false;
  }
});

$('#boutonDeconnexion').addEventListener('click', async () => {
  await poste('/deconnexion', {}).catch(() => {});
  location.hash = '';
  montrerConnexion();
});

/* ── Navigation ────────────────────────────────────────────── */

function routerHash() {
  const h = location.hash.slice(1) || 'planning';
  const [section, id] = h.split('/');

  $('#ongletPlanning').hidden = section !== 'planning';
  $('#ongletClientes').hidden = section !== 'clientes';
  $('#ongletSeance').hidden = section !== 'seance';

  document.querySelectorAll('.onglets a').forEach((a) =>
    a.classList.toggle('actif', a.dataset.onglet === section));

  if (section === 'planning') chargerPlanning();
  if (section === 'clientes') chargerClientes();
  if (section === 'seance' && id) chargerSeance(id);
}

window.addEventListener('hashchange', () => { if (!$('#vueAdmin').hidden) routerHash(); });
$('#boutonRetour').addEventListener('click', () => { location.hash = 'planning'; });

/* ── Planning ──────────────────────────────────────────────── */

async function chargerPlanning() {
  const zone = $('#listePlanning');
  try {
    const { seances } = await api('/admin/seances');
    if (!seances.length) {
      zone.className = '';
      zone.innerHTML = '<p class="vide">Aucune séance pour le moment.<br>'
        + 'Cliquez sur « Nouvelle séance » pour publier votre premier cours.</p>';
      return;
    }
    const avenir = seances.filter((s) => !s.passee).sort((a, b) => a.debut.localeCompare(b.debut));
    const passees = seances.filter((s) => s.passee).sort((a, b) => b.debut.localeCompare(a.debut));

    zone.className = '';
    zone.innerHTML =
      (avenir.length ? '<p class="groupe-titre">À venir</p>' + avenir.map(carteSeance).join('') : '')
      + (passees.length ? '<p class="groupe-titre">Passées</p>' + passees.map(carteSeance).join('') : '');

    zone.querySelectorAll('[data-seance]').forEach((el) =>
      el.addEventListener('click', () => { location.hash = 'seance/' + el.dataset.seance; }));
  } catch (e) {
    zone.className = '';
    zone.innerHTML = '<p class="vide">' + txt(e.message) + '</p>';
  }
}

function carteSeance(s) {
  const d = dateCourte(s.debut);
  const complet = s.inscrites >= s.capacite;
  return `
    <button type="button" class="carte${s.passee ? ' passee' : ''}" data-seance="${s.id}">
      <span class="carte-date"><b>${d.jour}</b><span>${d.mois}</span></span>
      <span class="carte-corps">
        <h3>${txt(s.titre)}</h3>
        <span class="carte-meta">${txt(s.type === 'stage' ? 'Stage' : 'Cours')} · ${txt(s.discipline)}
          · ${heure(s.debut)}–${heure(s.fin)} · ${txt(s.lieu)}</span>
      </span>
      <span class="carte-droite">
        <span class="places${complet ? ' complet' : ''}">${s.inscrites}/${s.capacite}</span>
        <span class="etat ${s.statut}">${({ publiee: 'Publiée', brouillon: 'Brouillon', annulee: 'Annulée' })[s.statut]}</span>
      </span>
    </button>`;
}

/* ── Fiche d'une séance ────────────────────────────────────── */

async function chargerSeance(id) {
  const zone = $('#ficheSeance');
  zone.innerHTML = '<p class="chargement">Chargement…</p>';
  try {
    const { seance, inscrites } = await api('/admin/seance?id=' + encodeURIComponent(id));
    const actives = inscrites.filter((i) => i.statut !== 'annulee');
    const passee = seance.fin < new Date().toISOString().slice(0, 19);

    zone.innerHTML = `
      <div class="titre-ligne">
        <div>
          <h2>${txt(seance.titre)}</h2>
          <p class="carte-meta">${txt(seance.type === 'stage' ? 'Stage' : 'Cours')} · ${txt(seance.discipline)}
            · ${dateLongue(seance.debut)} · ${heure(seance.debut)}–${heure(seance.fin)} · ${txt(seance.lieu)}</p>
        </div>
        <button type="button" class="bouton" id="boutonModifier">Modifier</button>
      </div>

      <p class="groupe-titre">Inscrites — ${actives.length}/${seance.capacite}</p>
      ${inscrites.length === 0
        ? '<p class="vide">Personne d’inscrit pour le moment.</p>'
        : `<div class="enveloppe-tableau"><table class="tableau">
            <thead><tr><th>Cliente</th><th>Contact</th><th>Réglé</th><th>Statut</th><th></th></tr></thead>
            <tbody>${inscrites.map((i) => ligneInscrite(i, passee)).join('')}</tbody>
          </table></div>`}`;

    $('#boutonModifier').addEventListener('click', () => ouvrirPanneau(seance));

    zone.querySelectorAll('[data-desinscrire]').forEach((b) =>
      b.addEventListener('click', async () => {
        if (!confirm('Désinscrire cette cliente et lui rendre son crédit ?')) return;
        try {
          await poste('/admin/desinscrire', { reservation: b.dataset.desinscrire, rembourser: '1' });
          message('Cliente désinscrite, crédit rendu.');
          chargerSeance(id);
        } catch (e) { message(e.message, true); }
      }));

    zone.querySelectorAll('[data-presence]').forEach((sel) =>
      sel.addEventListener('change', async () => {
        try {
          await poste('/admin/presence', { reservation: sel.dataset.presence, statut: sel.value });
          message('Présence enregistrée.');
        } catch (e) { message(e.message, true); }
      }));
  } catch (e) {
    zone.innerHTML = '<p class="vide">' + txt(e.message) + '</p>';
  }
}

function ligneInscrite(i, passee) {
  const nom = txt(i.prenom + (i.nom ? ' ' + i.nom : ''));
  const options = ['confirmee', 'presente', 'absente']
    .map((v) => `<option value="${v}"${i.statut === v ? ' selected' : ''}>`
      + ({ confirmee: 'Inscrite', presente: 'Présente', absente: 'Absente' })[v] + '</option>').join('');
  return `
    <tr class="${i.statut === 'annulee' ? 'annulee' : ''}">
      <td><strong>${nom}</strong><br><span class="carte-meta">${i.credits} crédit(s) restant(s)</span></td>
      <td><a href="mailto:${txt(i.email)}">${txt(i.email)}</a>
        ${i.telephone ? `<br><a href="tel:${txt(i.telephone)}">${txt(i.telephone)}</a>` : ''}</td>
      <td>${i.montant_centimes > 0
            ? (i.montant_centimes / 100).toFixed(2) + ' €'
            : i.credits_utilises + ' crédit(s)'}</td>
      <td>${i.statut === 'annulee' ? 'Annulée'
            : passee ? `<select data-presence="${i.id}">${options}</select>`
            : 'Inscrite'}</td>
      <td>${i.statut === 'annulee' ? ''
            : `<button type="button" class="lien-discret" data-desinscrire="${i.id}">Désinscrire</button>`}</td>
    </tr>`;
}

/* ── Panneau de séance ─────────────────────────────────────── */

function ouvrirPanneau(s = null) {
  $('#titrePanneau').textContent = s ? 'Modifier la séance' : 'Nouvelle séance';
  $('#boutonEnregistrer').textContent = s ? 'Enregistrer' : 'Publier';
  $('#boutonSupprimer').hidden = !s;
  $('#erreurSeance').textContent = '';

  $('#champId').value = s ? s.id : '';
  $('#champType').value = s ? s.type : 'cours';
  $('#champDiscipline').value = s ? s.discipline : 'Yoga';
  $('#champTitre').value = s ? s.titre : '';
  $('#champLieu').value = s ? s.lieu : '';
  $('#champDate').value = s ? s.debut.slice(0, 10) : '';
  $('#champHeureDebut').value = s ? s.debut.slice(11, 16) : '';
  $('#champHeureFin').value = s ? s.fin.slice(11, 16) : '';
  $('#champCapacite').value = s ? s.capacite : '';
  $('#champCout').value = s ? s.cout_credits : 1;
  $('#champPrix').value = s ? Math.round(s.prix_centimes / 100) : 0;
  $('#champDescription').value = s ? (s.description || '') : '';
  // Une nouvelle séance est publiée d'emblée : c'est le cas courant, et le
  // brouillon reste accessible pour préparer une date sans l'annoncer.
  $('#champStatut').value = s ? s.statut : 'publiee';

  $('#voile').hidden = false;
  $('#panneauSeance').hidden = false;
  $('#champTitre').focus();
}

function fermerPanneau() {
  $('#voile').hidden = true;
  $('#panneauSeance').hidden = true;
}

$('#boutonNouvelle').addEventListener('click', () => ouvrirPanneau(null));
$('#fermerPanneau').addEventListener('click', fermerPanneau);
$('#voile').addEventListener('click', fermerPanneau);
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && !$('#panneauSeance').hidden) fermerPanneau();
});

$('#formSeance').addEventListener('submit', async (e) => {
  e.preventDefault();
  const err = $('#erreurSeance');
  const bouton = $('#boutonEnregistrer');
  err.textContent = '';

  const id = $('#champId').value;
  const date = $('#champDate').value;
  const corps = {
    id,
    type: $('#champType').value,
    titre: $('#champTitre').value.trim(),
    discipline: $('#champDiscipline').value,
    lieu: $('#champLieu').value.trim(),
    debut: date + 'T' + $('#champHeureDebut').value + ':00',
    fin: date + 'T' + $('#champHeureFin').value + ':00',
    capacite: $('#champCapacite').value,
    cout_credits: $('#champCout').value,
    prix: $('#champPrix').value,
    description: $('#champDescription').value.trim(),
    statut: $('#champStatut').value,
  };

  bouton.disabled = true;
  try {
    await poste(id ? '/admin/seances/modifier' : '/admin/seances', corps);
    fermerPanneau();
    message(id ? 'Séance mise à jour.' : 'Séance créée.');
    if (id) chargerSeance(id); else location.hash = 'planning';
    if (location.hash.slice(1) === 'planning') chargerPlanning();
  } catch (ex) {
    err.textContent = ex.message;
  } finally {
    bouton.disabled = false;
  }
});

$('#boutonSupprimer').addEventListener('click', async () => {
  const id = $('#champId').value;
  if (!id) return;
  if (!confirm('Supprimer cette séance ?\n\nSi des clientes y sont inscrites, '
    + 'elle sera annulée et non effacée, pour garder la trace de ce qu’elles ont réglé.')) return;
  try {
    const r = await poste('/admin/seances/supprimer', { id });
    fermerPanneau();
    message(r.annulee ? 'Séance annulée (des clientes y étaient inscrites).' : 'Séance supprimée.');
    location.hash = 'planning';
    chargerPlanning();
  } catch (e) { message(e.message, true); }
});

/* ── Clientes ──────────────────────────────────────────────── */

let toutesLesClientes = [];

async function chargerClientes() {
  const zone = $('#listeClientes');
  try {
    const { clientes } = await api('/admin/clientes');
    toutesLesClientes = clientes;
    afficherClientes();
  } catch (e) {
    zone.className = '';
    zone.innerHTML = '<p class="vide">' + txt(e.message) + '</p>';
  }
}

function afficherClientes() {
  const zone = $('#listeClientes');
  const q = $('#rechercheCliente').value.trim().toLowerCase();
  const liste = q
    ? toutesLesClientes.filter((c) =>
        (c.prenom + ' ' + (c.nom || '') + ' ' + c.email).toLowerCase().includes(q))
    : toutesLesClientes;

  zone.className = '';
  if (!liste.length) {
    zone.innerHTML = `<p class="vide">${q ? 'Aucune cliente ne correspond.' : 'Aucune cliente inscrite pour le moment.'}</p>`;
    return;
  }
  zone.innerHTML = liste.map(carteCliente).join('');

  zone.querySelectorAll('form[data-cliente]').forEach((f) =>
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const b = f.querySelector('button');
      b.disabled = true;
      try {
        const r = await poste('/admin/credits', {
          cliente: f.dataset.cliente,
          delta: f.querySelector('[name=delta]').value,
          motif: f.querySelector('[name=motif]').value.trim(),
          mois: f.querySelector('[name=mois]').value,
        });
        message('Nouveau solde : ' + r.solde + ' crédit(s).');
        chargerClientes();
      } catch (ex) {
        message(ex.message, true);
      } finally {
        b.disabled = false;
      }
    }));
}

function carteCliente(c) {
  const nom = txt(c.prenom + (c.nom ? ' ' + c.nom : ''));
  const exp = c.expiration;
  return `
    <article class="cliente">
      <div class="cliente-entete">
        <div>
          <h3>${nom}</h3>
          <p class="cliente-contact">
            <a href="mailto:${txt(c.email)}">${txt(c.email)}</a>
            ${c.telephone ? ' · <a href="tel:' + txt(c.telephone) + '">' + txt(c.telephone) + '</a>' : ''}
          </p>
          <p class="cliente-meta">Inscrite le ${dateLongue(c.cree_le)} · ${c.reservations} réservation(s)</p>
        </div>
        <div class="solde">
          <b>${c.credits}</b><span>crédit${c.credits > 1 ? 's' : ''}</span>
          ${exp ? `<p class="expire${exp.jours <= 15 ? ' urgent' : ''}">${exp.credits} expire(nt) dans ${exp.jours} j</p>` : ''}
        </div>
      </div>

      <form class="ligne-credits" data-cliente="${c.id}">
        <input type="number" name="delta" step="1" placeholder="+6" required
               aria-label="Crédits à ajouter ou retirer pour ${nom}">
        <input type="text" name="motif" placeholder="Motif — pack 6 réglé en espèces…" required
               aria-label="Motif">
        <select name="mois" aria-label="Validité">
          <option value="">Sans expiration</option>
          <option value="1">1 mois</option>
          <option value="2" selected>2 mois</option>
          <option value="4">4 mois</option>
        </select>
        <button type="submit" class="bouton">Appliquer</button>
      </form>
    </article>`;
}

$('#rechercheCliente').addEventListener('input', afficherClientes);

/* ── Démarrage ─────────────────────────────────────────────── */
verifierSession();
