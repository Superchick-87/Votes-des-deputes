// js/tooltip.js
console.log("Tooltip JS chargé (Graphique Corrigé) !");

// 1. Dictionnaire MAÎTRE
const NORMALIZED_DEPTS = {
    'ain': '01', 'aisne': '02', 'allier': '03', 'alpesdehauteprovence': '04', 'hautesalpes': '05',
    'alpesmaritimes': '06', 'ardeche': '07', 'ardennes': '08', 'ariege': '09', 'aube': '10',
    'aude': '11', 'aveyron': '12', 'bouchesdurhone': '13', 'calvados': '14', 'cantal': '15',
    'charente': '16', 'charentemaritime': '17', 'cher': '18', 'correze': '19', 'corsedusud': '2A',
    'hautecorse': '2B', 'cotedor': '21', 'cotesdarmor': '22', 'creuse': '23', 'dordogne': '24',
    'doubs': '25', 'drome': '26', 'eure': '27', 'eureetloir': '28', 'finistere': '29',
    'gard': '30', 'hautegaronne': '31', 'gers': '32', 'gironde': '33', 'herault': '34',
    'illeetvilaine': '35', 'indre': '36', 'indreetloire': '37', 'isere': '38', 'jura': '39',
    'landes': '40', 'loiretcher': '41', 'loire': '42', 'hauteloire': '43', 'loireatlantique': '44',
    'loiret': '45', 'lot': '46', 'lotetgaronne': '47', 'lozere': '48', 'maineetloire': '49',
    'manche': '50', 'marne': '51', 'hautemarne': '52', 'mayenne': '53', 'meurtheetmoselle': '54',
    'meuse': '55', 'morbihan': '56', 'moselle': '57', 'nievre': '58', 'nord': '59',
    'oise': '60', 'orne': '61', 'pasdecalais': '62', 'puydedome': '63', 'pyreneesatlantiques': '64',
    'hautespyrenees': '65', 'pyreneesorientales': '66', 'basrhin': '67', 'hautrhin': '68', 'rhone': '69',
    'hautesaone': '70', 'saoneetloire': '71', 'sarthe': '72', 'savoie': '73', 'hautesavoie': '74',
    'paris': '75', 'seinemaritime': '76', 'seineetmarne': '77', 'yvelines': '78', 'deuxsevres': '79',
    'somme': '80', 'tarn': '81', 'tarnetgaronne': '82', 'var': '83', 'vaucluse': '84',
    'vendee': '85', 'vienne': '86', 'hautevienne': '87', 'vosges': '88', 'yonne': '89',
    'territoiredebelfort': '90', 'essonne': '91', 'hautsdeseine': '92', 'seinesaintdenis': '93',
    'valdemarne': '94', 'valdoise': '95',
    'guadeloupe': '971', 'martinique': '972', 'guyane': '973', 'lareunion': '974', 'mayotte': '976',
    'reunion': '974', 'val d oise': '95',
    'francaisetablishorsdefrance': 'hf'
};

