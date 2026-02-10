<?php
// update.php - VERSION CORRIGÉE AVEC SIÈGES
define('IS_CLI', php_sapi_name() === 'cli');
ini_set('memory_limit', '2048M');
set_time_limit(600);

define('MODE_UPDATE', true);
require __DIR__ . '/db.php';

$tmpDir = __DIR__ . "/tmp/";
$step = isset($_GET['step']) ? (int)$_GET['step'] : 0;

// --- FONCTIONS ---
function viderDossier($dir)
{
    if (!is_dir($dir)) return;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $f) {
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    }
}

function downloadAndExtract($url, $destFolder)
{
    global $tmpDir;
    if (!is_dir($destFolder)) mkdir($destFolder, 0755, true);
    $zipFile = $tmpDir . "temp_" . uniqid() . ".zip";
    $fp = fopen($zipFile, 'w+');
    if (!$fp) return false;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    $success = curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    if ($success === false || filesize($zipFile) < 1000) {
        if (file_exists($zipFile)) unlink($zipFile);
        return false;
    }
    $zip = new ZipArchive;
    if ($zip->open($zipFile) === TRUE) {
        $zip->extractTo($destFolder);
        $zip->close();
        unlink($zipFile);
        return true;
    }
    return false;
}

function determinerTheme($txt)
{
    $t = mb_strtolower($txt, 'UTF-8');
    $regles = [
        '💰 Budget' => ['finance', 'budget', 'règlement', 'impôt', 'taxe', 'plfr'],
        '🏥 Santé' => ['social', 'sante', 'santé', 'hôpital', 'retraite', 'handicap'],
        '🌱 Écologie' => ['climat', 'énergie', 'environnement', 'eau ', 'nucléaire'],
        '👮 Sécurité' => ['justice', 'sécurité', 'police', 'immigration', 'pénal'],
        '🎓 Éducation' => ['éducation', 'école', 'culture', 'sport', 'recherche'],
        '🚜 Agriculture' => ['agricu', 'pêche', 'alimentation', 'ferme'],
        '🌍 International' => ['europe', 'international', 'guerre', 'accord'],
        '🏢 Économie' => ['entreprise', 'travail', 'emploi', 'industrie', 'commerce'],
        '🏛️ Institutions' => ['constitution', 'institution', 'assemblée', 'vote'],
        '🏠 Logement' => ['logement', 'immobilier', 'urbanisme']
    ];
    foreach ($regles as $theme => $motsCles) {
        foreach ($motsCles as $mot) {
            if (strpos($t, $mot) !== false) return $theme;
        }
    }
    return '📜 Autres';
}

function determinerType($titre, $objet)
{
    $full = mb_strtolower($titre . ' ' . $objet, 'UTF-8');
    if (strpos($full, 'amendement') !== false) return 'amendement';
    if (strpos($full, 'ensemble du projet') !== false || strpos($full, 'ensemble de la proposition') !== false) return 'loi';
    if (strpos($full, 'motion') !== false) return 'motion';
    if (strpos($full, 'déclaration') !== false) return 'declaration';
    return 'autre';
}

function importScrutins($leg, $url)
{
    global $pdo, $tmpDir;
    $dirRaw = $tmpDir . "scrutins_$leg/";
    viderDossier($dirRaw);
    if (downloadAndExtract($url, $dirRaw)) {
        $files = is_dir($dirRaw . "xml/") ? glob($dirRaw . "xml/*.xml") : glob($dirRaw . "*.xml");
        if (count($files) > 0) {
            $sqlScrutin = "INSERT OR IGNORE INTO scrutins (uid, numero, legislature, date_scrutin, titre, objet, sort, pour, contre, abstention, theme, type_scrutin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtS = $pdo->prepare($sqlScrutin);
            $sqlVote = "INSERT OR IGNORE INTO votes (scrutin_uid, acteur_uid, vote, groupe_uid) VALUES (?, ?, ?, ?)";
            $stmtV = $pdo->prepare($sqlVote);
            $pdo->beginTransaction();
            foreach ($files as $f) {
                $xml = simplexml_load_file($f);
                if (!$xml) continue;
                $uidS = (string)$xml->uid;
                $stmtS->execute([$uidS, (int)$xml->numero, $leg, (string)$xml->dateScrutin, (string)$xml->titre, (string)$xml->objet->libelle, (string)$xml->sort->code, (int)$xml->syntheseVote->decompte->pour, (int)$xml->syntheseVote->decompte->contre, (int)$xml->syntheseVote->decompte->abstentions, determinerTheme((string)$xml->titre . ' ' . (string)$xml->objet->libelle), determinerType((string)$xml->titre, (string)$xml->objet->libelle)]);
                if (isset($xml->ventilationVotes->organe->groupes->groupe)) {
                    foreach ($xml->ventilationVotes->organe->groupes->groupe as $g) {
                        $gRef = (string)$g->organeRef;
                        $votes = ['Pour' => $g->vote->decompteNominatif->pours, 'Contre' => $g->vote->decompteNominatif->contres, 'Abstention' => $g->vote->decompteNominatif->abstentions];
                        foreach ($votes as $choix => $node) {
                            if (isset($node->votant)) {
                                foreach ($node->votant as $v) {
                                    $stmtV->execute([$uidS, (string)$v->acteurRef, $choix, $gRef]);
                                }
                            }
                        }
                    }
                }
            }
            $pdo->commit();
        }
    }
    viderDossier($dirRaw);
}

