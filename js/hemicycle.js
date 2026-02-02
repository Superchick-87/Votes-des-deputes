class Hemicycle {
    constructor(containerId, dataUrl) {
        this.container = document.getElementById(containerId);
        this.dataUrl = dataUrl;
        this.deputes = [];
        this.tooltip = null;
        this.width = 900;
        this.height = 500;
        this.init();
    }

    async init() {
        this.createTooltip();
        try {
            const response = await fetch(this.dataUrl);
            this.deputes = await response.json();
            // On s'assure qu'on ne dépasse pas 577 places même si la base a des doublons
            this.deputes = this.deputes.slice(0, 577);
            this.draw();
        } catch (error) {
            console.error(error);
            this.container.innerHTML = "<p style='text-align:center; color:#999'>Chargement des données...</p>";
        }
    }

    createTooltip() {
        this.tooltip = document.createElement('div');
        this.tooltip.className = 'hemi-tooltip';
        document.body.appendChild(this.tooltip);
    }

    /**
     * Algorithme "Parliament Chart" pour un placement parfait
     */
    generatePoints(n, cx, cy, innerRadius, outerRadius) {
        let points = [];
        let rows = 12; // Nombre de rangées standard
        let seatRadius = 7; // Taille visuelle d'un siège (incluant l'espace)
        
        // Hauteur d'une rangée
        let rowHeight = (outerRadius - innerRadius) / (rows - 1);

        let currentCount = 0;
        
        // On génère les rangées de l'intérieur vers l'extérieur
        for (let r = 0; r < rows; r++) {
            let radius = innerRadius + (r * rowHeight);
            
            // Calcul de la longueur de l'arc disponible (Demi-cercle = PI * R)
            let arcLength = Math.PI * radius;
            
            // Combien de sièges tiennent sur cet arc ?
            let seatsInRow = Math.floor(arcLength / (seatRadius * 2.2)); // 2.2 = espacement
            
            // Ajustement pour la dernière rangée pour tout faire rentrer
            if (r === rows - 1) {
                seatsInRow = n - currentCount;
            }

            // Calcul des angles
            for (let s = 0; s < seatsInRow; s++) {
                if (currentCount >= n) break;

                // On répartit les sièges de 180° (PI) à 0° (0)
                // On ajoute un petit décalage pour centrer la rangée
                let angleStep = Math.PI / (seatsInRow - 1 || 1); 
                let angle = Math.PI - (s * angleStep);

                points.push({
                    x: cx + radius * Math.cos(angle),
                    y: cy - radius * Math.sin(angle),
                    angle: angle // Utile si on voulait orienter les formes
                });
                currentCount++;
            }
        }
        
        // On trie les points pour remplir de gauche à droite, rangée par rangée ? 
        // Non, l'ordre politique standard remplit "en tranche" (camembert).
        // Mais pour un hémicycle simple, remplir rangée par rangée est visuellement étrange.
        // L'astuce : On veut que LFI soit tout à gauche (sur toutes les rangées) et RN tout à droite.
        // On va donc trier nos points générés par ANGLE.
        return points.sort((a, b) => b.angle - a.angle);
    }

    draw() {
        // Paramètres géométriques
        const cx = this.width / 2;
        const cy = this.height - 50; // Bas de l'image
        const r_min = 120; // Trou central
        const r_max = 400; // Largeur max

        // Génération des places vides
        const seats = this.generatePoints(this.deputes.length, cx, cy, r_min, r_max);

        let svgContent = `<svg viewBox="0 0 ${this.width} ${this.height}" xmlns="http://www.w3.org/2000/svg">`;
        
        // Ajout d'un arc de fond décoratif (optionnel)
        svgContent += `<path d="M ${cx-r_min} ${cy} A ${r_min} ${r_min} 0 0 1 ${cx+r_min} ${cy}" fill="none" stroke="#eee" stroke-width="2"/>`;

        // Dessin des sièges
        this.deputes.forEach((depute, index) => {
            if (!seats[index]) return;
            
            let pos = seats[index];
            let color = depute.couleur ? depute.couleur : '#ccc';

            svgContent += `
                <circle cx="${pos.x}" cy="${pos.y}" r="6" fill="${color}" 
                    class="seat" 
                    data-id="${depute.uid}"
                    data-nom="${depute.nom}"
                    data-groupe="${depute.groupe_nom}"
                    data-photo="${depute.photo_url}"
                />`;
        });

        svgContent += `</svg>`;
        this.container.innerHTML = svgContent;
        this.attachEvents();
    }

    attachEvents() {
        const seats = this.container.querySelectorAll('.seat');
        seats.forEach(seat => {
            seat.addEventListener('mouseenter', (e) => this.showTooltip(e, seat));
            seat.addEventListener('mouseleave', () => this.hideTooltip());
            seat.addEventListener('mousemove', (e) => this.moveTooltip(e)); // Suivi fluide
            seat.addEventListener('click', (e) => {
                let uid = seat.getAttribute('data-id');
                window.location.href = `depute.php?uid=${uid}`;
            });
        });
    }

    showTooltip(e, seat) {
        const nom = seat.getAttribute('data-nom');
        const groupe = seat.getAttribute('data-groupe');
        const photo = seat.getAttribute('data-photo');
        
        this.tooltip.innerHTML = `
            <div class="hemi-tip-content">
                <img src="${photo}" onerror="this.src='https://via.placeholder.com/50'">
                <div>
                    <strong>${nom}</strong><br>
                    <span>${groupe}</span>
                </div>
            </div>`;
        
        this.tooltip.style.display = 'block';
        
        // Effet loupe
        seat.setAttribute('r', '10');
        seat.style.stroke = '#333';
        seat.style.strokeWidth = '2';
        
        // On met ce siège au premier plan (SVG z-index hack)
        seat.parentNode.appendChild(seat); 
    }

    hideTooltip() {
        this.tooltip.style.display = 'none';
        const seats = this.container.querySelectorAll('.seat');
        seats.forEach(s => {
            s.setAttribute('r', '6');
            s.style.stroke = 'none';
        });
    }

    moveTooltip(e) {
        this.tooltip.style.left = (e.pageX + 15) + 'px';
        this.tooltip.style.top = (e.pageY + 15) + 'px';
    }
}