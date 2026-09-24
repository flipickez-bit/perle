<?php
/**
 * Crédits de séance.
 *
 * Un achat = un lot, avec sa propre date d'expiration. On consomme toujours
 * le lot qui expire le plus tôt : sinon des crédits périment pendant que
 * d'autres, achetés plus tard, sont dépensés à leur place.
 */

declare(strict_types=1);

/** Lots encore valides, du plus proche de l'expiration au plus lointain. */
function lotsValides(int $clienteId): array
{
    $st = bdd()->prepare(
        'SELECT id, restants, expire_le, achete_le, origine
           FROM lots_credits
          WHERE cliente_id = ? AND restants > 0 AND (expire_le IS NULL OR expire_le > ?)
          ORDER BY CASE WHEN expire_le IS NULL THEN 1 ELSE 0 END, expire_le ASC, id ASC'
    );
    $st->execute([$clienteId, maintenant()]);
    return $st->fetchAll();
}

function solde(int $clienteId): int
{
    $total = 0;
    foreach (lotsValides($clienteId) as $l) {
        $total += (int) $l['restants'];
    }
    return $total;
}

/** Prochaine échéance, pour prévenir la cliente avant qu'elle ne perde des crédits. */
function prochaineExpiration(int $clienteId): ?array
{
    foreach (lotsValides($clienteId) as $l) {
        if ($l['expire_le'] !== null) {
            $jours = (int) floor(
                ((new DateTimeImmutable($l['expire_le']))->getTimestamp() - time()) / 86400
            );
            return ['credits' => (int) $l['restants'], 'expire_le' => $l['expire_le'], 'jours' => max(0, $jours)];
        }
    }
    return null;
}

function ajouterLot(int $clienteId, int $credits, ?int $moisValidite, string $origine): void
{
    $expire = $moisValidite !== null
        ? (new DateTimeImmutable('now'))->modify("+{$moisValidite} months")->format('Y-m-d\TH:i:s')
        : null;

    $st = bdd()->prepare(
        'INSERT INTO lots_credits (cliente_id, credits, restants, achete_le, expire_le, origine)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$clienteId, $credits, $credits, maintenant(), $expire, $origine]);
}

/**
 * Consomme n crédits. Renvoie false si le solde est insuffisant, sans rien
 * modifier : une réservation ne doit jamais entamer des crédits à moitié.
 */
function consommer(int $clienteId, int $nombre): bool
{
    if ($nombre <= 0) {
        return true;
    }
    if (solde($clienteId) < $nombre) {
        return false;
    }

    $reste = $nombre;
    $maj = bdd()->prepare('UPDATE lots_credits SET restants = restants - ? WHERE id = ?');
    foreach (lotsValides($clienteId) as $lot) {
        if ($reste <= 0) {
            break;
        }
        $pris = min($reste, (int) $lot['restants']);
        $maj->execute([$pris, $lot['id']]);
        $reste -= $pris;
    }
    return true;
}

/**
 * Rend des crédits après une annulation.
 *
 * Ils reviennent dans un lot neuf portant la même échéance que le lot
 * d'origine aurait eue — à défaut on ne saurait pas quand les faire expirer.
 */
function rendre(int $clienteId, int $nombre, ?string $expireLe, string $origine): void
{
    if ($nombre <= 0) {
        return;
    }
    $st = bdd()->prepare(
        'INSERT INTO lots_credits (cliente_id, credits, restants, achete_le, expire_le, origine)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$clienteId, $nombre, $nombre, maintenant(), $expireLe, $origine]);
}

/**
 * Ajout ou retrait à la main par l'administration.
 *
 * Le motif est obligatoire et conservé : c'est ce qui permet, des mois plus
 * tard, d'expliquer pourquoi un solde a bougé si une cliente le conteste.
 */
function ajusterManuellement(int $clienteId, int $delta, string $motif, ?int $moisValidite, int $adminId): array
{
    $db = bdd();
    $avant = solde($clienteId);

    if ($delta === 0) {
        return ['ok' => false, 'message' => 'Indiquez un nombre de crédits différent de zéro.'];
    }
    if ($motif === '') {
        return ['ok' => false, 'message' => 'Le motif est obligatoire.'];
    }
    if ($delta < 0 && $avant < -$delta) {
        return ['ok' => false, 'message' => "Retrait impossible : {$avant} crédit(s) disponible(s) seulement."];
    }

    $db->beginTransaction();
    try {
        if ($delta > 0) {
            ajouterLot($clienteId, $delta, $moisValidite, 'Ajout manuel — ' . $motif);
        } else {
            consommer($clienteId, -$delta);
        }
        $apres = solde($clienteId);

        $st = $db->prepare(
            'INSERT INTO mouvements_credits (cliente_id, delta, motif, solde_apres, admin_id, cree_le)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$clienteId, $delta, $motif, $apres, $adminId, maintenant()]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return ['ok' => true, 'solde' => $apres];
}
