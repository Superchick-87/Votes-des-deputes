<?php
// update.php - VERSION HYBRIDE (WEB & CRON)
// Détection de l'environnement : CLI (Cron) ou Web
define('IS_CLI', php_sapi_name() === 'cli');

// Configuration pour éviter les timeouts (dans la mesure du possible sur mutualisé)
set_time_limit(0); 
ini_set('memory_limit', '2048M');

define('MODE_UPDATE', true);

// UTILISATION DE __DIR__ : Indispensable pour que le CRON trouve db.php
require __DIR__ . '/db.php';

// Fonction de log intelligente
function logger($msg) { 
    if (IS_CLI) {
        // Mode CRON : On nettoie le HTML et on ajoute un saut de ligne texte
        echo "[" . date('H:i:s') . "] " . strip_tags($msg) . "\n";
    } else {
        // Mode WEB : On garde le HTML et on force l'affichage
        echo "<div>$msg</div>"; 
        if (ob_get_level() > 0) ob_flush();
        flush(); 
    }
}

// Affichage de l'en-tête HTML seulement si on est sur le Web
if (!IS_CLI) {
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Mise à jour</title>
    <style>body{font-family:sans-serif;padding:20px;background:#f4f4f9;color:#333} .log{background:#1e1e1e;color:#0f0;padding:15px;height:600px;overflow-y:scroll;border-radius:5px;font-family:monospace} .step{font-weight:bold;color:#fff;border-top:1px solid #555;margin-top:10px;padding-top:10px;display:block} .success{color:#2ecc71} .warn{color:orange} a.btn{background:#3498db;color:white;padding:15px;text-decoration:none;display:inline-block;border-radius:5px;font-weight:bold;margin-top:20px;}</style></head>
    <body><h1>🚀 Mise à jour (Correction Groupes)</h1><div class="log">';
} else {
    echo "--- DEMARRAGE TACHE CRON ---\n";
}

$urls = [
    'acteurs' => "https://data.assemblee-nationale.fr/static/openData/repository/17/amo/tous_acteurs_mandats_organes_xi_legislature/AMO30_tous_acteurs_tous_mandats_tous_organes_historique.xml.zip",
    'scrutins17' => "https://data.assemblee-nationale.fr/static/openData/repository/17/loi/scrutins/Scrutins.xml.zip",
    'scrutins16' => "https://data.assemblee-nationale.fr/static/openData/repository/16/loi/scrutins/Scrutins.xml.zip"
];

// Utilisation de __DIR__ pour garantir que le dossier tmp est créé au bon endroit
$tmpDir = __DIR__ . "/tmp/";

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

function deplacerContenu($dossierSource, $dossierCible) {
    if (!is_dir($dossierSource)) return;
    if (!is_dir($dossierCible)) mkdir($dossierCible, 0777, true);
    $fichiers = scandir($dossierSource);
    foreach ($fichiers as $fichier) {
        if ($fichier === '.' || $fichier === '..') continue;
        rename($dossierSource . '/' . $fichier, $dossierCible . '/' . $fichier);
    }
}

function viderDossier($dossier) {
    if(!is_dir($dossier)) return;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $fileinfo) $fileinfo->isDir() ? rmdir($fileinfo->getRealPath()) : unlink($fileinfo->getRealPath());
    rmdir($dossier);
}

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

// 1. BASE TEMP
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

// 2. ACTEURS
logger("<span class='step'>2. Traitement des Acteurs...</span>");
$dirActeursFinal = $tmpDir . "acteurs/";
$dirOrganesFinal = $tmpDir . "organe/";
$dirRawAMO       = $tmpDir . "raw_amo/";
viderDossier($dirActeursFinal); viderDossier($dirOrganesFinal); viderDossier($dirRawAMO);

if(downloadAndExtract($urls['acteurs'], $dirRawAMO)) {
    deplacerContenu($dirRawAMO . "xml/acteur", $dirActeursFinal);
    deplacerContenu($dirRawAMO . "xml/organe", $dirOrganesFinal);
    viderDossier($dirRawAMO);

    // Groupes
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

    // Députés
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

                if($type === 'GP') {
                    if($dFin >= $dateFinRefGroupe) {
                        $dateFinRefGroupe = $dFin;
                        $groupeId = (string)$m->organes->organeRef;
                    }
                }
                
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
            // Petit feedback visuel qui marche en CLI et en Web
            if(!IS_CLI) { echo ". "; flush(); }
        }
    }
    $pdo->commit();
    logger("✅ $countDep députés importés.");
}

// 3. SCRUTINS
logger("<span class='step'>3. Traitement des Scrutins...</span>");
$dirScrutinsFinal = $tmpDir . "Scrutins/";
$dirRawScrutins   = $tmpDir . "raw_scrutins/";
viderDossier($dirScrutinsFinal); viderDossier($dirRawScrutins);

$ok17 = downloadAndExtract($urls['scrutins17'], $dirRawScrutins);
$ok16 = downloadAndExtract($urls['scrutins16'], $dirRawScrutins);

if($ok17 || $ok16) {
    if(is_dir($dirRawScrutins . "xml/")) deplacerContenu($dirRawScrutins . "xml", $dirScrutinsFinal);
    else deplacerContenu($dirRawScrutins, $dirScrutinsFinal);
    viderDossier($dirRawScrutins);
    $files = glob($dirScrutinsFinal . "*.xml");
    rsort($files);
    
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

    // 4. SWAP
    $pdo = null;
    // On suppose que $db_file et $temp_file sont définis dans db.php
    // Si ce n'est pas le cas, assurez-vous de les avoir ou utilisez les noms en dur ci-dessous
    if(!isset($db_file)) $db_file = __DIR__ . "/assemblee.sqlite"; // fallback
    if(!isset($temp_file)) $temp_file = __DIR__ . "/assemblee_temp.sqlite"; // fallback

    if (file_exists($db_file)) unlink($db_file);
    if (file_exists($temp_file) && rename($temp_file, $db_file)) logger("<div class='success'>✅ Mise à jour terminée !</div>");
    else logger("<div class='warn'>❌ Erreur swap BDD.</div>");

} else {
    logger("<span class='warn'>Aucun scrutin trouvé.</span>");
}

if (!IS_CLI) {
    echo '</div><br><a href="index.php" class="btn">Retour Accueil</a></body></html>';
} else {
    echo "--- FIN DE TACHE CRON ---\n";
}
?>