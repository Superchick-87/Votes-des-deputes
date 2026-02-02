<?php
// test_curl.php - A lancer pour diagnostiquer
ini_set('display_errors', 1);

$url = "https://data.assemblee-nationale.fr/static/openData/repository/17/loi/scrutins/Scrutins.xml.zip";

echo "<h2>Test de connexion vers l'Assemblée Nationale</h2>";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true); // On veut voir les en-têtes
curl_setopt($ch, CURLOPT_NOBODY, true); // On ne télécharge pas le corps, juste pour voir si ça répond
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.212 Safari/537.36');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "<strong>Code HTTP :</strong> $httpCode <br>";
if ($httpCode == 200) {
    echo "<span style='color:green'>✅ Le serveur accepte la connexion ! Le problème vient de l'écriture du fichier.</span>";
} elseif ($httpCode == 403) {
    echo "<span style='color:red'>🚫 Erreur 403 Forbidden : Votre IP serveur est bannie par l'Assemblée.</span>";
} elseif ($httpCode == 0) {
    echo "<span style='color:red'>💀 Erreur de connexion (Timeout/DNS) : $error</span>";
} else {
    echo "<pre>$response</pre>";
}
?>