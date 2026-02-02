<?php 
require 'db.php'; 
$uid = $_GET['uid'] ?? '';
if(!$uid) header('Location: index.php');

$stmt = $pdo->prepare("SELECT * FROM scrutins WHERE uid = ?");
$stmt->execute([$uid]);
$scrutin = $stmt->fetch();

// --- RECUPERATION DONNEES ---
$sql = "SELECT v.vote, v.groupe_uid, 
               d.nom, d.photo_url, d.departement, d.place_hemicycle,
               g.libelle as nom_groupe, g.couleur
        FROM votes v
        LEFT JOIN deputes d ON v.acteur_uid = d.uid
        LEFT JOIN groupes g ON v.groupe_uid = g.uid
        WHERE v.scrutin_uid = ?
        ORDER BY g.libelle, v.vote, d.nom";

$stmt = $pdo->prepare($sql);
$stmt->execute([$uid]);
$allVotes = $stmt->fetchAll();

$groupes = [];
$stats = ['Pour' => 0, 'Contre' => 0, 'Abstention' => 0];
$chartData = [];
$listeDepts = [];
$listeGroupesFilter = [];

foreach($allVotes as $v) {
    $idGrp = $v->groupe_uid;
    $nomGrp = $v->nom_groupe ?? 'Non Inscrit';
    $coulGrp = $v->couleur ?? '#ccc'; 

    // 1. Structure Affichage (Cartes)
    if(!isset($groupes[$idGrp])) {
        $groupes[$idGrp] = [
            'nom' => $nomGrp,
            'couleur' => $coulGrp,
            'votes' => []
        ];
    }
    $groupes[$idGrp]['votes'][] = $v;
    
    // 2. Compteur Global
    if(isset($stats[$v->vote])) $stats[$v->vote]++;

    // 3. Structure Graphique (JSON)
    if(!isset($chartData[$idGrp])) {
        $chartData[$idGrp] = [
            'nom' => $nomGrp,
            'couleur' => $coulGrp,
            'stats' => ['Pour' => 0, 'Contre' => 0, 'Abstention' => 0]
        ];
    }
    if(isset($chartData[$idGrp]['stats'][$v->vote])) {
        $chartData[$idGrp]['stats'][$v->vote]++;
    }

    // 4. Listes Filtres
    if(!empty($v->departement)) $listeDepts[$v->departement] = $v->departement;
    $listeGroupesFilter[$v->groupe_uid] = $nomGrp;
}

asort($listeDepts);
asort($listeGroupesFilter);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Scrutin <?= $scrutin->numero ?></title>
    <link rel="stylesheet" href="css/style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
    <div class="container">
        <div style="margin-bottom:20px">
            <a href="index.php" class="btn-back">← Retour à la liste</a>
        </div>
        
        <div class="header-scrutin">
            <span class="date-scrutin"><?= date('d/m/Y', strtotime($scrutin->date_scrutin)) ?></span>
            <h1><?= $scrutin->titre ?></h1>
            <div class="objet"><?= $scrutin->objet ?></div>
            
            <div class="barre-resultat">
                <div class="stat-box p-pour">Pour: <?= $stats['Pour'] ?></div>
                <div class="stat-box p-contre">Contre: <?= $stats['Contre'] ?></div>
                <div class="stat-box p-abs">Abst: <?= $stats['Abstention'] ?></div>
                <div class="resultat-final <?= ($scrutin->sort == 'adopté') ? 'bg-green' : 'bg-red' ?>">
                    <?= strtoupper($scrutin->sort) ?>
                </div>
            </div>

            <div class="header-separator"></div>

            <select id="filter-vote" onchange="appliquerFiltres()" class="header-select">
                <option value="all">🗳️ Vue Globale (Tous les votes)</option>
                <option value="Pour">✅ Qui a voté Pour ?</option>
                <option value="Contre">❌ Qui a voté Contre ?</option>
                <option value="Abstention">⚠️ Qui s'est Abstenu ?</option>
            </select>
            <br>
            
            <button id="btn-toggle-chart" onclick="toggleChart()" class="btn-toggle-chart">Masquer le graphique ▲</button>

            <div id="chart-section" class="chart-container" style="box-shadow: none; border: none; margin: 0 auto; padding: 0;">
                <h3 class="chart-title">📊 Répartition par groupe : <span id="chart-vote-type">TOUS</span></h3>
                <div id="chart-bars-container"></div>
            </div>
        </div>

        <div class="filters-bar">
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
                <?php foreach($listeDepts as $dept): ?>
                    <option value="<?= htmlspecialchars($dept) ?>"><?= $dept ?></option>
                <?php endforeach; ?>
            </select>

            <select id="filter-groupe" onchange="appliquerFiltres()">
                <option value="all">👥 Groupes (Tous)</option>
                <?php foreach($listeGroupesFilter as $uid => $nom): ?>
                    <option value="<?= $uid ?>"><?= $nom ?></option>
                <?php endforeach; ?>
            </select>
            
            <div id="compteur-filtre"></div>
        </div>

        <?php foreach($groupes as $grpId => $grp): ?>
            <div class="bloc-groupe" style="border-top: 5px solid <?= $grp['couleur'] ?>">
                <h2><?= $grp['nom'] ?> <span class="count">(<?= count($grp['votes']) ?>)</span></h2>
                <div class="votes-grid">
                    <?php foreach($grp['votes'] as $vote): 
                        $classVote = 'v-'.strtolower($vote->vote);
                        $siege = ($vote->place_hemicycle) ? "Siège " . $vote->place_hemicycle : "";
                    ?>
                        <a href="depute.php?uid=<?= $vote->acteur_uid ?>" 
                           class="depute-card <?= $classVote ?>"
                           style="text-decoration:none; color:inherit;"
                           data-dept="<?= htmlspecialchars($vote->departement) ?>"
                           data-groupe="<?= $vote->groupe_uid ?>"
                           data-vote="<?= $vote->vote ?>">
                            
                            <div class="img-wrapper">
                                <img src="<?= $vote->photo_url ?>" 
                                     loading="lazy" 
                                     alt="" 
                                     class="photo-depute"
                                     onerror="
                                        if (this.src.includes('/17/')) { 
                                            this.src = this.src.replace('/17/', '/16/'); 
                                        } else if (this.src.includes('/16/')) { 
                                            this.src = this.src.replace('/16/', '/15/'); 
                                        } else { 
                                            this.src = 'https://via.placeholder.com/60x70?text=?'; 
                                            this.onerror = null; 
                                        }
                                     "
                                >
                            </div>
                            <div class="info">
                                <div class="nom"><?= $vote->nom ?></div>
                                <div class="meta-info">
                                    <span class="dept"><?= $vote->departement ?></span>
                                    <?php if($siege): ?><span class="siege"><?= $siege ?></span><?php endif; ?>
                                </div>
                                <div class="badge-vote"><?= $vote->vote ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

   <script>
        // On rend ces données accessibles globalement pour nos fichiers JS externes
        window.allDeptsGlobal = <?= json_encode(array_keys($listeDepts)) ?>;
        window.chartDataGlobal = <?= json_encode($chartData) ?>;
    </script>

    <script src="js/common.js"></script>
    <script src="js/scrutin.js"></script>
        
</body>
</html>