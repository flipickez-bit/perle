<?php
/** Inscription, connexion, déconnexion et profil. */

declare(strict_types=1);

function profilPublic(array $c): array
{
    $id = (int) $c['id'];
    return [
        'id'        => $id,
        'email'     => $c['email'],
        'prenom'    => $c['prenom'],
        'nom'       => $c['nom'],
        'telephone' => $c['telephone'],
        'admin'     => $c['role'] === 'admin',
        'credits'   => solde($id),
        'expiration' => prochaineExpiration($id),
    ];
}

/**
 * Limite les tentatives de connexion sur une même adresse.
 *
 * Sans cela, un robot peut essayer des milliers de mots de passe. On
 * compte les échecs en session : c'est sommaire mais suffisant à cette
 * échelle, et sans table supplémentaire.
 */
function tropDeTentatives(): bool
{
    demarrerSession();
    $e = $_SESSION['echecs'] ?? ['n' => 0, 'jusqu' => 0];
    if ($e['n'] >= 5 && time() < $e['jusqu']) {
        return true;
    }
    if (time() >= ($e['jusqu'] ?? 0)) {
        $_SESSION['echecs'] = ['n' => 0, 'jusqu' => 0];
    }
    return false;
}

function noterEchec(): void
{
    demarrerSession();
    $e = $_SESSION['echecs'] ?? ['n' => 0, 'jusqu' => 0];
    $e['n']++;
    if ($e['n'] >= 5) {
        $e['jusqu'] = time() + 900; // un quart d'heure
    }
    $_SESSION['echecs'] = $e;
}

function routerComptes(string $chemin): void
{
    switch ($chemin) {
        case '/inscription':
            exigeMethode('POST');
            $email  = mb_strtolower(champ('email'));
            $mdp    = (string) (corps()['motdepasse'] ?? '');
            $prenom = champ('prenom');
            $nom    = champ('nom');
            $tel    = champ('telephone');

            if ($prenom === '')        erreur('Indiquez votre prénom.');
            if (!emailValide($email))  erreur('Adresse email invalide.');
            if ($m = motDePasseValide($mdp)) erreur($m);

            $st = bdd()->prepare('SELECT id FROM clientes WHERE email = ?');
            $st->execute([$email]);
            if ($st->fetch()) {
                erreur('Un compte existe déjà avec cette adresse.', 409);
            }

            $st = bdd()->prepare(
                'INSERT INTO clientes (email, motdepasse_hash, prenom, nom, telephone, role, actif, cree_le)
                 VALUES (?, ?, ?, ?, ?, \'cliente\', 1, ?)'
            );
            $st->execute([$email, password_hash($mdp, PASSWORD_DEFAULT), $prenom, $nom ?: null, $tel ?: null, maintenant()]);
            $id = (int) bdd()->lastInsertId();

            ouvrirSession($id);
            $st = bdd()->prepare('SELECT * FROM clientes WHERE id = ?');
            $st->execute([$id]);
            json(['compte' => profilPublic($st->fetch())], 201);

        case '/connexion':
            exigeMethode('POST');
            if (tropDeTentatives()) {
                erreur('Trop de tentatives. Réessayez dans un quart d\'heure.', 429);
            }
            $email = mb_strtolower(champ('email'));
            $mdp   = (string) (corps()['motdepasse'] ?? '');

            $st = bdd()->prepare('SELECT * FROM clientes WHERE email = ?');
            $st->execute([$email]);
            $c = $st->fetch();

            // Message identique que l'email existe ou non : révéler lequel des
            // deux est faux permettrait de deviner qui a un compte.
            if (!$c || !password_verify($mdp, $c['motdepasse_hash'])) {
                noterEchec();
                erreur('Email ou mot de passe incorrect.', 401);
            }
            if ((int) $c['actif'] !== 1) {
                erreur('Ce compte est désactivé. Contactez-nous.', 403);
            }

            // Le coût du hachage augmente avec le temps : on remet à niveau
            // le mot de passe au passage, sans rien demander à la cliente.
            if (password_needs_rehash($c['motdepasse_hash'], PASSWORD_DEFAULT)) {
                $u = bdd()->prepare('UPDATE clientes SET motdepasse_hash = ? WHERE id = ?');
                $u->execute([password_hash($mdp, PASSWORD_DEFAULT), $c['id']]);
            }

            demarrerSession();
            $_SESSION['echecs'] = ['n' => 0, 'jusqu' => 0];
            ouvrirSession((int) $c['id']);
            json(['compte' => profilPublic($c)]);

        case '/deconnexion':
            exigeMethode('POST');
            fermerSession();
            json(['ok' => true]);

        case '/moi':
            $c = clienteConnectee();
            json(['compte' => $c ? profilPublic($c) : null]);

        case '/mot-de-passe':
            exigeMethode('POST');
            $c = exigeConnexion();
            $actuel  = (string) (corps()['actuel'] ?? '');
            $nouveau = (string) (corps()['nouveau'] ?? '');

            $st = bdd()->prepare('SELECT motdepasse_hash FROM clientes WHERE id = ?');
            $st->execute([$c['id']]);
            $hash = (string) $st->fetchColumn();

            if (!password_verify($actuel, $hash)) {
                erreur('Mot de passe actuel incorrect.', 403);
            }
            if ($m = motDePasseValide($nouveau)) {
                erreur($m);
            }
            $u = bdd()->prepare('UPDATE clientes SET motdepasse_hash = ? WHERE id = ?');
            $u->execute([password_hash($nouveau, PASSWORD_DEFAULT), $c['id']]);
            json(['ok' => true]);
    }
}
