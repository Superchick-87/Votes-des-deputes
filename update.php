<?php
// update.php - VERSION FINALE (Hybrid Web/Cron + Nettoyage + Chemins Absolus)

// 1. DÉTECTION ENVIRONNEMENT & CONFIG
// ---------------------------------------------------------
define('IS_CLI', php_sapi_name() === 'cli');

// Tentative d'augmentation des ressources pour le traitement lourd
set_time_limit(0); 
ini_set('memory_limit', '2048M');

define('MODE_UPDATE', true);

// Inclusion avec chemin absolu (Crucial pour le CRON OVH)
require __DIR__ . '/db.php';

// Dossiers de travail
$tmpDir = __DIR__ . "/tmp/";

// URLs des données Open Data
$urls = [
    'acteurs' => "https://data.assemblee-nationale.fr/static/openData/repository/17/amo/tous_acteurs_mandats_organes_xi_legislature/AMO30_tous_acteurs_tous_mandats_tous_organes_historique.xml.zip",
    'scrutins17' => "https://data.assemblee-nationale.fr/static/openData/repository/17/loi/scrutins/Scrutins.xml.zip",
    'scrutins16' => "https://data.assemblee-nationale.fr/static/openData/repository/16/loi/scrutins/Scrutins.xml.zip"
];

// 2. FONCTIONS UTILITAIRES
// ---------------------------------------------------------

/**
 * Gestionnaire de logs adaptatif (HTML pour le web, Texte pour les logs OVH)
 */
function logger($msg) { 
    if (IS_CLI) {
        // Mode CRON : Timestamp + Texte brut
        echo "[" . date('H:i:s') . "] " . strip_tags($msg) . "\n";
    } else {
        // Mode WEB : HTML + Flush pour affichage progressif
        echo "<div>$msg</div>"; 
        if (ob_get_level() > 0) ob_flush();
        flush(); 
    }
}

/**
 * Télécharge et extrait un ZIP
 */
function downloadAndExtract($url, $destFolder) {
    global $tmpDir;
    if (!is_dir($destFolder)) mkdir($destFolder, 0777, true);
    
    $zipFile = $tmpDir . "temp_" . uniqid() . ".zip";
    logger("⬇️ Téléchargement : " . basename($url));
    
    $fp = fopen($zipFile, 'w+');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600); 
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Bot/1.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    
    if ($httpCode == 200 && filesize($zipFile) > 10000) {
        logger("📦 Extraction...");
        $zip = new ZipArchive;
        if ($zip->open($zipFile) === TRUE) {
            $zip->extractTo($destFolder);
            $zip->close();
            unlink($zipFile); 
            return true;
        }
    }
    logger("<span class='warn'>Échec téléchargement (Code $httpCode).</span>");
    return false;
}

/**
 * Déplace le contenu d'un dossier vers un autre
 */
function deplacerContenu($dossierSource, $dossierCible) {
    if (!is_dir($dossierSource)) return;
    if (!is_dir($dossierCible)) mkdir($dossierCible, 0777, true);
    $fichiers = scandir($dossierSource);
    foreach ($fichiers as $fichier) {
        if ($fichier === '.' || $fichier === '..') continue;
        rename($dossierSource . '/' . $fichier, $dossierCible . '/' . $fichier);
    }
}

/**
 * Vide et supprime un dossier récursivement
 */
function viderDossier($dossier) {
    if(!is_dir($dossier)) return;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dossier, RecursiveDirectoryIterator::SKIP_DOTS), 
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach($files as $fileinfo) {
        $fileinfo->isDir() ? rmdir($fileinfo->getRealPath()) : unlink($fileinfo->getRealPath());
    }
    rmdir($dossier);
}

/**
 * Détermine le thème d'un scrutin
 */
