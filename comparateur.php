<?php
require 'db.php';
require 'includes/functions.php';

// --- CHARGEMENT CONFIG & LÉGISLATURES ACTIVES ---
$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
$lesLegislaturesActives = array_values(array_filter($config['legislatures'], function ($l) {
    return isset($l['active']) && $l['active'] === true;
}));

// On définit la législature par défaut comme étant la première du JSON (souvent la plus récente)
$defaultLeg = !empty($lesLegislaturesActives) ? $lesLegislaturesActives[0]['id'] : '17';

// --- GESTION DES PARAMÈTRES ---
$leg = $_GET['leg'] ?? $defaultLeg;
// 1. Groupes dispos (Lecture Cache)
$stmt = $pdo->prepare("SELECT DISTINCT nom_groupe FROM stats_groupes WHERE legislature = ? ORDER BY nom_groupe");
$stmt->execute([$leg]);
$nomsGroupes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Récupération des couleurs
$allGroupesDB = $pdo->query("SELECT libelle, couleur FROM groupes")->fetchAll(PDO::FETCH_ASSOC);
$couleursMap = [];
foreach ($allGroupesDB as $g) {
    $couleursMap[$g['libelle']] = $g['couleur'];
}

$groupesDispo = [];
$groupesMap = [];
$processedKeys = []; // Tableau pour traquer les doublons normalisés

foreach ($nomsGroupes as $nomGroupe) {
    // Nettoyage standardisé
    $cleanLabel = str_replace('Groupe ', '', $nomGroupe);
    $cleanLabel = trim(preg_replace('/\s*\(.*?\)/', '', $cleanLabel));

    // Clé de dédoublonnage (minuscule, sans espace)
    $dedupKey = mb_strtolower(preg_replace('/\s+/', '', $cleanLabel));

    // Si on a déjà traité ce groupe, on ignore
    if (in_array($dedupKey, $processedKeys)) continue;
    $processedKeys[] = $dedupKey;

    // ID basé sur le nom nettoyé
    $fakeId = md5($cleanLabel);

    $groupesDispo[$fakeId] = $cleanLabel;

    $groupesMap[$fakeId] = [
        'libelle_clean' => $cleanLabel,
        'couleur' => $couleursMap[$nomGroupe] ?? '#888'
    ];
}

// Utilisation de la fonction centralisée
uasort($groupesDispo, 'compareFrancais');

