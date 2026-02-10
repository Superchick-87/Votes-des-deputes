<?php
require 'db.php';
require 'includes/functions.php';
ini_set('max_execution_time', 60);

// --- CHARGEMENT CONFIG & LÉGISLATURES ACTIVES ---
$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
$lesLegislaturesActives = array_values(array_filter($config['legislatures'], function ($l) {
    return isset($l['active']) && $l['active'] === true;
}));

// On définit la législature par défaut comme étant la première du JSON (souvent la plus récente)
$defaultLeg = !empty($lesLegislaturesActives) ? $lesLegislaturesActives[0]['id'] : '17';

// --- GESTION DES PARAMÈTRES ---
$leg = $_GET['leg'] ?? $defaultLeg;

// 1. RÉCUPÉRATION DES TOTAUX
$sqlCounts = "SELECT 
    COUNT(*) as all_scrutins,
    SUM(CASE WHEN type_scrutin = 'loi' THEN 1 ELSE 0 END) as nb_loi,
    SUM(CASE WHEN type_scrutin = 'amendement' THEN 1 ELSE 0 END) as nb_amendement,
    SUM(CASE WHEN type_scrutin = 'motion' THEN 1 ELSE 0 END) as nb_motion,
    SUM(CASE WHEN type_scrutin = 'autre' THEN 1 ELSE 0 END) as nb_autre
FROM scrutins WHERE legislature = ?";
$stmt = $pdo->prepare($sqlCounts);
$stmt->execute([$leg]);
$totals = $stmt->fetch(PDO::FETCH_ASSOC);

$totals['all_scrutins'] = $totals['all_scrutins'] ?: 1;
$totals['nb_loi'] = $totals['nb_loi'] ?: 1;
$totals['nb_amendement'] = $totals['nb_amendement'] ?: 1;
$totals['nb_motion'] = $totals['nb_motion'] ?: 1;
$totals['nb_autre'] = $totals['nb_autre'] ?: 1;

// 2. RÉCUPÉRATION DE TOUTES LES STATS
$sql = "SELECT 
            d.uid, d.nom, d.photo_url, d.groupe_uid, d.departement, d.circonscription, d.est_actif,
            COALESCE(g.libelle, 'Non inscrit') as groupe_nom, 
            COALESCE(g.couleur, '#888888') as couleur,
            st.nb_total, st.nb_loi, st.nb_amendement, st.nb_motion, st.nb_autre
        FROM deputes d
        INNER JOIN stats_deputes st ON d.uid = st.depute_uid AND st.legislature = ?
        LEFT JOIN groupes g ON d.groupe_uid = g.uid 
        ORDER BY st.nb_total DESC, d.nom ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$leg]);
$classement = $stmt->fetchAll();

// 3. PRÉPARATION DONNÉES
$listeDepts = [];
$listeGroupes = [];
$statsGroupes = [];
$sommeMoyenne = ['all' => 0, 'loi' => 0, 'amendement' => 0, 'motion' => 0, 'autre' => 0];
$nbDeputes = count($classement);

foreach ($classement as $c) {
    if (!empty($c->departement)) $listeDepts[$c->departement] = $c->departement;

    $nomGroupe = $c->groupe_nom ?? 'Non inscrit';
    $uidGroupe = $c->groupe_uid ?? 'NI';
    $cleanName = str_replace('Groupe ', '', $nomGroupe);
    $cleanName = trim(preg_replace('/\s*\(.*?\)/', '', $cleanName));
    if ($c->groupe_uid === 'NI' || $nomGroupe === 'Non inscrit') $cleanName = 'Non inscrit';

    $listeGroupes[$uidGroupe] = $cleanName;

    $sommeMoyenne['all'] += $c->nb_total;
    $sommeMoyenne['loi'] += $c->nb_loi;
    $sommeMoyenne['amendement'] += $c->nb_amendement;
    $sommeMoyenne['motion'] += $c->nb_motion;
    $sommeMoyenne['autre'] += $c->nb_autre;

    if (!isset($statsGroupes[$cleanName])) {
        $statsGroupes[$cleanName] = [
            'nom' => $cleanName,
            'couleur' => $c->couleur ?? '#888',
            'nb_deputes' => 0,
            'cumul' => ['all' => 0, 'loi' => 0, 'amendement' => 0, 'motion' => 0, 'autre' => 0]
        ];
    }
    $statsGroupes[$cleanName]['nb_deputes']++;
    $statsGroupes[$cleanName]['cumul']['all'] += $c->nb_total;
    $statsGroupes[$cleanName]['cumul']['loi'] += $c->nb_loi;
    $statsGroupes[$cleanName]['cumul']['amendement'] += $c->nb_amendement;
    $statsGroupes[$cleanName]['cumul']['motion'] += $c->nb_motion;
    $statsGroupes[$cleanName]['cumul']['autre'] += $c->nb_autre;
}