function determinerTheme($texte) {
    $t = mb_strtolower($texte, 'UTF-8');
    if (strpos($t, 'finance') !== false || strpos($t, 'budget') !== false || strpos($t, 'plf') !== false) return '💰 Budget & Finances';
    if (strpos($t, 'sécurité sociale') !== false || strpos($t, 'retraite') !== false || strpos($t, 'santé') !== false) return '🏥 Social & Santé';
    if (strpos($t, 'énergie') !== false || strpos($t, 'climat') !== false || strpos($t, 'environnement') !== false) return '🌱 Écologie & Énergie';
    if (strpos($t, 'constitution') !== false || strpos($t, 'institution') !== false) return '⚖️ Institutions';
    if (strpos($t, 'justice') !== false || strpos($t, 'police') !== false || strpos($t, 'sécurité') !== false) return '👮 Sécurité & Justice';
    if (strpos($t, 'éducation') !== false || strpos($t, 'école') !== false) return '🎓 Éducation & Culture';
    if (strpos($t, 'agriculture') !== false || strpos($t, 'alimentation') !== false) return '🚜 Agriculture';
    if (strpos($t, 'numérique') !== false || strpos($t, 'internet') !== false) return '💻 Numérique';
    if (strpos($t, 'europe') !== false || strpos($t, 'international') !== false) return '🌍 International';
    if (strpos($t, 'logement') !== false || strpos($t, 'immobilier') !== false) return '🏠 Logement';
    return '📜 Autres'; 
}

