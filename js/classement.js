// js/classement.js - Logique spécifique à la page de classement

// --- 1. TRI DU TABLEAU ---
let currentSort = { column: 'perf', order: 'desc' }; // Tri par défaut

function trierTableau(colonne) {
    const tbody = document.getElementById('table-body');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    // Inversion de l'ordre si on clique sur la même colonne
    if (currentSort.column === colonne) {
        currentSort.order = (currentSort.order === 'asc') ? 'desc' : 'asc';
    } else {
        currentSort.column = colonne;
        currentSort.order = 'asc'; 
        // Exception : Pour la performance, on préfère commencer par Descendant
        if(colonne === 'perf') currentSort.order = 'desc';
    }

    // Tri des lignes
    rows.sort((a, b) => {
        let valA, valB;

        // Récupération des valeurs via data-attributes
        if (colonne === 'nom') {
            valA = a.getAttribute('data-nom').toLowerCase();
            valB = b.getAttribute('data-nom').toLowerCase();
        } else if (colonne === 'groupe') {
            valA = a.getAttribute('data-groupe-nom').toLowerCase();
            valB = b.getAttribute('data-groupe-nom').toLowerCase();
        } else if (colonne === 'perf') {
            valA = parseFloat(a.getAttribute('data-perf'));
            valB = parseFloat(b.getAttribute('data-perf'));
        } else if (colonne === 'rang') {
            valA = parseInt(a.getAttribute('data-rang'));
            valB = parseInt(b.getAttribute('data-rang'));
        }

        // Comparaison
        if (valA < valB) return (currentSort.order === 'asc') ? -1 : 1;
        if (valA > valB) return (currentSort.order === 'asc') ? 1 : -1;
        return 0;
    });

    // Réinjection dans le DOM
    rows.forEach(row => tbody.appendChild(row));

    // Mise à jour visuelle des flèches
    document.querySelectorAll('.rank-table th').forEach(th => {
        th.classList.remove('th-sort-asc', 'th-sort-desc');
        const arrow = th.querySelector('.sort-arrow');
        if(arrow) arrow.innerText = '▲▼'; 
    });

    // Mise à jour de la colonne active
    const thActive = document.querySelector(`th[onclick="trierTableau('${colonne}')"]`);
    if (thActive) {
        thActive.classList.add(currentSort.order === 'asc' ? 'th-sort-asc' : 'th-sort-desc');
        const arrow = thActive.querySelector('.sort-arrow');
        if(arrow) arrow.innerText = '▼'; 
    }
}

// --- 2. FILTRES ---
function appliquerFiltres() {
    var selRegion = document.getElementById('filter-region').value;
    var selDept = document.getElementById('filter-dept').value;
    var selGroupe = document.getElementById('filter-groupe').value;
    var selPerf = document.getElementById('filter-perf').value;
    
    var minPerf = 0, maxPerf = 100;
    if(selPerf !== 'all') {
        var parts = selPerf.split('-');
        minPerf = parseInt(parts[0]);
        maxPerf = parseInt(parts[1]);
    }

    var rows = document.querySelectorAll('.depute-row');
    var visibleCount = 0;
    
    rows.forEach(function(row) {
        var dept = row.getAttribute('data-dept');
        var grp = row.getAttribute('data-groupe');
        var perf = parseFloat(row.getAttribute('data-perf'));

        // Utilisation de regionsMap depuis common.js
        var matchRegion = (selRegion === 'all') ? true : (regionsMap[selRegion] && regionsMap[selRegion].includes(dept));
        var matchDept = (selDept === 'all' || dept === selDept);
        var matchGroupe = (selGroupe === 'all' || grp === selGroupe);
        var matchPerf = (perf >= minPerf && perf <= maxPerf);

        if (matchRegion && matchDept && matchGroupe && matchPerf) {
            row.classList.remove('hidden');
            visibleCount++;
        } else {
            row.classList.add('hidden');
        }
    });
    document.getElementById('compteur-filtre').innerText = visibleCount + ' affiché(s)';
}

document.addEventListener('DOMContentLoaded', appliquerFiltres);