function normalizeDeptName(name) {
    if (!name) return "";
    return name.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/['’\-\s]/g, "");
}

function getCodeDept(nom) {
    const key = normalizeDeptName(nom);
    return NORMALIZED_DEPTS[key] || null;
}

document.addEventListener('DOMContentLoaded', function() {
    
    // 2. Création de l'élément tooltip
    let tooltip = document.querySelector('.custom-tooltip');
    if (!tooltip) {
        tooltip = document.createElement('div');
        tooltip.className = 'custom-tooltip';
        tooltip.style.display = 'none'; 
        tooltip.style.pointerEvents = 'auto'; 
        document.body.appendChild(tooltip);
    }

    // Gestionnaire pour FERMER l'infobulle
    const closeHandler = function(e) {
        e.stopPropagation(); 
        hideTooltip();
    };

    tooltip.addEventListener('click', closeHandler);
    tooltip.addEventListener('touchstart', closeHandler, {passive: false});

    let isVisible = false;

    // 3. Fonction d'affichage
    function showTooltip(e, card) {
        if(e.type === 'touchstart') e.stopPropagation();

        // A. Données
        const nom = card.getAttribute('data-nom') || '?';
        const groupe = card.getAttribute('data-groupe-nom') || '';
        const dept = card.getAttribute('data-dept') || '';
        const circo = card.getAttribute('data-circo') || '';
        const age = card.getAttribute('data-age') || '';
        const job = card.getAttribute('data-job') || '';
        const photo = card.getAttribute('data-photo') || '';
        const color = card.getAttribute('data-couleur') || '#ccc';
        
        // Stats
        const part = parseFloat(card.getAttribute('data-participation')) || 0;
        const moy = parseFloat(card.getAttribute('data-moyenne')) || 0;
        const barColor = color; 

        // B. Image
        const codeDept = getCodeDept(dept);
        let mapUrl = '';
        if (codeDept) {
            mapUrl = `images/maps/${codeDept}.svg`;
        }

        // C. HTML (CORRIGÉ ICI : Noms de classes alignés sur style.css)
        tooltip.innerHTML = `
            <div class="tip-header" style="border-left: 4px solid ${color}">
                <img src="${photo}" onerror="this.src='https://via.placeholder.com/50'">
                <div>
                    <strong style="font-size:1.3em">${nom}</strong>
                    <div style="font-size:1em; color:#666">${groupe}</div>
                    ${age ? `<div style="font-size:1em;line-height: 1em;">${age}</div>` : ''}
                </div>
            </div>
            
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                <div style="width:150px; height:150px; flex-shrink:0; display:flex; align-items:center; justify-content:center;">
                    ${mapUrl ? `
                        <img src="${mapUrl}" 
                             style="max-width:100%; max-height:100%; object-fit:contain;" 
                             onerror="this.parentElement.innerHTML='<span style=\'font-size:3em; opacity:0.1;\'>🇫🇷</span>'" 
                             alt="Carte ${dept}"
                        >
                    ` : `
                        <span style="font-size:3em; opacity:0.1;">🌍</span>
                    `}
                </div>
                
                <div style="font-size:1.1em;">
                    <div style="font-weight:bold; color:#333;">${dept}</div>
                    <div style="color:#888; line-height: 0.3em;">${circo}<sup>e</sup> circo.</div>
                </div>
            </div>

            <div class="tip-stats-box">
                <div style="font-size:0.85em; color:#999; margin-bottom:5px; text-transform:uppercase; font-weight:bold;">Participation (Législature)</div>
                
                <div class="tip-stat-row">
                    <div class="tip-label">Élu(e)</div>
                    <div class="tip-track"><div class="tip-fill" style="width:${part}%; background:${barColor}"></div></div>
                    <div class="tip-val">${part}%</div>
                </div>
                
                <div class="tip-stat-row">
                    <div class="tip-label">Moyenne</div>
                    <div class="tip-track"><div class="tip-fill" style="width:${moy}%; background:#95a5a6"></div></div>
                    <div class="tip-val">${moy}%</div>
                </div>
            </div>

            ${job ? `<div style="display:none;">${job}</div>` : ''}
            
            <div style="text-align:center; font-size:0.8em; color:#999; margin-top:10px;">(Toucher pour fermer)</div>
        `;

        tooltip.style.display = 'block';
        moveTooltip(e);
        isVisible = true;
    }

    // 4. Mouvement
    function moveTooltip(e) {
        if (!isVisible) return;
        if (e.type === 'touchmove' || e.type === 'touchstart') return;

        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;
        const rect = tooltip.getBoundingClientRect();
        
        let left = clientX + 15;
        let top = clientY + 15;

        if (left + rect.width > window.innerWidth) left = clientX - rect.width - 10;
        if (top + rect.height > window.innerHeight) top = clientY - rect.height - 10;

        tooltip.style.left = left + 'px';
        tooltip.style.top = top + 'px';
    }

    // 5. Masquer
    function hideTooltip() {
        tooltip.style.display = 'none';
        isVisible = false;
    }

    // --- Events ---
    const cards = document.querySelectorAll('.depute-card');
    cards.forEach(card => {
        // Desktop
        card.addEventListener('mouseenter', (e) => showTooltip(e, card));
        card.addEventListener('mousemove', moveTooltip);
        card.addEventListener('mouseleave', hideTooltip);

        // Mobile
        card.addEventListener('touchstart', (e) => {
            showTooltip(e, card);
        }, {passive: false});
    });

    document.addEventListener('touchstart', function(e) {
        if (isVisible && !e.target.closest('.depute-card') && !e.target.closest('.custom-tooltip')) {
            hideTooltip();
        }
    }, {passive: false});
});