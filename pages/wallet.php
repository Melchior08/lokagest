<?php
/**
 * LokaGest - Portefeuille Propriétaire (Wallet)
 * 
 * Permet de visualiser le solde en temps réel, l'historique détaillé des revenus
 * par chambre/locataire, et de soumettre des demandes de retrait MoMo (MTN/Moov).
 */

require_once __DIR__ . '/../lib/auth_guard.php';
require_once __DIR__ . '/../lib/supabase.php';
require_once __DIR__ . '/../lib/app_helpers.php';

if (isset($_SESSION['mode_gestionnaire']) && $_SESSION['mode_gestionnaire'] === true) {
    header('Location: ' . APP_URL . '/pages/dashboard.php?error=wallet_blocked');
    exit;
}
require_once __DIR__ . '/../lib/whatsapp_sender.php';
require_once __DIR__ . '/../lib/sms_sender.php';

$user = $_SESSION['user'];
$userId = $user['id'];

$errorMsg = null;
$successMsg = null;

// 1. Récupérer le portefeuille (Wallet)
$wallet = null;
$walletResponse = SupabaseClient::select('wallets', '*', 'user_id=eq.' . $userId);
if ($walletResponse['status'] === 200 && !empty($walletResponse['data'])) {
    $wallet = $walletResponse['data'][0];
} else {
    // Si pas de wallet par erreur, on le crée
    $newWallet = [
        'user_id' => $userId,
        'solde' => 0,
        'total_entre' => 0,
        'total_sorti' => 0
    ];
    $ins = SupabaseClient::insert('wallets', $newWallet);
    if ($ins['status'] === 201 && !empty($ins['data'])) {
        $wallet = $ins['data'][0];
    } else {
        $wallet = $newWallet;
    }
}

// 2. Traitement d'une demande de retrait (F20)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_withdrawal'])) {
    $montant = floatval($_POST['montant'] ?? 0);
    $numeroMomo = trim($_POST['numero_momo'] ?? '');
    
    if ($montant < 5000) {
        $errorMsg = "Le montant minimum de retrait est de 5 000 FCFA.";
    } elseif ($montant > $wallet['solde']) {
        $errorMsg = "Solde insuffisant pour effectuer ce retrait (Solde : " . number_format($wallet['solde'], 0, ',', ' ') . " FCFA).";
    } elseif (empty($numeroMomo)) {
        $errorMsg = "Veuillez entrer un numéro de téléphone pour le transfert MoMo.";
    } else {
        // Nettoyer le numéro de téléphone MoMo
        $numeroMomo = preg_replace('/[^0-9]/', '', $numeroMomo);
        if (strlen($numeroMomo) === 8) {
            $numeroMomo = '229' . $numeroMomo;
        }
        
        // Commencer la transaction de retrait
        $nouveauSolde = $wallet['solde'] - $montant;
        $nouveauTotalSorti = $wallet['total_sorti'] + $montant;
        
        // Mettre à jour le portefeuille en base
        $updateWalletRes = SupabaseClient::update(
            'wallets',
            ['solde' => $nouveauSolde, 'total_sorti' => $nouveauTotalSorti],
            'id=eq.' . $wallet['id']
        );
        
        if ($updateWalletRes['status'] >= 200 && $updateWalletRes['status'] < 300) {
            // Insérer la demande de retrait dans la table withdrawals
            $newWithdrawal = [
                'wallet_id' => $wallet['id'],
                'montant' => $montant,
                'numero_momo' => $numeroMomo,
                'statut' => 'en_attente',
                'date_retrait' => date('Y-m-d H:i:s')
            ];
            
            $insertWithdrawalRes = SupabaseClient::insert('withdrawals', $newWithdrawal);
            
            if ($insertWithdrawalRes['status'] >= 200 && $insertWithdrawalRes['status'] < 300) {
                // Mettre à jour les variables locales
                $wallet['solde'] = $nouveauSolde;
                $wallet['total_sorti'] = $nouveauTotalSorti;
                
                $successMsg = "Demande de retrait enregistrée ! Vous recevrez exactement " . number_format($montant, 0, ',', ' ') . " FCFA sans frais sur votre compte MoMo.";
                
                // Notification WhatsApp de confirmation de retrait (F20)
                $notifText = "LokaGest : Votre demande de retrait de " . number_format($montant, 0, ',', ' ') . " FCFA vers le numero MoMo +$numeroMomo a ete bien enregistree et est en cours de traitement par l'administration.";
                
                WhatsAppSender::send($user['telephone'] ?: $numeroMomo, $notifText, getCallMeBotKey());
            } else {
                // En cas d'erreur de création du retrait, on recrédite le portefeuille (F20 - "si échec → wallet recrédité")
                SupabaseClient::update(
                    'wallets',
                    ['solde' => $wallet['solde'], 'total_sorti' => $wallet['total_sorti']],
                    'id=eq.' . $wallet['id']
                );
                $errorMsg = "Une erreur est survenue lors de la validation du retrait. Le solde de votre portefeuille a été recrédité.";
            }
        } else {
            $errorMsg = "Erreur lors du débit du portefeuille.";
        }
    }
}

