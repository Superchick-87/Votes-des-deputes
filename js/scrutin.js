// js/scrutin.js - Logique spécifique à la page de détail d'un scrutin

// --- 0. UTILITAIRE : Gestion des couleurs (Contraste) ---
function getTextColor(hex) {
    if (!hex) return '#000000';
    hex = hex.replace('#', '');
    if (hex.length === 3) {
        hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
    }
    var r = parseInt(hex.substring(0, 2), 16);
    var g = parseInt(hex.substring(2, 4), 16);
    var b = parseInt(hex.substring(4, 6), 16);
    var yiq = ((r * 299) + (g * 587) + (b * 114)) / 1000;
    return (yiq >= 160) ? '#000000' : '#FFFFFF';
}

// --- 1. UI : Plier/Déplier ---
function toggleChart() {
    var chart = document.getElementById('chart-section');
    var btn = document.getElementById('btn-toggle-chart');

    if (chart.style.display === 'none') {
        chart.style.display = 'block';
        void chart.offsetWidth;
        chart.style.opacity = 1;
        btn.innerHTML = 'Masquer le graphique ▲';
    } else {
        chart.style.opacity = 0;
        setTimeout(() => { chart.style.display = 'none'; }, 300);
        btn.innerHTML = 'Afficher le graphique ▼';
    }
}

// --- 2. Graphique Animé ---
function updateChart(voteType) {
    var chartData = window.chartDataGlobal;
    var container = document.getElementById('chart-bars-container');

    // Mise à jour du titre
    var labelText = '';
    if (voteType === 'all') {
        labelText = 'TOUS (Répartition proportionnelle)';
    } else {
        var labelMap = { 'Pour': 'POUR ✅', 'Contre': 'CONTRE ❌', 'Abstention': 'ABSTENTION ⚠️' };
        labelText = labelMap[voteType] || voteType;
    }
    document.getElementById('chart-vote-type').innerText = labelText;

    if (!container) return;
    container.innerHTML = '';

    var isAll = (voteType === 'all');

    // --- ETAPE 1 : Trouver l'échelle de référence ---
    // En vue globale, l'échelle est déterminée par le groupe qui a le plus de membres (total_membres).
    // En vue filtrée, l'échelle reste basée sur le nombre de votes maximum dans la catégorie choisie.
    var maxReference = 0;

    Object.keys(chartData).forEach(function (key) {
        var data = chartData[key];
        var val = 0;
        if (isAll) {
            // On prend le total théorique (membres du groupe) comme référence
            val = parseInt(data.total_membres) || 0;
        } else {
            val = data.stats[voteType] || 0;
        }
        if (val > maxReference) maxReference = val;
    });

    if (maxReference === 0) {
        container.innerHTML = '<div style="text-align:center;color:#999;padding:15px;">Aucune donnée pour ce filtre</div>';
        return;
    }

    // --- ETAPE 2 : Tri des données ---
    var sortedKeys = Object.keys(chartData).sort(function (a, b) {
        var valA = isAll ? (parseInt(chartData[a].total_membres) || 0) : chartData[a].stats[voteType];
        var valB = isAll ? (parseInt(chartData[b].total_membres) || 0) : chartData[b].stats[voteType];
        return valB - valA;
    });

    // --- ETAPE 3 : Génération ---
    sortedKeys.forEach(function (key) {
        var data = chartData[key];

        var vPour = parseInt(data.stats['Pour']) || 0;
        var vContre = parseInt(data.stats['Contre']) || 0;
        var vAbs = parseInt(data.stats['Abstention']) || 0;

        var totalGroupe = parseInt(data.total_membres) || 0;
        var sumVotes = vPour + vContre + vAbs;

        // Sécurité : si la base de données a un souci et que totalGroupe < votes, on ajuste
        if (totalGroupe < sumVotes) totalGroupe = sumVotes;
        if (totalGroupe === 0) totalGroupe = 1;

        // Calcul des non-votants (C'est la nouvelle portion)
        var vNonVotant = totalGroupe - sumVotes;

        // Valeur à comparer pour savoir si on affiche la ligne (filtre actif)
        var valComparaison = isAll ? totalGroupe : data.stats[voteType];

        if (valComparaison > 0) {
            var row = document.createElement('div');
            row.className = 'chart-row';

            if (isAll) {
                // --- MODE VUE GLOBALE ---

                // 1. Largeur de la BARRE ENTIÈRE par rapport à la page (maxReference)
                // Si le groupe a 60 membres et le max est 120, la barre fera 50% de l'espace dispo.
                var widthGlobal = (totalGroupe / maxReference * 100);

                // 2. Largeur des SEGMENTS INTERNES (par rapport à la barre du groupe)
                // La somme fait 100% de widthGlobal
                var pP = (vPour / totalGroupe * 100);
                var pC = (vContre / totalGroupe * 100);
                var pA = (vAbs / totalGroupe * 100);
                var pNV = (vNonVotant / totalGroupe * 100);

                var displayTotal = sumVotes + ' / ' + totalGroupe;

                // Construction HTML
                // On utilise un conteneur flex aligné à gauche
                row.innerHTML = `
                    <div class="chart-label" title="${data.nom}">${data.nom}</div>
                    
                    <div class="chart-track" style="width: 100%; display:flex; align-items:center;">
                        <div class="chart-bar-container" style="width:${widthGlobal}%; height:20px; display:flex; border-radius:4px; overflow:hidden;">
                            
                            <div class="chart-bar-segment" 
                                 style="width:0%; background-color:#27ae60; transition:width 0.5s ease;" 
                                 data-target="${pP}" title="Pour: ${vPour}"></div>
                            
                            <div class="chart-bar-segment" 
                                 style="width:0%; background-color:#c0392b; transition:width 0.5s ease;" 
                                 data-target="${pC}" title="Contre: ${vContre}"></div>
                            
                            <div class="chart-bar-segment" 
                                 style="width:0%; background-color:#f1c40f; transition:width 0.5s ease;" 
                                 data-target="${pA}" title="Abstention: ${vAbs}"></div>
                            
                            <div class="chart-bar-segment" 
                                 style="width:0%; background-color:#bdc3c7; transition:width 0.5s ease;" 
                                 data-target="${pNV}" title="Non-Votants / Absents: ${vNonVotant}"></div>
                        </div>
                        
                        </div>
                    
                    <div class="chart-value" style="width: 80px; text-align:right; font-weight:bold;">${displayTotal}</div>
                `;

            } else {
                // --- MODE FILTRÉ (Classique) ---
                var bgCol = (data.couleur && data.couleur.length > 2) ? data.couleur : '#999';
                var pVal = (valComparaison / maxReference * 100);
                var displayStat = valComparaison + ' / ' + totalGroupe;

                // Logique d'affichage du texte à l'intérieur ou extérieur
                var threshold = 15;
                var isInside = (pVal >= threshold);
                var barHTML = '';

                if (isInside) {
                    var txtCol = getTextColor(bgCol);
                    barHTML = `<div class="chart-bar-fill" style="width:0%; background-color:${bgCol}; color:${txtCol};" data-target="${pVal}">${displayStat}</div>`;
                } else {
                    barHTML = `
                        <div class="chart-bar-fill" style="width:0%; background-color:${bgCol};" data-target="${pVal}"></div>
                        <div style="margin-left:8px; font-weight:bold; color:#333; font-size:0.9em;">${displayStat}</div>
                    `;
                }

                row.innerHTML = `
                    <div class="chart-label" title="${data.nom}">${data.nom}</div>
                    <div class="chart-track" style="width: 100%; display:flex; align-items:center;">
                        <div class="chart-bar-area" style="width:100%; height:20px; display:flex;">
                             ${barHTML}
                        </div>
                    </div>
                `;
            }
            container.appendChild(row);
        }
    });

    // Animation différée
    setTimeout(function () {
        // Sélecteur pour les segments (vue globale)
        container.querySelectorAll('.chart-bar-segment').forEach(function (el) {
            var t = parseFloat(el.getAttribute('data-target'));
            if (t < 0) t = 0;
            el.style.width = t + '%';
        });
        // Sélecteur pour les barres remplies (vue filtrée)
        container.querySelectorAll('.chart-bar-fill').forEach(function (el) {
            el.style.width = el.getAttribute('data-target') + '%';
        });
    }, 50);
}

