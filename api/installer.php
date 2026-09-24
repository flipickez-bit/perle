<?php
/**
 * Installation — à lancer une seule fois, puis à supprimer.
 *
 * Crée les tables et le compte administrateur. Refuse de s'exécuter si un
 * administrateur existe déjà : sans ce verrou, quiconque trouverait
 * l'adresse de ce fichier pourrait se créer un accès complet.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

// Sans configuration, on le dit clairement plutot que de planter.
if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(503);
    exit("Le site n'est pas encore configure : api/config.php est absent.");
}
require __DIR__ . '/config.php';
require __DIR__ . '/lib/reponse.php';
require __DIR__ . '/lib/bdd.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/credits.php';

header('Content-Type: text/html; charset=utf-8');

// Verrou : sans le jeton defini dans config.php, cette page ne fait rien.
// C'est ce qui empeche un inconnu de creer l'administration a votre place
// entre le moment ou la configuration existe et celui ou vous installez.
$jeton = (string) (config()['jeton_installation'] ?? '');
$fourni = (string) ($_GET['jeton'] ?? $_POST['jeton'] ?? '');
if ($jeton === '' || !hash_equals($jeton, $fourni)) {
    http_response_code(404);
    exit('Introuvable.');
}

$message = '';
$termine = false;

try {
    creerTables();
    $adminExiste = (int) bdd()->query("SELECT COUNT(*) FROM clientes WHERE role = 'admin'")->fetchColumn() > 0;
} catch (Throwable $e) {
    echo '<p style="font-family:system-ui;padding:2rem">La base de données est injoignable. '
       . 'Vérifiez les identifiants dans <code>api/config.php</code>.</p>';
    error_log('[perle][install] ' . $e->getMessage());
    exit;
}

if ($adminExiste) {
    $termine = true;
    $message = "L'installation est déjà faite. <strong>Supprimez ce fichier</strong> (api/installer.php).";
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email  = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $mdp    = (string) ($_POST['motdepasse'] ?? '');
    $prenom = trim((string) ($_POST['prenom'] ?? ''));

    if (!emailValide($email)) {
        $message = 'Adresse email invalide.';
    } elseif ($m = motDePasseValide($mdp)) {
        $message = $m;
    } elseif ($prenom === '') {
        $message = 'Indiquez un prénom.';
    } else {
        $st = bdd()->prepare(
            'INSERT INTO clientes (email, motdepasse_hash, prenom, role, actif, cree_le)
             VALUES (?, ?, ?, \'admin\', 1, ?)'
        );
        $st->execute([$email, password_hash($mdp, PASSWORD_DEFAULT), $prenom, maintenant()]);
        $termine = true;
        $message = "Compte administrateur créé. <strong>Supprimez maintenant ce fichier</strong> "
                 . "(api/installer.php), puis connectez-vous.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Installation — Perle</title>
<style>
  body { font-family: system-ui, sans-serif; background:#FDF6EE; color:#2C2C2A;
         display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:20px; }
  .boite { max-width:440px; width:100%; background:#fff; border:1px solid rgba(75,46,32,.14);
           border-radius:18px; padding:30px 28px; }
  h1 { font-size:1.3rem; color:#4B2E20; margin:0 0 6px; }
  p.sous { margin:0 0 22px; font-size:.9rem; opacity:.7; }
  label { display:block; font-size:.76rem; text-transform:uppercase; letter-spacing:.08em;
          font-weight:600; color:#4B2E20; margin-bottom:5px; }
  input { width:100%; box-sizing:border-box; padding:11px 13px; margin-bottom:16px;
          border:1px solid rgba(75,46,32,.2); border-radius:10px; font-size:.95rem; }
  button { width:100%; padding:13px; background:#4B2E20; color:#FDF6EE; border:none;
           border-radius:999px; font-size:.9rem; letter-spacing:.06em; text-transform:uppercase; cursor:pointer; }
  .msg { padding:12px 14px; border-radius:10px; background:rgba(248,193,204,.35);
         font-size:.88rem; margin-bottom:18px; line-height:1.5; }
</style>
</head>
<body>
  <div class="boite">
    <h1>Installation de Perle</h1>
    <p class="sous">Création des tables et du compte administrateur.</p>
    <?php if ($message !== ''): ?><div class="msg"><?= $message ?></div><?php endif; ?>
    <?php if (!$termine): ?>
    <form method="post">
      <input type="hidden" name="jeton" value="<?= htmlspecialchars($fourni, ENT_QUOTES) ?>">
      <label for="prenom">Prénom</label>
      <input id="prenom" name="prenom" required value="<?= htmlspecialchars((string) ($_POST['prenom'] ?? ''), ENT_QUOTES) ?>">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" required value="<?= htmlspecialchars((string) ($_POST['email'] ?? ''), ENT_QUOTES) ?>">
      <label for="motdepasse">Mot de passe (8 caractères minimum)</label>
      <input id="motdepasse" name="motdepasse" type="password" required autocomplete="new-password">
      <button type="submit">Créer l'administration</button>
    </form>
    <?php endif; ?>
  </div>
</body>
</html>
