<?php
require 'db.php';
require 'includes/functions.php';

$uid = $_GET['uid'] ?? '';
if (!$uid) {
    header('Location: index.php');
    exit;
}

// 1. Récupération du scrutin
$stmt = $pdo->prepare("SELECT * FROM scrutins WHERE uid = ?");
$stmt->execute([$uid]);
$scrutin = $stmt->fetch();
if (!$scrutin) die("Scrutin introuvable.");

$currentLeg = $scrutin->legislature;

// 2. LOGIQUE STATISTIQUE
$colonneStat = 'nb_autre';
$typeLabel = "autres scrutins";

if ($scrutin->type_scrutin === 'loi') {
    $colonneStat = 'nb_loi';
    $typeLabel = "scrutins sur les Lois";
} elseif ($scrutin->type_scrutin === 'amendement') {
    $colonneStat = 'nb_amendement';
    $typeLabel = "votes d'Amendements";
} elseif ($scrutin->type_scrutin === 'motion') {
    $colonneStat = 'nb_motion';
    $typeLabel = "Motions de censure/rejet";
}

$sqlCountType = "SELECT COUNT(*) FROM scrutins WHERE legislature = ? AND type_scrutin = ?";
$stmt = $pdo->prepare($sqlCountType);
$stmt->execute([$currentLeg, $scrutin->type_scrutin]);
$totalScrutinsCeType = $stmt->fetchColumn();
if ($totalScrutinsCeType == 0) $totalScrutinsCeType = 1;

$sqlPart = "SELECT depute_uid, nb_total, $colonneStat as nb_type FROM stats_deputes WHERE legislature = ?";
$stmt = $pdo->prepare($sqlPart);
$stmt->execute([$currentLeg]);
$statsMap = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM scrutins WHERE legislature = ?");
$stmt->execute([$currentLeg]);
$nbScrutinsTotal = $stmt->fetchColumn();

// 3. Récupération des votes
$sql = "SELECT v.vote, v.groupe_uid, 
               d.uid as depute_uid, d.nom, d.photo_url, d.departement, d.place_hemicycle,
               d.date_naissance, d.profession, d.circonscription, d.est_actif,
               g.libelle as nom_groupe, g.couleur
        FROM votes v
        LEFT JOIN deputes d ON v.acteur_uid = d.uid
        LEFT JOIN groupes g ON v.groupe_uid = g.uid
        WHERE v.scrutin_uid = ? 
        AND d.place_hemicycle IS NOT NULL 
        AND d.place_hemicycle != ''
        ORDER BY g.libelle, v.vote, d.nom";

$stmt = $pdo->prepare($sql);
$stmt->execute([$uid]);
$allVotes = $stmt->fetchAll();

// 4. Traitement des données
$dataMap = [];
$listeGroupesFilter = [];

