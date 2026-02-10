<?php

/**
 * update.php - SCRIPT DE MISE À JOUR AUTOMATISÉ
 * * Ce script gère l'importation des données de l'Assemblée Nationale.
 * Il fonctionne par étapes pour éviter les dépassements de mémoire (Timeout/RAM).
 * Supporte l'exécution via Navigateur (Web) ou via Tâche Cron (CLI).
 */

// --- CONFIGURATION SYSTÈME ---
define('IS_CLI', php_sapi_name() === 'cli');
ini_set('memory_limit', '2048M');
set_time_limit(0); // Pas de limite de temps pour les imports massifs
$cronRequested = isset($_GET['cron']) ? (int)$_GET['cron'] : 0;
$stepRequested = isset($_GET['step']) ? (int)$_GET['step'] : 0;

// --- CHARGEMENT DE LA CONFIGURATION ---
echo '*'.$stepRequested.'*';
if ($cronRequested==1) 
	$configPath = '/data/www/infographie/prod/Votes-des-deputes/config.json';
else
	$configPath = __DIR__ . '/config.json';
if (!file_exists($configPath)) {
    die("Erreur : Le fichier config.json est manquant.");
}
$config = json_decode(file_get_contents($configPath), true);

// Filtrage des législatures actives uniquement
$lesLegislatures = array_values(array_filter($config['legislatures'], function ($l) {
    return isset($l['active']) && $l['active'] === true;
}));

// Calcul des étapes totales :
// 1 (Initialisation) + 1 (Acteurs) + X (Scrutins actifs) + 1 (Stats & Finalisation)
$totalSteps = 3 + count($lesLegislatures);

// --- INITIALISATION BASE DE DONNÉES ---
define('MODE_UPDATE', true);
require __DIR__ . '/db.php'; // Charge la connexion PDO vers assemblee_temp.sqlite

$tmpDir = __DIR__ . "/tmp/";
if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

// --- LOGIQUE D'ENTRÉE ---
$stepRequested = isset($_GET['step']) ? (int)$_GET['step'] : 0;
$cronRequested = isset($_GET['cron']) ? (int)$_GET['cron'] : 0;

if ($cronRequested == 1) {
    // Mode Ligne de Commande (Cron)
    logMessage("--- LANCEMENT TÂCHE AUTOMATIQUE (CLI) ---");
    try {
        for ($i = 1; $i <= $totalSteps; $i++) {
            runStep($i);
        }
        logMessage("--- FIN DE TÂCHE RÉUSSIE ---");
    } catch (Exception $e) {
        envoiAlerte($e->getMessage());
    }
    exit;
} else {
    // Mode Navigateur (Interface Web)
    afficherEntete($stepRequested, $totalSteps);

    if ($stepRequested == 0) {
        echo '<div style="text-align:center; margin-top:50px;">
                <p>Prêt à importer ' . count($lesLegislatures) . ' législature(s).</p>
                <a href="?step=1" style="padding:15px 30px; background:#2ecc71; color:white; text-decoration:none; border-radius:5px; font-weight:bold;">🚀 LANCER LA MISE À JOUR</a>
              </div>';
    } else {
        try {
            runStep($stepRequested);
            if ($stepRequested < $totalSteps) {
                $next = $stepRequested + 1;
                echo "<p>Étape $stepRequested terminée avec succès...</p>";
                echo "<p>Redirection vers l'étape suivante dans 1 seconde...</p>";
                echo "<meta http-equiv='refresh' content='1;url=?step=$next' />";
            }
        } catch (Exception $e) {
            echo "<div style='background:#e74c3c; color:white; padding:20px; border-radius:5px;'>
                    <strong>ERREUR :</strong> " . $e->getMessage() . "
                  </div>";
            envoiAlerte($e->getMessage());
        }
    }
    afficherPiedDePage();
}

/**
 * FONCTION PRINCIPALE : GESTION DES ÉTAPES
 */