$groupesDataJS = [];
foreach ($statsGroupes as $nom => $data) {
    $nb = $data['nb_deputes'];
    $groupesDataJS[] = [
        'nom' => $nom,
        'couleur' => $data['couleur'],
        'nb_deputes' => $nb,
        'stats' => [
            'all' => round(($data['cumul']['all'] / $nb / $totals['all_scrutins']) * 100, 1),
            'loi' => round(($data['cumul']['loi'] / $nb / $totals['nb_loi']) * 100, 1),
            'amendement' => round(($data['cumul']['amendement'] / $nb / $totals['nb_amendement']) * 100, 1),
            'motion' => round(($data['cumul']['motion'] / $nb / $totals['nb_motion']) * 100, 1),
            'autre' => round(($data['cumul']['autre'] / $nb / $totals['nb_autre']) * 100, 1)
        ]
    ];
}

$globalAverages = [
    'all' => ($nbDeputes > 0) ? round(($sommeMoyenne['all'] / $nbDeputes / $totals['all_scrutins']) * 100, 1) : 0,
    'loi' => ($nbDeputes > 0) ? round(($sommeMoyenne['loi'] / $nbDeputes / $totals['nb_loi']) * 100, 1) : 0,
    'amendement' => ($nbDeputes > 0) ? round(($sommeMoyenne['amendement'] / $nbDeputes / $totals['nb_amendement']) * 100, 1) : 0,
    'motion' => ($nbDeputes > 0) ? round(($sommeMoyenne['motion'] / $nbDeputes / $totals['nb_motion']) * 100, 1) : 0,
    'autre' => ($nbDeputes > 0) ? round(($sommeMoyenne['autre'] / $nbDeputes / $totals['nb_autre']) * 100, 1) : 0,
];

