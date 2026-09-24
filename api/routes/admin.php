<?php
/** Administration : séances, clientes, crédits, inscriptions. */

declare(strict_types=1);

function lireSeanceDepuisFormulaire(): array
{
    $type = champ('type', 'cours');
    if (!in_array($type, ['cours', 'stage'], true)) {
        erreur('Type de séance inconnu.');
    }

    $titre = champ('titre');
    $lieu  = champ('lieu');
    $debut = champ('debut');
    $fin   = champ('fin');

    if ($titre === '') erreur('Le titre est obligatoire.');
    if ($lieu === '')  erreur('Le lieu est obligatoire.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $debut)) erreur('Date de début invalide.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $fin))   erreur('Date de fin invalide.');
    if ($fin <= $debut) erreur('La fin doit suivre le début.');

    $capacite = champEntier('capacite');
    if ($capacite < 1) erreur('La capacité doit être d\'au moins une place.');

    $statut = champ('statut', 'brouillon');
    if (!in_array($statut, ['brouillon', 'publiee', 'annulee'], true)) {
        erreur('Statut inconnu.');
    }

    return [
        'type'         => $type,
        'titre'        => $titre,
        'discipline'   => champ('discipline', 'Yoga'),
        'description'  => champ('description') ?: null,
        'lieu'         => $lieu,
        'debut'        => substr($debut, 0, 19),
        'fin'          => substr($fin, 0, 19),
        'capacite'     => $capacite,
        'cout_credits' => max(0, champEntier('cout_credits', 1)),
        'prix_centimes' => max(0, (int) round((float) champ('prix', '0') * 100)),
        'statut'       => $statut,
    ];
}

