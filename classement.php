<?php
require 'db.php';

// 1. Récupérer le nombre total de scrutins
$stmt = $pdo->query("SELECT COUNT(*) FROM scrutins");
$totalScrutins = $stmt->fetchColumn();

// 2. Récupérer les données DES DÉPUTÉS ACTIFS UNIQUEMENT
// MODIFICATION : Ajout de "WHERE d.est_actif = 1"
$sql = "SELECT d.uid, d.nom, d.photo_url, d.groupe_uid, d.departement,
               COALESCE(g.libelle, 'Non inscrit') as groupe_nom, 
               COALESCE(g.couleur, '#888888') as couleur,
               COUNT(v.id) as participation
        FROM deputes d
        LEFT JOIN votes v ON d.uid = v.acteur_uid
        LEFT JOIN groupes g ON d.groupe_uid = g.uid
        WHERE d.est_actif = 1 
        GROUP BY d.uid
        ORDER BY participation DESC, d.nom ASC";

$stmt = $pdo->query($sql);
$classement = $stmt->fetchAll();

// Préparation des listes pour les filtres
$listeDepts = [];
$listeGroupes = [];
$totalVotesAssemblee = 0;

foreach($classement as $c) {
    if(!empty($c->departement)) $listeDepts[$c->departement] = $c->departement;
    
    // On sécurise le nom du groupe pour les listes
    $nomGroupe = $c->groupe_nom ?? 'Non inscrit';
    $uidGroupe = $c->groupe_uid ?? 'NI';
    $listeGroupes[$uidGroupe] = $nomGroupe;
    
    $totalVotesAssemblee += $c->participation;
}
asort($listeDepts);
asort($listeGroupes);

