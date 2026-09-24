<?php
/**
 * Comptes, mots de passe et session.
 *
 * Les mots de passe ne sont jamais stockés ni comparés en clair : PHP les
 * hache avec un sel propre à chacun. Même en cas de fuite de la base, ils
 * ne peuvent pas être relus.
 */

declare(strict_types=1);

function demarrerSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,      // inaccessible au JavaScript : bloque le vol de session par script injecté
        'secure'   => $https,    // en local (http) le cookie doit rester utilisable
        'samesite' => 'Lax',     // le cookie n'accompagne pas les requêtes venues d'un autre site
    ]);
    session_name('perle_session');
    session_start();
}

/**
 * Refuse les requêtes qui modifient des données et ne viennent pas du site.
 *
 * Un formulaire hébergé ailleurs ne peut pas ajouter cet en-tête sans
 * déclencher une vérification préalable du navigateur, qui échouera. C'est
 * ce qui empêche un site tiers de faire agir une cliente à son insu.
 */
function exigeOrigineSite(): void
{
    if (in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    if (($_SERVER['HTTP_X_PERLE'] ?? '') !== '1') {
        erreur('Requête refusée.', 403);
    }
}

function clienteConnectee(): ?array
{
    demarrerSession();
    $id = $_SESSION['cliente_id'] ?? null;
    if (!$id) {
        return null;
    }

    $st = bdd()->prepare(
        'SELECT id, email, prenom, nom, telephone, role, actif, cree_le FROM clientes WHERE id = ?'
    );
    $st->execute([$id]);
    $c = $st->fetch();

    // Compte supprimé ou désactivé entre-temps : la session ne doit pas survivre.
    if (!$c || (int) $c['actif'] !== 1) {
        unset($_SESSION['cliente_id']);
        return null;
    }
    return $c;
}

function exigeConnexion(): array
{
    $c = clienteConnectee();
    if (!$c) {
        erreur('Connectez-vous pour continuer.', 401);
    }
    return $c;
}

function exigeAdmin(): array
{
    $c = exigeConnexion();
    if ($c['role'] !== 'admin') {
        erreur('Accès réservé à l\'administration.', 403);
    }
    return $c;
}

function ouvrirSession(int $clienteId): void
{
    demarrerSession();
    // Nouvel identifiant de session à chaque connexion : empêche qu'un
    // identifiant obtenu avant la connexion serve à usurper le compte.
    session_regenerate_id(true);
    $_SESSION['cliente_id'] = $clienteId;
}

function fermerSession(): void
{
    demarrerSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function emailValide(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 190;
}

/**
 * Exigences de mot de passe.
 *
 * Huit caractères minimum : le site gère de l'argent et des données
 * personnelles, quatre caractères comme sur l'ancienne version se cassent
 * en quelques secondes.
 */
function motDePasseValide(string $mdp): ?string
{
    if (strlen($mdp) < 8) {
        return 'Le mot de passe doit faire au moins 8 caractères.';
    }
    if (strlen($mdp) > 200) {
        return 'Mot de passe trop long.';
    }
    return null;
}
