<?php
/**
 * Terminal de paiement — Stripe.
 *
 * Le montant n'est jamais lu depuis la page : il est recalculé ici à partir
 * du catalogue ou de la séance. Sans cela, on pourrait modifier le prix
 * envoyé par le navigateur et payer un pack à 1 €.
 *
 * Deux chemins de confirmation :
 *  — le retour de Stripe dans le navigateur, qui peut être manqué si la
 *    cliente ferme l'onglet ;
 *  — le webhook, appelé de serveur à serveur, qui fait foi.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/packs.php';

function stripeConfigure(): bool
{
    $c = config();
    return $c['paiement_fournisseur'] === 'stripe' && $c['paiement_cle_secrete'] !== '';
}

/** Appel de l'API Stripe en HTTP, sans bibliothèque à installer. */
function stripeAppel(string $ressource, array $donnees): array
{
    $ch = curl_init('https://api.stripe.com/v1/' . $ressource);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($donnees),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . config()['paiement_cle_secrete'],
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $corps = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);

    if ($corps === false) {
        error_log('[perle][stripe] ' . $err);
        erreur('Le service de paiement est injoignable. Réessayez dans un instant.', 502);
    }
    $json = json_decode((string) $corps, true) ?: [];
    if ($code >= 400) {
        error_log('[perle][stripe] ' . ($json['error']['message'] ?? $corps));
        erreur('Le paiement n\'a pas pu être initialisé.', 502);
    }
    return $json;
}

/**
 * Vérifie que l'appel vient bien de Stripe.
 *
 * Sans cette vérification, n'importe qui pourrait appeler l'adresse du
 * webhook et faire créditer un compte sans avoir payé.
 */
function signatureWebhookValide(string $charge, string $entete, string $secret): bool
{
    $horodatage = null;
    $signatures = [];
    foreach (explode(',', $entete) as $partie) {
        [$k, $v] = array_pad(explode('=', trim($partie), 2), 2, '');
        if ($k === 't') $horodatage = $v;
        if ($k === 'v1') $signatures[] = $v;
    }
    if ($horodatage === null || $signatures === []) {
        return false;
    }
    // Un appel rejoué des heures plus tard est refusé.
    if (abs(time() - (int) $horodatage) > 300) {
        return false;
    }
    $attendue = hash_hmac('sha256', $horodatage . '.' . $charge, $secret);
    foreach ($signatures as $s) {
        if (hash_equals($attendue, $s)) {
            return true;
        }
    }
    return false;
}

/** Crédite le compte une seule fois, même si Stripe rappelle plusieurs fois. */
function encaisser(int $paiementId): void
{
    $db = bdd();
    $st = $db->prepare('SELECT * FROM paiements WHERE id = ?');
    $st->execute([$paiementId]);
    $p = $st->fetch();
    if (!$p || $p['statut'] === 'regle') {
        return;
    }

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE paiements SET statut = 'regle', regle_le = ? WHERE id = ? AND statut <> 'regle'")
           ->execute([maintenant(), $paiementId]);

        if ($p['objet'] === 'pack' && ($pk = formule((string) $p['objet_id']))) {
            ajouterLot((int) $p['cliente_id'], $pk['credits'], $pk['mois'], $pk['libelle'] . ' — payé en ligne');
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function routerPaiement(string $chemin): void
{
    switch ($chemin) {

        case '/paiement/preparer':
            exigeMethode('POST');
            $c = exigeConnexion();

            if (!stripeConfigure()) {
                // Pas de clé : on ne montre jamais un bouton de paiement mort.
                erreur('Le paiement en ligne n\'est pas encore activé. Contactez Perle pour régler votre place.', 503);
            }

            $objet   = champ('objet');   // 'pack' | 'stage' | 'acompte'
            $objetId = champ('objet_id');

            if ($objet === 'pack') {
                $pk = formule($objetId);
                if (!$pk) erreur('Formule inconnue.');
                $libelle  = $pk['libelle'];
                $centimes = $pk['centimes'];
            } elseif ($objet === 'stage' || $objet === 'acompte') {
                $st = bdd()->prepare("SELECT * FROM seances WHERE id = ? AND type = 'stage' AND statut = 'publiee'");
                $st->execute([(int) $objetId]);
                $s = $st->fetch();
                if (!$s) erreur('Stage introuvable.', 404);
                $centimes = (int) $s['prix_centimes'];
                if ($centimes <= 0) erreur('Ce stage n\'a pas de prix défini.', 409);
                if ($objet === 'acompte') {
                    $centimes = (int) round($centimes * PART_ACOMPTE);
                    $libelle  = 'Acompte 30 % — ' . $s['titre'];
                } else {
                    $libelle = $s['titre'];
                }
            } else {
                erreur('Objet de paiement inconnu.');
            }

            $st = bdd()->prepare(
                'INSERT INTO paiements (cliente_id, fournisseur, objet, objet_id, montant_centimes, statut, cree_le)
                 VALUES (?, \'stripe\', ?, ?, ?, \'en_attente\', ?)'
            );
            $st->execute([$c['id'], $objet, $objetId, $centimes, maintenant()]);
            $paiementId = (int) bdd()->lastInsertId();

            $site = rtrim(config()['site_url'], '/');
            $sess = stripeAppel('checkout/sessions', [
                'mode'                        => 'payment',
                'customer_email'              => $c['email'],
                'client_reference_id'         => (string) $paiementId,
                'line_items[0][quantity]'     => 1,
                'line_items[0][price_data][currency]'             => 'eur',
                'line_items[0][price_data][unit_amount]'          => $centimes,
                'line_items[0][price_data][product_data][name]'   => $libelle,
                'metadata[paiement_id]'       => (string) $paiementId,
                'success_url'                 => $site . '/paiement-confirme.html?p=' . $paiementId,
                'cancel_url'                  => $site . '/compte.html?paiement=annule',
                'locale'                      => 'fr',
            ]);

            bdd()->prepare('UPDATE paiements SET reference = ? WHERE id = ?')
                 ->execute([$sess['id'] ?? null, $paiementId]);

            json(['url' => $sess['url'] ?? null, 'montant' => $centimes]);

        case '/paiement/retour':
            // Confirmation d'affichage seulement : le crédit réel vient du webhook.
            $c = exigeConnexion();
            $st = bdd()->prepare('SELECT statut, montant_centimes, objet FROM paiements WHERE id = ? AND cliente_id = ?');
            $st->execute([champEntier('p') ?: (int) ($_GET['p'] ?? 0), $c['id']]);
            $p = $st->fetch();
            json(['paiement' => $p ?: null, 'credits' => solde((int) $c['id'])]);

        case '/paiement/webhook':
            // Appelé par Stripe, pas par le site : l'en-tête maison ne s'applique pas.
            $charge = file_get_contents('php://input') ?: '';
            $secret = config()['paiement_webhook_secret'];
            $entete = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

            if ($secret === '' || !signatureWebhookValide($charge, $entete, $secret)) {
                erreur('Signature invalide.', 400);
            }

            $ev = json_decode($charge, true) ?: [];
            if (($ev['type'] ?? '') === 'checkout.session.completed') {
                $id = (int) ($ev['data']['object']['metadata']['paiement_id'] ?? 0);
                if ($id > 0) {
                    encaisser($id);
                }
            }
            json(['recu' => true]);
    }
}
