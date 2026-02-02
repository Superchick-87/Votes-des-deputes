<?php 
require 'db.php'; 

// --- 1. RÉCUPÉRATION DES PARAMÈTRES ---
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20; 

$filterTheme = $_GET['theme'] ?? 'all';
$filterSort  = $_GET['sort'] ?? 'all';
$search      = $_GET['search'] ?? ''; 

$filterDay   = $_GET['day'] ?? 'all';
$filterMonth = $_GET['month'] ?? 'all';
$filterYear  = $_GET['year'] ?? 'all';

// --- 2. CONSTRUCTION DE LA REQUÊTE SQL ---
$conditions = [];
$params = [];

if ($filterTheme !== 'all') { $conditions[] = "theme = ?"; $params[] = $filterTheme; }
if ($filterSort !== 'all') { $conditions[] = "sort = ?"; $params[] = $filterSort; }
if (!empty($search)) { $conditions[] = "(titre LIKE ? OR objet LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($filterYear !== 'all') { $conditions[] = "strftime('%Y', date_scrutin) = ?"; $params[] = $filterYear; }
if ($filterMonth !== 'all') { $conditions[] = "strftime('%m', date_scrutin) = ?"; $params[] = sprintf("%02d", $filterMonth); }
if ($filterDay !== 'all') { $conditions[] = "strftime('%d', date_scrutin) = ?"; $params[] = sprintf("%02d", $filterDay); }

$sqlWhere = "";
if (count($conditions) > 0) { $sqlWhere = "WHERE " . implode(" AND ", $conditions); }

// --- 3. EXÉCUTION DES REQUÊTES ---
try {
    $stmtYears = $pdo->query("SELECT DISTINCT strftime('%Y', date_scrutin) as y FROM scrutins ORDER BY y DESC");
    $yearsDispo = $stmtYears->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $yearsDispo = []; }

try {
    $stmtThemes = $pdo->query("SELECT DISTINCT theme FROM scrutins WHERE theme != '' ORDER BY theme ASC");
    $themesDispo = $stmtThemes->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $themesDispo = []; }

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM scrutins $sqlWhere");
$stmtCount->execute($params);
$totalScrutins = $stmtCount->fetchColumn();
$totalPages = ceil($totalScrutins / $limit);

$offset = ($page - 1) * $limit;
$sql = "SELECT * FROM scrutins $sqlWhere ORDER BY date_scrutin DESC, numero DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$res = $stmt->fetchAll();

$moisFrancais = [1=>'Janvier', 2=>'Février', 3=>'Mars', 4=>'Avril', 5=>'Mai', 6=>'Juin', 7=>'Juillet', 8=>'Août', 9=>'Septembre', 10=>'Octobre', 11=>'Novembre', 12=>'Décembre'];

function getUrl($newPage) {
    global $limit, $filterTheme, $filterSort, $filterDay, $filterMonth, $filterYear, $search;
    return "?page=$newPage&limit=$limit&theme=".urlencode($filterTheme)."&sort=".urlencode($filterSort)."&day=".urlencode($filterDay)."&month=".urlencode($filterMonth)."&year=".urlencode($filterYear)."&search=".urlencode($search);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Scrutins Publics</title>
    <link rel="stylesheet" href="css/style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
    <div class="container">
        
        <header style="display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; margin-bottom:30px;">
            <h1 style="margin:0;">🏛️ Scrutins Publics</h1>
            <div style="display:flex; gap:10px; margin-top:10px;">
                <a href="classement.php" class="btn-update" style="background:#8e44ad;">🏆 Voir le Classement</a>
                <a href="update.php" class="btn-update" style="display:none;">>🔄 Mettre à jour</a>
            </div>
        </header>

        <form method="GET" action="index.php" class="filters-container">
            <div class="filters-row">
                <input type="text" name="search" placeholder="🔍 Rechercher un mot clé (ex: nucléaire, budget...)" value="<?= htmlspecialchars($search) ?>">
                
                <select name="theme" onchange="this.form.submit()">
                    <option value="all">📂 Tous les Thèmes</option>
                    <?php foreach($themesDispo as $th): ?>
                        <option value="<?= htmlspecialchars($th) ?>" <?= ($filterTheme === $th) ? 'selected' : '' ?>><?= $th ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filters-row" style="border-top:1px solid #eee; padding-top:15px; margin-bottom:0;">
                <select name="sort" onchange="this.form.submit()">
                    <option value="all">📊 Résultat (Tous)</option>
                    <option value="adopté" <?= ($filterSort === 'adopté') ? 'selected' : '' ?>>✅ Adopté</option>
                    <option value="rejeté" <?= ($filterSort === 'rejeté') ? 'selected' : '' ?>>❌ Rejeté</option>
                </select>

                <div style="display:flex; gap:5px; align-items:center;">
                    <select name="day" onchange="this.form.submit()">
                        <option value="all">J</option>
                        <?php for($d=1; $d<=31; $d++): ?>
                            <option value="<?= $d ?>" <?= ($filterDay == $d) ? 'selected' : '' ?>><?= sprintf("%02d", $d) ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="month" onchange="this.form.submit()">
                        <option value="all">Mois</option>
                        <?php foreach($moisFrancais as $num => $nom): ?>
                            <option value="<?= $num ?>" <?= ($filterMonth == $num) ? 'selected' : '' ?>><?= substr($nom,0,3) ?>.</option>
                        <?php endforeach; ?>
                    </select>
                    <select name="year" onchange="this.form.submit()">
                        <option value="all">Année</option>
                        <?php foreach($yearsDispo as $y): ?>
                            <option value="<?= $y ?>" <?= ($filterYear == $y) ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <select name="limit" onchange="this.form.submit()" title="Nombre par page">
                    <option value="12" <?= ($limit == 12) ? 'selected' : '' ?>>12 / page</option>
                    <option value="24" <?= ($limit == 24) ? 'selected' : '' ?>>24 / page</option>
                    <option value="48" <?= ($limit == 48) ? 'selected' : '' ?>>48 / page</option>
                </select>

                <div class="count-badge"><?= $totalScrutins ?> résultats</div>
            </div>
            
            <input type="hidden" name="page" value="1"> 
        </form>

        <?php if ($totalPages > 1): ?>
        <div class="pagination-bar">
            <a href="<?= getUrl($page - 1) ?>" class="btn-page <?= ($page <= 1) ? 'disabled' : '' ?>">&larr; Précédent</a>
            <span style="color:#666;">Page <?= $page ?> / <?= $totalPages ?></span>
            <a href="<?= getUrl($page + 1) ?>" class="btn-page <?= ($page >= $totalPages) ? 'disabled' : '' ?>">Suivant &rarr;</a>
        </div>
        <?php endif; ?>

        <div class="liste-scrutins">
            <?php if(empty($res)): ?>
                <div style="grid-column: 1 / -1; text-align:center; padding: 50px; background:#fff; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.05);">
                    <div style="font-size:3em; margin-bottom:10px;">🕵️‍♂️</div>
                    <h3 style="color:#555;">Aucun scrutin trouvé</h3>
                    <p>Essayez de modifier vos filtres ou votre recherche.</p>
                    <a href="index.php" style="display:inline-block; margin-top:10px; padding:10px 20px; background:#3498db; color:white; border-radius:5px; text-decoration:none;">Tout réinitialiser</a>
                </div>
            <?php else: 
                foreach($res as $s):
                    $isAdopte = ($s->sort == 'adopté');
                    $classCard = $isAdopte ? 'card-adopte' : 'card-rejete';
                    $classBadge = $isAdopte ? 'bg-green' : 'bg-red';
            ?>
                <a href="scrutin.php?uid=<?= $s->uid ?>" class="card-scrutin <?= $classCard ?>">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div class="date"><?= date('d/m/Y', strtotime($s->date_scrutin)) ?></div>
                        <?php if($s->theme): ?>
                        <div style="font-size:0.7em; background:#f0f2f5; padding:3px 8px; border-radius:10px; color:#555; max-width:120px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                            <?= $s->theme ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <h3>Scrutin n°<?= $s->numero ?> : <?= $s->titre ?></h3>
                    
                    <p style="display:none;"><?= (strlen($s->objet) > 130) ? substr($s->objet, 0, 130) . '...' : $s->objet ?></p>
                    
                    <div class="barre-resultat" style="margin-top:auto; justify-content: flex-start; font-size: 0.8em;">
                        <div class="stat-box p-pour">Pour: <?= $s->pour ?></div>
                        <div class="stat-box p-contre">Contre: <?= $s->contre ?></div>
                        
                        <div class="resultat-final <?= $classBadge ?>" style="margin-left:auto; font-size:0.9em;"><?= strtoupper($s->sort) ?></div>
                    </div>
                </a>
            <?php endforeach; endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination-bar">
            <a href="<?= getUrl($page - 1) ?>" class="btn-page <?= ($page <= 1) ? 'disabled' : '' ?>">&larr; Précédent</a>
            <span style="color:#666;">Page <?= $page ?> / <?= $totalPages ?></span>
            <a href="<?= getUrl($page + 1) ?>" class="btn-page <?= ($page >= $totalPages) ? 'disabled' : '' ?>">Suivant &rarr;</a>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>