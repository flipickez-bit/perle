<?php
/**
 * Ce qui peut être acheté.
 *
 * Les prix sont définis ici, côté serveur, et jamais lus depuis la page :
 * sinon n'importe qui pourrait modifier le montant envoyé et payer 1 € un
 * pack à 198 €. En centimes, pour éviter tout arrondi sur l'argent.
 */

declare(strict_types=1);

const PACKS = [
    'seance1' => ['libelle' => 'Séance à l\'unité', 'credits' => 1,  'centimes' => 3800,  'mois' => 1],
    'pack6'   => ['libelle' => 'Pack 6 séances',   'credits' => 6,  'centimes' => 19800, 'mois' => 2],
    'pack12'  => ['libelle' => 'Pack 12 séances',  'credits' => 12, 'centimes' => 34800, 'mois' => 4],
];

/** Part du prix d'un stage réglée en ligne quand le solde est payé sur place. */
const PART_ACOMPTE = 0.30;

function formule(string $cle): ?array
{
    return PACKS[$cle] ?? null;
}
