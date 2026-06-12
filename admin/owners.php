<?php
/**
 * LokaGest - Administration de la gestion des propriétaires
 * 
 * Permet à l'administrateur de lister les comptes (F58), d'octroyer le statut de fondateur
 * manuellement (F59) et de suspendre ou réactiver les propriétaires (F58).
 * Chaque action est tracée de manière permanente (F65).
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/supabase.php';

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: dashboard.php');
    exit;
}

$errorMsg = null;
$successMsg = null;

// 1. Traitement des Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ownerId = $_POST['owner_id'] ?? '';
    $action = $_POST['action'] ?? '';
    
    if (!empty($ownerId) && !empty($action)) {
        // Charger le propriétaire concerné pour le log
        $ownRes = SupabaseClient::select('users', 'prenom, email, fondateur, statut', 'id=eq.' . $ownerId);
        $owner = ($ownRes['status'] === 200 && !empty($ownRes['data'])) ? $ownRes['data'][0] : null;
        
        if ($owner) {
            if ($action === 'suspend') {
                $nouveauStatut = ($owner['statut'] === 'suspendu') ? 'actif' : 'suspendu';
                $update = SupabaseClient::update('users', ['statut' => $nouveauStatut], 'id=eq.' . $ownerId);
                
                if ($update['status'] >= 200 && $update['status'] < 300) {
                    $successMsg = "Statut du propriétaire mis à jour à : $nouveauStatut.";
                    
                    // Journaliser l'action (F65)
                    $log = [
                        'action' => ($nouveauStatut === 'suspendu') ? 'Suspension de compte propriétaire' : 'Réactivation de compte propriétaire',
                        'compte_concerne' => $owner['email'],
                        'date_action' => date('Y-m-d H:i:s')
                    ];
                    SupabaseClient::insert('admin_logs', $log);
                }
            } elseif ($action === 'founder') {
                $nouveauFondateur = !$owner['fondateur'];
                $plan = $nouveauFondateur ? 'premium' : 'free';
                $update = SupabaseClient::update('users', ['fondateur' => $nouveauFondateur, 'plan' => $plan], 'id=eq.' . $ownerId);
                
                if ($update['status'] >= 200 && $update['status'] < 300) {
                    $successMsg = "Statut fondateur mis à jour avec succès.";
                    
                    // Journaliser l'action (F65)
                    $log = [
                        'action' => $nouveauFondateur ? 'Attribution statut Fondateur' : 'Retrait statut Fondateur',
                        'compte_concerne' => $owner['email'],
                        'date_action' => date('Y-m-d H:i:s')
                    ];
                    SupabaseClient::insert('admin_logs', $log);
                }
            }
        }
    }
}

// 2. Charger tous les propriétaires
$owners = [];
$response = SupabaseClient::select('users', '*', 'order=date_inscription.desc');
if ($response['status'] === 200 && is_array($response['data'])) {
    $owners = $response['data'];
}

// Compter les places de fondateurs restantes (50 places au total) (F59)
$foundersCount = 0;
foreach ($owners as $o) {
    if ($o['fondateur'] === true) {
        $foundersCount++;
    }
}
$placesRestantes = max(0, 50 - $foundersCount);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion Propriétaires - Admin</title>
    
    <!-- Google Fonts - Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="bg-dark">

    <div class="app-container shadow bg-white min-vh-100 d-flex flex-column pb-5">
        
        <!-- En-tête -->
        <header class="bg-primary-green text-white p-3 rounded-bottom-4 shadow-sm">
            <h5 class="mb-0 fw-bold">Gestion des Comptes</h5>
        </header>

        <!-- Navigation de l'administration -->
        <div class="bg-light d-flex justify-content-around py-2 border-bottom shadow-sm">
            <a href="dashboard.php" class="text-muted text-decoration-none small fw-semibold">Dashboard</a>
            <a href="owners.php" class="text-success text-decoration-none small fw-bold">Propriétaires</a>
            <a href="transactions.php" class="text-muted text-decoration-none small fw-semibold">Transactions</a>
        </div>

        <!-- Contenu -->
        <main class="flex-grow-1 p-3 text-start">
            
            <?php if ($successMsg): ?>
                <div class="alert alert-success small py-2"><?php echo htmlspecialchars($successMsg); ?></div>
            <?php endif; ?>
            
            <!-- Compteur Fondateur (F59) -->
            <div class="card border-0 rounded-4 p-3 mb-4 bg-light text-center">
                <span class="text-muted small d-block">PROGRAMME FONDATEURS (F59)</span>
                <h5 class="fw-bold my-1 text-success"><?php echo $placesRestantes; ?> / 50 places restantes</h5>
                <span class="text-muted small" style="font-size: 0.72rem;">Les fondateurs ont une commission de 0% à vie sur MTN/Moov MoMo.</span>
            </div>

            <h6 class="fw-bold text-dark mb-3">Liste des Propriétaires</h6>
            
            <?php if (empty($owners)): ?>
                <p class="text-muted small text-center py-4">Aucun propriétaire inscrit.</p>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($owners as $o): ?>
                        <div class="card border-0 rounded-3 shadow-sm p-3 small border-start border-4 <?php echo ($o['statut'] === 'suspendu') ? 'border-danger' : 'border-success'; ?>">
                            <div class="d-flex justify-content-between align-items-start border-bottom pb-2 mb-2">
                                <div>
                                    <h6 class="fw-bold text-dark mb-0"><?php echo htmlspecialchars($o['prenom']); ?></h6>
                                    <span class="text-muted" style="font-size: 0.75rem;"><?php echo htmlspecialchars($o['email']); ?></span>
                                    <span class="d-block text-muted" style="font-size: 0.72rem;">Tél : +229 <?php echo htmlspecialchars($o['telephone'] ?: 'Non renseigné'); ?></span>
                                </div>
                                <div class="text-end">
                                    <span class="badge <?php echo ($o['statut'] === 'suspendu') ? 'bg-danger' : 'bg-success'; ?> rounded-pill mb-1">
                                        <?php echo htmlspecialchars($o['statut']); ?>
                                    </span>
                                    <span class="d-block text-muted" style="font-size: 0.68rem;">Inscrit : <?php echo date('d/m/Y', strtotime($o['date_inscription'])); ?></span>
                                </div>
                            </div>

                            <div class="d-flex gap-2 justify-content-end">
                                <!-- Bouton fondateur -->
                                <form action="owners.php" method="POST" class="m-0">
                                    <input type="hidden" name="owner_id" value="<?php echo $o['id']; ?>">
                                    <input type="hidden" name="action" value="founder">
                                    <button type="submit" class="btn btn-outline-warning btn-xs rounded-pill px-3 fw-semibold text-dark" style="font-size: 0.7rem;">
                                        <?php echo $o['fondateur'] ? 'Retirer Fondateur' : 'Rendre Fondateur'; ?>
                                    </button>
                                </form>

                                <!-- Bouton suspendre -->
                                <form action="owners.php" method="POST" class="m-0">
                                    <input type="hidden" name="owner_id" value="<?php echo $o['id']; ?>">
                                    <input type="hidden" name="action" value="suspend">
                                    <button type="submit" class="btn <?php echo ($o['statut'] === 'suspendu') ? 'btn-success' : 'btn-outline-danger'; ?> btn-xs rounded-pill px-3 fw-semibold" style="font-size: 0.7rem;">
                                        <?php echo ($o['statut'] === 'suspendu') ? 'Réactiver' : 'Suspendre'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </main>

        <!-- Footer -->
        <footer class="text-center mt-4 text-muted small" style="font-size: 0.72rem;">
            Console Réseau LokaGest BJ.
        </footer>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
