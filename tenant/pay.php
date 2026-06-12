<?php
/**
 * LokaGest - Portail de Paiement du Locataire (Public)
 * 
 * Page accessible par le locataire en scannant le QR code ou via le lien WhatsApp.
 * Gère l'authentification par token (F41/F42/F53), l'affichage du loyer/charges (F43),
 * le paiement FedaPay (MTN/Moov MoMo F44/F45/F46), le signalement d'incidents (F54),
 * et la consultation de l'historique personnel sans compte par téléphone (F52).
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/supabase.php';
require_once __DIR__ . '/../lib/whatsapp_sender.php';
require_once __DIR__ . '/../lib/sms_sender.php';

$token = $_GET['token'] ?? '';
$errorMsg = null;
$successMsg = null;

$linkRecord = null;
$unit = null;
$property = null;
$tenant = null;
$charges = [];
$totalGeneral = 0;

// 1. Vérification du token de paiement (F53)
if (!empty($token)) {
    $linkResponse = SupabaseClient::select('payment_links', '*', 'token=eq.' . $token);
    if ($linkResponse['status'] === 200 && !empty($linkResponse['data'])) {
        $linkRecord = $linkResponse['data'][0];
        
        if ($linkRecord['statut'] !== 'actif') {
            // Token désactivé (F53)
            showInactiveLinkPage();
            exit;
        }
        
        // Charger les entités
        $unitRes = SupabaseClient::select('units', '*', 'id=eq.' . $linkRecord['unit_id']);
        if ($unitRes['status'] === 200 && !empty($unitRes['data'])) {
            $unit = $unitRes['data'][0];
        }
        
        $tenantRes = SupabaseClient::select('tenants', '*', 'id=eq.' . $linkRecord['tenant_id'] . '&statut=eq.actif');
        if ($tenantRes['status'] === 200 && !empty($tenantRes['data'])) {
            $tenant = $tenantRes['data'][0];
        }
        
        if ($unit) {
            $propRes = SupabaseClient::select('properties', '*', 'id=eq.' . $unit['property_id']);
            if ($propRes['status'] === 200 && !empty($propRes['data'])) {
                $property = $propRes['data'][0];
            }
            
            // Récupérer les charges de ce mois (F33)
            $moisEnCours = date('Y-m');
            $chargesRes = SupabaseClient::select('charges', '*', 'unit_id=eq.' . $unit['id'] . '&mois=eq.' . $moisEnCours);
            if ($chargesRes['status'] === 200 && is_array($chargesRes['data'])) {
                $charges = $chargesRes['data'];
            }
        }
    } else {
        showInactiveLinkPage();
        exit;
    }
}

// Page d'erreur token inactif (F53)
function showInactiveLinkPage() {
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Lien Inactif - LokaGest</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="../css/style.css">
    </head>
    <body class="lokagest-app">
        <div class="app-container p-5 text-center">
            <div class="empty-state">
                <div class="empty-icon" style="background:var(--danger-light);color:var(--danger);">⚠️</div>
                <h4 class="fw-bold">Lien inactif</h4>
                <p class="text-muted small">Ce QR code n'est plus valide. Contactez votre propriétaire.</p>
                <a href="javascript:window.close()" class="btn btn-nimbus-outline mt-3 d-inline-block text-decoration-none">Fermer</a>
            </div>
        </div>
    </body>
    </html>
    <?php
}

// Calculer le total à payer (Loyer + Charges)
if ($tenant) {
    $totalGeneral = $tenant['loyer_convenu'];
    foreach ($charges as $c) {
        $totalGeneral += $c['montant'];
    }
}

// 2. Traitement d'un signalement d'incident (F54)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_issue'])) {
    $message = trim($_POST['issue_message'] ?? '');
    if (empty($message)) {
        $errorMsg = "Veuillez décrire le problème rencontré.";
    } else {
        $newIssue = [
            'unit_id' => $unit['id'],
            'message' => $message,
            'statut' => 'ouvert',
            'date_creation' => date('Y-m-d H:i:s')
        ];
        
        $insertIssueRes = SupabaseClient::insert('issues', $newIssue);
        if ($insertIssueRes['status'] >= 200 && $insertIssueRes['status'] < 300) {
            $successMsg = "Votre problème a été signalé avec succès au propriétaire.";
            
            // Envoyer une alerte WhatsApp au propriétaire
            // Récupérer le numéro du propriétaire
            $ownerRes = SupabaseClient::select('users', 'telephone, prenom', 'id=eq.' . $property['user_id']);
            if ($ownerRes['status'] === 200 && !empty($ownerRes['data'])) {
                $owner = $ownerRes['data'][0];
                $alertMsg = "LokaGest - ALERTE PROBLEME : Le locataire de la chambre " . $unit['code_unique'] . " (" . $tenant['prenom'] . ") a signale un probleme :\n\n\"$message\"";
                WhatsAppSender::send($owner['telephone'], $alertMsg);
            }
        } else {
            $errorMsg = "Erreur lors de l'envoi du signalement.";
        }
    }
}

// 3. Consultation d'historique personnel sans compte (F52)
$pastPayments = [];
$searchedPhone = $_GET['search_phone'] ?? '';
if (!empty($searchedPhone)) {
    // Nettoyer
    $searchedPhone = preg_replace('/[^0-9]/', '', $searchedPhone);
    if (strlen($searchedPhone) === 8) {
        $searchedPhone = '229' . $searchedPhone;
    }
    // Retirer l'indicatif international si stocké brut
    if (strpos($searchedPhone, '229') === 0 && strlen($searchedPhone) === 11) {
        $searchedPhone = substr($searchedPhone, 3);
    }
    
    // Trouver les locataires liés à ce téléphone
    $tenantsListRes = SupabaseClient::select('tenants', 'id', 'telephone=eq.' . $searchedPhone);
    if ($tenantsListRes['status'] === 200 && !empty($tenantsListRes['data'])) {
        $tIds = array_column($tenantsListRes['data'], 'id');
        $tIdsList = implode(',', $tIds);
        
        $pastPaysRes = SupabaseClient::select('payments', '*', 'tenant_id=in.(' . $tIdsList . ')&statut=eq.confirme&order=date_paiement.desc');
        if ($pastPaysRes['status'] === 200 && is_array($pastPaysRes['data'])) {
            $pastPayments = $pastPaysRes['data'];
        }
    }
}

$moisEnLettres = [
    '01' => 'Janvier', '02' => 'Février', '03' => 'Mars', '04' => 'Avril',
    '05' => 'Mai', '06' => 'Juin', '07' => 'Juillet', '08' => 'Août',
    '09' => 'Septembre', '10' => 'Octobre', '11' => 'Novembre', '12' => 'Décembre'
];

$pageTitle = 'Paiement loyer';
$basePath = '..';
$bodyClass = 'lokagest-app';
require_once __DIR__ . '/../includes/head.php';
?>

    <div class="app-container min-vh-100 d-flex flex-column">
        <header class="verify-header text-start">
            <?php if ($property && $unit): ?>
            <p class="mb-1 small opacity-90"><?php echo htmlspecialchars($property['nom']); ?> · Chambre <?php echo htmlspecialchars($unit['code_unique']); ?></p>
            <?php endif; ?>
            <h1 class="header-title text-white mb-0" style="font-size:1.35rem;">Payer votre loyer</h1>
        </header>

        <main class="app-main text-start">
            
            <?php if (isset($_GET['error']) && $_GET['error'] === 'select_operator'): ?>
                <div class="alert alert-warning border-0 small py-2 mb-3">Choisissez d'abord MTN MoMo ou Moov Money.</div>
            <?php endif; ?>

            <?php if ($errorMsg): ?>
                <div class="alert alert-danger border-0 shadow-sm small py-2 mb-3" role="alert">
                    <?php echo htmlspecialchars($errorMsg); ?>
                </div>
            <?php endif; ?>

            <?php if ($successMsg): ?>
                <div class="alert alert-success border-0 shadow-sm small py-2 mb-3" role="alert">
                    <?php echo htmlspecialchars($successMsg); ?>
                </div>
            <?php endif; ?>

            <?php if ($tenant): ?>
                <div class="nimbus-card nimbus-card-body mb-3">
                    <div class="d-flex justify-content-between py-2 border-bottom small">
                        <span class="text-muted">Locataire</span>
                        <b><?php echo htmlspecialchars($tenant['prenom']); ?></b>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-bottom small">
                        <span class="text-muted">Mois concerné</span>
                        <b><?php echo $moisEnLettres[date('m')] ?? date('m/Y'); ?> <?php echo date('Y'); ?></b>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-bottom small">
                        <span class="text-muted">Loyer mensuel</span>
                        <b><?php echo number_format($tenant['loyer_convenu'], 0, ',', ' '); ?> FCFA</b>
                    </div>
                    <?php
                    $chargesTotal = 0;
                    foreach ($charges as $c) { $chargesTotal += $c['montant']; }
                    if ($chargesTotal > 0): ?>
                    <div class="d-flex justify-content-between py-2 border-bottom small">
                        <span class="text-muted">Charges (eau + élec)</span>
                        <b><?php echo number_format($chargesTotal, 0, ',', ' '); ?> FCFA</b>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="pay-total-box">
                    <span class="fw-bold text-success">Total à payer</span>
                    <span class="pay-total-amount"><?php echo number_format($totalGeneral, 0, ',', ' '); ?> FCFA</span>
                </div>

                <h6 class="fw-bold small mb-3">Choisissez votre mode de paiement</h6>
                <form action="../api/payments.php" method="POST" id="pay-form">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <input type="hidden" name="montant" value="<?php echo $totalGeneral; ?>">
                    <input type="hidden" name="operator" id="operator-input" value="">

                    <div class="pay-method mtn-style" data-operator="mtn" role="button" tabindex="0">
                        <span class="pm-logo mtn">MTN</span>
                        <div class="flex-grow-1">
                            <div class="fw-bold">MTN MoMo</div>
                            <div class="text-muted small">Confirmation sur votre téléphone</div>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </div>
                    <div class="pay-method moov-style" data-operator="moov" role="button" tabindex="0">
                        <span class="pm-logo moov">MV</span>
                        <div class="flex-grow-1">
                            <div class="fw-bold">Moov Money</div>
                            <div class="text-muted small">Confirmation sur votre téléphone</div>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </div>

                    <div id="pay-phone-panel" class="pay-phone-panel d-none">
                        <label for="momo_phone" class="form-label small fw-semibold">Votre numéro MoMo (+229)</label>
                        <input type="tel" class="form-control rounded-3 fw-bold mb-3" id="momo_phone" name="momo_phone" placeholder="97 00 00 00" pattern="[0-9]{8}">
                        <button type="submit" name="pay_full" class="btn btn-nimbus w-100 mb-2">Payer <?php echo number_format($totalGeneral, 0, ',', ' '); ?> FCFA</button>
                        <button type="submit" name="pay_half" class="btn btn-nimbus-outline w-100 btn-sm">
                            Payer la moitié (<?php echo number_format($totalGeneral / 2, 0, ',', ' '); ?> F)
                        </button>
                    </div>
                </form>

                <p class="text-center text-muted mt-3 mb-2" style="font-size:0.72rem;">
                    Les frais de traitement (~1,5%) sont inclus dans le montant affiché
                </p>

                <div class="pay-footer-actions">
                    <button type="button" class="pay-footer-btn" data-bs-toggle="modal" data-bs-target="#receiptsModal">
                        <span class="pfb-icon">🧾</span>
                        Mes anciens reçus
                    </button>
                    <button type="button" class="pay-footer-btn" data-bs-toggle="modal" data-bs-target="#issueModal">
                        <span class="pfb-icon">⚠️</span>
                        Signaler un problème
                    </button>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- Modal reçus -->
    <div class="modal fade" id="receiptsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered mx-3">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-0">
                    <h6 class="modal-title fw-bold">Mes anciens reçus</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-0">
                    <p class="text-muted small">Entrez votre numéro de téléphone (+229).</p>
                    <form action="pay.php" method="GET" class="mb-3">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                        <div class="input-group mb-2">
                            <span class="input-group-text">+229</span>
                            <input type="tel" class="form-control" name="search_phone" placeholder="97000000" pattern="[0-9]{8}" value="<?php echo htmlspecialchars($_GET['search_phone'] ?? ''); ?>" required>
                        </div>
                        <button type="submit" class="btn btn-nimbus w-100 btn-sm">Rechercher</button>
                    </form>
                    <?php if (!empty($pastPayments)): ?>
                        <?php foreach ($pastPayments as $pp): ?>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom small">
                            <div>
                                <b><?php echo date('m/Y', strtotime($pp['mois'] . '-01')); ?></b>
                                <span class="d-block text-muted"><?php echo number_format($pp['montant'], 0, ',', ' '); ?> F</span>
                            </div>
                            <a href="../api/pdf.php?action=receipt&id=<?php echo $pp['id']; ?>" target="_blank" class="btn btn-nimbus-outline btn-sm">PDF</a>
                        </div>
                        <?php endforeach; ?>
                    <?php elseif (isset($_GET['search_phone'])): ?>
                        <p class="text-danger small mb-0">Aucun reçu trouvé.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal signalement -->
    <div class="modal fade" id="issueModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered mx-3">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-0">
                    <h6 class="modal-title fw-bold">Signaler un problème</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="pay.php?token=<?php echo htmlspecialchars($token); ?>" method="POST">
                    <div class="modal-body pt-0">
                        <textarea class="form-control rounded-3 mb-3" name="issue_message" rows="3" placeholder="Décrivez le problème…" required></textarea>
                        <button type="submit" name="report_issue" class="btn btn-nimbus w-100">Envoyer au propriétaire</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/../includes/scripts.php'; ?>
    <script>
    const phonePanel = document.getElementById('pay-phone-panel');
    const phoneInput = document.getElementById('momo_phone');
    const opInput = document.getElementById('operator-input');

    function selectOperator(el) {
        document.querySelectorAll('.pay-method').forEach(m => m.classList.remove('selected'));
        el.classList.add('selected');
        opInput.value = el.dataset.operator;
        phonePanel.classList.remove('d-none');
        phoneInput.required = true;
        phoneInput.focus();
    }

    document.querySelectorAll('.pay-method[data-operator]').forEach(el => {
        el.addEventListener('click', () => selectOperator(el));
        el.addEventListener('keydown', (e) => { if (e.key === 'Enter') selectOperator(el); });
    });

    document.getElementById('pay-form')?.addEventListener('submit', (e) => {
        if (!opInput.value) {
            e.preventDefault();
            alert('Choisissez MTN MoMo ou Moov Money.');
        }
    });

    <?php if (isset($_GET['search_phone'])): ?>
    document.addEventListener('DOMContentLoaded', () => {
        new bootstrap.Modal(document.getElementById('receiptsModal')).show();
    });
    <?php endif; ?>
    </script>
</body>
</html>