// 3. Charger l'historique des entrées (Paiements confirmés pour les chambres de ce propriétaire)
$inflowTransactions = [];
$propertiesResponse = SupabaseClient::select('properties', 'id', 'user_id=eq.' . $userId);

if ($propertiesResponse['status'] === 200 && !empty($propertiesResponse['data'])) {
    $propIds = array_column($propertiesResponse['data'], 'id');
    $propIdsList = implode(',', $propIds);
    
    $unitsResponse = SupabaseClient::select('units', 'id, code_unique', 'property_id=in.(' . $propIdsList . ')');
    if ($unitsResponse['status'] === 200 && !empty($unitsResponse['data'])) {
        $unitIds = array_column($unitsResponse['data'], 'id');
        $unitCodes = array_column($unitsResponse['data'], 'code_unique', 'id');
        $unitIdsList = implode(',', $unitIds);
        
        $paymentsResponse = SupabaseClient::select(
            'payments',
            '*',
            'unit_id=in.(' . $unitIdsList . ')&statut=eq.confirme&order=date_paiement.desc&limit=15'
        );
        
        if ($paymentsResponse['status'] === 200 && is_array($paymentsResponse['data'])) {
            foreach ($paymentsResponse['data'] as $pay) {
                // Récupérer le nom du locataire associé
                $tRes = SupabaseClient::select('tenants', 'prenom', 'id=eq.' . $pay['tenant_id']);
                $tPrenom = ($tRes['status'] === 200 && !empty($tRes['data'])) ? $tRes['data'][0]['prenom'] : 'Locataire';
                
                $inflowTransactions[] = [
                    'date' => $pay['date_paiement'],
                    'libelle' => $tPrenom . ' — ' . ($unitCodes[$pay['unit_id']] ?? 'Chambre'),
                    'locataire' => 'Loyer ' . date('M Y', strtotime($pay['mois'] . '-01')) . ' · ' . (strpos($pay['mode'], 'moov') !== false ? 'Moov Money' : ($pay['mode'] === 'especes' ? 'Espèces' : 'MTN MoMo')),
                    'montant' => $pay['montant'],
                    'type' => 'entree',
                    'mode' => $pay['mode']
                ];
            }
        }
    }
}

// 4. Charger l'historique des retraits (Sorties de fonds)
$outflowTransactions = [];
$withdrawalsResponse = SupabaseClient::select(
    'withdrawals',
    '*',
    'wallet_id=eq.' . $wallet['id'] . '&order=date_retrait.desc&limit=15'
);

if ($withdrawalsResponse['status'] === 200 && is_array($withdrawalsResponse['data'])) {
    foreach ($withdrawalsResponse['data'] as $w) {
        $outflowTransactions[] = [
            'date' => $w['date_retrait'],
            'libelle' => 'Retrait vers MoMo',
            'locataire' => 'Vers +' . substr($w['numero_momo'], -8),
            'montant' => $w['montant'],
            'type' => 'sortie',
            'statut' => $w['statut'] // en_attente, valide, rejete
        ];
    }
}

// 5. Fusionner et ordonner l'historique complet des flux financiers
$allTransactions = array_merge($inflowTransactions, $outflowTransactions);
usort($allTransactions, function ($a, $b) {
    return strcmp($b['date'], $a['date']); // Ordre chronologique inverse (le plus récent en premier)
});

