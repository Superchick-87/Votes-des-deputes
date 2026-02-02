<?php
// inspect_db.php
// Affiche la structure réelle de la base de données SQLite

require 'db.php';

echo "<h1>🔍 Inspection de la structure de la Base de Données</h1>";

try {
    // 1. Récupérer la liste des tables
    $query = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    $tables = $query->fetchAll(PDO::FETCH_COLUMN);

    if (empty($tables)) {
        echo "<h2 style='color:red'>La base de données est VIDE !</h2>";
    } else {
        echo "<div style='display:flex; flex-wrap:wrap; gap:20px;'>";
        
        foreach ($tables as $table) {
            if ($table === 'sqlite_sequence') continue; // On ignore la table système

            echo "<div style='background:#f4f4f4; padding:15px; border-radius:8px; border:1px solid #ccc; min-width:250px;'>";
            echo "<h3 style='margin-top:0; border-bottom:2px solid #333; padding-bottom:5px;'>Table : <span style='color:#2980b9'>$table</span></h3>";
            
            // 2. Récupérer les colonnes pour chaque table
            $colsQuery = $pdo->query("PRAGMA table_info($table)");
            $columns = $colsQuery->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<ul style='padding-left:20px;'>";
            foreach ($columns as $col) {
                $name = htmlspecialchars($col['name']);
                $type = htmlspecialchars($col['type']);
                $pk = ($col['pk'] == 1) ? " 🔑" : "";
                
                // Mise en évidence de la colonne problématique
                if ($name === 'libelle' || $name === 'nom' || $name === 'titre') {
                    echo "<li style='color:green; font-weight:bold;'>$name <span style='color:#999; font-size:0.8em'>($type)$pk</span></li>";
                } else {
                    echo "<li>$name <span style='color:#999; font-size:0.8em'>($type)$pk</span></li>";
                }
            }
            echo "</ul>";
            
            // Compter les lignes
            $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            echo "<div style='font-size:0.9em; color:#666; margin-top:10px;'>$count enregistrements</div>";
            echo "</div>";
        }
        echo "</div>";
    }

} catch (PDOException $e) {
    echo "<h2 style='color:red'>Erreur : " . $e->getMessage() . "</h2>";
}
?>