// 3. AFFICHAGE DÉBUT (HTML ou TEXTE)
// ---------------------------------------------------------
if (!IS_CLI) {
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Mise à jour</title>
    <style>body{font-family:sans-serif;padding:20px;background:#f4f4f9;color:#333} .log{background:#1e1e1e;color:#0f0;padding:15px;height:600px;overflow-y:scroll;border-radius:5px;font-family:monospace} .step{font-weight:bold;color:#fff;border-top:1px solid #555;margin-top:10px;padding-top:10px;display:block} .success{color:#2ecc71} .warn{color:orange} a.btn{background:#3498db;color:white;padding:15px;text-decoration:none;display:inline-block;border-radius:5px;font-weight:bold;margin-top:20px;}</style></head>
    <body><h1>🚀 Mise à jour (Correction Groupes)</h1><div class="log">';
} else {
    echo "--- DEMARRAGE TACHE CRON ---\n";
}

// 4. PRÉPARATION BASE DE DONNÉES
// ---------------------------------------------------------
logger("<span class='step'>1. Préparation de la Base...</span>");
$pdo->exec("DROP TABLE IF EXISTS votes");
$pdo->exec("DROP TABLE IF EXISTS scrutins");
$pdo->exec("DROP TABLE IF EXISTS deputes");
$pdo->exec("DROP TABLE IF EXISTS groupes");

$pdo->exec("CREATE TABLE groupes (uid TEXT PRIMARY KEY, libelle TEXT, couleur TEXT)");
$pdo->exec("CREATE TABLE deputes (uid TEXT PRIMARY KEY, nom TEXT, groupe_uid TEXT, photo_url TEXT, departement TEXT, circonscription TEXT, place_hemicycle TEXT, date_naissance DATE, profession TEXT, emails TEXT, twitter TEXT, est_actif INTEGER DEFAULT 0)");
$pdo->exec("CREATE TABLE scrutins (uid TEXT PRIMARY KEY, numero INTEGER, date_scrutin DATE, titre TEXT, objet TEXT, sort TEXT, pour INTEGER, contre INTEGER, abstention INTEGER, theme TEXT)");
$pdo->exec("CREATE TABLE votes (id INTEGER PRIMARY KEY AUTOINCREMENT, scrutin_uid TEXT, acteur_uid TEXT, vote TEXT, groupe_uid TEXT)");

$pdo->exec("CREATE INDEX idx_depute_groupe ON deputes(groupe_uid)");
$pdo->exec("CREATE INDEX idx_vote_scrutin ON votes(scrutin_uid)");
$pdo->exec("CREATE INDEX idx_depute_actif ON deputes(est_actif)");

// 5. TRAITEMENT DES ACTEURS
// ---------------------------------------------------------
logger("<span class='step'>2. Traitement des Acteurs...</span>");
$dirActeursFinal = $tmpDir . "acteurs/";
$dirOrganesFinal = $tmpDir . "organe/";
$dirRawAMO       = $tmpDir . "raw_amo/";

// On s'assure que tout est vide avant de commencer
viderDossier($dirActeursFinal); viderDossier($dirOrganesFinal); viderDossier($dirRawAMO);

if(downloadAndExtract($urls['acteurs'], $dirRawAMO)) {
    deplacerContenu($dirRawAMO . "xml/acteur", $dirActeursFinal);
    deplacerContenu($dirRawAMO . "xml/organe", $dirOrganesFinal);
    viderDossier($dirRawAMO); // On supprime le zip extrait brut

    // Importation des Groupes
    $filesOrg = glob($dirOrganesFinal . "*.xml");
    $stmtGrp = $pdo->prepare("INSERT OR REPLACE INTO groupes (uid, libelle, couleur) VALUES (?, ?, ?)");
    $pdo->beginTransaction();
    foreach($filesOrg as $f) {
        $xml = simplexml_load_file($f);
        if((string)$xml->codeType === 'GP') {
            $col = (string)$xml->couleurAssociee ?? '#888';
            $stmtGrp->execute([(string)$xml->uid, (string)$xml->libelle, $col]);
        }
    }
    $pdo->commit();

    // Importation des Députés
    $filesAct = glob($dirActeursFinal . "*.xml");
    $stmtDep = $pdo->prepare("INSERT OR REPLACE INTO deputes (uid, nom, groupe_uid, photo_url, departement, circonscription, place_hemicycle, date_naissance, profession, emails, twitter, est_actif) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $pdo->beginTransaction();
    $countDep = 0;
    
    foreach($filesAct as $f) {
        $xml = simplexml_load_file($f);
        $uid = (string)$xml->uid;
        
        $aEuMandatAssemblee = false;
        $estActif = 0; 
        $groupeId = 'NI'; 
        $departement = ''; $siege = ''; $circo = '';
        
        $dateFinMandatRef = '0000-00-00'; 
        $dateFinRefGroupe = '0000-00-00'; 

        if(isset($xml->mandats->mandat)) {
            foreach($xml->mandats->mandat as $m) {
                $type = (string)$m->typeOrgane;
                $dFin = (string)$m->dateFin;
                
                $isMandatEnCours = empty($dFin) || $dFin > date('Y-m-d');
                if(empty($dFin)) $dFin = '9999-12-31';

                // Gestion Groupe Politique (Indépendant du mandat assemblée)
                if($type === 'GP') {
                    if($dFin >= $dateFinRefGroupe) {
                        $dateFinRefGroupe = $dFin;
                        $groupeId = (string)$m->organes->organeRef;
                    }
                }
                
                // Gestion Mandat Assemblée
                if($type === 'ASSEMBLEE') {
                     $aEuMandatAssemblee = true;
                     if($isMandatEnCours) $estActif = 1;

                     if($dFin >= $dateFinMandatRef) {
                         $dateFinMandatRef = $dFin;
                         if(isset($m->election->lieu->departement)) $departement = (string)$m->election->lieu->departement;
                         if(isset($m->election->lieu->numCirco)) $circo = (string)$m->election->lieu->numCirco . 'e circ.';
                         if(isset($m->mandature->placeHemicycle)) $siege = (string)$m->mandature->placeHemicycle;
                     }
                }
            }
        }
        
        if(!$aEuMandatAssemblee) continue;

        $prenom = (string)$xml->etatCivil->ident->prenom;
        $nom = (string)$xml->etatCivil->ident->nom;
        $dateNais = (string)$xml->etatCivil->infoNaissance->dateNais;
        $prof = (string)$xml->profession->libelleCourant;
        $photoUrl = "https://www2.assemblee-nationale.fr/static/tribun/17/photos/" . str_replace('PA', '', $uid) . ".jpg";
        
        $emails = []; $twitter = "";
        if(isset($xml->adresses->adresse)) {
            foreach($xml->adresses->adresse as $adr) {
                $type = (string)$adr->typeLibelle;
                $val = (string)$adr->valElec;
                if($type === 'Mèl' && !empty($val)) $emails[] = $val;
                if($type === 'Twitter' && !empty($val)) $twitter = $val;
            }
        }
        $emailsStr = implode(', ', $emails);
        $nomComplet = str_replace(['Mme ', 'M. '], '', "$prenom $nom");
        
        $stmtDep->execute([$uid, $nomComplet, $groupeId, $photoUrl, $departement, $circo, $siege, $dateNais, $prof, $emailsStr, $twitter, $estActif]);
        $countDep++;
        if($countDep % 200 == 0) { 
            if(!IS_CLI) { echo ". "; flush(); }
        }
    }
    $pdo->commit();
    logger("✅ $countDep députés importés.");
}

// 6. TRAITEMENT DES SCRUTINS
// ---------------------------------------------------------
logger("<span class='step'>3. Traitement des Scrutins...</span>");
$dirScrutinsFinal = $tmpDir . "Scrutins/";
$dirRawScrutins   = $tmpDir . "raw_scrutins/";
viderDossier($dirScrutinsFinal); viderDossier($dirRawScrutins);

// Téléchargement des deux législatures (16 et 17)
$ok17 = downloadAndExtract($urls['scrutins17'], $dirRawScrutins);
$ok16 = downloadAndExtract($urls['scrutins16'], $dirRawScrutins);

if($ok17 || $ok16) {
    if(is_dir($dirRawScrutins . "xml/")) deplacerContenu($dirRawScrutins . "xml", $dirScrutinsFinal);
    else deplacerContenu($dirRawScrutins, $dirScrutinsFinal);
    viderDossier($dirRawScrutins);

    $files = glob($dirScrutinsFinal . "*.xml");
    rsort($files); // Tri inverse pour avoir les plus récents
    
    $stmtScrutin = $pdo->prepare("INSERT OR IGNORE INTO scrutins (uid, numero, date_scrutin, titre, objet, sort, pour, contre, abstention, theme) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtVote = $pdo->prepare("INSERT OR IGNORE INTO votes (scrutin_uid, acteur_uid, vote, groupe_uid) VALUES (?, ?, ?, ?)");
    
    $pdo->beginTransaction();
    $count = 0;
    foreach($files as $file) {
        $xml = simplexml_load_file($file);
        if(!$xml) continue;
        
        $uidScrutin = (string)$xml->uid;
        $titreComplet = (string)$xml->titre . ' ' . (string)$xml->objet->libelle;
        $theme = determinerTheme($titreComplet);
        $pour = (int)$xml->syntheseVote->decompte->pour;
        $contre = (int)$xml->syntheseVote->decompte->contre;
        $abstention = (int)$xml->syntheseVote->decompte->abstentions;
        
        $stmtScrutin->execute([$uidScrutin, (int)$xml->numero, (string)$xml->dateScrutin, (string)$xml->titre, (string)$xml->objet->libelle, (string)$xml->sort->code, $pour, $contre, $abstention, $theme]);
        
        if(isset($xml->ventilationVotes->organe->groupes->groupe)) {
            foreach ($xml->ventilationVotes->organe->groupes->groupe as $g) {
                $gRef = (string)$g->organeRef;
                $types = ['Pour' => $g->vote->decompteNominatif->pours, 'Contre' => $g->vote->decompteNominatif->contres, 'Abstention' => $g->vote->decompteNominatif->abstentions];
                foreach($types as $choix => $node) {
                    if (isset($node->votant)) {
                        foreach ($node->votant as $v) $stmtVote->execute([$uidScrutin, (string)$v->acteurRef, $choix, $gRef]);
                    }
                }
            }
        }
        $count++;
        if($count % 100 == 0) { 
             if(!IS_CLI) { echo ". "; flush(); }
        }
    }
    $pdo->commit();
    logger("✅ Scrutins terminés.");

    // 7. SWAP BDD & NETTOYAGE
    // ---------------------------------------------------------
    // Fermeture connexion pour libérer le fichier
    $pdo = null;

    // Définition des chemins de BDD (Fallback si non définis dans db.php)
    // On force des chemins absolus avec __DIR__
    if(!isset($db_file)) $db_file = __DIR__ . "/data/assemblee.sqlite";
    if(!isset($temp_file)) $temp_file = __DIR__ . "/data/assemblee_temp.sqlite"; 
    
    // Si temp_file n'est pas dans le dossier data mais à la racine (cas par défaut de db.php parfois)
    // On ajuste ici selon votre structure, mais ceci devrait couvrir les cas standards
    
    if (file_exists($db_file)) unlink($db_file);
    
    if (file_exists($temp_file) && rename($temp_file, $db_file)) {
        logger("<div class='success'>✅ Base de données mise à jour avec succès !</div>");
        
        // -- NETTOYAGE --
        logger("🧹 Suppression des fichiers temporaires...");
        viderDossier($tmpDir);
        // ---------------
        
    } else {
        logger("<div class='warn'>❌ Erreur lors du remplacement de la BDD.</div>");
    }

} else {
    logger("<span class='warn'>Aucun scrutin trouvé ou erreur téléchargement.</span>");
}

// 8. FIN
// ---------------------------------------------------------
if (!IS_CLI) {
    echo '</div><br><a href="index.php" class="btn">Retour Accueil</a></body></html>';
} else {
    echo "--- FIN DE TACHE CRON ---\n";
}
?>