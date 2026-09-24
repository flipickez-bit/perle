<?php
/** Catalogue public, réservation et annulation. */

declare(strict_types=1);

/**
 * Délai d'annulation sans frais, en heures.
 *
 * Ces valeurs reprennent exactement ce qui est publié sur la page
 * « Annulation & remboursement ». Les changer ici sans changer la page
 * créerait une contradiction opposable.
 */
const DELAI_ANNULATION = ['cours' => 48, 'stage' => 168]; // 168 h = 7 jours

function seancePublique(array $s, int $places): array
{
    return [
        'id'          => (int) $s['id'],
        'type'        => $s['type'],
        'titre'       => $s['titre'],
        'discipline'  => $s['discipline'],
        'description' => $s['description'],
        'lieu'        => $s['lieu'],
        'debut'       => $s['debut'],
        'fin'         => $s['fin'],
        'capacite'    => (int) $s['capacite'],
        'inscrites'   => (int) $s['capacite'] - $places,
        'places'      => $places,
        'cout_credits' => (int) $s['cout_credits'],
        'prix'        => (int) $s['prix_centimes'],
    ];
}

/** Places restantes, les annulations ne comptant pas. */
function placesRestantes(int $seanceId, int $capacite): int
{
    $st = bdd()->prepare(
        "SELECT COUNT(*) FROM reservations WHERE seance_id = ? AND statut <> 'annulee'"
    );
    $st->execute([$seanceId]);
    return max(0, $capacite - (int) $st->fetchColumn());
}

function routerReservations(string $chemin): void
{
    switch ($chemin) {

        case '/seances':
            // Seules les séances publiées et à venir sont visibles : une date
            // passée ne doit jamais rester réservable.
            $type = champ('type');
            $sql = "SELECT * FROM seances WHERE statut = 'publiee' AND debut > ?";
            $args = [maintenant()];
            if (in_array($type, ['cours', 'stage'], true)) {
                $sql .= ' AND type = ?';
                $args[] = $type;
            }
            $sql .= ' ORDER BY debut ASC';

            $st = bdd()->prepare($sql);
            $st->execute($args);

            $out = [];
            foreach ($st->fetchAll() as $s) {
                $out[] = seancePublique($s, placesRestantes((int) $s['id'], (int) $s['capacite']));
            }
            json(['seances' => $out]);

        case '/reserver':
            exigeMethode('POST');
            $c = exigeConnexion();
            $seanceId = champEntier('seance');

            $st = bdd()->prepare("SELECT * FROM seances WHERE id = ? AND statut = 'publiee'");
            $st->execute([$seanceId]);
            $s = $st->fetch();
            if (!$s)                       erreur('Cette séance n\'est pas disponible.', 404);
            if ($s['debut'] <= maintenant()) erreur('Cette séance est passée.', 409);

            $st = bdd()->prepare("SELECT id, statut FROM reservations WHERE seance_id = ? AND cliente_id = ?");
            $st->execute([$seanceId, $c['id']]);
            $deja = $st->fetch();
            if ($deja && $deja['statut'] !== 'annulee') {
                erreur('Vous êtes déjà inscrite à cette séance.', 409);
            }

            if (placesRestantes($seanceId, (int) $s['capacite']) < 1) {
                erreur('Cette séance est complète.', 409);
            }

            $cout = (int) $s['cout_credits'];
            $db = bdd();
            $db->beginTransaction();
            try {
                if ($cout > 0 && !consommer((int) $c['id'], $cout)) {
                    $db->rollBack();
                    erreur('Crédits insuffisants.', 402, ['credits' => solde((int) $c['id']), 'requis' => $cout]);
                }
                if ($deja) {
                    $db->prepare("UPDATE reservations SET statut = 'confirmee', credits_utilises = ?, cree_le = ? WHERE id = ?")
                       ->execute([$cout, maintenant(), $deja['id']]);
                } else {
                    $db->prepare(
                        'INSERT INTO reservations (seance_id, cliente_id, statut, credits_utilises, montant_centimes, cree_le)
                         VALUES (?, ?, \'confirmee\', ?, 0, ?)'
                    )->execute([$seanceId, $c['id'], $cout, maintenant()]);
                }
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            json(['ok' => true, 'credits' => solde((int) $c['id'])], 201);

        case '/annuler':
            exigeMethode('POST');
            $c = exigeConnexion();
            $id = champEntier('reservation');

            $st = bdd()->prepare(
                'SELECT r.*, s.debut, s.type FROM reservations r
                   JOIN seances s ON s.id = r.seance_id
                  WHERE r.id = ? AND r.cliente_id = ?'
            );
            $st->execute([$id, $c['id']]);
            $r = $st->fetch();
            if (!$r)                          erreur('Réservation introuvable.', 404);
            if ($r['statut'] === 'annulee')   erreur('Cette réservation est déjà annulée.', 409);

            $heures = (strtotime($r['debut']) - time()) / 3600;
            $limite = DELAI_ANNULATION[$r['type']] ?? 48;
            $aTemps = $heures >= $limite;

            $db = bdd();
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE reservations SET statut = 'annulee' WHERE id = ?")->execute([$id]);
                if ($aTemps && (int) $r['credits_utilises'] > 0) {
                    rendre((int) $c['id'], (int) $r['credits_utilises'], null, 'Annulation dans les délais');
                }
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            json([
                'ok'        => true,
                'rembourse' => $aTemps,
                'credits'   => solde((int) $c['id']),
                'message'   => $aTemps
                    ? 'Annulation enregistrée, votre crédit vous est rendu.'
                    : sprintf(
                        'Annulation enregistrée. Le délai de %s était dépassé, le crédit reste décompté.',
                        $limite >= 168 ? '7 jours' : '48 heures'
                    ),
            ]);

        case '/mes-reservations':
            $c = exigeConnexion();
            $st = bdd()->prepare(
                'SELECT r.id, r.statut, r.credits_utilises, r.cree_le,
                        s.id AS seance_id, s.titre, s.type, s.discipline, s.lieu, s.debut, s.fin
                   FROM reservations r
                   JOIN seances s ON s.id = r.seance_id
                  WHERE r.cliente_id = ?
                  ORDER BY s.debut DESC'
            );
            $st->execute([$c['id']]);
            json(['reservations' => $st->fetchAll(), 'credits' => solde((int) $c['id'])]);
    }
}
