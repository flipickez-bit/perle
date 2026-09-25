<?php
/**
 * Réponses JSON et lecture des requêtes.
 *
 * Tout ce que l'API renvoie passe par ici : un seul endroit décide du
 * format, des en-têtes et des codes HTTP.
 */

declare(strict_types=1);

function json(array $donnees, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    // Une réponse d'API ne doit jamais être mise en cache : elle dépend de
    // qui est connecté.
    header('Cache-Control: no-store');
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function erreur(string $message, int $code = 400, array $extra = []): never
{
    json(array_merge(['erreur' => $message], $extra), $code);
}

/** Corps de la requête, qu'il arrive en JSON ou en formulaire classique. */
function corps(): array
{
    static $corps = null;
    if ($corps !== null) {
        return $corps;
    }

    $brut = file_get_contents('php://input') ?: '';
    $type = $_SERVER['CONTENT_TYPE'] ?? '';

    if (str_contains($type, 'application/json')) {
        $decode = json_decode($brut, true);
        $corps = is_array($decode) ? $decode : [];
    } elseif ($brut !== '') {
        parse_str($brut, $parse);
        $corps = $parse;
    } else {
        $corps = $_POST;
    }

    return $corps;
}

/**
 * Valeur envoyee, qu'elle vienne du corps de la requete ou de l'adresse.
 *
 * Sans le repli sur $_GET, un filtre passe en ?type=stage etait ignore et
 * la page recevait tout le catalogue.
 */
function champ(string $nom, string $defaut = ''): string
{
    $v = corps()[$nom] ?? ($_GET[$nom] ?? $defaut);
    return is_scalar($v) ? trim((string) $v) : $defaut;
}

function champEntier(string $nom, int $defaut = 0): int
{
    $v = corps()[$nom] ?? ($_GET[$nom] ?? $defaut);
    return is_numeric($v) ? (int) $v : $defaut;
}

/** Exige une méthode HTTP précise ; refuse proprement sinon. */
function exigeMethode(string $methode): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $methode) {
        erreur('Méthode non autorisée.', 405);
    }
}
