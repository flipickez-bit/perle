<?php
/**
 * Installation et dépannage.
 *
 * Crée les tables, puis le compte administrateur. Une fois celui-ci créé,
 * la page sert à redéfinir son mot de passe — utile si Perle l'a oublié,
 * puisqu'il n'existe aucune autre porte d'entrée.
 *
 * Supprimer ce fichier ne suffit pas à le neutraliser : il est suivi par
 * Git, donc le déploiement suivant le remet en place. Pour le fermer
 * définitivement, il faut vider `jeton_installation` dans config.ini.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

// La configuration se lit dans config.ini, un fichier texte simple : une
// ligne mal ecrite y est sans consequence, la ou un config.php casse rend
// tout le site inaccessible. config.php reste accepte pour ne pas rompre
// une installation existante.
if (is_file(__DIR__ . '/config.ini')) {
    require __DIR__ . '/lib/configuration.php';
} elseif (is_file(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
} else {
    http_response_code(503);
    exit("Le site n'est pas encore configure : api/config.ini est absent.");
}
require __DIR__ . '/lib/reponse.php';
require __DIR__ . '/lib/bdd.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/credits.php';

header('Content-Type: text/html; charset=utf-8');

// Verrou : sans le jeton defini dans config.ini, cette page se comporte
// comme si elle n'existait pas. Vider le jeton la ferme pour de bon.
$jeton = (string) (config()['jeton_installation'] ?? '');
$fourni = (string) ($_GET['jeton'] ?? $_POST['jeton'] ?? '');
if ($jeton === '' || !hash_equals($jeton, $fourni)) {
    http_response_code(404);
    exit('Introuvable.');
}

$message = '';
$succes  = false;

try {
    creerTables();
    $admins = bdd()->query("SELECT id, email, prenom FROM clientes WHERE role = 'admin' ORDER BY id ASC")->fetchAll();
} catch (Throwable $e) {
    $brut = $e->getMessage();
    $c = config();
    if (str_contains($brut, '1045')) {
        $mdp = (string) $c['bdd_motdepasse'];
        $n = strlen($mdp);
        $suspect = strpbrk($mdp, "'\"\\") !== false;
        $cause = "L'utilisateur ou le mot de passe est refuse par MySQL. "
               . "Verifiez que l'utilisateur est bien rattache a la base dans hPanel."
               . "<br><br>Le mot de passe lu dans le fichier fait <strong>" . $n . " caractere(s)</strong>."
               . ($n === 0 ? " Il est vide : la ligne n'a pas ete enregistree." : "")
               . ($suspect ? " Il contient une apostrophe, un guillemet ou un antislash." : "");
    } elseif (str_contains($brut, '1049')) {
        $cause = "La base <code>" . htmlspecialchars((string) $c['bdd_nom'], ENT_QUOTES) . "</code> n'existe pas.";
    } else {
        $sansMdp = $c['bdd_motdepasse'] !== ''
            ? str_replace((string) $c['bdd_motdepasse'], '[masque]', $brut)
            : $brut;
        $cause = 'Message de MySQL : <code>' . htmlspecialchars($sansMdp, ENT_QUOTES) . '</code>';
    }
    echo '<div style="font-family:system-ui;max-width:560px;margin:3rem auto;padding:1.5rem;'
       . 'background:#fff;border-radius:16px;line-height:1.6">'
       . '<h2 style="color:#4B2E20;margin:0 0 .6rem">Connexion a la base impossible</h2>'
       . '<p>' . $cause . '</p></div>';
    error_log('[perle][install] ' . $brut);
    exit;
}

$existe = count($admins) > 0;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email  = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $mdp    = (string) ($_POST['motdepasse'] ?? '');
    $prenom = trim((string) ($_POST['prenom'] ?? ''));

    if (!emailValide($email)) {
        $message = 'Adresse email invalide.';
    } elseif ($m = motDePasseValide($mdp)) {
        $message = $m;
    } elseif (!$existe && $prenom === '') {
        $message = 'Indiquez un prénom.';
    } else {
        $hash = password_hash($mdp, PASSWORD_DEFAULT);
        $st = bdd()->prepare('SELECT id FROM clientes WHERE email = ?');
        $st->execute([$email]);
        $trouve = $st->fetch();

        if ($trouve) {
            // Le compte existe : on redéfinit son mot de passe et on s'assure
            // qu'il est bien administrateur et actif.
            bdd()->prepare("UPDATE clientes SET motdepasse_hash = ?, role = 'admin', actif = 1 WHERE id = ?")
                 ->execute([$hash, $trouve['id']]);
            $message = 'Mot de passe redéfini. Vous pouvez vous connecter.';
        } else {
            bdd()->prepare(
                "INSERT INTO clientes (email, motdepasse_hash, prenom, role, actif, cree_le)
                 VALUES (?, ?, ?, 'admin', 1, ?)"
            )->execute([$email, $hash, $prenom !== '' ? $prenom : 'Perle', maintenant()]);
            $message = 'Compte administrateur créé. Vous pouvez vous connecter.';
        }
        $succes = true;
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
  .boite { max-width:460px; width:100%; background:#fff; border:1px solid rgba(75,46,32,.14);
           border-radius:18px; padding:30px 28px; }
  h1 { font-size:1.3rem; color:#4B2E20; margin:0 0 6px; }
  p.sous { margin:0 0 22px; font-size:.9rem; opacity:.7; line-height:1.5; }
  label { display:block; font-size:.74rem; text-transform:uppercase; letter-spacing:.08em;
          font-weight:600; color:#4B2E20; margin-bottom:5px; }
  input { width:100%; box-sizing:border-box; padding:11px 13px; margin-bottom:16px;
          border:1px solid rgba(75,46,32,.2); border-radius:10px; font-size:.95rem; }
  button { width:100%; padding:13px; background:#4B2E20; color:#FDF6EE; border:none;
           border-radius:999px; font-size:.85rem; letter-spacing:.06em; text-transform:uppercase;
           font-weight:600; cursor:pointer; }
  .msg { padding:12px 14px; border-radius:10px; background:rgba(248,193,204,.35);
         font-size:.88rem; margin-bottom:18px; line-height:1.5; }
  .msg.ok { background:rgba(230,215,184,.5); }
  .apres { margin-top:20px; font-size:.82rem; opacity:.75; line-height:1.55;
           border-top:1px solid rgba(75,46,32,.12); padding-top:16px; }
  code { background:rgba(75,46,32,.07); padding:1px 5px; border-radius:4px; font-size:.9em; }
  .comptes { font-size:.82rem; opacity:.7; margin:-10px 0 18px; }
  a { color:#4B2E20; }
</style>
</head>
<body>
  <div class="boite">
    <h1><?= $existe ? 'Reprendre la main' : 'Installation de Perle' ?></h1>
    <p class="sous">
      <?= $existe
        ? "Un compte administrateur existe deja. Saisissez son adresse et un nouveau mot de passe pour le redefinir."
        : "Creation des tables et du compte administrateur." ?>
    </p>

    <?php if ($existe && !$succes): ?>
      <p class="comptes">Compte enregistre :
        <?php foreach ($admins as $a): ?>
          <code><?= htmlspecialchars($a['email'], ENT_QUOTES) ?></code>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
      <div class="msg<?= $succes ? ' ok' : '' ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <?php if (!$succes): ?>
    <form method="post">
      <input type="hidden" name="jeton" value="<?= htmlspecialchars($fourni, ENT_QUOTES) ?>">
      <?php if (!$existe): ?>
        <label for="prenom">Prenom</label>
        <input id="prenom" name="prenom" required>
      <?php endif; ?>
      <label for="email">Email</label>
      <input id="email" name="email" type="email" required
             value="<?= htmlspecialchars((string) ($_POST['email'] ?? ($admins[0]['email'] ?? '')), ENT_QUOTES) ?>">
      <label for="motdepasse">Nouveau mot de passe (8 caracteres minimum)</label>
      <input id="motdepasse" name="motdepasse" type="password" required autocomplete="new-password">
      <button type="submit"><?= $existe ? 'Redefinir le mot de passe' : "Creer l'administration" ?></button>
    </form>
    <?php else: ?>
      <p><a href="/admin/">Aller a l'administration &rarr;</a></p>
    <?php endif; ?>

    <p class="apres">
      <strong>Quand vous avez termine :</strong> ouvrez <code>api/config.ini</code> et laissez la ligne
      <code>jeton_installation&nbsp;=</code> vide. Cette page deviendra alors definitivement
      inaccessible. Supprimer le fichier ne suffit pas : le deploiement le remet en place.
    </p>
  </div>
</body>
</html>
