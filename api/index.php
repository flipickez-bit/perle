<?php
/**
 * Point d'entrée unique de l'API.
 *
 * Toutes les requêtes /api/... arrivent ici (voir .htaccess) et sont
 * dirigées vers la bonne fonction. Un seul fichier décide donc de ce qui
 * existe et de ce qui exige une connexion.
 */

declare(strict_types=1);

// Les erreurs ne doivent jamais s'afficher à la visiteuse : elles révèlent
// des chemins de fichiers et des extraits de requêtes. On les journalise.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$racine = __DIR__;
// Sans configuration, on le dit clairement plutot que de planter.
if (!is_file($racine . '/config.php')) {
    http_response_code(503);
    exit("Le site n'est pas encore configure : api/config.php est absent.");
}
require $racine . '/config.php';
require $racine . '/lib/reponse.php';
require $racine . '/lib/bdd.php';
require $racine . '/lib/auth.php';
require $racine . '/lib/credits.php';

set_exception_handler(static function (Throwable $e): void {
    error_log('[perle] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    erreur("Une erreur est survenue. Réessayez, ou appelez le 06 77 30 30 65.", 500);
});

// Chemin demandé, débarrassé du préfixe /api et des paramètres.
$chemin = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$chemin = preg_replace('#^.*?/api#', '', $chemin) ?: '/';
$chemin = '/' . trim($chemin, '/');

exigeOrigineSite();

switch ($chemin) {
    // ── Comptes ──────────────────────────────────────────────────────────
    case '/inscription':
    case '/connexion':
    case '/deconnexion':
    case '/moi':
    case '/mot-de-passe':
        require $racine . '/routes/comptes.php';
        routerComptes($chemin);
        break;

    // ── Catalogue et réservations ────────────────────────────────────────
    case '/seances':
    case '/reserver':
    case '/annuler':
    case '/mes-reservations':
        require $racine . '/routes/reservations.php';
        routerReservations($chemin);
        break;

    // ── Paiement ─────────────────────────────────────────────────────────
    case '/paiement/preparer':
    case '/paiement/retour':
    case '/paiement/webhook':
        require $racine . '/routes/paiement.php';
        routerPaiement($chemin);
        break;

    // ── Administration ───────────────────────────────────────────────────
    default:
        if (str_starts_with($chemin, '/admin/')) {
            require $racine . '/routes/admin.php';
            routerAdmin(substr($chemin, 6));
            break;
        }
        erreur('Ressource inconnue.', 404);
}
