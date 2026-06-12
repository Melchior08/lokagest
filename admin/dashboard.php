<?php
/**
 * LokaGest - Tableau de bord d'Administration
 * 
 * Accès réservé aux administrateurs (F56).
 * Valide l'adresse IP et propose une vue globale sur les indicateurs de la plateforme
 * (propriétaires actifs, chambres, volume FCFA, commissions ce mois-ci) (F57).
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/supabase.php';

// F56 : Restriction IP optionnelle
$allowedIPs = getenv('ADMIN_ALLOWED_IPS') ? explode(',', getenv('ADMIN_ALLOWED_IPS')) : [];
$clientIP = $_SERVER['REMOTE_ADDR'] ?? '';

if (!empty($allowedIPs) && !in_array($clientIP, $allowedIPs) && $clientIP !== '127.0.0.1' && $clientIP !== '::1') {
    header('HTTP/1.1 403 Forbidden');
    echo "<h1>Accès Interdit</h1>";
    echo "<p>Votre adresse IP ($clientIP) n'est pas autorisée à accéder à cet espace d'administration.</p>";
    exit;
}

// Authentification basique Administrateur (Simulation de login forte ou vérification de session admin)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    // Si tentative de login
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_admin'])) {
        $password = $_POST['admin_password'] ?? '';
        // Utilisation d'un hachage ou mot de passe fort modifiable dans l'environnement
        $correctPassword = getenv('ADMIN_PASSWORD') ?: 'LokaGestAdmin2026!';
        
        if ($password === $correctPassword) {
            $_SESSION['admin_logged'] = true;
            // Journaliser l'action (F65)
            $log = [
                'action' => 'Connexion réussie à l\'administration',
                'compte_concerne' => 'admin',
                'date_action' => date('Y-m-d H:i:s')
            ];
            SupabaseClient::insert('admin_logs', $log);
        } else {
            $errorLogin = "Mot de passe incorrect.";
            // Journaliser la tentative échouée (F65 / F75)
            $log = [
                'action' => 'Tentative échouée de connexion administrateur',
                'compte_concerne' => 'IP: ' . $clientIP,
                'date_action' => date('Y-m-d H:i:s')
            ];
            SupabaseClient::insert('admin_logs', $log);
        }
    }
}

// Déconnexion Admin
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['admin_logged']);
    header('Location: dashboard.php');
    exit;
}

// Rendu de la page de connexion admin si non connecté
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true):
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration Login - LokaGest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="bg-dark d-flex align-items-center justify-content-center min-vh-100">
    <div class="app-container bg-white p-4 shadow rounded-4 text-center">
        <div class="my-4">
            <span class="fs-1 text-success"><i class="bi bi-shield-lock"></i></span>
            <h4 class="fw-bold text-dark mt-2">LokaGest Admin</h4>
            <p class="text-muted small">Espace réservé aux administrateurs réseau</p>
        </div>
        
        <?php if (isset($errorLogin)): ?>
            <div class="alert alert-danger small py-2"><?php echo htmlspecialchars($errorLogin); ?></div>
        <?php endif; ?>
        
        <form action="dashboard.php" method="POST">
            <div class="mb-4 text-start">
                <label for="admin_password" class="form-label small fw-semibold text-secondary">Mot de passe d'administration *</label>
                <input type="password" class="form-control rounded-3" id="admin_password" name="admin_password" required placeholder="Saisir la clé d'administration">
            </div>
            <button type="submit" name="login_admin" class="btn btn-success w-100 py-3 rounded-3 shadow-sm fw-bold">
                Se connecter
            </button>
        </form>
    </div>
</body>
</html>
<?php
exit;
endif;

// -------------------------------------------------------------
// TRAITEMENT ADMIN CONNECTÉ : RÉCUPÉRATION DES INDICES (F57)
// -------------------------------------------------------------
$ownersCount = 0;
$roomsCount = 0;
$paymentsCount = 0;
$totalAmountFCFA = 0;
$commissionsMois = 0;

// Propriétaires
$ownersRes = SupabaseClient::select('users', 'id, statut');
if ($ownersRes['status'] === 200 && is_array($ownersRes['data'])) {
    $ownersCount = count($ownersRes['data']);
}

// Chambres
$roomsRes = SupabaseClient::select('units', 'id');
if ($roomsRes['status'] === 200 && is_array($roomsRes['data'])) {
    $roomsCount = count($roomsRes['data']);
}

// Paiements confirmés
$paymentsRes = SupabaseClient::select('payments', '*', 'statut=eq.confirme');
if ($paymentsRes['status'] === 200 && is_array($paymentsRes['data'])) {
    $paymentsCount = count($paymentsRes['data']);
    foreach ($paymentsRes['data'] as $p) {
        $totalAmountFCFA += $p['montant'];
        // Commission théorique de 1% pour les non-fondateurs sur les transactions en ligne (Momo)
        // (F21 : Fondateurs = commission 0%)
        // Pour estimer les commissions, on charge l'utilisateur par paiement
        // Pour simplifier l'affichage du dashboard, on calcule 1% sur le volume hors espèces
        if ($p['mode'] !== 'especes') {
            // Dans ce MVP, on applique 1% de commission fictive pour l'affichage de projection de revenus
            $commissionsMois += ($p['montant'] * 0.01);
        }
    }
}

$logsRes = SupabaseClient::select('admin_logs', '*', 'order=date_action.desc&limit=10');
$logs = ($logsRes['status'] === 200 && is_array($logsRes['data'])) ? $logsRes['data'] : [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - LokaGest</title>
    
    <!-- Google Fonts - Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="admin-dark">

    <div class="app-container min-vh-100 d-flex flex-column pb-5" style="background:#0F172A;">
        
        <header class="p-4 pb-2">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h5 class="mb-0 fw-bold text-white">LokaGest Admin</h5>
                    <p class="small text-secondary mb-0">admin.lokagest.bj · Accès restreint IP</p>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <span class="badge bg-danger rounded-pill"><?php echo min(2, count($logs ?? [])); ?> alertes</span>
                    <a href="?action=logout" class="text-white opacity-75"><i class="bi bi-box-arrow-right"></i></a>
                </div>
            </div>
        </header>

        <nav class="d-flex justify-content-around px-3 pb-3 border-bottom border-secondary">
            <a href="dashboard.php" class="text-success text-decoration-none small fw-bold">Dashboard</a>
            <a href="owners.php" class="text-secondary text-decoration-none small">Propriétaires</a>
            <a href="transactions.php" class="text-secondary text-decoration-none small">Transactions</a>
        </nav>

        <main class="flex-grow-1 p-3 text-start">
            <div class="admin-stat-card">
                <div class="admin-stat-label">Propriétaires inscrits</div>
                <div class="admin-stat-value"><?php echo $ownersCount; ?></div>
                <div class="small text-secondary">Plateforme LokaGest Bénin</div>
            </div>
            <div class="admin-stat-card">
                <div class="admin-stat-label">Chambres gérées</div>
                <div class="admin-stat-value green"><?php echo $roomsCount; ?></div>
            </div>
            <div class="admin-stat-card">
                <div class="admin-stat-label">Volume total</div>
                <div class="admin-stat-value green"><?php echo number_format($totalAmountFCFA / 1000000, 1, ',', ' '); ?>M FCFA</div>
                <div class="small text-secondary"><?php echo $paymentsCount; ?> paiements confirmés</div>
            </div>
            <div class="admin-stat-card">
                <div class="admin-stat-label">Commissions système</div>
                <div class="admin-stat-value"><?php echo number_format($commissionsMois, 0, ',', ' '); ?> F</div>
            </div>

            <h6 class="text-uppercase small text-secondary fw-bold mt-4 mb-2" style="letter-spacing:0.06em;">Alertes actives</h6>
            <?php if (empty($logs)): ?>
                <div class="admin-alert-card opacity-75">Aucune alerte pour le moment.</div>
            <?php else: ?>
                <?php foreach (array_slice($logs, 0, 3) as $l): ?>
                <div class="admin-alert-card">
                    <b>⚠ <?php echo htmlspecialchars($l['action']); ?></b><br>
                    <span class="opacity-75"><?php echo htmlspecialchars($l['compte_concerne']); ?> · <?php echo date('d/m H:i', strtotime($l['date_action'])); ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <h6 class="text-uppercase small text-secondary fw-bold mt-4 mb-2" style="letter-spacing:0.06em;">Journal récent</h6>
            <?php foreach ($logs as $l): ?>
            <div class="small py-2 border-bottom border-secondary text-secondary">
                <?php echo htmlspecialchars($l['action']); ?> — <?php echo date('d/m/Y H:i', strtotime($l['date_action'])); ?>
            </div>
            <?php endforeach; ?>
        </main>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
