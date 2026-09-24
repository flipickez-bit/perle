<?php
/**
 * Modèle de configuration.
 *
 * À copier en `config.php` et à remplir. `config.php` n'est jamais envoyé
 * sur GitHub : il contient le mot de passe de la base et les clés de
 * paiement, qui ne doivent apparaître nulle part ailleurs.
 */

declare(strict_types=1);

function config(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }

    $c = [
        // ── Base de données ──────────────────────────────────────────────
        // 'mysql' en production (Hostinger), 'sqlite' pour développer.
        'bdd_type'       => 'mysql',
        'bdd_hote'       => 'localhost',
        'bdd_port'       => 3306,
        'bdd_nom'        => 'REMPLACER_nom_de_la_base',
        'bdd_utilisateur' => 'REMPLACER_utilisateur',
        'bdd_motdepasse' => 'REMPLACER_mot_de_passe',
        'bdd_fichier'    => __DIR__ . '/../.donnees/perle.sqlite', // si sqlite

        // ── Site ─────────────────────────────────────────────────────────
        'site_url'   => 'https://perleexperience.com',
        'contact'    => 'contact@perleexperience.com',

        // Mot de passe a usage unique protegeant api/installer.php. Sans lui,
        // quiconque trouverait l'adresse de l'installateur pourrait se creer
        // l'acces administrateur avant vous. Inventez une suite de caracteres
        // au hasard, puis supprimez installer.php une fois l'installation faite.
        'jeton_installation' => 'REMPLACER_par_une_suite_aleatoire',

        // ── Paiement ─────────────────────────────────────────────────────
        // Laisser 'aucun' tant qu'aucun prestataire n'est choisi : le site
        // propose alors le règlement sur place, sans bouton de paiement mort.
        // Valeurs possibles : 'aucun', 'stripe', 'sumup'
        'paiement_fournisseur' => 'aucun',
        'paiement_cle_secrete' => '',
        'paiement_cle_publique' => '',
        'paiement_webhook_secret' => '',
    ];

    return $c;
}