function runStep($step)
{
    global $pdo, $tmpDir, $config, $lesLegislatures, $totalSteps;
    logMessage("Début de l'étape $step / $totalSteps");

    switch ($step) {
        case 1:
            // --- ÉTAPE 1 : RÉINITIALISATION DES TABLES ---
            $tables = ['votes', 'scrutins', 'deputes', 'groupes', 'stats_deputes', 'stats_groupes'];
            foreach ($tables as $t) $pdo->exec("DROP TABLE IF EXISTS $t");

            $pdo->exec("CREATE TABLE groupes (uid TEXT PRIMARY KEY, libelle TEXT, couleur TEXT)");
            $pdo->exec("CREATE TABLE deputes (uid TEXT PRIMARY KEY, nom TEXT, groupe_uid TEXT, photo_url TEXT, departement TEXT, circonscription TEXT, place_hemicycle TEXT, date_naissance DATE, profession TEXT, est_actif INTEGER)");
            $pdo->exec("CREATE TABLE scrutins (uid TEXT PRIMARY KEY, numero INTEGER, legislature INTEGER, date_scrutin DATE, titre TEXT, objet TEXT, sort TEXT, pour INTEGER, contre INTEGER, abstention INTEGER, theme TEXT, type_scrutin TEXT)");
            $pdo->exec("CREATE TABLE votes (id INTEGER PRIMARY KEY AUTOINCREMENT, scrutin_uid TEXT, acteur_uid TEXT, vote TEXT, groupe_uid TEXT)");
            $pdo->exec("CREATE TABLE stats_deputes (legislature INTEGER, depute_uid TEXT, nb_total INTEGER, nb_loi INTEGER, nb_amendement INTEGER, nb_motion INTEGER, nb_autre INTEGER, PRIMARY KEY(legislature, depute_uid))");
            $pdo->exec("CREATE TABLE stats_groupes (legislature INTEGER, nom_groupe TEXT, theme TEXT, type_scrutin TEXT, nb_pour INTEGER, nb_contre INTEGER, nb_abs INTEGER, total INTEGER)");
            logMessage("Tables réinitialisées.");
            break;

        case 2:
            // --- ÉTAPE 2 : IMPORT DES ACTEURS ET ORGANES ---
            importActeurs($config['url_acteurs']);
            break;

        default:
            // --- ÉTAPES INTERMÉDIAIRES : SCRUTINS ---
            $indexLeg = $step - 3;
            if (isset($lesLegislatures[$indexLeg])) {
                $legData = $lesLegislatures[$indexLeg];
                logMessage("Importation des scrutins - Législature " . $legData['id']);
                importScrutins($legData['id'], $legData['url_scrutins']);
            }
            // --- ÉTAPE FINALE : CALCULS ET DÉPLOIEMENT ---
            elseif ($step == $totalSteps) {
                logMessage("Calcul des statistiques finales...");

                // Stats par député
                $pdo->exec("INSERT INTO stats_deputes (legislature, depute_uid, nb_total, nb_loi, nb_amendement, nb_motion, nb_autre) 
                            SELECT s.legislature, v.acteur_uid, COUNT(*), 
                            SUM(CASE WHEN s.type_scrutin='loi' THEN 1 ELSE 0 END), 
                            SUM(CASE WHEN s.type_scrutin='amendement' THEN 1 ELSE 0 END), 
                            SUM(CASE WHEN s.type_scrutin='motion' THEN 1 ELSE 0 END), 
                            SUM(CASE WHEN s.type_scrutin NOT IN ('loi', 'amendement', 'motion') THEN 1 ELSE 0 END) 
                            FROM votes v JOIN scrutins s ON v.scrutin_uid = s.uid GROUP BY s.legislature, v.acteur_uid");

                // Stats par groupe
                $pdo->exec("INSERT INTO stats_groupes (legislature, nom_groupe, theme, type_scrutin, nb_pour, nb_contre, nb_abs, total) 
                            SELECT s.legislature, TRIM(REPLACE(REPLACE(g.libelle, 'Groupe ', ''), ' (NUPES)', '')), s.theme, s.type_scrutin, 
                            SUM(CASE WHEN v.vote='Pour' THEN 1 ELSE 0 END), 
                            SUM(CASE WHEN v.vote='Contre' THEN 1 ELSE 0 END), 
                            SUM(CASE WHEN v.vote='Abstention' THEN 1 ELSE 0 END), COUNT(*) 
                            FROM votes v JOIN scrutins s ON v.scrutin_uid = s.uid JOIN groupes g ON v.groupe_uid = g.uid 
                            GROUP BY s.legislature, TRIM(REPLACE(REPLACE(g.libelle, 'Groupe ', ''), ' (NUPES)', '')), s.theme, s.type_scrutin");

                // Fermeture et bascule de la base temporaire vers la production
                $pdo = null;
                $dbProd = __DIR__ . '/data/assemblee.sqlite';
                $dbTemp = __DIR__ . '/data/assemblee_temp.sqlite';

                if (file_exists($dbTemp)) {
                    if (@rename($dbTemp, $dbProd) || copy($dbTemp, $dbProd)) {
                        logMessage("SUCCÈS : Base de données mise à jour.");
                        if (!IS_CLI) echo "<h1 style='color:#2ecc71;'>✅ Mise à jour terminée avec succès !</h1>";
                    } else {
                        throw new Exception("Impossible de remplacer le fichier de production.");
                    }
                }
            }
            break;
    }
}

/**
 * LOGIQUE D'IMPORT DES ACTEURS
 */
function importActeurs($url)
{
    global $pdo, $tmpDir;
    $dirRaw = $tmpDir . "acteurs/";
    viderDossier($dirRaw);
    if (!downloadAndExtract($url, $dirRaw)) throw new Exception("Échec téléchargement Acteurs");

    $stmtG = $pdo->prepare("INSERT OR REPLACE INTO groupes (uid, libelle, couleur) VALUES (?, ?, ?)");
    $stmtD = $pdo->prepare("INSERT OR REPLACE INTO deputes (uid, nom, groupe_uid, photo_url, departement, circonscription, place_hemicycle, date_naissance, profession, est_actif) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $pdo->beginTransaction();
    // 1. Organes (Groupes parlementaires)
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
        $siege = null;
        $actif = 0;

        if (isset($xml->mandats->mandat)) {
            foreach ($xml->mandats->mandat as $m) {
                $dFin = (string)$m->dateFin ?: '9999-12-31';
                if ((string)$m->typeOrgane === 'GP' && $dFin >= date('Y-m-d')) $grp = (string)$m->organes->organeRef;
                if ((string)$m->typeOrgane === 'ASSEMBLEE' && $dFin > date('Y-m-d')) {
                    $actif = 1;
                    $dept = (string)$m->election->lieu->departement;
                    $circo = (string)$m->election->lieu->numCirco;
                    $siege = (string)$m->mandature->placeHemicycle;
                }
            }
        }
        $stmtD->execute([$uid, $nom, $grp, $photo, $dept, $circo, (string)$siege, (string)$xml->etatCivil->infoNaissance->dateNais, (string)$xml->profession->libelleCourant, $actif]);
    }
    $pdo->commit();
    viderDossier($dirRaw);
    logMessage("Import Acteurs terminé.");
}

/**
 * LOGIQUE D'IMPORT DES SCRUTINS
 */
function importScrutins($leg, $url)
{
    global $pdo, $tmpDir;
    $dirRaw = $tmpDir . "scrutins_$leg/";
    viderDossier($dirRaw);
    if (!downloadAndExtract($url, $dirRaw)) throw new Exception("Échec Scrutins Législature $leg");

    $files = glob($dirRaw . "xml/*.xml") ?: glob($dirRaw . "*.xml");
    $pdo->beginTransaction();
    $stmtS = $pdo->prepare("INSERT OR IGNORE INTO scrutins (uid, numero, legislature, date_scrutin, titre, objet, sort, pour, contre, abstention, theme, type_scrutin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtV = $pdo->prepare("INSERT OR IGNORE INTO votes (scrutin_uid, acteur_uid, vote, groupe_uid) VALUES (?, ?, ?, ?)");

    foreach ($files as $f) {
        $xml = simplexml_load_file($f);
        if (!$xml) continue;
        $uidS = (string)$xml->uid;
        $stmtS->execute([$uidS, (int)$xml->numero, $leg, (string)$xml->dateScrutin, (string)$xml->titre, (string)$xml->objet->libelle, (string)$xml->sort->code, (int)$xml->syntheseVote->decompte->pour, (int)$xml->syntheseVote->decompte->contre, (int)$xml->syntheseVote->decompte->abstentions, determinerTheme((string)$xml->titre), determinerType((string)$xml->titre, (string)$xml->objet->libelle)]);

        if (isset($xml->ventilationVotes->organe->groupes->groupe)) {
            foreach ($xml->ventilationVotes->organe->groupes->groupe as $g) {
                $gRef = (string)$g->organeRef;
                $vTypes = ['Pour' => $g->vote->decompteNominatif->pours, 'Contre' => $g->vote->decompteNominatif->contres, 'Abstention' => $g->vote->decompteNominatif->abstentions];
                foreach ($vTypes as $choix => $node) {
                    if (isset($node->votant)) {
                        foreach ($node->votant as $v) $stmtV->execute([$uidS, (string)$v->acteurRef, $choix, $gRef]);
                    }
                }
            }
        }
    }
    $pdo->commit();
    viderDossier($dirRaw);
}

// --- FONCTIONS OUTILS ---

function logMessage($msg)
{
    $line = "[" . date("Y-m-d H:i:s") . "] " . $msg . PHP_EOL;
    file_put_contents(__DIR__ . "/update.log", $line, FILE_APPEND);
    if (IS_CLI) echo $line;
}

function viderDossier($dir)
{
    if (!is_dir($dir)) return;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $f) {
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    }
}

function downloadAndExtract($url, $destFolder)
{
    global $tmpDir;
    $zipFile = $tmpDir . "temp.zip";
    $fp = fopen($zipFile, 'w+');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_TIMEOUT => 600, CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true]);
    $success = curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    if (!$success) return false;
    $zip = new ZipArchive;
    if ($zip->open($zipFile) === TRUE) {
        $zip->extractTo($destFolder);
        $zip->close();
        unlink($zipFile);
        return true;
    }
    return false;
}

