// js/common.js
// Données et fonctions partagées entre toutes les pages

const regionsMap = {
    "Auvergne-Rhône-Alpes": ["Ain", "Allier", "Ardèche", "Cantal", "Drôme", "Isère", "Loire", "Haute-Loire", "Puy-de-Dôme", "Rhône", "Savoie", "Haute-Savoie"],
    "Bourgogne-Franche-Comté": ["Côte-d'Or", "Doubs", "Jura", "Nièvre", "Haute-Saône", "Saône-et-Loire", "Yonne", "Territoire de Belfort"],
    "Bretagne": ["Côtes-d'Armor", "Finistère", "Ille-et-Vilaine", "Morbihan"],
    "Centre-Val de Loire": ["Cher", "Eure-et-Loir", "Indre", "Indre-et-Loire", "Loir-et-Cher", "Loiret"],
    "Corse": ["Corse-du-Sud", "Haute-Corse"],
    "Grand Est": ["Ardennes", "Aube", "Marne", "Haute-Marne", "Meurthe-et-Moselle", "Meuse", "Moselle", "Bas-Rhin", "Haut-Rhin", "Vosges"],
    "Hauts-de-France": ["Aisne", "Nord", "Oise", "Pas-de-Calais", "Somme"],
    "Île-de-France": ["Paris", "Seine-et-Marne", "Yvelines", "Essonne", "Hauts-de-Seine", "Seine-Saint-Denis", "Val-de-Marne", "Val-d'Oise"],
    "Normandie": ["Calvados", "Eure", "Manche", "Orne", "Seine-Maritime"],
    "Nouvelle-Aquitaine": ["Charente", "Charente-Maritime", "Corrèze", "Creuse", "Dordogne", "Gironde", "Landes", "Lot-et-Garonne", "Pyrénées-Atlantiques", "Deux-Sèvres", "Vienne", "Haute-Vienne"],
    "Occitanie": ["Ariège", "Aude", "Aveyron", "Gard", "Haute-Garonne", "Gers", "Hérault", "Lot", "Lozère", "Hautes-Pyrénées", "Pyrénées-Orientales", "Tarn", "Tarn-et-Garonne"],
    "Pays de la Loire": ["Loire-Atlantique", "Maine-et-Loire", "Mayenne", "Sarthe", "Vendée"],
    "Provence-Alpes-Côte d'Azur": ["Alpes-de-Haute-Provence", "Hautes-Alpes", "Alpes-Maritimes", "Bouches-du-Rhône", "Var", "Vaucluse"],
    "Outre-Mer": ["Guadeloupe", "Martinique", "Guyane", "La Réunion", "Mayotte", "Nouvelle-Calédonie", "Polynésie française", "Saint-Barthélemy", "Saint-Martin", "Saint-Pierre-et-Miquelon", "Wallis-et-Futuna"]
};

// Fonction pour mettre à jour les départements selon la région
function updateDeptsFromRegion() {
    var selRegion = document.getElementById('filter-region').value;
    var selectDept = document.getElementById('filter-dept');
    
    selectDept.innerHTML = '<option value="all">🌍 Départements (Tous)</option>';
    
    // "window.allDeptsGlobal" doit être défini dans la page PHP avant l'appel
    var sourceDepts = (typeof window.allDeptsGlobal !== 'undefined') ? window.allDeptsGlobal : [];

    var deptsToAdd = [];
    if (selRegion === 'all') {
        deptsToAdd = sourceDepts;
    } else {
        var deptsRegion = regionsMap[selRegion] || [];
        deptsToAdd = deptsRegion.filter(d => sourceDepts.includes(d));
    }
    
    deptsToAdd.sort().forEach(function(d) {
        var opt = document.createElement('option');
        opt.value = d; 
        opt.innerHTML = d; 
        selectDept.appendChild(opt);
    });
    
    // Si la fonction "appliquerFiltres" existe (définie dans scrutin.js ou classement.js), on l'appelle
    if(typeof appliquerFiltres === 'function') {
        appliquerFiltres();
    }
}