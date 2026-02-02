// js/scrutin.js - Logique spécifique à la page de détail d'un scrutin

// --- 1. UI : Plier/Déplier ---
function toggleChart() {
    var chart = document.getElementById('chart-section');
    var btn = document.getElementById('btn-toggle-chart');
    
    if (chart.style.display === 'none') {
        chart.style.display = 'block';
        chart.style.opacity = 0;
        setTimeout(() => { 
            chart.style.transition = 'opacity 0.3s';
            chart.style.opacity = 1; 
        }, 10);
        btn.innerHTML = 'Masquer le graphique ▲';
    } else {
        chart.style.display = 'none';
        btn.innerHTML = 'Afficher le graphique ▼';
    }
}

// --- 2. Graphique Animé ---
function updateChart(voteType) {
    // window.chartDataGlobal est défini dans le PHP
    var chartData = window.chartDataGlobal; 

    // Gestion du Titre
    var labelText = '';
    if (voteType === 'all') {
        labelText = 'TOUS'; 
    } else {
        var labelMap = {'Pour': 'POUR ✅', 'Contre': 'CONTRE ❌', 'Abstention': 'ABSTENTION ⚠️'};
        labelText = labelMap[voteType] || voteType;
    }
    document.getElementById('chart-vote-type').innerText = labelText;

    var container = document.getElementById('chart-bars-container');
    container.innerHTML = ''; 

    // Config Données
    var isAll = (voteType === 'all');
    var maxVal = 0;

    // Calcul Max
    Object.keys(chartData).forEach(function(key) {
        var data = chartData[key];
        var total = isAll 
            ? (data.stats['Pour'] + data.stats['Contre'] + data.stats['Abstention'])
            : data.stats[voteType];
        if(total > maxVal) maxVal = total;
    });

    if(maxVal === 0) {
        container.innerHTML = '<div style="text-align:center;color:#999;padding:15px;">Aucune donnée</div>';
        return;
    }

    // Tri
    var sortedKeys = Object.keys(chartData).sort(function(a, b) {
        var valA = isAll 
            ? (chartData[a].stats['Pour'] + chartData[a].stats['Contre'] + chartData[a].stats['Abstention'])
            : chartData[a].stats[voteType];
        var valB = isAll 
            ? (chartData[b].stats['Pour'] + chartData[b].stats['Contre'] + chartData[b].stats['Abstention'])
            : chartData[b].stats[voteType];
        return valB - valA; 
    });

    // Affichage
    sortedKeys.forEach(function(key) {
        var data = chartData[key];
        var vPour = data.stats['Pour'];
        var vContre = data.stats['Contre'];
        var vAbs = data.stats['Abstention'];
        
        var totalGrp = vPour + vContre + vAbs;
        var valComparaison = isAll ? totalGrp : data.stats[voteType];

        if(valComparaison > 0) {
            var row = document.createElement('div');
            row.className = 'chart-row';
            var barHTML = '';
            
            if (isAll) {
                barHTML = `
                    <div class="chart-bar-segment bg-pour" id="seg-p-${key}" title="Pour: ${vPour}" style="width:0%"></div>
                    <div class="chart-bar-segment bg-contre" id="seg-c-${key}" title="Contre: ${vContre}" style="width:0%"></div>
                    <div class="chart-bar-segment bg-abstention" id="seg-a-${key}" title="Abs: ${vAbs}" style="width:0%"></div>
                `;
            } else {
                var bgCol = (data.couleur && data.couleur.length > 2) ? data.couleur : '#999';
                barHTML = `<div class="chart-bar-fill" id="bar-${key}" style="width: 0%; background-color: ${bgCol};"></div>`;
            }

            row.innerHTML = `
                <div class="chart-label" title="${data.nom}">${data.nom}</div>
                <div class="chart-bar-area">${barHTML}</div>
                <div class="chart-value">${valComparaison}</div>
            `;
            container.appendChild(row);

            setTimeout(function() {
                if(isAll) {
                    if(document.getElementById('seg-p-'+key)) document.getElementById('seg-p-'+key).style.width = (vPour / maxVal * 100) + '%';
                    if(document.getElementById('seg-c-'+key)) document.getElementById('seg-c-'+key).style.width = (vContre / maxVal * 100) + '%';
                    if(document.getElementById('seg-a-'+key)) document.getElementById('seg-a-'+key).style.width = (vAbs / maxVal * 100) + '%';
                } else {
                    if(document.getElementById('bar-'+key)) document.getElementById('bar-'+key).style.width = (valComparaison / maxVal * 100) + '%';
                }
            }, 50);
        }
    });
}

// --- 3. Filtres (Cartes) ---
function appliquerFiltres() {
    var selRegion = document.getElementById('filter-region').value;
    var selDept = document.getElementById('filter-dept').value;
    var selGroupe = document.getElementById('filter-groupe').value;
    var selVote = document.getElementById('filter-vote').value;

    updateChart(selVote);

    var totalVisible = 0;
    document.querySelectorAll('.bloc-groupe').forEach(function(bloc) {
        var cartes = bloc.querySelectorAll('.depute-card');
        var visibleGrp = 0;
        cartes.forEach(function(card) {
            var dept = card.getAttribute('data-dept');
            var grp = card.getAttribute('data-groupe');
            var vote = card.getAttribute('data-vote');

            var matchRegion = (selRegion === 'all') ? true : (regionsMap[selRegion] && regionsMap[selRegion].includes(dept));
            var matchDept = (selDept === 'all' || dept === selDept);
            var matchGroupe = (selGroupe === 'all' || grp === selGroupe);
            var matchVote = (selVote === 'all' || vote === selVote);

            if (matchRegion && matchDept && matchGroupe && matchVote) {
                card.style.display = ''; visibleGrp++; totalVisible++;
            } else {
                card.style.display = 'none';
            }
        });
        if(visibleGrp === 0) bloc.style.display = 'none';
        else {
            bloc.style.display = 'block';
            var c = bloc.querySelector('.count');
            if(c) c.innerText = '(' + visibleGrp + ')';
        }
    });
    document.getElementById('compteur-filtre').innerText = totalVisible + ' député(s)';
}

document.addEventListener('DOMContentLoaded', appliquerFiltres);