// --- 3. Filtres (Cartes) ---
function appliquerFiltres() {
    var selRegion = document.getElementById('filter-region').value;
    var selDept = document.getElementById('filter-dept').value;
    var selGroupe = document.getElementById('filter-groupe').value;
    var selVote = document.getElementById('filter-vote').value;

    updateChart(selVote);

    var totalVisible = 0;
    document.querySelectorAll('.bloc-groupe').forEach(function (bloc) {
        var cartes = bloc.querySelectorAll('.depute-card');
        var visibleGrp = 0;
        cartes.forEach(function (card) {
            var dept = card.getAttribute('data-dept');
            var grp = card.getAttribute('data-groupe');
            var vote = card.getAttribute('data-vote');

            var matchRegion = (selRegion === 'all') ? true : (typeof regionsMap !== 'undefined' && regionsMap[selRegion] && regionsMap[selRegion].includes(dept));
            var matchDept = (selDept === 'all' || dept === selDept);
            var matchGroupe = (selGroupe === 'all' || grp === selGroupe);
            var matchVote = (selVote === 'all' || vote === selVote);

            if (matchRegion && matchDept && matchGroupe && matchVote) {
                card.style.display = ''; visibleGrp++; totalVisible++;
            } else {
                card.style.display = 'none';
            }
        });

        if (visibleGrp === 0) {
            bloc.style.display = 'none';
        } else {
            bloc.style.display = 'block';
            var c = bloc.querySelector('.count');
            if (c) c.innerText = visibleGrp;
        }
    });

    var cpt = document.getElementById('compteur-filtre');
    if (cpt) cpt.innerText = totalVisible + ' député(s)';
}

document.addEventListener('DOMContentLoaded', function () {
    if (typeof updateChart === 'function') updateChart('all');
});