$pageTitle = 'Portefeuille';
$activeNav = 'wallet';
$basePath = '..';
require_once __DIR__ . '/../includes/head.php';
?>

    <div class="app-container has-nav">
        <div class="wallet-page-header">
            <a href="dashboard.php" class="text-white text-decoration-none d-inline-flex align-items-center gap-2 mb-3">
                <i class="bi bi-arrow-left"></i> <span class="fw-bold">Mon Wallet</span>
            </a>
            <div class="small opacity-75 mb-1">Solde disponible</div>
            <div class="fw-bold" style="font-size:2rem;line-height:1.1;"><?php echo number_format($wallet['solde'], 0, ',', ' '); ?> <span class="fs-6 fw-normal opacity-75">FCFA</span></div>
            <div class="small opacity-75 mt-2">Dernière mise à jour : aujourd'hui à <?php echo date('H\hi'); ?></div>
        </div>

        <main class="app-main" style="padding-top:0;">
            
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger border-0 shadow-sm small py-2 mb-3 text-start"><?php echo htmlspecialchars($errorMsg); ?></div>
            <?php endif; ?>
            <?php if ($successMsg): ?>
                <div class="alert alert-success border-0 shadow-sm small py-2 mb-3 text-start"><?php echo htmlspecialchars($successMsg); ?></div>
            <?php endif; ?>

            <?php if ($wallet['solde'] >= 5000): ?>
            <div class="wallet-withdraw-float">
                <h6 class="fw-bold mb-3">Retirer vers Mobile Money</h6>
                <form action="wallet.php" method="POST">
                    <input type="number" class="form-control rounded-3 fw-bold fs-4 text-center mb-2" name="montant" min="5000" max="<?php echo intval($wallet['solde']); ?>" value="<?php echo min(50000, intval($wallet['solde'])); ?>" required>
                    <p class="text-muted small mb-3">Minimum 5 000 FCFA<?php if (!empty($user['telephone'])): ?> · Numéro MoMo : +229 <?php echo htmlspecialchars($user['telephone']); ?><?php endif; ?></p>
                    <?php if (!empty($user['telephone'])): ?>
                    <input type="hidden" name="numero_momo" value="<?php echo htmlspecialchars($user['telephone']); ?>">
                    <?php else: ?>
                    <div class="input-group mb-3">
                        <span class="input-group-text">+229</span>
                        <input type="tel" class="form-control" name="numero_momo" pattern="[0-9]{8}" placeholder="97000000" required>
                    </div>
                    <?php endif; ?>
                    <div class="alert alert-success border small py-2 mb-3" style="background:#F0FDF4;border-color:#BBF7D0!important;">
                        Vous recevrez exactement le montant demandé sur votre MoMo. Aucune commission LokaGest.
                    </div>
                    <button type="submit" name="request_withdrawal" class="btn btn-nimbus w-100 rounded-3">Retirer maintenant →</button>
                </form>
            </div>
            <?php else: ?>
                <div class="alert alert-warning border-0 small mx-3 mb-4">Solde insuffisant — minimum 5 000 FCFA pour retirer.</div>
            <?php endif; ?>

            <div class="wallet-stat-row mx-0">
                <div class="text-muted small mb-1">Total entré</div>
                <div class="fw-bold fs-5"><?php echo number_format($wallet['total_entre'], 0, ',', ' '); ?></div>
            </div>
            <div class="wallet-stat-row mx-0">
                <div class="text-muted small mb-1">Total retiré</div>
                <div class="fw-bold fs-5"><?php echo number_format($wallet['total_sorti'], 0, ',', ' '); ?></div>
            </div>

            <h6 class="fw-bold mt-4 mb-3">Derniers mouvements</h6>
            <?php if (empty($allTransactions)): ?>
                <p class="text-muted small text-center py-3">Aucune transaction.</p>
            <?php else: ?>
                <?php foreach ($allTransactions as $t):
                    $isEntree = ($t['type'] === 'entree');
                    $modeLabel = $isEntree ? (strpos($t['mode'] ?? '', 'moov') !== false ? 'Moov Money' : 'MTN MoMo') : 'Retrait MoMo';
                ?>
                <div class="tx-row-mock">
                    <div class="tx-icon <?php echo $isEntree ? 'in' : 'out'; ?>"><?php echo $isEntree ? '💰' : '↑'; ?></div>
                    <div class="flex-grow-1 min-width-0">
                        <div class="fw-bold small"><?php echo htmlspecialchars($t['libelle']); ?></div>
                        <div class="text-muted" style="font-size:0.72rem;"><?php echo htmlspecialchars($t['locataire']); ?></div>
                    </div>
                    <div class="fw-bold <?php echo $isEntree ? 'text-success' : 'text-danger'; ?>">
                        <?php echo $isEntree ? '+' : '-'; ?><?php echo number_format($t['montant'], 0, ',', ' '); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>

        <?php require_once __DIR__ . '/../includes/nav.php'; ?>
    </div>
    <?php require_once __DIR__ . '/../includes/scripts.php'; ?>
</body>
</html>
