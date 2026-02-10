// js/classement.js

console.log("Chargement classement.js (Logique de Tri Corrigée)");

let currentSort = { col: 'perf', order: 'desc' };
let currentType = 'all'; 

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

document.addEventListener('DOMContentLoaded', () => {
    const selectElement = document.getElementById('filter-type');
    const defaultType = selectElement ? selectElement.value : 'all';

    updateScrutinType(defaultType);

    const chartContainer = document.getElementById('groupes-chart-container');
    const btn = document.getElementById('btn-toggle-chart');
    
    if (chartContainer && getComputedStyle(chartContainer).display === 'none') {
        if (btn) btn.innerHTML = 'Voir le classement des groupes ▼';
    } else {
        if (btn) btn.innerHTML = 'Masquer le classement des groupes ▲';
    }

    if (btn) {
        btn.addEventListener('click', toggleGroupChart);
    }

    const scrollPos = sessionStorage.getItem('classementScrollPos');
    if (scrollPos) {
        window.scrollTo(0, parseInt(scrollPos));
        sessionStorage.removeItem('classementScrollPos');
    }
    const reloadSelects = document.querySelectorAll('.leg-selector');
    reloadSelects.forEach(select => {
        select.addEventListener('change', () => {
            sessionStorage.setItem('classementScrollPos', window.scrollY);
        });
    });
});

// --- CHANGEMENT DE TYPE ---
function updateScrutinType(type) {
    currentType = type;

    // 1. Stats Globales
    const labelMap = {
        'all': 'Tous les Scrutins',
        'loi': 'Projets de Loi',
        'amendement': 'Amendements',
        'motion': 'Motions',
        'autre': 'Autres votes'
    };
    const keyMap = {
        'all': 'all_scrutins',
        'loi': 'nb_loi',
        'amendement': 'nb_amendement',
        'motion': 'nb_motion',
        'autre': 'nb_autre'
    };

    const elLabel = document.getElementById('label-total-type');
    if(elLabel) elLabel.innerText = labelMap[type];
    
    const totalVotes = window.serverTotals[keyMap[type]];
    const elTotal = document.getElementById('val-total-count');
    if(elTotal) elTotal.innerText = totalVotes;
    
    const elMoyenne = document.getElementById('val-moyenne-global');
    if(elMoyenne) elMoyenne.innerText = window.serverAverages[type] + '%';

    // 2. Mise à jour des pourcentages du Tableau
    const rows = document.querySelectorAll('.depute-row');
    rows.forEach(row => {
        const rawVal = parseInt(row.getAttribute('data-nb-' + type)) || 0;
        let percent = 0;
        if (totalVotes > 0) {
            percent = ((rawVal / totalVotes) * 100).toFixed(1);
        }
        const percentFloat = parseFloat(percent);

        row.querySelector('.score-text').innerText = percent + '%';
        row.querySelector('.score-abs').innerText = '(' + rawVal + ' votes)';

        const progressBar = row.querySelector('.progress-bar');
        progressBar.style.width = percent + '%';

        let barColor = '#3498db';
        if (percentFloat < 20) barColor = '#e74c3c';
        else if (percentFloat > 80) barColor = '#27ae60';
        progressBar.style.background = barColor;

        row.setAttribute('data-perf', percentFloat);
    });

    // 3. Mise à jour du Graphique
    rebuildGroupChart(type);

    // 4. Recalcul des rangs (Basé sur le nouveau score)
    updateRanks();

    // 5. Tri par défaut (Performance Descendante)
    currentSort.col = 'perf';
    currentSort.order = 'desc';
    trierTableau('perf');
}

