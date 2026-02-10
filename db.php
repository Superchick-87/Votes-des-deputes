<?php
try {
    // Création automatique du dossier data si absent
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) mkdir($dataDir, 0777, true);

    $db_file   = $dataDir . '/assemblee.sqlite';       // Production
    $temp_file = $dataDir . '/assemblee_temp.sqlite';  // Construction

    // Si on est en mode UPDATE
    if (defined('MODE_UPDATE') && MODE_UPDATE === true) {
        $target_db = $temp_file;

        // --- CORRECTION POUR COMPATIBILITÉ CLI & WEB ---
        // On récupère l'étape soit par l'URL (Web), soit par la logique du script (CLI)
        $step = 0;
        if (defined('IS_CLI') && IS_CLI) {
            // En mode CLI, le script update.php gère sa boucle de 1 à 6.
            // La variable $i dans la boucle de update.php définit l'étape.
            // On peut utiliser la variable globale $step si elle est déjà définie.
            global $step;
        } else {
            $step = isset($_GET['step']) ? (int)$_GET['step'] : 0;
        }

        // On ne supprime le fichier temporaire QUE si on commence l'étape 1
        // Cela permet de repartir sur une base propre sans corrompre les étapes suivantes
        if ($step === 1) {
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
        }
        // -----------------------------------------------

    } else {
        $target_db = $db_file;
    }

    $pdo = new PDO("sqlite:" . $target_db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);

    // Optimisation pour l'écriture rapide
    if (defined('MODE_UPDATE') && MODE_UPDATE === true) {
        $pdo->exec("PRAGMA synchronous = OFF");
        $pdo->exec("PRAGMA journal_mode = MEMORY");
    }
} catch (Exception $e) {
    die("Erreur de connexion SQL : " . $e->getMessage());
}