asort($listeDepts);
uasort($listeGroupes, 'compareFrancais');
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Classement Assiduité - Législature <?= $leg ?></title>
    <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body>
    <div class="container">
        <header class="header-resum">
            <div class="header-classement">
                <a href="index.php" class="btn-back">← Retour Accueil</a>
                <h1>Classement par Assiduité</h1>
            </div>
            <?php
            // --- AJOUT : RÉCUPÉRATION LÉGISLATURES ACTIVES VIA JSON ---
            $configPath = __DIR__ . '/config.json';
            $lesLegislaturesActives = [];
            if (file_exists($configPath)) {
                $config = json_decode(file_get_contents($configPath), true);
                $lesLegislaturesActives = array_filter($config['legislatures'], function ($l) {
                    return isset($l['active']) && $l['active'] === true;
                });
            }
            ?>

            <select class="leg-selector" onchange="window.location.href='?leg='+this.value">
                <?php foreach ($lesLegislaturesActives as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= $leg == $l['id'] ? 'selected' : '' ?>>
                        <?= $l['id'] ?>ᵉ législature
                    </option>
                <?php endforeach; ?>
            </select>
        </header>

        <div class="stats-header">
            <div class="stat-card">
                <div id="label-total-type">Tous les Scrutins</div>
                <div class="stat-big" id="val-total-count"><?= $totals['all_scrutins'] ?></div>
            </div>
            <div class="stat-card">
                <div>Députés classés</div>
                <div class="stat-big"><?= $nbDeputes ?></div>
            </div>
            <div class="stat-card">
                <div>Moyenne Participation</div>
                <div class="stat-big" id="val-moyenne-global"><?= $globalAverages['all'] ?>%</div>
            </div>
        </div>

        <script>
            if (localStorage.getItem('chartState') === 'hidden') {
                document.write('<style>#groupes-chart-container { display: none; }</style>');
            }
        </script>
        <button id="btn-toggle-chart" class="btn-toggle-chart">Masquer le classement des groupes ▲</button>

        <div id="groupes-chart-container" class="chart-container">
            <h3 class="chart-title">Moyenne de participation par groupe</h3>
            <div id="chart-content"></div>
        </div>

        <div class="filters-bar">
            <select id="filter-type" class="type-selector" onchange="updateScrutinType(this.value)">
                <option value="all">📑 Tous les scrutins</option>
                <option value="loi" selected>📜 Projets de Loi</option>
                <option value="amendement">📝 Amendements</option>
                <option value="motion">🛑 Motions de Censure</option>
                <option value="autre">🔹 Autres votes</option>
            </select>

            <select id="filter-region" onchange="updateDeptsFromRegion()">
                <option value="all">🇫🇷 Régions (Toutes)</option>
                <option value="Auvergne-Rhône-Alpes">Auvergne-Rhône-Alpes</option>
                <option value="Bourgogne-Franche-Comté">Bourgogne-Franche-Comté</option>
                <option value="Bretagne">Bretagne</option>
                <option value="Centre-Val de Loire">Centre-Val de Loire</option>
                <option value="Corse">Corse</option>
                <option value="Grand Est">Grand Est</option>
                <option value="Hauts-de-France">Hauts-de-France</option>
                <option value="Île-de-France">Île-de-France</option>
                <option value="Normandie">Normandie</option>
                <option value="Nouvelle-Aquitaine">Nouvelle-Aquitaine</option>
                <option value="Occitanie">Occitanie</option>
                <option value="Pays de la Loire">Pays de la Loire</option>
                <option value="Provence-Alpes-Côte d'Azur">PACA</option>
                <option value="Outre-Mer">Outre-Mer</option>
            </select>

            <select id="filter-dept" onchange="appliquerFiltres()">
                <option value="all">🌍 Départements (Tous)</option>
                <?php foreach ($listeDepts as $dept): ?><option value="<?= htmlspecialchars($dept) ?>"><?= $dept ?></option><?php endforeach; ?>
            </select>

            <select id="filter-groupe" onchange="appliquerFiltres()">
                <option value="all">👥 Groupes (Tous)</option>
                <?php foreach ($listeGroupes as $uid => $nom): ?><option value="<?= $uid ?>"><?= $nom ?></option><?php endforeach; ?>
            </select>
            <div id="compteur-filtre"></div>
        </div>

        <div class="table-responsive">
            <table class="rank-table" id="table-classement">
                <thead>
                    <tr>
                        <th onclick="trierTableau('rang')" width="60"><span class="sort-arrow">▼</span></th>
                        <th onclick="trierTableau('nom')">Député <span class="sort-arrow">▲▼</span></th>
                        <th onclick="trierTableau('groupe')" class="hide-mobile">Groupe <span class="sort-arrow">▲▼</span></th>
                        <th onclick="trierTableau('perf')">Participation <span class="sort-arrow">▲▼</span></th>
                    </tr>
                </thead>
                <tbody id="table-body">
                    <?php
                    $rank = 1;
                    foreach ($classement as $c):
                        $photoUrl = str_replace('/17/', "/$leg/", $c->photo_url);
                        $rawName = $c->groupe_nom ?? 'Non inscrit';
                        $cleanName = str_replace('Groupe ', '', $rawName);
                        $cleanName = trim(preg_replace('/\s*\(.*?\)/', '', $cleanName));
                        if ($c->groupe_uid === 'NI' || $rawName === 'Non inscrit') $cleanName = 'Non inscrit';
                        $geoInfo = $c->departement . (!empty($c->circonscription) ? ' (' . $c->circonscription . ($c->circonscription == 1 ? 'ère' : 'e') . ' circo)' : '');
                        $classInactive = getClasseDeputeInactif($c->est_actif);
                    ?>
                        <tr class="depute-row <?= $classInactive ?>"
                            data-dept="<?= htmlspecialchars($c->departement ?? '') ?>"
                            data-groupe="<?= $c->groupe_uid ?? 'NI' ?>"
                            data-nom="<?= htmlspecialchars($c->nom ?? '') ?>"
                            data-groupe-nom="<?= htmlspecialchars($cleanName) ?>"
                            data-nb-all="<?= $c->nb_total ?>"
                            data-nb-loi="<?= $c->nb_loi ?>"
                            data-nb-amendement="<?= $c->nb_amendement ?>"
                            data-nb-motion="<?= $c->nb_motion ?>"
                            data-nb-autre="<?= $c->nb_autre ?>"

                            data-perf="0" data-rang="<?= $rank ?>">

                            <td class="rank-num"><?= $rank ?></td>
                            <td>
                                <div class="depute-cell">
                                    <img src="<?= $photoUrl ?>" class="avatar-mini" loading="lazy" onerror="if (this.src.includes('/16/')) { this.src = this.src.replace('/16/', '/15/'); } else if (this.src.includes('/17/')) { this.src = this.src.replace('/17/', '/16/'); } else { this.src = 'https://via.placeholder.com/40x50'; this.onerror = null; }">
                                    <div class="name-link"><?= $c->nom ?>
                                        <div style="font-size:0.8em; color:#666; font-weight: 500;"><?= $geoInfo ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="hide-mobile cell-groupe">
                                <?php
                                $logoPath = "img/logos/" . ($c->groupe_uid ?? 'NI') . ".png";
                                if (file_exists($logoPath)) {
                                    echo '<img src="' . $logoPath . '" class="grp-logo" alt="Logo">';
                                } else {
                                    echo '<span class="grp-pastille" style="background:' . ($c->couleur ?? '#888') . ';"></span>';
                                }
                                ?>
                                <span class="grp-nom" style="color:<?= $c->couleur ?? '#333' ?>;"><?= htmlspecialchars($cleanName) ?></span>
                            </td>
                            <td style="width: 30%;">
                                <div>
                                    <span class="score-text">0%</span>
                                    <span class="score-abs" style="font-size:0.8em; color:#888;">(0 votes)</span>
                                </div>
                                <div class="progress-bg">
                                    <div class="progress-bar" style="width: 0%; background: #ccc;"></div>
                                </div>
                            </td>
                        </tr>
                    <?php $rank++;
                    endforeach; ?>
                </tbody>
            </table>
        </div>
        <br><br>
    </div>
    <?php include 'includes/footer.php'; ?>
    <script>
        window.allDeptsGlobal = <?= json_encode(array_keys($listeDepts)) ?>;
        window.serverTotals = <?= json_encode($totals) ?>;
        window.serverAverages = <?= json_encode($globalAverages) ?>;
        window.groupStats = <?= json_encode($groupesDataJS) ?>;
    </script>
    <script src="js/common.js?v=<?= time() ?>"></script>
    <script src="js/classement.js?v=<?= time() ?>"></script>
</body>

</html>