foreach ($allVotes as $v) {
    $s = $statsMap[$v->depute_uid] ?? ['nb_total' => 0, 'nb_type' => 0];
    
    $percentGlobal = ($nbScrutinsTotal > 0) ? round(($s['nb_total'] / $nbScrutinsTotal) * 100, 1) : 0;
    $nbVotesType = $s['nb_type'] ?? 0;
    $percentType = ($totalScrutinsCeType > 0) ? round(($nbVotesType / $totalScrutinsCeType) * 100, 1) : 0;

    $photoUrl = str_replace('/17/', "/$currentLeg/", $v->photo_url);

    $nomGrp = $v->nom_groupe ?? 'Non Inscrit';
    $grpId = $v->groupe_uid ?? 'NI';
    $listeGroupesFilter[$grpId] = $nomGrp;

    $svgId = 'p' . $v->place_hemicycle;

    $dataMap[$svgId] = [
        'vote'          => $v->vote,
        'groupe_uid'    => $grpId,
        'nom'           => htmlspecialchars($v->nom, ENT_QUOTES),
        'groupe_nom'    => htmlspecialchars($nomGrp, ENT_QUOTES),
        'photo'         => $photoUrl,
        'couleur'       => $v->couleur ?? '#cccccc',
        'participation' => $percentGlobal,
        'part_type'     => $percentType,   
        'nb_type'       => $nbVotesType
    ];
}
asort($listeGroupesFilter);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Hémicycle - <?= htmlspecialchars($scrutin->titre) ?></title>
    <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-size: 1rem; line-height: 1.5; }

        .hemicycle-wrapper {
            max-width: 1000px;
            margin: 0 auto;
            text-align: center;
            position: relative;
            margin-top: 20px;
        }
        
        #hemicycle-svg svg {
            width: 100%;
            height: auto;
            max-height: 75vh;
        }

        /* --- STYLES DES SIÈGES --- */
        .seat-item {
            cursor: default;
            /* Transition fluide */
            transition: r 0.1s linear, fill 0.2s, stroke 0.2s, fill-opacity 0.2s, opacity 0.2s;
        }

        /* Opacité pour les éléments non sélectionnés (filtres) */
        .dimmed { opacity: 0.2 !important; }

        /* --- NOUVEAU : Highlight de Groupe --- */
        /* Cette classe est ajoutée en JS sur tout le groupe quand on survole un membre */
        .highlight-group {
            opacity: 1 !important; /* Force l'opacité même si dimmed */
        }

        /* Au survol spécifique d'un siège, on ajoute un contour pour le distinguer du groupe */
        .seat-item:hover {
            opacity: 1 !important; 
            stroke: #333 !important;
            stroke-width: 2px !important;
        }
        
        /* Toolbar */
        .toolbar {
            background: #fff;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: center;
            align-items: center;
            margin-bottom: 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .toolbar select {
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            cursor: pointer;
        }

        .btn-group {
            display: flex;
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid #34495e;
        }
        .btn-mode {
            padding: 10px 18px;
            border: none;
            background: #fff;
            color: #34495e;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            border-right: 1px solid #34495e;
            transition: all 0.2s;
        }
        .btn-mode:last-child { border-right: none; }
        .btn-mode:hover { background: #f0f0f0; }
        .btn-mode.active { background: #34495e; color: #fff; font-weight: bold; }

        /* Sliders */
        .slider-wrapper {
            display: flex;
            flex-direction: column;
            gap: 5px;
            font-size: 0.85rem;
            min-width: 160px;
        }
        .slider-row { display: flex; align-items: center; justify-content: space-between; }
        .slider-row input { width: 100px; cursor: pointer; }

        .checkbox-wrapper { display: flex; align-items: center; gap: 8px; font-size: 1rem; cursor: pointer; }
        .checkbox-wrapper input { cursor: pointer; transform: scale(1.3); }

        /* Légendes */
        .legend {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 10px;
            flex-wrap: wrap;
            font-size: 1rem;
            align-items: center;
        }
        .legend-item { display: flex; align-items: center; gap: 8px; }
        .dot { width: 14px; height: 14px; border-radius: 50%; display: inline-block; }
        
        .legend-circles { display: flex; align-items: flex-end; gap: 20px; height: 85px; justify-content: center; padding-bottom: 5px; }
        .circle-sample { 
            border-radius: 50%; 
            border: 1px solid #7f8c8d; 
            display: inline-block; 
            margin-bottom: 5px; 
            background: rgba(149, 165, 166, 0.5); 
            transition: width 0.1s, height 0.1s;
        }
        .legend-val { font-size: 0.9rem; color: #666; text-align: center; }
    </style>
</head>
<body>

<div class="container">
    <div style="margin-bottom:10px">
        <a href="scrutin.php?uid=<?= $uid ?>" class="btn-back">← Retour au scrutin</a>
    </div>

    <div class="header-scrutin">
        <h1><?= htmlspecialchars($scrutin->titre) ?></h1>
        <div class="resultat-final <?= ($scrutin->sort == 'adopté') ? 'bg-green' : 'bg-red' ?>">
            <?= strtoupper($scrutin->sort) ?>
        </div>
    </div>

    <div class="toolbar">
        <select id="filter-vote" onchange="updateFilters()">
            <option value="all">🗳️ Tous les votes</option>
            <option value="pour">✅ Pour</option>
            <option value="contre">❌ Contre</option>
            <option value="abstention">⚠️ Abstention</option>
            <option value="non-votant">⚪ Non-votant</option>
        </select>

        <select id="filter-groupe" onchange="updateFilters()">
            <option value="all">👥 Tous les groupes</option>
            <?php foreach ($listeGroupesFilter as $uidGrp => $nomGrp): ?>
                <option value="<?= $uidGrp ?>"><?= $nomGrp ?></option>
            <?php endforeach; ?>
        </select>

        <div class="btn-group">
            <button onclick="setMode('vote')" class="btn-mode active" id="btn-vote">🗳️ Vote</button>
            <button onclick="setMode('groupe')" class="btn-mode" id="btn-groupe">🎨 Groupe</button>
            <button onclick="setMode('participation')" class="btn-mode" id="btn-part">📊 Assiduité</button>
        </div>

        <div class="slider-wrapper" id="slider-box" style="display:none;">
            <div class="slider-row">
                <span>Min: <b id="val-min">1</b>px</span>
                <input type="range" id="input-min" min="1" max="20" value="1" oninput="updateSizes()">
            </div>
            <div class="slider-row">
                <span>Max: <b id="val-max">36</b>px</span>
                <input type="range" id="input-max" min="5" max="60" value="36" oninput="updateSizes()">
            </div>
        </div>

        <label class="checkbox-wrapper">
            <input type="checkbox" id="cb-outline" onchange="toggleOutline()">
            <span>⭕ Contours</span>
        </label>
    </div>

    <div class="legend" id="legend-vote">
        <div class="legend-item"><span class="dot" style="background:#27ae60"></span> Pour</div>
        <div class="legend-item"><span class="dot" style="background:#c0392b"></span> Contre</div>
        <div class="legend-item"><span class="dot" style="background:#f1c40f"></span> Abstention</div>
        <div class="legend-item"><span class="dot" style="background:#ecf0f1; border:1px solid #ccc"></span> Non-votant</div>
    </div>
    
    <div class="legend" id="legend-groupe" style="display:none; color:#666;">
        <small>Les sièges prennent la couleur officielle de leur groupe parlementaire (base de données).</small>
    </div>

    <div class="legend" id="legend-part" style="display:none; flex-direction:column; gap:5px;">
        <div style="font-weight:bold; font-size:0.9em; margin-bottom:5px;">Participation :</div>
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

    <div class="hemicycle-wrapper" id="hemicycle-svg">
        <?php 
            $svgPath = 'images/hemicycle.svg';
            if (file_exists($svgPath)) echo file_get_contents($svgPath);
            else echo "<p style='color:red;'>Erreur : images/hemicycle.svg introuvable.</p>";
        ?>
    </div>
</div>

<script>
    const mapData = <?= json_encode($dataMap) ?>;
    
    // Configuration des couleurs de VOTE
    const VOTE_COLORS = {
        'pour': '#27ae60',
        'contre': '#c0392b',
        'abstention': '#f1c40f',
        'non-votant': '#ecf0f1'
    };
    
    const STROKE_NON_VOTANT = '#bdc3c7';

    let currentMode = 'vote';
    let isOutlineMode = false;
    let currentMinR = 1;
    let currentMaxR = 36; 

    document.addEventListener('DOMContentLoaded', function() {
        const seats = document.querySelectorAll('#hemicycle-svg circle, #hemicycle-svg path');
        if(seats.length === 0) return;

        seats.forEach(seat => {
            const rawId = seat.id; 
            if (!rawId) return;

            let d = null;
            if (mapData[rawId]) d = mapData[rawId];
            else if (mapData['p' + rawId]) d = mapData['p' + rawId];
            else if (rawId.startsWith('p') && mapData[rawId.substring(1)]) d = mapData[rawId.substring(1)];

            if (d) {
                seat.classList.add('seat-item');
                
                // Data Attributes
                seat.setAttribute('data-couleur', d.couleur);
                seat.setAttribute('data-participation', d.participation);
                seat.setAttribute('data-vote-type', d.vote.toLowerCase());
                seat.setAttribute('data-groupe-uid', d.groupe_uid);

                // --- ÉVÉNEMENTS HOVER GROUPE ---
                seat.addEventListener('mouseenter', function() {
                    highlightGroup(d.groupe_uid);
                });
                
                seat.addEventListener('mouseleave', function() {
                    removeHighlightGroup();
                });

                updateVisuals(seat, d);
            } else {
                seat.classList.add('seat-item');
                seat.setAttribute('data-vote-type', 'none');
                seat.setAttribute('data-groupe-uid', 'none');
                updateVisuals(seat, { vote: 'non-votant', couleur: '#ecf0f1', part_type: 0 });
            }
        });
        
        updateSizes(false);
    });

    // --- FONCTIONS HOVER GROUPE ---
    function highlightGroup(groupeUid) {
        if (!groupeUid || groupeUid === 'NI') return;
        
        // Sélectionne tous les sièges du même groupe via l'attribut data
        const groupSeats = document.querySelectorAll(`.seat-item[data-groupe-uid="${groupeUid}"]`);
        groupSeats.forEach(s => {
            s.classList.add('highlight-group');
        });
    }

    function removeHighlightGroup() {
        const highlighted = document.querySelectorAll('.highlight-group');
        highlighted.forEach(s => {
            s.classList.remove('highlight-group');
        });
    }

    // Détermine la couleur
    function getColor(d) {
        if (currentMode === 'vote') {
            let v = d.vote ? d.vote.toLowerCase() : 'non-votant';
            if (v.includes('pour')) return VOTE_COLORS['pour'];
            if (v.includes('contre')) return VOTE_COLORS['contre'];
            if (v.includes('abstention')) return VOTE_COLORS['abstention'];
            return VOTE_COLORS['non-votant'];
        } 
        else {
            return d.couleur;
        }
    }

    function updateVisuals(seat, data) {
        const baseColor = getColor(data);
        const isNonVotant = (!data.vote || data.vote.toLowerCase().includes('non-votant') || currentMode === 'vote' && data.vote === 'non-votant');

        if (isOutlineMode) {
            // MODE CONTOUR (Fond 0.2, Bordure 2px)
            seat.style.fill = baseColor;
            seat.style.fillOpacity = '0.2'; // Opacité du fond en mode contour
            
            if (currentMode === 'vote' && isNonVotant) {
                seat.style.stroke = STROKE_NON_VOTANT;
            } else {
                seat.style.stroke = baseColor;
            }
            
            seat.style.strokeWidth = '2px';
            seat.style.strokeOpacity = '1';
        } else {
            // MODE PLEIN
            seat.style.fill = baseColor;
            seat.style.fillOpacity = '1';
            
            if (currentMode === 'vote' && isNonVotant) {
                seat.style.stroke = STROKE_NON_VOTANT;
                seat.style.strokeWidth = '1px';
            } else {
                seat.style.stroke = 'none';
            }
        }

        if(seat.tagName === 'circle') {
            if (currentMode === 'participation') {
                const ratio = (data.part_type !== undefined) ? data.part_type / 100 : 0; 
                const newR = currentMinR + (ratio * (currentMaxR - currentMinR));
                seat.setAttribute('r', newR);
            } else {
                seat.setAttribute('r', '6');
            }
        }
    }

    // Gestion des Sliders
    function updateSizes(redraw = true) {
        currentMinR = parseInt(document.getElementById('input-min').value);
        currentMaxR = parseInt(document.getElementById('input-max').value);

        if (currentMinR >= currentMaxR) currentMaxR = currentMinR + 1;

        document.getElementById('val-min').innerText = currentMinR;
        document.getElementById('val-max').innerText = currentMaxR;

        const minD = currentMinR * 2;
        const maxD = currentMaxR * 2;
        const midD = minD + (maxD - minD) / 2;

        const legMin = document.getElementById('leg-c-min');
        const legMid = document.getElementById('leg-c-mid');
        const legMax = document.getElementById('leg-c-max');

        legMin.style.width = minD + 'px'; legMin.style.height = minD + 'px';
        legMax.style.width = maxD + 'px'; legMax.style.height = maxD + 'px';
        legMid.style.width = midD + 'px'; legMid.style.height = midD + 'px';

        if (redraw && currentMode === 'participation') {
            refreshAllSeats();
        }
    }

    function toggleOutline() {
        isOutlineMode = document.getElementById('cb-outline').checked;
        refreshAllSeats();
    }

    function setMode(mode) {
        currentMode = mode;
        
        document.querySelectorAll('.btn-mode').forEach(b => b.classList.remove('active'));
        if(mode === 'vote') document.getElementById('btn-vote').classList.add('active');
        if(mode === 'groupe') document.getElementById('btn-groupe').classList.add('active');
        if(mode === 'participation') document.getElementById('btn-part').classList.add('active');

        document.getElementById('legend-vote').style.display = (mode === 'vote') ? 'flex' : 'none';
        document.getElementById('legend-groupe').style.display = (mode === 'groupe') ? 'flex' : 'none';
        document.getElementById('legend-part').style.display = (mode === 'participation') ? 'flex' : 'none';
        document.getElementById('slider-box').style.display = (mode === 'participation') ? 'flex' : 'none';

        refreshAllSeats();
    }

    function refreshAllSeats() {
        const seats = document.querySelectorAll('.seat-item');
        seats.forEach(seat => {
            const rawId = seat.id;
            let d = null;
            if (mapData[rawId]) d = mapData[rawId];
            else if (mapData['p' + rawId]) d = mapData['p' + rawId];
            else if (rawId.startsWith('p') && mapData[rawId.substring(1)]) d = mapData[rawId.substring(1)];
            
            if (!d) d = { vote: 'non-votant', couleur: '#ecf0f1', part_type: 0 };
            
            updateVisuals(seat, d);
        });
    }

    function updateFilters() {
        const filterVote = document.getElementById('filter-vote').value.toLowerCase();
        const filterGroupe = document.getElementById('filter-groupe').value;
        const seats = document.querySelectorAll('.seat-item');

        seats.forEach(seat => {
            const seatVote = seat.getAttribute('data-vote-type') || 'none';
            const seatGroupe = seat.getAttribute('data-groupe-uid') || 'none';

            let matchVote = (filterVote === 'all') || (seatVote.includes(filterVote));
            if (filterVote === 'non-votant' && !['pour','contre','abstention'].some(x => seatVote.includes(x))) matchVote = true;
            let matchGroupe = (filterGroupe === 'all') || (seatGroupe === filterGroupe);

            if (matchVote && matchGroupe) seat.classList.remove('dimmed');
            else seat.classList.add('dimmed');
        });
    }
</script>

</body>
</html>