// 2. STATS DÉTAILLÉES
$sqlStats = "SELECT nom_groupe, theme, type_scrutin, total, nb_pour, nb_contre, nb_abs FROM stats_groupes WHERE legislature = ?";
$stmt = $pdo->prepare($sqlStats);
$stmt->execute([$leg]);
$rawData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$themesList = array_unique(array_column($rawData, 'theme'));
sort($themesList);
$keysGroupes = array_keys($groupesDispo);
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Comparateur (<?= $titreLeg ?>)</title>
    <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>
    <div class="container">
        <header class="header-resum">
            <div class="header-classement">
                <a href="index.php" class="btn-back">← Retour Accueil</a>
                <h1>Comparaison des votes par groupe politique</h1>
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

        <div class="filters-container" id="comparateur-filters">

            <div class="row-filters">
                <div class="comp-top-filters">
                    <div class="comp-filter-box">
                        <select id="filterType" onchange="updateChart()">
                            <option value="all">📂 Tous les types</option>
                            <option value="loi" selected>📜 Projets de Loi</option>
                            <option value="amendement">📝 Amendements</option>
                            <option value="motion">🛑 Motions de Censure</option>
                        </select>
                    </div>
                    <div class="comp-filter-box">
                        <select id="filterVote" onchange="updateChart()">
                            <option value="Pour">✅ Taux de POUR</option>
                            <option value="Contre">❌ Taux de CONTRE</option>
                            <option value="Abstention">⚠️ Taux d'ABSTENTION</option>
                        </select>
                    </div>
                </div>
            </div>

            <div id="selectors-area" style="text-align:center;">
                <div id="selectors-list" class="comp-groups-grid"></div>
                <div style="margin-top:15px;"><button class="btn-action btn-add" onclick="addSelector()" title="Ajouter">+</button></div>
            </div>
        </div>

        <div class="chart-wrapper"><canvas id="mainChart"></canvas></div>
    </div>
    <?php include 'includes/footer.php'; ?>

    <script>
        const themesList = <?= json_encode($themesList) ?>;
        const rawData = <?= json_encode($rawData) ?>;
        const groupesInfos = <?= json_encode($groupesMap) ?>;
        const groupesLabels = <?= json_encode($groupesDispo) ?>;
        const availableGroupIds = <?= json_encode($keysGroupes) ?>;

        // --- CONSTANTES DE STYLE MOBILE ---
        const FIXED_BAR_THICKNESS = 20;
        const GAP_BETWEEN_THEMES = 35;
        const TEXT_HEIGHT_SPACE = 25;

        let mainChart = null;

        function switchLeg(val) {
            const overlay = document.getElementById('loading-overlay');
            if (overlay) {
                overlay.style.display = 'flex';
                const currentSelections = getActiveSelections().join(',');
                setTimeout(() => {
                    window.location.href = '?leg=' + val + '&groups=' + encodeURIComponent(currentSelections);
                }, 100);
            }
        }

        const container = document.getElementById('selectors-list');

        function createSelectHTML(selectedUid) {
            let html = `<select class="dynamic-select" onchange="onSelectChange()">`;
            for (const [uid, label] of Object.entries(groupesLabels)) {
                const isSelected = (uid === selectedUid) ? 'selected' : '';
                html += `<option value="${uid}" ${isSelected}>${label}</option>`;
            }
            html += `</select>`;
            return html;
        }

        function addSelector(initialUid = null) {
            if (container.children.length >= 8) return;
            if (!initialUid) {
                const currentSelections = getActiveSelections();
                initialUid = availableGroupIds.find(id => !currentSelections.includes(id));
                if (!initialUid) initialUid = availableGroupIds[0];
            }
            const div = document.createElement('div');
            div.className = 'group-selector-item';
            div.innerHTML = `${createSelectHTML(initialUid)}<button class="btn-action btn-remove" onclick="removeSelector(this)" title="Supprimer">+</button>`;
            container.appendChild(div);
            updateUIState();
            updateChart();
        }

        function removeSelector(btn) {
            if (container.children.length <= 2) return;
            btn.parentElement.remove();
            updateUIState();
            updateChart();
        }

        function updateUIState() {
            const items = container.querySelectorAll('.group-selector-item');
            const hideRemove = (items.length <= 2);
            items.forEach(item => {
                const btn = item.querySelector('.btn-remove');
                if (hideRemove) btn.classList.add('hidden');
                else btn.classList.remove('hidden');
            });
            const selects = container.querySelectorAll('select');
            const selectedValues = Array.from(selects).map(s => s.value);
            selects.forEach(select => {
                const myValue = select.value;
                Array.from(select.options).forEach(opt => {
                    if (selectedValues.includes(opt.value) && opt.value !== myValue) {
                        opt.disabled = true;
                    } else {
                        opt.disabled = false;
                    }
                });
            });
        }

        function onSelectChange() {
            updateUIState();
            updateChart();
        }

        function getActiveSelections() {
            const selects = container.querySelectorAll('select');
            return Array.from(selects).map(s => s.value);
        }

        function cleanGroupName(name) {
            return name.replace('Groupe ', '').replace(/\s*\(.*?\)/, '').trim();
        }

        function getDatasetForGroup(groupUid, voteFilter, typeFilter, isMobile) {
            const groupInfo = groupesInfos[groupUid];
            if (!groupInfo) return null;

            const targetCleanName = groupInfo.libelle_clean;
            let aggregation = {};
            themesList.forEach(t => {
                aggregation[t] = {
                    votes: 0,
                    total: 0
                };
            });

            rawData.forEach(row => {
                if (cleanGroupName(row.nom_groupe) !== targetCleanName) return;
                if (typeFilter !== 'all' && row.type_scrutin !== typeFilter) return;

                if (aggregation[row.theme]) {
                    aggregation[row.theme].total += parseInt(row.total);
                    if (voteFilter === 'Pour') aggregation[row.theme].votes += parseInt(row.nb_pour);
                    else if (voteFilter === 'Contre') aggregation[row.theme].votes += parseInt(row.nb_contre);
                    else if (voteFilter === 'Abstention') aggregation[row.theme].votes += parseInt(row.nb_abs);
                }
            });

            let dataPoints = [];
            themesList.forEach(t => {
                const item = aggregation[t];
                let pct = (item.total > 0) ? (item.votes / item.total) * 100 : 0;
                dataPoints.push(pct.toFixed(1));
            });

            // Configuration de base
            let datasetConfig = {
                label: targetCleanName,
                data: dataPoints,
                borderColor: groupInfo.couleur,
                backgroundColor: groupInfo.couleur,
                pointRadius: 3,
                fill: true
            };

            // Spécifique Mobile : Barres fixes
            if (isMobile) {
                datasetConfig.barThickness = FIXED_BAR_THICKNESS;
                datasetConfig.borderWidth = 0;
            } else {
                // Desktop (Radar)
                datasetConfig.backgroundColor = groupInfo.couleur + '40';
                datasetConfig.borderWidth = 2;
            }

            return datasetConfig;
        }

        // --- PLUGIN TEXTE COLLÉ AUX BARRES ---
        const textAboveBarsPlugin = {
            id: 'textAboveBars',
            afterDatasetsDraw(chart, args, options) {
                if (chart.config.type !== 'bar') return;

                const {
                    ctx,
                    scales: {
                        y
                    }
                } = chart;
                const datasetCount = chart.data.datasets.length;
                const totalBarsHeight = datasetCount * FIXED_BAR_THICKNESS;

                ctx.save();
                ctx.font = 'bold 13px sans-serif';
                ctx.fillStyle = '#2c3e50';
                ctx.textAlign = 'left';
                ctx.textBaseline = 'bottom';

                chart.data.labels.forEach((label, index) => {
                    const centerPos = y.getPixelForValue(index);
                    const topOfBars = centerPos - (totalBarsHeight / 2);
                    ctx.fillText(label, 0, topOfBars - 2);

                    const bottomOfBars = centerPos + (totalBarsHeight / 2);
                    const linePos = bottomOfBars + (GAP_BETWEEN_THEMES / 2);

                    ctx.beginPath();
                    ctx.strokeStyle = '#eee';
                    ctx.lineWidth = 1;
                    ctx.moveTo(0, linePos);
                    ctx.lineTo(chart.width, linePos);
                    ctx.stroke();
                });
                ctx.restore();
            }
        };

        function updateChart() {
            const uids = getActiveSelections();
            const voteType = document.getElementById('filterVote').value;
            const scrutinType = document.getElementById('filterType').value;

            // MODIFICATION ICI : Breakpoint synchronisé avec CSS
            const isMobile = window.innerWidth < 645;

            const newDatasets = [];
            uids.forEach(uid => {
                const ds = getDatasetForGroup(uid, voteType, scrutinType, isMobile);
                if (ds) newDatasets.push(ds);
            });

            const chartWrapper = document.querySelector('.chart-wrapper');

            // --- CALCUL HAUTEUR DYNAMIQUE ---
            if (isMobile) {
                const blockHeight = TEXT_HEIGHT_SPACE + (newDatasets.length * FIXED_BAR_THICKNESS) + GAP_BETWEEN_THEMES;
                const totalHeight = (themesList.length * blockHeight) + 40;
                chartWrapper.style.height = totalHeight + "px";
            } else {
                chartWrapper.style.height = "600px";
            }

            let configType = 'radar';
            let configOptions = {};
            let pluginsList = [];

            if (isMobile) {
                configType = 'bar';
                pluginsList = [textAboveBarsPlugin];
                configOptions = {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            top: 25,
                            left: 0,
                            right: 10,
                            bottom: 0
                        }
                    },
                    scales: {
                        x: {
                            min: 0,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + "%"
                                }
                            },
                            position: 'top',
                            grid: {
                                color: '#f0f0f0'
                            }
                        },
                        y: {
                            display: false,
                            grid: {
                                display: false
                            },
                            stacked: false,
                            offset: true
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 15
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.raw + '%';
                                }
                            }
                        }
                    }
                };
            } else {
                // DESKTOP (RADAR)
                configType = 'radar';
                configOptions = {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        r: {
                            angleLines: {
                                display: true,
                                color: '#ecf0f1'
                            },
                            grid: {
                                color: '#ecf0f1'
                            },
                            pointLabels: {
                                font: {
                                    size: 12,
                                    weight: 'bold'
                                },
                                color: '#34495e'
                            },
                            suggestedMin: 0,
                            suggestedMax: 100,
                            ticks: {
                                stepSize: 20,
                                backdropColor: 'transparent',
                                showLabelBackdrop: false
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                font: {
                                    size: 13
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.8)',
                            callbacks: {
                                label: function(context) {
                                    if (context.raw == 0) return null;
                                    return context.dataset.label + ': ' + context.raw + '%';
                                }
                            }
                        }
                    }
                };
            }

            if (mainChart) {
                mainChart.destroy();
            }

            const ctx = document.getElementById('mainChart').getContext('2d');
            mainChart = new Chart(ctx, {
                type: configType,
                data: {
                    labels: themesList,
                    datasets: newDatasets
                },
                options: configOptions,
                plugins: pluginsList
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const groupsFromUrl = urlParams.get('groups');
            let restored = false;

            if (groupsFromUrl) {
                const groupsToRestore = groupsFromUrl.split(',');
                groupsToRestore.forEach(id => {
                    if (groupesLabels[id]) {
                        addSelector(id);
                        restored = true;
                    }
                });
            }

            if (!restored) {
                if (availableGroupIds.length >= 2) {
                    addSelector(availableGroupIds[0]);
                    addSelector(availableGroupIds[1]);
                } else if (availableGroupIds.length > 0) {
                    availableGroupIds.forEach(id => addSelector(id));
                }
            } else {
                updateChart();
            }

            window.addEventListener('resize', () => {
                updateChart();
            });
        });
    </script>
</body>

</html>