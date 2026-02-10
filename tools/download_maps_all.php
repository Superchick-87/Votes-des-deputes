<?php
// tools/download_maps_all.php
// SCRIPT ULTIME pour télécharger TOUTES les cartes
// Gère les standards, les DOM-TOM et les problèmes d'encodage (Côte d'Or, Réunion) en une seule passe.

// 1. Configuration
set_time_limit(600); // 10 minutes max
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Chemin cible : On remonte d'un cran (dirname) pour aller dans images/maps
$targetDir = dirname(__DIR__) . '/images/maps/';

// Création du dossier si inexistant
if (!file_exists($targetDir)) {
    if (!mkdir($targetDir, 0777, true)) {
        die("<div style='color:red'>❌ Erreur critique : Impossible de créer le dossier $targetDir. Vérifiez les permissions.</div>");
    }
}

// 2. Configuration du "User-Agent" (Pour ne pas être bloqué par Wikipédia)
$options = [
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: MonSiteEducatif/1.0 (contact@monsite.fr)\r\n"
    ]
];
$context = stream_context_create($options);

// 3. Liste complète des départements
$depts = [
    '01' => 'Ain', '02' => 'Aisne', '03' => 'Allier', '04' => 'Alpes-de-Haute-Provence', '05' => 'Hautes-Alpes',
    '06' => 'Alpes-Maritimes', '07' => 'Ardèche', '08' => 'Ardennes', '09' => 'Ariège', '10' => 'Aube',
    '11' => 'Aude', '12' => 'Aveyron', '13' => 'Bouches-du-Rhône', '14' => 'Calvados', '15' => 'Cantal',
    '16' => 'Charente', '17' => 'Charente-Maritime', '18' => 'Cher', '19' => 'Corrèze', '2A' => 'Corse-du-Sud',
    '2B' => 'Haute-Corse', '21' => 'Côte-d\'Or', '22' => 'Côtes-d\'Armor', '23' => 'Creuse', '24' => 'Dordogne',
    '25' => 'Doubs', '26' => 'Drôme', '27' => 'Eure', '28' => 'Eure-et-Loir', '29' => 'Finistère',
    '30' => 'Gard', '31' => 'Haute-Garonne', '32' => 'Gers', '33' => 'Gironde', '34' => 'Hérault',
    '35' => 'Ille-et-Vilaine', '36' => 'Indre', '37' => 'Indre-et-Loire', '38' => 'Isère', '39' => 'Jura',
    '40' => 'Landes', '41' => 'Loir-et-Cher', '42' => 'Loire', '43' => 'Haute-Loire', '44' => 'Loire-Atlantique',
    '45' => 'Loiret', '46' => 'Lot', '47' => 'Lot-et-Garonne', '48' => 'Lozère', '49' => 'Maine-et-Loire',
    '50' => 'Manche', '51' => 'Marne', '52' => 'Haute-Marne', '53' => 'Mayenne', '54' => 'Meurthe-et-Moselle',
    '55' => 'Meuse', '56' => 'Morbihan', '57' => 'Moselle', '58' => 'Nièvre', '59' => 'Nord',
    '60' => 'Oise', '61' => 'Orne', '62' => 'Pas-de-Calais', '63' => 'Puy-de-Dôme', '64' => 'Pyrénées-Atlantiques',
    '65' => 'Hautes-Pyrénées', '66' => 'Pyrénées-Orientales', '67' => 'Bas-Rhin', '68' => 'Haut-Rhin', '69' => 'Rhône',
    '70' => 'Haute-Saône', '71' => 'Saône-et-Loire', '72' => 'Sarthe', '73' => 'Savoie', '74' => 'Haute-Savoie',
    '75' => 'Paris', '76' => 'Seine-Maritime', '77' => 'Seine-et-Marne', '78' => 'Yvelines', '79' => 'Deux-Sèvres',
    '80' => 'Somme', '81' => 'Tarn', '82' => 'Tarn-et-Garonne', '83' => 'Var', '84' => 'Vaucluse',
    '85' => 'Vendée', '86' => 'Vienne', '87' => 'Haute-Vienne', '88' => 'Vosges', '89' => 'Yonne',
    '90' => 'Territoire de Belfort', '91' => 'Essonne', '92' => 'Hauts-de-Seine', '93' => 'Seine-Saint-Denis',
    '94' => 'Val-de-Marne', '95' => 'Val-d\'Oise',
    '971' => 'Guadeloupe', '972' => 'Martinique', '973' => 'Guyane', '974' => 'La Réunion', '976' => 'Mayotte'
];

// 4. Liste des EXCEPTIONS et CORRECTIFS ENCODÉS
// On met ici directement les URLs encodées pour éviter tout problème PHP/Serveur avec les accents/apostrophes
$overrides = [
    // DOM-TOM (Noms spécifiques "in_France")
    '971' => 'Guadeloupe_in_France.svg',
    '972' => 'Martinique_in_France.svg',
    '973' => 'French_Guiana_in_France.svg',
    '976' => 'Mayotte_in_France.svg',

    // -- CORRECTIFS ENCODÉS (Les "tricky" qui échouaient) --
    // Côte d'Or (encodé)
    '21'  => 'C%C3%B4te-d%27Or-Position.svg',
    // Côtes d'Armor (encodé par sécurité)
    '22'  => 'C%C3%B4tes-d%27Armor-Position.svg',
    // La Réunion (encodé)
    '974' => 'R%C3%A9union_in_France.svg',
    // Val d'Oise (encodé par sécurité)
    '95'  => 'Val-d%27Oise-Position.svg',
    // Territoire de Belfort (encodé pour les underscores)
    '90'  => 'Territoire_de_Belfort-Position.svg'
];

echo "<h2>🚀 Démarrage du téléchargement global...</h2>";
echo "<p>Cible : <code>$targetDir</code></p>";
echo "<div style='font-family: monospace; background:#f4f4f4; padding:15px; border:1px solid #ddd; height:400px; overflow-y:scroll;'>";

foreach ($depts as $code => $nom) {
    
    // Initialisation
    $url = "";
    
    if (isset($overrides[$code])) {
        // C'est un cas spécial
        $filename = $overrides[$code];
        
        // Si le nom contient déjà des "%", c'est qu'il est pré-encodé (notre correctif)
        if (strpos($filename, '%') !== false) {
            $url = "https://commons.wikimedia.org/wiki/Special:FilePath/" . $filename;
        } else {
            // Sinon on l'encode normalement
            $url = "https://commons.wikimedia.org/wiki/Special:FilePath/" . urlencode($filename);
        }
    } else {
        // Cas standard : Nom-Position.svg
        $wikiFilename = str_replace(' ', '_', $nom) . "-Position.svg";
        $url = "https://commons.wikimedia.org/wiki/Special:FilePath/" . urlencode($wikiFilename);
    }

    // Chemin local
    $localFile = $targetDir . $code . ".svg";

    // Téléchargement
    $content = @file_get_contents($url, false, $context);

    if ($content && strlen($content) > 500) {
        file_put_contents($localFile, $content);
        echo "<div style='color:green'>✅ $code - $nom OK</div>";
    } else {
        echo "<div style='color:red; font-weight:bold;'>❌ ÉCHEC $code - $nom</div>";
        echo "<div style='font-size:0.8em; color:#666'>URL : $url</div>";
    }

    // Petite pause
    usleep(150000); 
    flush(); 
}

echo "</div>";
echo "<h3>✨ Opération terminée !</h3>";
?>