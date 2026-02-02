<?php
// upload_zipper.php
ini_set('display_errors', 1);
ini_set('upload_max_filesize', '200M');
ini_set('post_max_size', '200M');
ini_set('memory_limit', '512M');
set_time_limit(300);

$msg = "";

if (isset($_FILES['zipfile'])) {
    $targetDir = __DIR__ . "/tmp/";
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    
    $file = $_FILES['zipfile'];
    $filename = $file['name'];
    $targetFile = $targetDir . $filename;
    $type = $_POST['type_extract']; // 'acteurs' ou 'scrutins'
    
    // 1. Déplacement du fichier uploadé
    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        
        // 2. Analyse du fichier (Est-ce un vrai ZIP ou du HTML ?)
        $handle = fopen($targetFile, 'r');
        $header = fread($handle, 4);
        fclose($handle);
        
        // La signature d'un ZIP commence souvent par "PK"
        if (strpos($header, 'PK') === false) {
            $msg .= "<div style='color:red; font-weight:bold; padding:10px; border:2px solid red; margin:10px 0;'>";
            $msg .= "⛔ ERREUR CRITIQUE : Ce n'est pas un vrai fichier ZIP !<br>";
            $msg .= "Le serveur de l'Assemblée vous a envoyé une page HTML d'erreur (protection anti-robot).<br>";
            $msg .= "Taille du fichier reçu : " . round($file['size']/1024, 2) . " Ko (Trop petit).<br>";
            $msg .= "Solution : Réessayez de télécharger le fichier depuis un autre navigateur ou en navigation privée.";
            $msg .= "</div>";
            unlink($targetFile); // On supprime le faux fichier
        } else {
            // 3. C'est un vrai ZIP, on décompresse
            $extractPath = ($type == 'acteurs') ? $targetDir . 'acteurs/' : $targetDir . 'Scrutins/';
            
            // Nettoyage dossier cible
            if (!is_dir($extractPath)) mkdir($extractPath, 0777, true);
            
            $zip = new ZipArchive;
            if ($zip->open($targetFile) === TRUE) {
                $zip->extractTo($extractPath);
                $zip->close();
                $msg .= "<div style='color:green; font-weight:bold; padding:10px; border:2px solid green;'>";
                $msg .= "✅ SUCCÈS ! Le fichier a été décompressé dans : $extractPath<br>";
                $msg .= "Vous pouvez maintenant lancer update.php";
                $msg .= "</div>";
                unlink($targetFile); // On supprime le zip pour gagner de la place
            } else {
                $msg .= "Erreur lors de la décompression PHP.";
            }
        }
    } else {
        $msg .= "Erreur lors de l'upload. Vérifiez la limite 'upload_max_filesize' de votre PHP.";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Dézippeur de Secours</title>
    <style>
        body { font-family: sans-serif; padding: 30px; background: #f4f4f9; text-align: center; }
        .box { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); max-width: 500px; margin: 0 auto; }
        h1 { margin-top: 0; color: #333; }
        input[type=file] { margin: 20px 0; }
        button { background: #3498db; color: white; border: none; padding: 15px 30px; font-size: 1.1em; border-radius: 5px; cursor: pointer; }
        button:hover { background: #2980b9; }
        select { padding: 10px; font-size: 1em; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>📦 Dézippeur de Secours</h1>
        <p>Si vous n'arrivez pas à dézipper sur votre PC, envoyez le fichier ici.</p>
        
        <?= $msg ?>
        
        <form method="post" enctype="multipart/form-data">
            <label><strong>1. Choisissez quel type de fichier c'est :</strong></label><br>
            <select name="type_extract">
                <option value="scrutins">Les Votes (Scrutins.xml.zip)</option>
                <option value="acteurs">Les Députés (AMO10...zip)</option>
            </select>
            <br><br>
            
            <label><strong>2. Sélectionnez le fichier ZIP :</strong></label><br>
            <input type="file" name="zipfile" required>
            <br>
            
            <button type="submit">Envoyer et Décompresser</button>
        </form>
    </div>
</body>
</html>