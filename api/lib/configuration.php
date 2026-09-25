<?php
/**
 * Lecture de la configuration depuis un fichier texte simple.
 *
 * `config.ini` remplace l'ancien `config.php`. La différence compte : un
 * fichier PHP mal refermé rend tout le site inaccessible, alors qu'une
 * ligne `cle = valeur` ne peut pas casser quoi que ce soit. Une apostrophe
 * de trop dans un mot de passe n'a plus aucune conséquence.
 */

declare(strict_types=1);

function config(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }

    $fichier = __DIR__ . '/../config.ini';
    $lu = is_file($fichier) ? parse_ini_file($fichier, false, INI_SCANNER_RAW) : [];
    if (!is_array($lu)) {
        $lu = [];
    }

    // Les espaces en trop autour d'une valeur sont l'erreur de saisie la
    // plus fréquente : on les retire plutôt que de laisser échouer.
    foreach ($lu as $k => $v) {
        if (is_string($v)) {
            $lu[$k] = trim($v);
        }
    }

    $defauts = [
        'bdd_type'        => 'mysql',
        'bdd_hote'        => 'localhost',
        'bdd_port'        => '3306',
        'bdd_nom'         => '',
        'bdd_utilisateur' => '',
        'bdd_motdepasse'  => '',
        'bdd_fichier'     => __DIR__ . '/../../.donnees/perle.sqlite',
        'site_url'        => 'https://perleexperience.com',
        'contact'         => 'contact@perleexperience.com',
        'jeton_installation'      => '',
        'paiement_fournisseur'    => 'aucun',
        'paiement_cle_secrete'    => '',
        'paiement_cle_publique'   => '',
        'paiement_webhook_secret' => '',
    ];

    $c = array_merge($defauts, $lu);
    $c['bdd_port'] = (int) $c['bdd_port'];

    return $c;
}

/** Vrai si la configuration est utilisable pour se connecter. */
function configurationComplete(): bool
{
    $c = config();
    if ($c['bdd_type'] === 'sqlite') {
        return true;
    }
    return $c['bdd_nom'] !== '' && $c['bdd_utilisateur'] !== '';
}