function routerAdmin(string $chemin): void
{
    $admin = exigeAdmin();
    $db = bdd();

    switch ($chemin) {

        // ── Séances ──────────────────────────────────────────────────────
        case '/seances':
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                $s = lireSeanceDepuisFormulaire();
                $st = $db->prepare(
                    'INSERT INTO seances (type, titre, discipline, description, lieu, debut, fin,
                                          capacite, cout_credits, prix_centimes, statut, cree_le)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $st->execute([...array_values($s), maintenant()]);
                json(['id' => (int) $db->lastInsertId()], 201);
            }

            // Toutes les séances, brouillons et passées comprises : c'est la
            // vue de travail, pas la vitrine.
            $rows = $db->query(
                "SELECT s.*, (SELECT COUNT(*) FROM reservations r
                                WHERE r.seance_id = s.id AND r.statut <> 'annulee') AS inscrites
                   FROM seances s ORDER BY s.debut DESC"
            )->fetchAll();
            foreach ($rows as &$r) {
                $r['id'] = (int) $r['id'];
                $r['capacite'] = (int) $r['capacite'];
                $r['inscrites'] = (int) $r['inscrites'];
                $r['prix_centimes'] = (int) $r['prix_centimes'];
                $r['cout_credits'] = (int) $r['cout_credits'];
                $r['passee'] = $r['fin'] < maintenant();
            }
            json(['seances' => $rows]);

        case '/seance':
            $id = champEntier('id') ?: (int) ($_GET['id'] ?? 0);
            $st = $db->prepare('SELECT * FROM seances WHERE id = ?');
            $st->execute([$id]);
            $s = $st->fetch();
            if (!$s) erreur('Séance introuvable.', 404);

            $st = $db->prepare(
                'SELECT r.id, r.statut, r.credits_utilises, r.montant_centimes, r.cree_le,
                        c.id AS cliente_id, c.prenom, c.nom, c.email, c.telephone
                   FROM reservations r JOIN clientes c ON c.id = r.cliente_id
                  WHERE r.seance_id = ? ORDER BY r.cree_le ASC'
            );
            $st->execute([$id]);
            $inscrites = $st->fetchAll();
            foreach ($inscrites as &$i) {
                $i['credits'] = solde((int) $i['cliente_id']);
            }
            json(['seance' => $s, 'inscrites' => $inscrites]);

        case '/seances/modifier':
            exigeMethode('POST');
            $id = champEntier('id');
            $st = $db->prepare('SELECT capacite FROM seances WHERE id = ?');
            $st->execute([$id]);
            if (!$st->fetch()) erreur('Séance introuvable.', 404);

            $s = lireSeanceDepuisFormulaire();

            // La capacité ne peut pas descendre sous le nombre d'inscrites :
            // des places déjà réglées disparaîtraient sans que rien ne le dise.
            $st = $db->prepare("SELECT COUNT(*) FROM reservations WHERE seance_id = ? AND statut <> 'annulee'");
            $st->execute([$id]);
            $inscrites = (int) $st->fetchColumn();
            if ($s['capacite'] < $inscrites) {
                erreur("Impossible : {$inscrites} personne(s) sont déjà inscrites.", 409);
            }

            $db->prepare(
                'UPDATE seances SET type=?, titre=?, discipline=?, description=?, lieu=?, debut=?, fin=?,
                                    capacite=?, cout_credits=?, prix_centimes=?, statut=? WHERE id=?'
            )->execute([...array_values($s), $id]);
            json(['ok' => true]);

        case '/seances/supprimer':
            exigeMethode('POST');
            $id = champEntier('id');
            $st = $db->prepare('SELECT COUNT(*) FROM reservations WHERE seance_id = ?');
            $st->execute([$id]);

            // Une séance ayant des inscriptions est annulée, pas effacée :
            // la supprimer emporterait la trace de ce que des clientes ont payé.
            if ((int) $st->fetchColumn() > 0) {
                $db->prepare("UPDATE seances SET statut = 'annulee' WHERE id = ?")->execute([$id]);
                json(['ok' => true, 'annulee' => true]);
            }
            $db->prepare('DELETE FROM seances WHERE id = ?')->execute([$id]);
            json(['ok' => true, 'supprimee' => true]);

        // ── Clientes ─────────────────────────────────────────────────────
        case '/clientes':
            $rows = $db->query(
                "SELECT c.id, c.prenom, c.nom, c.email, c.telephone, c.role, c.actif, c.cree_le,
                        (SELECT COUNT(*) FROM reservations r
                          WHERE r.cliente_id = c.id AND r.statut <> 'annulee') AS reservations
                   FROM clientes c WHERE c.role <> 'admin' ORDER BY c.cree_le DESC"
            )->fetchAll();
            foreach ($rows as &$r) {
                $r['id'] = (int) $r['id'];
                $r['reservations'] = (int) $r['reservations'];
                $r['credits'] = solde((int) $r['id']);
                $r['expiration'] = prochaineExpiration((int) $r['id']);
            }
            json(['clientes' => $rows]);

        case '/cliente':
            $id = champEntier('id') ?: (int) ($_GET['id'] ?? 0);
            $st = $db->prepare('SELECT id, prenom, nom, email, telephone, cree_le FROM clientes WHERE id = ?');
            $st->execute([$id]);
            $c = $st->fetch();
            if (!$c) erreur('Cliente introuvable.', 404);

            $st = $db->prepare(
                'SELECT r.id, r.statut, r.credits_utilises, s.titre, s.type, s.debut, s.lieu
                   FROM reservations r JOIN seances s ON s.id = r.seance_id
                  WHERE r.cliente_id = ? ORDER BY s.debut DESC'
            );
            $st->execute([$id]);
            $resa = $st->fetchAll();

            $st = $db->prepare(
                'SELECT delta, motif, solde_apres, cree_le FROM mouvements_credits
                  WHERE cliente_id = ? ORDER BY cree_le DESC'
            );
            $st->execute([$id]);

            json([
                'cliente'      => $c,
                'credits'      => solde($id),
                'lots'         => lotsValides($id),
                'reservations' => $resa,
                'mouvements'   => $st->fetchAll(),
            ]);

        case '/credits':
            exigeMethode('POST');
            $r = ajusterManuellement(
                champEntier('cliente'),
                champEntier('delta'),
                champ('motif'),
                champEntier('mois') ?: null,
                (int) $admin['id']
            );
            if (!$r['ok']) erreur($r['message'], 409);
            json(['ok' => true, 'solde' => $r['solde']]);

        // ── Inscriptions ─────────────────────────────────────────────────
        case '/presence':
            exigeMethode('POST');
            $statut = champ('statut');
            if (!in_array($statut, ['confirmee', 'presente', 'absente'], true)) {
                erreur('Statut inconnu.');
            }
            $db->prepare('UPDATE reservations SET statut = ? WHERE id = ?')
               ->execute([$statut, champEntier('reservation')]);
            json(['ok' => true]);

        case '/desinscrire':
            exigeMethode('POST');
            $id = champEntier('reservation');
            $rembourser = champ('rembourser') === '1';

            $st = $db->prepare('SELECT * FROM reservations WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch();
            if (!$r) erreur('Réservation introuvable.', 404);
            if ($r['statut'] === 'annulee') json(['ok' => true]);

            $db->beginTransaction();
            try {
                $db->prepare("UPDATE reservations SET statut = 'annulee' WHERE id = ?")->execute([$id]);
                if ($rembourser && (int) $r['credits_utilises'] > 0) {
                    rendre((int) $r['cliente_id'], (int) $r['credits_utilises'], null, 'Désinscription par Perle');
                }
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            json(['ok' => true]);
    }

    erreur('Ressource inconnue.', 404);
}
