<?php
/**
 * LokaGest - Administration Financière et Transactions
 * 
 * Permet de suivre toutes les transactions de la plateforme (F60),
 * de valider ou rejeter les retraits MoMo (F63) avec recrédit automatique du wallet en cas de rejet,
 * d'enregistrer des remboursements manuels (F64), et d'exporter en CSV.
 * Toutes les actions sont tracées (F65).
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/supabase.php';

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: dashboard.php');
    exit;
}

$successMsg = null;
$errorMsg = null;

// 1. Traitement des Actions Administrateur (F63 / F64)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Actions sur retraits
    if (isset($_POST['update_withdrawal'])) {
        $wId = $_POST['withdrawal_id'] ?? '';
        $action = $_POST['action'] ?? ''; // approve ou reject
        
        if (!empty($wId) && !empty($action)) {
            // Charger le retrait
            $wRes = SupabaseClient::select('withdrawals', '*', 'id=eq.' . $wId);
            $withdrawal = ($wRes['status'] === 200 && !empty($wRes['data'])) ? $wRes['data'][0] : null;
            
            if ($withdrawal && $withdrawal['statut'] === 'en_attente') {
                if ($action === 'approve') {
                    // Valider le retrait
                    $up = SupabaseClient::update('withdrawals', ['statut' => 'valide'], 'id=eq.' . $wId);
                    if ($up['status'] >= 200 && $up['status'] < 300) {
                        $successMsg = "Demande de retrait validée avec succès.";
                        
                        // Journaliser (F65)
                        $log = [
                            'action' => 'Validation de retrait MoMo de ' . $withdrawal['montant'] . ' F',
                            'compte_concerne' => 'Wallet ID: ' . $withdrawal['wallet_id'],
                            'date_action' => date('Y-m-d H:i:s')
                        ];
                        SupabaseClient::insert('admin_logs', $log);
                    }
                } elseif ($action === 'reject') {
                    // Rejeter et recréditer le portefeuille (F20 / F63)
                    $up = SupabaseClient::update('withdrawals', ['statut' => 'rejete'], 'id=eq.' . $wId);
                    
                    if ($up['status'] >= 200 && $up['status'] < 300) {
                        // Charger le wallet pour le recréditer
                        $walletRes = SupabaseClient::select('wallets', '*', 'id=eq.' . $withdrawal['wallet_id']);
                        if ($walletRes['status'] === 200 && !empty($walletRes['data'])) {
                            $wallet = $walletRes['data'][0];
                            
                            $nouveauSolde = $wallet['solde'] + $withdrawal['montant'];
                            $nouveauTotalSorti = max(0, $wallet['total_sorti'] - $withdrawal['montant']);
                            
                            SupabaseClient::update(
                                'wallets', 
                                ['solde' => $nouveauSolde, 'total_sorti' => $nouveauTotalSorti], 
                                'id=eq.' . $wallet['id']
                            );
                        }
                        
                        $successMsg = "Retrait rejeté et portefeuille propriétaire recrédité de " . number_format($withdrawal['montant'], 0, ',', ' ') . " FCFA.";
                        
                        // Journaliser (F65)
                        $log = [
                            'action' => 'Rejet de retrait MoMo (Wallet recrédité) de ' . $withdrawal['montant'] . ' F',
                            'compte_concerne' => 'Wallet ID: ' . $withdrawal['wallet_id'],
                            'date_action' => date('Y-m-d H:i:s')
                        ];
                        SupabaseClient::insert('admin_logs', $log);
                    }
                }
            }
        }
    }
    
    // Enregistrement remboursement manuel (F64)
    if (isset($_POST['refund_manual'])) {
        $paymentId = $_POST['payment_id'] ?? '';
        $reason = trim($_POST['refund_reason'] ?? 'Raison non spécifiée');
        
        if (!empty($paymentId)) {
            // Charger le paiement
            $pRes = SupabaseClient::select('payments', '*', 'id=eq.' . $paymentId);
            $payment = ($pRes['status'] === 200 && !empty($pRes['data'])) ? $pRes['data'][0] : null;
            
            if ($payment && $payment['statut'] === 'confirme') {
                // Mettre à jour le paiement à 'echoue' ou créer un log de remboursement
                $up = SupabaseClient::update('payments', ['statut' => 'echoue'], 'id=eq.' . $paymentId);
                
                if ($up['status'] >= 200 && $up['status'] < 300) {
                    // Débiter également le portefeuille du propriétaire associé si nécessaire
                    // Récupérer le propriétaire par la chambre
                    $unitRes = SupabaseClient::select('units', 'property_id', 'id=eq.' . $payment['unit_id']);
                    if ($unitRes['status'] === 200 && !empty($unitRes['data'])) {
                        $propRes = SupabaseClient::select('properties', 'user_id', 'id=eq.' . $unitRes['data'][0]['property_id']);
                        if ($propRes['status'] === 200 && !empty($propRes['data'])) {
                            $ownerId = $propRes['data'][0]['user_id'];
                            
                            $walletRes = SupabaseClient::select('wallets', '*', 'user_id=eq.' . $ownerId);
                            if ($walletRes['status'] === 200 && !empty($walletRes['data'])) {
                                $wallet = $walletRes['data'][0];
                                $nouveauSolde = max(0, $wallet['solde'] - $payment['montant']);
                                $nouveauTotalEntre = max(0, $wallet['total_entre'] - $payment['montant']);
                                
                                SupabaseClient::update(
                                    'wallets',
                                    ['solde' => $nouveauSolde, 'total_entre' => $nouveauTotalEntre],
                                    'id=eq.' . $wallet['id']
                                );
                            }
                        }
                    }
                    
                    $successMsg = "Remboursement manuel enregistré. Paiement marqué comme échoué.";
                    
                    // Journaliser (F65)
                    $log = [
                        'action' => 'Remboursement manuel enregistré : ' . $reason,
                        'compte_concerne' => 'Paiement ID: ' . $paymentId,
                        'date_action' => date('Y-m-d H:i:s')
                    ];
                    SupabaseClient::insert('admin_logs', $log);
                }
            }
        }
    }
}

// 2. Charger les transactions pour l'affichage (Paiements + Retraits)
$payments = [];
$payRes = SupabaseClient::select('payments', '*', 'order=date_paiement.desc&limit=30');
if ($payRes['status'] === 200 && is_array($payRes['data'])) {
    $payments = $payRes['data'];
}

$withdrawals = [];
$withRes = SupabaseClient::select('withdrawals', '*', 'order=date_retrait.desc&limit=30');
if ($withRes['status'] === 200 && is_array($withRes['data'])) {
    $withdrawals = $withRes['data'];
}

// -------------------------------------------------------------
// EXPORTATION CSV GLOBAL DE TOUTES LES TRANSACTIONS (F60)
// -------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Toutes_Transactions_LokaGest_' . date('Ymd_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Écrire l'en-tête
    fputcsv($output, ['Type', 'Date', 'ID Externe', 'Montant (FCFA)', 'Statut']);
    
    // Ajouter paiements
    foreach ($payments as $p) {
        fputcsv($output, ['PAIEMENT LOYER', $p['date_paiement'], $p['numero_recu'], $p['montant'], $p['statut']]);
    }
    
    // Ajouter retraits
    foreach ($withdrawals as $w) {
        fputcsv($output, ['RETRAIT PORTABLE', $w['date_retrait'], 'MoMo: ' . $w['numero_momo'], $w['montant'], $w['statut']]);
    }
    
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Flux - Admin</title>
    
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
            <h5 class="mb-0 fw-bold">Gestion Financière</h5>
        </header>

        <!-- Navigation de l'administration -->
        <div class="bg-light d-flex justify-content-around py-2 border-bottom shadow-sm">
            <a href="dashboard.php" class="text-muted text-decoration-none small fw-semibold">Dashboard</a>
            <a href="owners.php" class="text-muted text-decoration-none small fw-semibold">Propriétaires</a>
            <a href="transactions.php" class="text-success text-decoration-none small fw-bold">Transactions</a>
        </div>

        <!-- Contenu -->
        <main class="flex-grow-1 p-3 text-start">
            
            <?php if ($successMsg): ?>
                <div class="alert alert-success small py-2"><?php echo htmlspecialchars($successMsg); ?></div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold text-dark mb-0">Retraits d'argent MoMo (F63)</h6>
                <a href="?export=csv" class="btn btn-outline-success btn-sm rounded-pill fw-bold px-3">
                    Exporter CSV <i class="bi bi-filetype-csv"></i>
                </a>
            </div>

            <!-- Liste des Retraits à valider -->
            <?php if (empty($withdrawals)): ?>
                <p class="text-muted small text-center py-3">Aucune demande de retrait.</p>
            <?php else: ?>
                <div class="d-flex flex-column gap-2 mb-4">
                    <?php foreach ($withdrawals as $w): ?>
                        <div class="card border-0 rounded-3 shadow-sm p-3 small <?php echo ($w['statut'] === 'en_attente') ? 'bg-warning-subtle' : 'bg-light'; ?>">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <span class="fw-bold text-dark">Retrait de <?php echo number_format($w['montant'], 0, ',', ' '); ?> F</span>
                                    <span class="d-block text-muted" style="font-size: 0.72rem;">Vers MoMo : +<?php echo htmlspecialchars($w['numero_momo']); ?></span>
                                    <span class="text-muted" style="font-size: 0.68rem;"><?php echo date('d/m/Y H:i', strtotime($w['date_retrait'])); ?></span>
                                </div>
                                <span class="badge bg-secondary rounded-pill"><?php echo htmlspecialchars($w['statut']); ?></span>
                            </div>

                            <?php if ($w['statut'] === 'en_attente'): ?>
                                <div class="d-flex gap-2 justify-content-end mt-2">
                                    <form action="transactions.php" method="POST" class="m-0">
                                        <input type="hidden" name="withdrawal_id" value="<?php echo $w['id']; ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" name="update_withdrawal" class="btn btn-danger btn-xs rounded-pill px-3">Rejeter</button>
                                    </form>
                                    <form action="transactions.php" method="POST" class="m-0">
                                        <input type="hidden" name="withdrawal_id" value="<?php echo $w['id']; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" name="update_withdrawal" class="btn btn-success btn-xs rounded-pill px-3 text-white">Valider Transfert</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Bilan des paiements reçus et Remboursements -->
            <h6 class="fw-bold text-dark border-top pt-3 mb-3">Derniers règlements & remboursements (F64)</h6>
            
            <?php if (empty($payments)): ?>
                <p class="text-muted small text-center py-3">Aucun règlement.</p>
            <?php else: ?>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($payments as $p): ?>
                        <div class="card border-0 rounded-3 shadow-sm p-3 small">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <b class="text-dark">Règlement : <?php echo number_format($p['montant'], 0, ',', ' '); ?> FCFA</b>
                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($p['statut']); ?></span>
                            </div>
                            <span class="text-muted d-block" style="font-size: 0.72rem;">Reçu : <?php echo substr($p['numero_recu'], 0, 8); ?>... • Date : <?php echo date('d/m/Y H:i', strtotime($p['date_paiement'])); ?></span>
                            
                            <?php if ($p['statut'] === 'confirme'): ?>
                                <div class="text-end mt-2">
                                    <button class="btn btn-outline-danger btn-xs rounded-pill px-2" style="font-size: 0.65rem;" data-bs-toggle="modal" data-bs-target="#refundModal" onclick="setRefundId('<?php echo $p['id']; ?>')">
                                        Remboursement Manuel
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </main>

        <!-- Modale de remboursement (F64) -->
        <div class="modal fade" id="refundModal" tabindex="-1" aria-labelledby="refundModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered mx-3 max-width-480">
                <div class="modal-content rounded-4 border-0 shadow">
                    <div class="modal-header border-0 bg-danger text-white rounded-top-4">
                        <h6 class="modal-title fw-bold" id="refundModalLabel">Remboursement Manuel</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="transactions.php" method="POST">
                        <input type="hidden" id="refund_payment_id" name="payment_id" value="">
                        <div class="modal-body py-4 text-start">
                            <div class="mb-3">
                                <label for="refund_reason" class="form-label small fw-semibold text-secondary">Raison du remboursement *</label>
                                <input type="text" class="form-control rounded-3" id="refund_reason" name="refund_reason" required placeholder="Ex: Paiement double, erreur locataire">
                            </div>
                            
                            <div class="alert alert-danger border-0 small py-2 mb-0" role="alert">
                                ⚠️ <b>Attention :</b> Cette action annulera le paiement, débitera le portefeuille du propriétaire et journalisera la modification.
                            </div>
                        </div>
                        <div class="modal-footer border-0 pb-4">
                            <button type="button" class="btn btn-light rounded-3 px-3" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" name="refund_manual" class="btn btn-danger rounded-3 px-4">Valider le remboursement</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="text-center mt-4 text-muted small" style="font-size: 0.72rem;">
            Console Réseau LokaGest BJ.
        </footer>
    </div>

    <script>
        function setRefundId(id) {
            document.getElementById('refund_payment_id').value = id;
        }
    </script>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
