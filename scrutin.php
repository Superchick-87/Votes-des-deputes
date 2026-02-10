<?php
require 'db.php';
require 'includes/functions.php';

$uid = $_GET['uid'] ?? '';
if (!$uid) header('Location: index.php');

$stmt = $pdo->prepare("SELECT * FROM scrutins WHERE uid = ?");
$stmt->execute([$uid]);
$scrutin = $stmt->fetch();
if (!$scrutin) die("Scrutin introuvable.");

$currentLeg = $scrutin->legislature;

$showHemicycle = ($currentLeg == 17);
$hemicycleDataMap = [];

if ($showHemicycle) {
    $colonneStat = 'nb_autre';
    if ($scrutin->type_scrutin === 'loi') $colonneStat = 'nb_loi';
    elseif ($scrutin->type_scrutin === 'amendement') $colonneStat = 'nb_amendement';
    elseif ($scrutin->type_scrutin === 'motion') $colonneStat = 'nb_motion';

    $sqlCountType = "SELECT COUNT(*) FROM scrutins WHERE legislature = ? AND type_scrutin = ?";
    $stmt = $pdo->prepare($sqlCountType);
    $stmt->execute([$currentLeg, $scrutin->type_scrutin]);
    $totalScrutinsCeType = $stmt->fetchColumn();
    if ($totalScrutinsCeType == 0) $totalScrutinsCeType = 1;

    $sqlPart = "SELECT depute_uid, nb_total, $colonneStat as nb_type FROM stats_deputes WHERE legislature = ?";
    $stmt = $pdo->prepare($sqlPart);
    $stmt->execute([$currentLeg]);
    $statsMapHemicycle = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM scrutins WHERE legislature = ?");
$stmt->execute([$currentLeg]);
$nbScrutinsTotal = $stmt->fetchColumn();

$sqlPart = "SELECT depute_uid, nb_total FROM stats_deputes WHERE legislature = ?";
$stmt = $pdo->prepare($sqlPart);
$stmt->execute([$currentLeg]);
$participationMap = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$moyenneVotes = 0;
if (count($participationMap) > 0) {
    $moyenneVotes = array_sum($participationMap) / count($participationMap);
}
$moyennePercent = ($nbScrutinsTotal > 0) ? round(($moyenneVotes / $nbScrutinsTotal) * 100, 1) : 0;

$sqlEffectifs = "SELECT d.groupe_uid, COUNT(*) as total 
                 FROM deputes d
                 INNER JOIN stats_deputes sd ON d.uid = sd.depute_uid
                 WHERE sd.legislature = ? 
                 GROUP BY d.groupe_uid";
$stmtEff = $pdo->prepare($sqlEffectifs);
$stmtEff->execute([$currentLeg]);
$effectifsGroupes = $stmtEff->fetchAll(PDO::FETCH_KEY_PAIR);

$sql = "SELECT v.vote, v.groupe_uid, 
               d.uid as depute_uid, d.nom, d.photo_url, d.departement, d.place_hemicycle,
               d.date_naissance, d.profession, d.circonscription, d.est_actif,
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

foreach ($allVotes as $v) {
    $idGrp = $v->groupe_uid;
    $nomGrp = $v->nom_groupe ?? 'Non Inscrit';
    $coulGrp = $v->couleur ?? '#ccc';

    if (!isset($groupes[$idGrp])) {
        $groupes[$idGrp] = ['nom' => $nomGrp, 'couleur' => $coulGrp, 'votes' => []];
    }
    $groupes[$idGrp]['votes'][] = $v;
    if (isset($stats[$v->vote])) $stats[$v->vote]++;

    if (!isset($chartData[$idGrp])) {
        $totalTheorique = $effectifsGroupes[$idGrp] ?? 0;
        $chartData[$idGrp] = [
            'nom' => $nomGrp,
            'couleur' => $coulGrp,
            'total_membres' => $totalTheorique,
            'stats' => ['Pour' => 0, 'Contre' => 0, 'Abstention' => 0]
        ];
    }
    if (isset($chartData[$idGrp]['stats'][$v->vote])) $chartData[$idGrp]['stats'][$v->vote]++;

    if (!empty($v->departement)) $listeDepts[$v->departement] = $v->departement;
    $listeGroupesFilter[$v->groupe_uid] = $nomGrp;

    if ($showHemicycle && !empty($v->place_hemicycle)) {
        $s = $statsMapHemicycle[$v->depute_uid] ?? ['nb_total' => 0, 'nb_type' => 0];
        $percentGlobal = ($nbScrutinsTotal > 0) ? round(($s['nb_total'] / $nbScrutinsTotal) * 100, 1) : 0;
        $nbVotesType = $s['nb_type'] ?? 0;
        $percentType = ($totalScrutinsCeType > 0) ? round(($nbVotesType / $totalScrutinsCeType) * 100, 1) : 0;
        $photoUrl = str_replace('/17/', "/$currentLeg/", $v->photo_url);

        $svgId = 'p' . $v->place_hemicycle;
        $hemicycleDataMap[$svgId] = [
            'uid'           => $v->depute_uid,
            'vote'          => $v->vote,
            'groupe_uid'    => $v->groupe_uid ?? 'NI',
            'nom'           => htmlspecialchars($v->nom, ENT_QUOTES),
            'groupe_nom'    => htmlspecialchars($nomGrp, ENT_QUOTES),
            'departement'   => htmlspecialchars($v->departement ?? '', ENT_QUOTES),
            'circonscription' => htmlspecialchars($v->circonscription ?? '', ENT_QUOTES),
            'photo'         => $photoUrl,
            'couleur'       => $coulGrp,
            'participation' => $percentGlobal,
            'part_type'     => $percentType
        ];
    }
}

asort($listeDepts);
uasort($listeGroupesFilter, 'compareFrancais');
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Scrutin n°<?= $scrutin->numero ?></title>
    <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body>
    <div class="container">
        <div style="margin-bottom:20px">
            <a href="index.php" class="btn-back">← Retour à la liste des scrutins</a>
        </div>

        <div class="header-scrutin">
            <span class="date-scrutin"><?= date('d/m/Y', strtotime($scrutin->date_scrutin)) ?></span>
            <span style="display:inline-block; background:#34495e; color:white; padding:2px 8px; border-radius:4px; font-size:0.8em; font-weight:bold; vertical-align:middle;">
                <?= $currentLeg ?>ème Législature
            </span>
            <?php if (isset($scrutin->type_scrutin) && $scrutin->type_scrutin !== 'autre'): ?>
                <span style="display:inline-block; background:#eee; color:#555; padding:2px 8px; border-radius:4px; font-size:0.8em; text-transform:uppercase; font-weight:bold; vertical-align:middle;">
                    <?= $scrutin->type_scrutin ?>
                </span>
            <?php endif; ?>

            <?php
            $infosTitre = formaterTitreScrutin($scrutin->titre);
            $cssClasses = 'label-lecture';
            if (stripos($infosTitre['lecture'], 'définitive') !== false) $cssClasses .= ' definitive';
            ?>
            <?php if ($infosTitre['lecture']): ?>
                <div class="<?= $cssClasses ?>"><?= $infosTitre['lecture'] ?></div>
            <?php endif; ?>

            <h1><?= $infosTitre['titre'] ?></h1>

            <?php
            $nbPour = $stats['Pour'];
            $nbContre = $stats['Contre'];
            $nbAbst = $stats['Abstention'];
            $totalVotants = $nbPour + $nbContre + $nbAbst;
            $pctPour = ($totalVotants > 0) ? round(($nbPour / $totalVotants) * 100) : 0;
            $pctContre = ($totalVotants > 0) ? round(($nbContre / $totalVotants) * 100) : 0;
            $pctAbst = ($totalVotants > 0) ? round(($nbAbst / $totalVotants) * 100) : 0;
            ?>

            <div class="barre-resultat">
                <div class="stat-box p-pour">Pour: <?= $nbPour ?> | <?= $pctPour ?>%</div>
                <div class="stat-box p-contre">Contre: <?= $nbContre ?> | <?= $pctContre ?>%</div>
                <div class="stat-box p-abs">Abst: <?= $nbAbst ?> | <?= $pctAbst ?>%</div>
                <div class="resultat-final <?= ($scrutin->sort == 'adopté') ? 'bg-green' : 'bg-red' ?>">
                    <?= strtoupper($scrutin->sort) ?>
                </div>
            </div>
            <div class="objet"><?= $scrutin->objet ?></div>
            <div class="header-separator"></div>

            <select id="filter-vote" onchange="appliquerFiltres(); if(window.updateHemiFilters) updateHemiFilters();" class="header-select">
                <option value="all">🗳️ Vue Globale</option>
                <option value="Pour">✅ Pour</option>
                <option value="Contre">❌ Contre</option>
                <option value="Abstention">⚠️ Abstention</option>
            </select>

            <button id="btn-toggle-chart" onclick="toggleChart()" class="btn-toggle-chart" style="margin-top:10px;">Masquer le graphique ▲</button>
            <div id="chart-section" class="chart-container">
                <h3 class="chart-title">📊 Répartition par groupe : <span id="chart-vote-type">TOUS</span></h3>
                <div id="chart-bars-container"></div>
            </div>

            <?php if ($showHemicycle): ?>
                <button onclick="toggleHemicycleView()" class="btn-toggle-hemi" id="btn-hemi-toggle">
                    🏛️ Afficher l'hémicycle interactif
                </button>

                <div id="hemicycle-container" style="display:none; border:1px solid #ddd; padding:10px; border-radius:8px; margin-top:15px; position:relative;">

                    <div class="toolbar-hemi">
                        <div class="btn-group">
                            <button onclick="setHemiMode('vote')" class="btn-mode active" id="hm-vote">🗳️ Vote</button>
                            <button onclick="setHemiMode('groupe')" class="btn-mode" id="hm-groupe">🎨 Groupe</button>
                            <button onclick="setHemiMode('participation')" class="btn-mode" id="hm-part" style="display:none;">📊 Assiduité</button>
                        </div>

                        <div class="slider-wrapper" id="slider-box" style="display:none;">
                            <div class="slider-row">
                                <span>Min: <b id="val-min">1</b>px</span>
                                <input type="range" id="input-min" min="1" max="15" value="1" oninput="updateSizes()">
                            </div>
                            <div class="slider-row">
                                <span>Max: <b id="val-max">36</b>px</span>
                                <input type="range" id="input-max" min="5" max="50" value="36" oninput="updateSizes()">
                            </div>
                        </div>

                        <label style="cursor:pointer; font-size:0.9rem;">
                            <input type="checkbox" id="cb-outline" onchange="toggleOutline()"> Contours seuls
                        </label>
                    </div>

                    <div class="hemicycle-wrapper" id="hemicycle-svg">
                        <?php
                        $svgPath = 'images/hemicycle.svg';
                        if (file_exists($svgPath)) echo file_get_contents($svgPath);
                        else echo "<p>Image hémicycle introuvable.</p>";
                        ?>
                    </div>

                    <div class="legend-hemi" id="legend-vote">
                        <div><span class="dot" style="background:#27ae60"></span>Pour</div>
                        <div><span class="dot" style="background:#c0392b"></span>Contre</div>
                        <div><span class="dot" style="background:#f1c40f"></span>Abs.</div>
                        <div><span class="dot" style="background:#ecf0f1; border:1px solid #ccc"></span>Non-Votant</div>
                    </div>

                    <div class="legend-hemi" id="legend-part" style="display:none; flex-direction:column;">
                        <div style="font-weight:bold; font-size:0.9em;">Participation :</div>
                        <div class="legend-circles">
                            <div style="text-align:center; display:flex; flex-direction:column; align-items:center; justify-content:end;">
                                <span class="circle-sample" id="leg-c-min"></span>
                                <span class="legend-val">0%</span>
                            </div>
                            <div style="text-align:center; display:flex; flex-direction:column; align-items:center; justify-content:end;">
                                <span class="circle-sample" id="leg-c-mid"></span>
                                <span class="legend-val">50%</span>
                            </div>
                            <div style="text-align:center; display:flex; flex-direction:column; align-items:center; justify-content:end;">
                                <span class="circle-sample" id="leg-c-max"></span>
                                <span class="legend-val">100%</span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <div class="filters-bar">
            <select id="filter-region" onchange="updateDeptsFromRegion(); if(window.updateHemiFilters) setTimeout(updateHemiFilters, 50);">
                <option value="all">🇫🇷 Régions</option>
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
            <select id="filter-dept" onchange="appliquerFiltres(); if(window.updateHemiFilters) setTimeout(updateHemiFilters, 50);">
                <option value="all">🌍 Départements</option>
                <?php foreach ($listeDepts as $dept): ?>
                    <option value="<?= htmlspecialchars($dept) ?>"><?= $dept ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filter-groupe" onchange="appliquerFiltres(); if(window.updateHemiFilters) updateHemiFilters();">
                <option value="all">👥 Groupes</option>
                <?php foreach ($listeGroupesFilter as $uid => $nom): ?>
                    <option value="<?= $uid ?>"><?= $nom ?></option>
                <?php endforeach; ?>
            </select>
            <div id="compteur-filtre">
                <?= count($allVotes) ?> député(s)
            </div>
        </div>

        <?php foreach ($groupes as $grpId => $grp):
            $bg = $grp['couleur'];
            $hex = ltrim($bg, '#');
            if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
            $textColor = ($yiq >= 160) ? '#000000' : '#FFFFFF';
        ?>
            <div class="bloc-groupe" style="border-top: 5px solid <?= $grp['couleur'] ?>">
                <h2><?= $grp['nom'] ?> <span class="count" style="background-color: <?= $bg ?>; color: <?= $textColor ?>;"><?= count($grp['votes']) ?></span></h2>
                <div class="votes-grid">
                    <?php foreach ($grp['votes'] as $vote):
                        $classVote = 'v-' . strtolower($vote->vote);
                        $photoUrl = str_replace('/17/', "/$currentLeg/", $vote->photo_url);
                        $classInactive = getClasseDeputeInactif($vote->est_actif);
                        $percentPerso = ($nbScrutinsTotal > 0) ? round(($participationMap[$vote->depute_uid] ?? 0) / $nbScrutinsTotal * 100, 1) : 0;
                    ?>
                        <div class="depute-card <?= $classVote ?> <?= $classInactive ?>"
                            id="card-<?= $vote->depute_uid ?>"
                            data-dept="<?= htmlspecialchars($vote->departement) ?>"
                            data-groupe="<?= $vote->groupe_uid ?>"
                            data-vote="<?= $vote->vote ?>"
                            data-nom="<?= htmlspecialchars($vote->nom) ?>"
                            data-groupe-nom="<?= htmlspecialchars($vote->nom_groupe ?? '') ?>"
                            data-photo="<?= $photoUrl ?>"
                            data-age="<?= !empty($vote->date_naissance) ? date_diff(date_create($vote->date_naissance), date_create('today'))->y . " ans" : "" ?>"
                            data-job="<?= htmlspecialchars($vote->profession ?? '') ?>"
                            data-circo="<?= htmlspecialchars($vote->circonscription ?? '') ?>"
                            data-couleur="<?= $vote->couleur ?>"
                            data-participation="<?= $percentPerso ?>"
                            data-moyenne="<?= $moyennePercent ?>">
                            <div class="img-wrapper">
                                <img src="<?= $photoUrl ?>" loading="lazy" class="photo-depute" onerror="this.onerror=null; this.src='https://via.placeholder.com/60x70?text=?'">
                            </div>
                            <div class="info">
                                <div class="nom"><?= $vote->nom ?></div>
                                <div class="meta-info">
                                    <span class="dept"><?= $vote->departement ?></span>
                                    <?php if (!empty($vote->circonscription)): ?>
                                        <span class="circo" style="color:#666; font-size:0.85em; margin-left:4px;">
                                            <?= $vote->circonscription ?><sup>e</sup> circo
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="badge-vote"><?= $vote->vote ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php include 'includes/footer.php'; ?>

    <script>
        window.allDeptsGlobal = <?= json_encode(array_keys($listeDepts)) ?>;
        window.chartDataGlobal = <?= json_encode($chartData) ?>;
    </script>
    <script src="js/common.js?v=<?= time() ?>"></script>
    <script src="js/scrutin.js?v=<?= time() ?>"></script>
    <script src="js/tooltip.js?v=<?= time() ?>"></script>

    <?php if ($showHemicycle): ?>
        <script>
            (function() {
                const hemiData = <?= json_encode($hemicycleDataMap) ?>;
                const VOTE_COLORS = {
                    'pour': '#27ae60',
                    'contre': '#c0392b',
                    'abstention': '#f1c40f',
                    'non-votant': '#ecf0f1'
                };
                let currentHemiMode = 'vote';
                let isHemiOutline = false;
                let currentMinR = 1;
                let currentMaxR = 36;

                let tooltipEl = document.getElementById('hemi-tooltip');
                if (!tooltipEl) {
                    tooltipEl = document.createElement('div');
                    tooltipEl.id = 'hemi-tooltip';
                    tooltipEl.className = 'hemi-tooltip';
                    document.body.appendChild(tooltipEl);
                }

                window.toggleHemicycleView = function() {
                    const container = document.getElementById('hemicycle-container');
                    const btn = document.getElementById('btn-hemi-toggle');
                    if (container.style.display === 'none') {
                        container.style.display = 'block';
                        btn.innerHTML = '🙈 Masquer l\'hémicycle';
                        updateSizes();
                        setTimeout(updateHemiFilters, 100);
                    } else {
                        container.style.display = 'none';
                        btn.innerHTML = '🏛️ Afficher l\'hémicycle interactif';
                    }
                };

                window.setHemiMode = function(mode) {
                    currentHemiMode = mode;
                    ['hm-vote', 'hm-groupe', 'hm-part'].forEach(id => document.getElementById(id).classList.remove('active'));
                    if (mode === 'vote') document.getElementById('hm-vote').classList.add('active');
                    if (mode === 'groupe') document.getElementById('hm-groupe').classList.add('active');
                    if (mode === 'participation') document.getElementById('hm-part').classList.add('active');

                    document.getElementById('slider-box').style.display = (mode === 'participation') ? 'flex' : 'none';
                    document.getElementById('legend-vote').style.display = (mode === 'vote') ? 'flex' : 'none';
                    document.getElementById('legend-part').style.display = (mode === 'participation') ? 'flex' : 'none';

                    refreshHemiSeats();
                };

                window.updateSizes = function() {
                    const inMin = document.getElementById('input-min');
                    const inMax = document.getElementById('input-max');
                    if (!inMin || !inMax) return;
                    currentMinR = parseInt(inMin.value);
                    currentMaxR = parseInt(inMax.value);
                    if (currentMinR >= currentMaxR) currentMaxR = currentMinR + 1;

                    document.getElementById('val-min').innerText = currentMinR;
                    document.getElementById('val-max').innerText = currentMaxR;

                    const minD = currentMinR * 2,
                        maxD = currentMaxR * 2,
                        midD = minD + (maxD - minD) / 2;
                    const lMin = document.getElementById('leg-c-min'),
                        lMid = document.getElementById('leg-c-mid'),
                        lMax = document.getElementById('leg-c-max');
                    if (lMin) {
                        lMin.style.width = minD + 'px';
                        lMin.style.height = minD + 'px';
                    }
                    if (lMax) {
                        lMax.style.width = maxD + 'px';
                        lMax.style.height = maxD + 'px';
                    }
                    if (lMid) {
                        lMid.style.width = midD + 'px';
                        lMid.style.height = midD + 'px';
                    }

                    if (currentHemiMode === 'participation') refreshHemiSeats();
                };

                window.toggleOutline = function() {
                    isHemiOutline = document.getElementById('cb-outline').checked;
                    refreshHemiSeats();
                };

                function refreshHemiSeats() {
                    const seats = document.querySelectorAll('#hemicycle-svg circle, #hemicycle-svg path');

                    seats.forEach(seat => {
                        const id = seat.id;
                        let d = hemiData[id] || hemiData['p' + id];
                        if (!d && id.startsWith('p')) d = hemiData[id.substring(1)];

                        if (d) {
                            let color = '#ccc';
                            if (currentHemiMode === 'vote') {
                                let v = (d.vote || 'non-votant').toLowerCase();
                                if (v.includes('pour')) color = VOTE_COLORS['pour'];
                                else if (v.includes('contre')) color = VOTE_COLORS['contre'];
                                else if (v.includes('abstention')) color = VOTE_COLORS['abstention'];
                                else color = VOTE_COLORS['non-votant'];
                            } else {
                                color = d.couleur;
                            }

                            if (isHemiOutline) {
                                seat.style.fill = color;
                                seat.style.fillOpacity = '0.3';
                                seat.style.stroke = color;
                                seat.style.strokeWidth = '2px';
                            } else {
                                seat.style.fill = color;
                                seat.style.fillOpacity = '1';
                                seat.style.stroke = 'none';
                            }

                            if (seat.tagName === 'circle') {
                                if (currentHemiMode === 'participation') {
                                    const ratio = (d.part_type !== undefined) ? d.part_type / 100 : 0;
                                    const r = currentMinR + (ratio * (currentMaxR - currentMinR));
                                    seat.setAttribute('r', r);
                                } else {
                                    seat.setAttribute('r', '6');
                                }
                            }

                            // --- TOOLTIP ---
                            seat.onmouseenter = function(e) {
                                // MODIFICATION MOBILE : Pas de tooltip si écran < 645px
                                if (window.innerWidth < 645) return;

                                tooltipEl.style.display = 'block';

                                let rawVote = (d.vote || '').toLowerCase();
                                let labelVote = 'Non Votant';
                                let classColor = '';

                                if (rawVote.includes('pour')) {
                                    labelVote = 'Vote pour';
                                    classColor = 'vote-val-pour';
                                } else if (rawVote.includes('contre')) {
                                    labelVote = 'Vote contre';
                                    classColor = 'vote-val-contre';
                                } else if (rawVote.includes('abstention')) {
                                    labelVote = 'Abstention';
                                    classColor = 'vote-val-abstention';
                                }

                                // HTML Compact
                                tooltipEl.innerHTML = `
                            <div class="hemi-tip-content">
                                <img src="${d.photo}" 
                                     style="border-left: 5px solid ${d.couleur};" 
                                     onerror="this.src='https://via.placeholder.com/50'">
                                     
                                <div class="hemi-tip-info">
                                    <strong>${d.nom}</strong>
                                    <small style="display:block; margin-bottom:4px;">${d.groupe_nom}</small>
                                    
                                    <div class="hemi-tip-details">
                                        <div style="font-weight:bold; color:#333;">${d.departement}</div>
                                        <small style="margin:0; padding:0; display:block;">${d.circonscription || '?'}<sup>e</sup> circo</small>
                                        <div style="margin-top:2px;">
                                            <span class="${classColor}">${labelVote}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                                positionTooltip(e);
                            };

                            seat.onmousemove = function(e) {
                                positionTooltip(e);
                            };

                            seat.onmouseleave = function() {
                                tooltipEl.style.display = 'none';
                            };

                        } else {
                            seat.style.fill = '#f0f0f0';
                            seat.onmouseenter = null;
                            seat.onmousemove = null;
                        }
                    });
                    updateHemiFilters();
                }

                function positionTooltip(e) {
                    const x = e.clientX;
                    const y = e.clientY;
                    let left = x + 15;
                    let top = y + 15;

                    const tipWidth = 240;
                    if (left + tipWidth > window.innerWidth) left = x - tipWidth - 10;
                    if (top + 150 > window.innerHeight) top = y - 160;

                    tooltipEl.style.left = left + 'px';
                    tooltipEl.style.top = top + 'px';
                }

                window.updateHemiFilters = function() {
                    const container = document.getElementById('hemicycle-container');
                    if (!container || container.style.display === 'none') return;

                    const visibleUIDs = new Set();
                    const cards = document.querySelectorAll('.depute-card');
                    cards.forEach(card => {
                        if (card.offsetParent !== null) {
                            const uid = card.id.replace('card-', '');
                            visibleUIDs.add(uid);
                        }
                    });

                    const seats = document.querySelectorAll('#hemicycle-svg circle, #hemicycle-svg path');
                    seats.forEach(seat => {
                        let d = hemiData[seat.id] || hemiData['p' + seat.id];
                        if (!d && seat.id.startsWith('p')) d = hemiData[seat.id.substring(1)];
                        if (d) {
                            if (visibleUIDs.has(String(d.uid))) seat.style.opacity = '1';
                            else seat.style.opacity = '0.1';
                        }
                    });
                };

                setTimeout(refreshHemiSeats, 500);
            })();
        </script>
    <?php endif; ?>
</body>

</html>