// --- AFFICHAGE ---
if (!IS_CLI && $step == 0) {
    echo '<h1>🚀 <a href="?step=1">Lancer la Mise à jour</a></h1>';
    exit;
}
if (!IS_CLI && $step > 0 && $step < 6) {
    echo '<meta http-equiv="refresh" content="1;url=?step=' . ($step + 1) . '" /><h2>Étape ' . $step . ' / 5...</h2>';
}

// --- ETAPES ---
if ($step == 1) {
    $tables = ['votes', 'scrutins', 'deputes', 'groupes', 'stats_deputes', 'stats_groupes'];
    foreach ($tables as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    $pdo->exec("CREATE TABLE groupes (uid TEXT PRIMARY KEY, libelle TEXT, couleur TEXT)");
    $pdo->exec("CREATE TABLE deputes (uid TEXT PRIMARY KEY, nom TEXT, groupe_uid TEXT, photo_url TEXT, departement TEXT, circonscription TEXT, place_hemicycle TEXT, date_naissance DATE, profession TEXT, est_actif INTEGER)");
    $pdo->exec("CREATE TABLE scrutins (uid TEXT PRIMARY KEY, numero INTEGER, legislature INTEGER, date_scrutin DATE, titre TEXT, objet TEXT, sort TEXT, pour INTEGER, contre INTEGER, abstention INTEGER, theme TEXT, type_scrutin TEXT)");
    $pdo->exec("CREATE TABLE votes (id INTEGER PRIMARY KEY AUTOINCREMENT, scrutin_uid TEXT, acteur_uid TEXT, vote TEXT, groupe_uid TEXT)");
    $pdo->exec("CREATE TABLE stats_deputes (legislature INTEGER, depute_uid TEXT, nb_total INTEGER, nb_loi INTEGER, nb_amendement INTEGER, nb_motion INTEGER, nb_autre INTEGER, PRIMARY KEY(legislature, depute_uid))");
    $pdo->exec("CREATE TABLE stats_groupes (legislature INTEGER, nom_groupe TEXT, theme TEXT, type_scrutin TEXT, nb_pour INTEGER, nb_contre INTEGER, nb_abs INTEGER, total INTEGER)");
}

if ($step == 2) {
    // Import Députés
    $url = "https://data.assemblee-nationale.fr/static/openData/repository/17/amo/tous_acteurs_mandats_organes_xi_legislature/AMO30_tous_acteurs_tous_mandats_tous_organes_historique.xml.zip";
    $dirRaw = $tmpDir . "acteurs/";
    viderDossier($dirRaw);
    if (downloadAndExtract($url, $dirRaw)) {
        $stmtG = $pdo->prepare("INSERT OR REPLACE INTO groupes (uid, libelle, couleur) VALUES (?, ?, ?)");
        $stmtD = $pdo->prepare("INSERT OR REPLACE INTO deputes (uid, nom, groupe_uid, photo_url, departement, circonscription, place_hemicycle, date_naissance, profession, est_actif) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $pdo->beginTransaction();
        
        // 1. Groupes
        foreach (glob($dirRaw . "xml/organe/*.xml") as $f) {
            $xml = simplexml_load_file($f);
            if ((string)$xml->codeType === 'GP') {
                $stmtG->execute([(string)$xml->uid, (string)$xml->libelle, (string)$xml->couleurAssociee ?? '#888']);
            }
        }

        // 2. Acteurs (Députés)
        foreach (glob($dirRaw . "xml/acteur/*.xml") as $f) {
            $xml = simplexml_load_file($f);
            $uid = (string)$xml->uid;
            $nom = (string)$xml->etatCivil->ident->prenom . " " . (string)$xml->etatCivil->ident->nom;
            $photo = "https://www2.assemblee-nationale.fr/static/tribun/17/photos/" . substr($uid, 2) . ".jpg";
            $grp = 'NI';
            $dept = '';
            $circo = '';
            $siege = null; // On initialise à null pour qu'il soit écrasé si trouvé
            $actif = 0;

            // A. Analyse des mandats
            if (isset($xml->mandats->mandat)) {
                $dFinRef = '0000-00-00';
                foreach ($xml->mandats->mandat as $m) {
                    $dFin = (string)$m->dateFin;
                    if (empty($dFin)) $dFin = '9999-12-31';

                    // Groupe politique
                    if ((string)$m->typeOrgane === 'GP' && $dFin >= $dFinRef) {
                        $dFinRef = $dFin;
                        $grp = (string)$m->organes->organeRef;
                    }
                    
                    // Mandat Assemblée
                    if ((string)$m->typeOrgane === 'ASSEMBLEE') {
                        if ($dFin > date('Y-m-d')) $actif = 1;
                        if (isset($m->election->lieu->departement)) $dept = (string)$m->election->lieu->departement;
                        if (isset($m->election->lieu->numCirco)) $circo = (string)$m->election->lieu->numCirco;
                        
                        // --- AJOUT : Récupération place via mandature (Ancienne méthode) ---
                        if (isset($m->mandature->placeHemicycle) && !empty($m->mandature->placeHemicycle)) {
                            $siege = (string)$m->mandature->placeHemicycle;
                        }
                    }
                }
            }

            // B. Sécurité : Si le siège est toujours vide, on regarde le bloc global (Nouvelle méthode)
            if (empty($siege) && isset($xml->placesHemicycle->placeHemicycle)) {
                foreach ($xml->placesHemicycle->placeHemicycle as $p) {
                    $dateFinPlace = (string)$p->dateFin;
                    // Si pas de date de fin ou date future => Siège actuel
                    if (empty($dateFinPlace) || $dateFinPlace > date('Y-m-d')) {
                        $siege = (string)$p->place;
                        break; 
                    }
                }
            }

            $stmtD->execute([$uid, $nom, $grp, $photo, $dept, $circo, (string)$siege, (string)$xml->etatCivil->infoNaissance->dateNais, (string)$xml->profession->libelleCourant, $actif]);
        }
        $pdo->commit();
    }
    viderDossier($dirRaw);
}

if ($step == 3) {
    importScrutins(16, "https://data.assemblee-nationale.fr/static/openData/repository/16/loi/scrutins/Scrutins.xml.zip");
}
if ($step == 4) {
    importScrutins(17, "https://data.assemblee-nationale.fr/static/openData/repository/17/loi/scrutins/Scrutins.xml.zip");
}

if ($step == 5) {
    // Calculs finaux
    $pdo->exec("INSERT INTO stats_deputes (legislature, depute_uid, nb_total, nb_loi, nb_amendement, nb_motion, nb_autre) 
                SELECT s.legislature, v.acteur_uid, COUNT(*), SUM(CASE WHEN s.type_scrutin='loi' THEN 1 ELSE 0 END), SUM(CASE WHEN s.type_scrutin='amendement' THEN 1 ELSE 0 END), SUM(CASE WHEN s.type_scrutin='motion' THEN 1 ELSE 0 END), SUM(CASE WHEN s.type_scrutin NOT IN ('loi', 'amendement', 'motion') THEN 1 ELSE 0 END) 
                FROM votes v JOIN scrutins s ON v.scrutin_uid = s.uid GROUP BY s.legislature, v.acteur_uid");

    $pdo->exec("INSERT INTO stats_groupes (legislature, nom_groupe, theme, type_scrutin, nb_pour, nb_contre, nb_abs, total) 
                SELECT s.legislature, TRIM(REPLACE(REPLACE(g.libelle, 'Groupe ', ''), ' (NUPES)', '')), s.theme, s.type_scrutin, SUM(CASE WHEN v.vote='Pour' THEN 1 ELSE 0 END), SUM(CASE WHEN v.vote='Contre' THEN 1 ELSE 0 END), SUM(CASE WHEN v.vote='Abstention' THEN 1 ELSE 0 END), COUNT(*) 
                FROM votes v JOIN scrutins s ON v.scrutin_uid = s.uid JOIN groupes g ON v.groupe_uid = g.uid GROUP BY s.legislature, TRIM(REPLACE(REPLACE(g.libelle, 'Groupe ', ''), ' (NUPES)', '')), s.theme, s.type_scrutin");

    echo "<script>window.location.href='?step=6';</script>";
}

if ($step == 6) {
    // 1. On ferme la connexion à la base temporaire
    $pdo = null;

    // 2. On écrase la base de PROD avec la base TEMP
    $dbProd = __DIR__ . '/data/assemblee.sqlite';
    $dbTemp = __DIR__ . '/data/assemblee_temp.sqlite';

    if (file_exists($dbTemp)) {
        if (!rename($dbTemp, $dbProd)) {
            if (copy($dbTemp, $dbProd)) {
                unlink($dbTemp);
            } else {
                die("Erreur critique : Impossible de copier la base de données vers le dossier de production.");
            }
        }
        echo "<h1>✅ Terminé ! Base de données mise à jour.</h1><p><a href='classement.php'>Voir le Classement</a></p>";
    } else {
        echo "<h1>⚠️ Erreur : Fichier temporaire introuvable.</h1>";
    }
}