function rebuildGroupChart(type) {
    const container = document.getElementById('chart-content');
    if (!container) return;
    container.innerHTML = ''; 

    let stats = window.groupStats; 
    stats.sort((a, b) => b.stats[type] - a.stats[type]);

    stats.forEach(grp => {
        const val = grp.stats[type];
        const bgCol = grp.couleur || '#888';
        
        const threshold = 15; 
        const isInside = (val >= threshold);
        let barHTML = '';
        
        if (isInside) {
            const txtCol = getTextColor(bgCol);
            barHTML = `
                <div class="chart-bar-fill" 
                     style="background-color: ${bgCol}; width: 0%; color: ${txtCol}; justify-content: flex-start; padding-left:10px;" 
                     data-width="${val}">
                     ${val}%
                </div>
            `;
        } else {
            barHTML = `
                <div class="chart-bar-fill" 
                     style="background-color: ${bgCol}; width: 0%;" 
                     data-width="${val}">
                </div>
                <div style="margin-left: 8px; font-weight:bold; color:#333; font-size:0.9em; display:flex; align-items:center;">
                    ${val}%
                </div>
            `;
        }

        const row = document.createElement('div');
        row.className = 'chart-row';
        row.innerHTML = `
            <div class="chart-label">
                ${grp.nom} <span class="badge-count" title="${grp.nb_deputes} députés">(${grp.nb_deputes})</span>
            </div>
            <div class="chart-bar-area">
                ${barHTML}
            </div>
        `;
        container.appendChild(row);
    });
    animateBars();
}

function animateBars() {
    setTimeout(function () {
        const bars = document.querySelectorAll('.chart-bar-fill');
        bars.forEach(function (bar) {
            const targetWidth = bar.getAttribute('data-width');
            if (targetWidth) {
                bar.style.width = targetWidth + '%';
            }
        });
    }, 50);
}

function toggleGroupChart() {
    const chartContainer = document.getElementById('groupes-chart-container');
    const btn = document.getElementById('btn-toggle-chart');
    if (!chartContainer || !btn) return;

    const isHidden = (window.getComputedStyle(chartContainer).display === 'none');
    if (isHidden) {
        chartContainer.style.display = 'block';
        void chartContainer.offsetWidth;
        chartContainer.style.opacity = '1';
        btn.innerHTML = 'Masquer le classement des groupes ▲';
        localStorage.setItem('chartState', 'visible');
        animateBars();
    } else {
        chartContainer.style.opacity = '0';
        setTimeout(() => { chartContainer.style.display = 'none'; }, 300);
        btn.innerHTML = 'Voir le classement des groupes ▼';
        localStorage.setItem('chartState', 'hidden');
    }
}

