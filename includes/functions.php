<?php
// functions.php

/**
 * Extrait la mention entre parenthèses, nettoie le titre,
 * met une majuscule au début et un point à la fin.
 */
function formaterTitreScrutin($titreOriginal) {
    $lecture = '';
    $titrePropre = $titreOriginal;

    // 1. Extraction de la partie entre parenthèses (lecture)
    if (preg_match('/ \(([^)]+)\)\.?$/', $titreOriginal, $matches)) {
        $lecture = ucfirst($matches[1]); 
        $titrePropre = str_replace($matches[0], '', $titreOriginal);
    }

    // 2. Nettoyage des espaces inutiles (début/fin)
    $titrePropre = trim($titrePropre);

    // 3. Majuscule à la première lettre (Support des accents avec mb_strtoupper)
    // On prend le 1er caractère en majuscule + le reste de la chaîne
    if (mb_strlen($titrePropre) > 0) {
        $firstChar = mb_substr($titrePropre, 0, 1, "UTF-8");
        $rest = mb_substr($titrePropre, 1, null, "UTF-8");
        $titrePropre = mb_strtoupper($firstChar, "UTF-8") . $rest;
    }

    // 4. Ajout du point final s'il n'existe pas déjà
    if (substr($titrePropre, -1) !== '.') {
        $titrePropre .= '.';
    }

    return [
        'titre' => $titrePropre,
        'lecture' => $lecture
    ];
}

/**
 * Retourne la configuration complète des mois.
 * Vous pouvez modifier le texte ici selon vos préférences.
 */
function getDefinitionMois() {
    return [
        1  => ['complet' => 'Janvier',   'abbr' => 'Jan.'],
        2  => ['complet' => 'Février',   'abbr' => 'Fév.'],
        3  => ['complet' => 'Mars',      'abbr' => 'Mars'],
        4  => ['complet' => 'Avril',     'abbr' => 'Avr.'],
        5  => ['complet' => 'Mai',       'abbr' => 'Mai'],
        6  => ['complet' => 'Juin',      'abbr' => 'Juin'],
        7  => ['complet' => 'Juillet',   'abbr' => 'Juil.'],
        8  => ['complet' => 'Août',      'abbr' => 'Août'], // Pas de point, car court
        9  => ['complet' => 'Septembre', 'abbr' => 'Sept.'],
        10 => ['complet' => 'Octobre',   'abbr' => 'Oct.'],
        11 => ['complet' => 'Novembre',  'abbr' => 'Nov.'],
        12 => ['complet' => 'Décembre',  'abbr' => 'Déc.']
    ];
}

/**
 * Helper : Retourne juste la liste des noms complets (pour compatibilité)
 */
function getListeMois() {
    $mois = getDefinitionMois();
    return array_map(function($m) { return $m['complet']; }, $mois);
}

/**
 * Retourne la classe CSS 'el-inactif' si le député n'est plus en mandat.
 * Centralise la logique visuelle pour les députés sortants.
 *
 * @param int|string|null $estActif La valeur de la colonne est_actif (0 ou 1)
 * @return string
 */
function getClasseDeputeInactif($estActif) {
    // On vérifie si la variable est définie et si elle vaut strictement 0
    if (isset($estActif) && $estActif == 0) {
        return 'el-inactif';
    }
    return '';
}

/**
 * Fonction de comparaison pour trier alphabétiquement en ignorant les accents.
 * À utiliser avec uasort().
 * Ex: uasort($monTableau, 'compareFrancais');
 */
function compareFrancais($a, $b) {
    // 1. Si l'extension Intl est activée (Méthode Pro)
    if (class_exists('Collator')) {
        $collator = new Collator('fr_FR');
        return $collator->compare($a, $b);
    }

    // 2. Méthode Manuelle (Fallback robuste)
    // On nettoie les accents fréquents pour comparer "Ecolo" et "Écolo" comme identiques
    $cleanA = str_replace(['É', 'È', 'Ê', 'À', 'Â', 'Ô', 'Î', 'Ï', 'Û'], ['E', 'E', 'E', 'A', 'A', 'O', 'I', 'I', 'U'], mb_strtoupper($a, 'UTF-8'));
    $cleanB = str_replace(['É', 'È', 'Ê', 'À', 'Â', 'Ô', 'Î', 'Ï', 'Û'], ['E', 'E', 'E', 'A', 'A', 'O', 'I', 'I', 'U'], mb_strtoupper($b, 'UTF-8'));

    return strcmp($cleanA, $cleanB);
}
?>