$moyenne = ($totalScrutins > 0 && count($classement) > 0) 
    ? round(($totalVotesAssemblee / count($classement) / $totalScrutins) * 100, 1) 
    : 0;
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Classement Assiduité</title>
    <link rel="stylesheet" href="css/style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        .stats-header {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px;
        }
        .stat-card {
            background: white; padding: 20px; border-radius: 8px; text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .stat-big { font-size: 2em; font-weight: bold; color: #2c3e50; }
        
        .rank-table {
            width: 100%; border-collapse: collapse; background: white;
            border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .rank-table th { 
            background: #f8f9fa; color: #666; text-align: left; padding: 15px; 
            cursor: pointer; user-select: none; /* Pour le clic */
            transition: background 0.2s;
        }
        .rank-table th:hover { background: #eee; color: #333; }
        
        .rank-table td { padding: 12px 15px; border-bottom: 1px solid #eee; vertical-align: middle; }
        .rank-table tr.hidden { display: none; }
        
        .rank-num { font-weight: bold; color: #888; font-size: 1.1em; width: 50px; text-align: center; }
        .rank-1 { color: #f1c40f; font-size: 1.5em; }
        .rank-2 { color: #95a5a6; font-size: 1.4em; }
        .rank-3 { color: #cd7f32; font-size: 1.3em; }

        .depute-cell { display: flex; align-items: center; gap: 15px; }
        .avatar-mini { width: 40px; height: 50px; object-fit: cover; border-radius: 4px; background: #eee; }
        .name-link { font-weight: bold; color: #333; text-decoration: none; }
        .progress-bg { width: 100%; background: #eee; height: 8px; border-radius: 4px; margin-top: 5px; overflow: hidden; }
        .progress-bar { height: 100%; background: #3498db; border-radius: 4px; }
        .score-text { font-weight: bold; color: #333; }
        
        /* STYLE DES FLECHES DE TRI */
        .sort-arrow { font-size: 0.8em; margin-left: 5px; opacity: 0.3; }
        .th-sort-asc .sort-arrow { opacity: 1; }
        .th-sort-desc .sort-arrow { opacity: 1; transform: rotate(180deg); display:inline-block; }

        @media (max-width: 700px) {
            .stats-header { grid-template-columns: 1fr; }
            .hide-mobile { display: none; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header style="display:flex; justify-content:space-between; align-items:center; margin-bottom:30px;">
            <div>
                <a href="index.php" class="btn-back">← Retour Accueil</a>
                <h1 style="margin:10px 0 0 0;">🏆 Classement par Assiduité</h1>
            </div>
            <a href="update.php" class="btn-update" style="display:none;">🔄 Mettre à jour</a>
        </header>

        <div class="stats-header">
            <div class="stat-card"><div>Total Scrutins</div><div class="stat-big"><?= $totalScrutins ?></div></div>
            <div class="stat-card"><div>Députés actifs</div><div class="stat-big"><?= count($classement) ?></div></div>
            <div class="stat-card"><div>Moyenne Participation</div><div class="stat-big"><?= $moyenne ?>%</div></div>
        </div>

        <div class="filters-bar">
            <select id="filter-region" onchange="updateDeptsFromRegion()"><option value="all">🇫🇷 Régions (Toutes)</option>
                <option value="Auvergne-Rhône-Alpes">Auvergne-Rhône-Alpes</option><option value="Bourgogne-Franche-Comté">Bourgogne-Franche-Comté</option><option value="Bretagne">Bretagne</option><option value="Centre-Val de Loire">Centre-Val de Loire</option><option value="Corse">Corse</option><option value="Grand Est">Grand Est</option><option value="Hauts-de-France">Hauts-de-France</option><option value="Île-de-France">Île-de-France</option><option value="Normandie">Normandie</option><option value="Nouvelle-Aquitaine">Nouvelle-Aquitaine</option><option value="Occitanie">Occitanie</option><option value="Pays de la Loire">Pays de la Loire</option><option value="Provence-Alpes-Côte d'Azur">PACA</option><option value="Outre-Mer">Outre-Mer</option>
            </select>
            <select id="filter-dept" onchange="appliquerFiltres()"><option value="all">🌍 Départements (Tous)</option><?php foreach($listeDepts as $dept): ?><option value="<?= htmlspecialchars($dept) ?>"><?= $dept ?></option><?php endforeach; ?></select>
            <select id="filter-groupe" onchange="appliquerFiltres()"><option value="all">👥 Groupes (Tous)</option><?php foreach($listeGroupes as $uid => $nom): ?><option value="<?= $uid ?>"><?= $nom ?></option><?php endforeach; ?></select>
            <select id="filter-perf" onchange="appliquerFiltres()"><option value="all">📈 Performance (Toute)</option><option value="91-100">Excellente (91-100%)</option><option value="81-90">Très bonne (81-90%)</option><option value="71-80">Bonne (71-80%)</option><option value="61-70">Moyenne + (61-70%)</option><option value="51-60">Moyenne (51-60%)</option><option value="41-50">Faible (41-50%)</option><option value="0-40">Très faible (0-40%)</option></select>
            <div id="compteur-filtre"></div>
        </div>

        <div class="table-responsive">
            <table class="rank-table" id="table-classement">
                <thead>
                    <tr>
                        <th onclick="trierTableau('rang')" width="60"># <span class="sort-arrow">▼</span></th>
                        <th onclick="trierTableau('nom')">Député <span class="sort-arrow">▲▼</span></th>
                        <th onclick="trierTableau('groupe')" class="hide-mobile">Groupe <span class="sort-arrow">▲▼</span></th>
                        <th onclick="trierTableau('perf')">Participation <span class="sort-arrow">▲▼</span></th>
                    </tr>
                </thead>
                <tbody id="table-body">
                    <?php 
                    $rank = 1;
                    foreach($classement as $c): 
                        // ... (le contenu PHP reste identique) ...
                        $percent = ($totalScrutins > 0) ? round(($c->participation / $totalScrutins) * 100, 1) : 0;
                        $rankClass = 'rank-num';
                        $rankSymbol = $rank;
                        if($rank == 1) { $rankClass .= ' rank-1'; $rankSymbol = '🥇'; }
                        elseif($rank == 2) { $rankClass .= ' rank-2'; $rankSymbol = '🥈'; }
                        elseif($rank == 3) { $rankClass .= ' rank-3'; $rankSymbol = '🥉'; }
                        
                        $barColor = '#3498db';
                        if($percent < 20) $barColor = '#e74c3c';
                        elseif($percent > 80) $barColor = '#27ae60';

                        $deptAttr = htmlspecialchars($c->departement ?? '');
                        $grpAttr = $c->groupe_uid ?? 'NI';
                        $nomAttr = htmlspecialchars($c->nom ?? '');
                        $grpNomAttr = htmlspecialchars($c->groupe_nom ?? 'Non inscrit');
                    ?>
                    <tr class="depute-row" 
                        data-dept="<?= $deptAttr ?>" 
                        data-groupe="<?= $grpAttr ?>" 
                        data-perf="<?= $percent ?>"
                        data-nom="<?= $nomAttr ?>"
                        data-groupe-nom="<?= $grpNomAttr ?>"
                        data-rang="<?= $rank ?>">
                        
                        <td class="<?= $rankClass ?>"><?= $rankSymbol ?></td>
                        <td>
                            <div class="depute-cell">
                                <img src="<?= $c->photo_url ?>" class="avatar-mini" loading="lazy" onerror="this.src='https://via.placeholder.com/40x50'">
                                <div>
                                    <a href="depute.php?uid=<?= $c->uid ?>" class="name-link"><?= $c->nom ?></a>
                                    <div style="font-size:0.8em; color:#666;" class="hide-mobile"><?= $c->groupe_nom ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="hide-mobile">
                            <?php 
                            $logoPath = "img/logos/" . ($c->groupe_uid ?? 'NI') . ".png";
                            if (file_exists($logoPath)) {
                                echo '<img src="'.$logoPath.'" alt="'.$grpNomAttr.'" style="height: 30px; vertical-align: middle; margin-right: 10px;">';
                            } else {
                                $couleurPastille = $c->couleur ?? '#888';
                                echo '<span style="display:inline-block; width:20px; height:20px; border-radius:50%; background:'.$couleurPastille.'; vertical-align:middle; margin-right:10px;"></span>';
                            }
                            ?>
                            <span style="color:<?= $c->couleur ?? '#333' ?>; font-weight:bold; vertical-align:middle;"><?= $grpNomAttr ?></span>
                        </td>
                        <td style="width: 30%;">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <span class="score-text"><?= $percent ?>%</span>
                                <span style="font-size:0.8em; color:#888;">(<?= $c->participation ?> votes)</span>
                            </div>
                            <div class="progress-bg"><div class="progress-bar" style="width: <?= $percent ?>%; background: <?= $barColor ?>;"></div></div>
                        </td>
                    </tr>
                    <?php $rank++; endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- ```

### Résultat -->
<!-- Sur mobile, le tableau sera légèrement compacté (polices plus petites). S'il est toujours trop large, l'utilisateur pourra faire glisser **uniquement le tableau** de droite à gauche avec le doigt, sans que cela ne décale le titre ou le header du site. -->
        <br><br>
    </div>

    <script>
        window.allDeptsGlobal = <?= json_encode(array_keys($listeDepts)) ?>;
    </script>

    <script src="js/common.js"></script>
    <script src="js/classement.js"></script>
</body>
</html>