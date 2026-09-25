<?php
/**
 * Connexion à la base et création des tables.
 *
 * Le même code tourne sur MySQL (Hostinger, en production) et sur SQLite
 * (en local, pour développer et tester sans installer de serveur). Les
 * seules différences entre les deux sont isolées ici : partout ailleurs le
 * SQL est standard.
 */

declare(strict_types=1);

function bdd(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $c = config();

    if ($c['bdd_type'] === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['bdd_fichier']);
        // Sans cela SQLite ignore silencieusement les clés étrangères.
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $c['bdd_hote'],
            $c['bdd_port'],
            $c['bdd_nom']
        );
        $pdo = new PDO($dsn, $c['bdd_utilisateur'], $c['bdd_motdepasse']);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Les entiers doivent revenir comme entiers, pas comme chaînes.
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    return $pdo;
}

function estSqlite(): bool
{
    return config()['bdd_type'] === 'sqlite';
}

/** Clé primaire auto-incrémentée, écrite différemment selon le moteur. */
function cle(): string
{
    return estSqlite()
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
}

/**
 * Colonne qui référence une clé primaire.
 *
 * MySQL exige un type strictement identique à celui de la colonne visée,
 * signe compris : un INT signé pointant vers un INT UNSIGNED fait échouer
 * la création de la table avec « Foreign key constraint is incorrectly
 * formed ». SQLite ne verifie pas ce point, d'ou un bug invisible en test
 * local et bloquant en production.
 */
function ref(): string
{
    return estSqlite() ? 'INTEGER' : 'INT UNSIGNED';
}


/**
 * Crée un index s'il n'existe pas déjà.
 *
 * SQLite accepte CREATE INDEX IF NOT EXISTS, MySQL non : la requête y
 * echoue purement et simplement. On tente donc la création et on ignore la
 * seule erreur acceptable, celle de l'index déjà présent.
 */
function creerIndex(PDO $db, string $nom, string $table, string $colonnes, bool $unique = false): void
{
    $sql = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX $nom ON $table $colonnes";
    try {
        if (estSqlite()) {
            $sql = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX IF NOT EXISTS $nom ON $table $colonnes";
        }
        $db->exec($sql);
    } catch (PDOException $e) {
        // 1061 = index deja existant ; toute autre erreur doit remonter.
        if (!str_contains($e->getMessage(), '1061')) {
            throw $e;
        }
    }
}

/**
 * Crée les tables si elles n'existent pas.
 *
 * Les dates sont stockées en texte ISO 8601 (2026-10-03T07:30:00) : c'est
 * lisible, trié correctement par ordre alphabétique, et identique sur les
 * deux moteurs. Les montants sont en centimes, jamais en décimal, pour
 * éviter les erreurs d'arrondi sur l'argent.
 */
function creerTables(): void
{
    $db = bdd();
    $suffixe = estSqlite() ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $k = cle();

    $db->exec("CREATE TABLE IF NOT EXISTS clientes (
        id              $k,
        email           VARCHAR(190) NOT NULL UNIQUE,
        motdepasse_hash VARCHAR(255) NOT NULL,
        prenom          VARCHAR(80)  NOT NULL,
        nom             VARCHAR(80)  NULL,
        telephone       VARCHAR(30)  NULL,
        role            VARCHAR(10)  NOT NULL DEFAULT 'cliente',
        actif           INT          NOT NULL DEFAULT 1,
        cree_le         VARCHAR(25)  NOT NULL
    )$suffixe");

    // Un pack acheté = un lot, avec sa propre date d'expiration. On consomme
    // toujours le lot qui expire le plus tôt, sinon des crédits périment
    // pendant que d'autres, plus récents, sont utilisés à leur place.
    $db->exec("CREATE TABLE IF NOT EXISTS lots_credits (
        id          $k,
        cliente_id  " . ref() . " NOT NULL,
        credits     INT NOT NULL,
        restants    INT NOT NULL,
        achete_le   VARCHAR(25) NOT NULL,
        expire_le   VARCHAR(25) NULL,
        origine     VARCHAR(60) NOT NULL,
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
    )$suffixe");

    $db->exec("CREATE TABLE IF NOT EXISTS seances (
        id            $k,
        type          VARCHAR(10)  NOT NULL DEFAULT 'cours',
        titre         VARCHAR(160) NOT NULL,
        discipline    VARCHAR(60)  NOT NULL,
        description   TEXT         NULL,
        lieu          VARCHAR(160) NOT NULL,
        debut         VARCHAR(25)  NOT NULL,
        fin           VARCHAR(25)  NOT NULL,
        capacite      INT          NOT NULL,
        cout_credits  INT          NOT NULL DEFAULT 1,
        prix_centimes INT          NOT NULL DEFAULT 0,
        statut        VARCHAR(12)  NOT NULL DEFAULT 'brouillon',
        cree_le       VARCHAR(25)  NOT NULL
    )$suffixe");

    $db->exec("CREATE TABLE IF NOT EXISTS reservations (
        id             $k,
        seance_id      " . ref() . " NOT NULL,
        cliente_id     " . ref() . " NOT NULL,
        statut         VARCHAR(12) NOT NULL DEFAULT 'confirmee',
        credits_utilises INT NOT NULL DEFAULT 0,
        montant_centimes INT NOT NULL DEFAULT 0,
        cree_le        VARCHAR(25) NOT NULL,
        FOREIGN KEY (seance_id)  REFERENCES seances(id)  ON DELETE CASCADE,
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
    )$suffixe");

    // Une même cliente ne peut pas réserver deux fois la même séance.
    creerIndex($db, 'idx_resa_unique', 'reservations', '(seance_id, cliente_id)', true);

    // Historique de tout mouvement de crédit fait à la main. Sans lui, un
    // solde modifié ne s'explique plus si une cliente conteste.
    $db->exec("CREATE TABLE IF NOT EXISTS mouvements_credits (
        id          $k,
        cliente_id  " . ref() . " NOT NULL,
        delta       INT NOT NULL,
        motif       VARCHAR(255) NOT NULL,
        solde_apres INT NOT NULL,
        admin_id    " . ref() . " NULL,
        cree_le     VARCHAR(25) NOT NULL,
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
    )$suffixe");

    $db->exec("CREATE TABLE IF NOT EXISTS paiements (
        id               $k,
        cliente_id       " . ref() . " NOT NULL,
        fournisseur      VARCHAR(20) NOT NULL,
        reference        VARCHAR(190) NULL,
        objet            VARCHAR(30) NOT NULL,
        objet_id         VARCHAR(60) NULL,
        montant_centimes INT NOT NULL,
        statut           VARCHAR(15) NOT NULL DEFAULT 'en_attente',
        cree_le          VARCHAR(25) NOT NULL,
        regle_le         VARCHAR(25) NULL,
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
    )$suffixe");

    creerIndex($db, 'idx_seances_debut', 'seances', '(statut, debut)');
    creerIndex($db, 'idx_lots_cliente', 'lots_credits', '(cliente_id)');
}

/** Horodatage ISO, unique format employé dans toute la base. */
function maintenant(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:s');
}