function determinerTheme($t)
{
    $t = mb_strtolower($t, 'UTF-8');
    $r = [
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
    foreach ($r as $th => $m) {
        foreach ($m as $word) {
            if (strpos($t, $word) !== false) return $th;
        }
    }
    return '📜 Autres';
}

function determinerType($t, $o)
{
    $f = mb_strtolower($t . ' ' . $o, 'UTF-8');
    if (strpos($f, 'amendement') !== false) return 'amendement';
    if (strpos($f, 'projet de loi') !== false || strpos($f, 'proposition de loi') !== false) return 'loi';
    if (strpos($f, 'motion') !== false) return 'motion';
    return 'autre';
}

function envoiAlerte($erreur)
{
    logMessage("ERREUR CRITIQUE : $erreur");
    // Ajoutez ici un envoi de mail si besoin via mail()
}

// --- FONCTIONS D'AFFICHAGE WEB ---

function afficherEntete($currentStep, $total)
{
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Update Assemblée</title>";
    echo "<style>
        body { font-family: sans-serif; background: #f4f7f6; color: #333; line-height: 1.6; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .menu { display: flex; justify-content: space-between; margin-bottom: 30px; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        .step { font-size: 0.8em; color: #999; }
        .step.active { color: #2c3e50; font-weight: bold; border-bottom: 2px solid #3498db; }
        .progress-bar { height: 10px; background: #eee; border-radius: 5px; margin-bottom: 20px; overflow: hidden; }
        .progress-fill { height: 100%; background: #3498db; width: " . ($total > 0 ? ($currentStep / $total * 100) : 0) . "%; transition: width 0.5s; }
    </style></head><body><div class='container'>";
    echo "<h1>⚙️ Mise à jour des données</h1>";

    if ($total > 0) {
        echo "<div class='progress-bar'><div class='progress-fill'></div></div>";
        echo "<div class='menu'>";
        for ($i = 1; $i <= $total; $i++) {
            $class = ($i == $currentStep) ? "step active" : "step";
            echo "<span class='$class'>Étape $i</span>";
        }
        echo "</div>";
    }
}

function afficherPiedDePage()
{
    echo "</div></body></html>";
}