function appliquerFiltres() {
    const elRegion = document.getElementById('filter-region');
    const elDept = document.getElementById('filter-dept');
    const elGroupe = document.getElementById('filter-groupe');

    const fRegion = elRegion ? elRegion.value : 'all';
    const fDept = elDept ? elDept.value : 'all';
    const fGroupe = elGroupe ? elGroupe.value : 'all';

    const rows = document.querySelectorAll('.depute-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const rDept = row.getAttribute('data-dept');
        const rGroupeUID = row.getAttribute('data-groupe');

        let matchGeo = false;
        if (fDept !== 'all') {
            matchGeo = (rDept === fDept);
        } else {
            if (fRegion === 'all') {
                matchGeo = true;
            } else {
                if (typeof regionsMap !== 'undefined' && regionsMap[fRegion]) {
                    matchGeo = regionsMap[fRegion].includes(rDept);
                } else {
                    matchGeo = true;
                }
            }
        }
        const matchGroupe = (fGroupe === 'all' || rGroupeUID === fGroupe);

        if (matchGeo && matchGroupe) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    // On recalcule les rangs UNIQUEMENT après un filtrage (pour dire "1er de la région")
    updateRanks();
    updateCompteur(visibleCount);
    
    // On réapplique le tri actuel pour bien ordonner les éléments filtrés
    trierTableau(currentSort.col);
}

// --- CORE : CALCUL DU CLASSEMENT ---
// Cette fonction ne doit PAS se fier à l'ordre visuel, mais au SCORE.
function updateRanks() {
    const visibleRows = Array.from(document.querySelectorAll('.depute-row'))
        .filter(r => r.style.display !== 'none');

    // 1. On crée une copie virtuelle triée par SCORE DESCENDANT (Meilleur -> Moins bon)
    // C'est ce qui détermine qui est le "vrai" 1er, 2ème, etc.
    const sortedForRank = visibleRows.slice().sort((a, b) => {
        const scoreA = parseFloat(a.getAttribute('data-perf'));
        const scoreB = parseFloat(b.getAttribute('data-perf'));
        return scoreB - scoreA; // Descendant
    });

    // 2. On attribue les numéros de rang
    let rank = 1;
    let prevScore = -1;
    let realRank = 0;

    sortedForRank.forEach(row => {
        const score = parseFloat(row.getAttribute('data-perf'));
        
        // Gestion des ex-aequo
        if (score !== prevScore) {
            realRank = rank;
        }
        
        // Mise à jour visuelle (Chiffre #)
        row.querySelector('.rank-num').innerText = realRank;
        // Mise à jour de l'attribut pour le tri JS futur
        row.setAttribute('data-rang', realRank);
        
        prevScore = score;
        rank++;
    });
}

function updateCompteur(count = null) {
    if (count === null) {
        const rows = document.querySelectorAll('.depute-row');
        count = 0;
        rows.forEach(r => { if (r.style.display !== 'none') count++; });
    }
    const div = document.getElementById('compteur-filtre');
    if (div) div.innerText = count + " députés affichés";
}

// --- TRI DU TABLEAU ---
function trierTableau(col) {
    if (currentSort.col === col) {
        // Si c'est un clic manuel (event présent), on inverse. Sinon on garde.
        if (window.event && window.event.type === 'click') {
            currentSort.order = (currentSort.order === 'asc') ? 'desc' : 'asc';
        }
    } else {
        currentSort.col = col;
        currentSort.order = (col === 'nom' || col === 'groupe') ? 'asc' : 'desc';
    }

    document.querySelectorAll('.rank-table th').forEach(th => th.classList.remove('th-sort-asc', 'th-sort-desc'));
    const activeTh = document.querySelector(`th[onclick="trierTableau('${col}')"]`);
    if (activeTh) activeTh.classList.add(currentSort.order === 'asc' ? 'th-sort-asc' : 'th-sort-desc');

    const tbody = document.getElementById('table-body');
    if (!tbody) return;
    const rowsArray = Array.from(tbody.querySelectorAll('.depute-row'));

    rowsArray.sort((a, b) => {
        let valA, valB;
        switch (col) {
            case 'nom':
                valA = (a.getAttribute('data-nom') || '').toLowerCase();
                valB = (b.getAttribute('data-nom') || '').toLowerCase();
                return (currentSort.order === 'asc') ? valA.localeCompare(valB) : valB.localeCompare(valA);
            
            case 'groupe':
                valA = (a.getAttribute('data-groupe-nom') || '').toLowerCase();
                valB = (b.getAttribute('data-groupe-nom') || '').toLowerCase();
                return (currentSort.order === 'asc') ? valA.localeCompare(valB) : valB.localeCompare(valA);
            
            case 'rang':
                // Le rang 1 = Meilleure Perf. 
                // Donc trier par Rang ASC (1, 2, 3) = Perf DESC (100%, 90%)
                valA = parseFloat(a.getAttribute('data-perf') || 0);
                valB = parseFloat(b.getAttribute('data-perf') || 0);
                return (currentSort.order === 'asc') ? valB - valA : valA - valB;

            case 'perf':
                valA = parseFloat(a.getAttribute('data-perf') || 0);
                valB = parseFloat(b.getAttribute('data-perf') || 0);
                // Perf ASC = 0% -> 100%
                return (currentSort.order === 'asc') ? valA - valB : valB - valA;

            default:
                // Fallback (ne devrait pas arriver si le data-rang est bien géré)
                valA = parseInt(a.getAttribute('data-rang') || 0);
                valB = parseInt(b.getAttribute('data-rang') || 0);
                return (currentSort.order === 'asc') ? valA - valB : valB - valA;
        }
    });

    rowsArray.forEach(row => tbody.appendChild(row));
    
    // IMPORTANT : On ne recalcule PAS les rangs ici (updateRanks)
    // Sinon le tri "Nom" changerait les numéros de rangs.
}