<?php
try {
    // Création automatique du dossier data si absent
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) mkdir($dataDir, 0777, true);
    
    // Définition des chemins des fichiers (accessibles après le require)
    $db_file   = $dataDir . '/assemblee.sqlite';       // La VRAIE base (Production)
    $temp_file = $dataDir . '/assemblee_temp.sqlite';  // La base TEMPORAIRE (Construction)

    // LOGIQUE INTELLIGENTE :
    // Si la constante 'MODE_UPDATE' est définie à true (par update.php), on tape sur le fichier temporaire.
    // Sinon (pour les visiteurs du site), on tape sur la vraie base.
    if (defined('MODE_UPDATE') && MODE_UPDATE === true) {
        $target_db = $temp_file;
        
        // Sécurité : On s'assure de repartir d'un fichier vide pour la mise à jour
        // (Uniquement si on n'est pas déjà connecté, pour éviter de supprimer en boucle)
        if (!isset($pdo)) { 
             if (file_exists($temp_file)) unlink($temp_file);
        }
    } else {
        $target_db = $db_file;
    }

    $pdo = new PDO("sqlite:" . $target_db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);

    // OPTIMISATION SPECIALE MISE A JOUR :
    // Si on est sur le fichier temporaire, on désactive les sécurités d'écriture pour aller 10x plus vite
    if (defined('MODE_UPDATE') && MODE_UPDATE === true) {
        $pdo->exec("PRAGMA synchronous = OFF");
        $pdo->exec("PRAGMA journal_mode = MEMORY");
    }

} catch (Exception $e) {
    die("Erreur de connexion SQL : " . $e->getMessage());
}
?>