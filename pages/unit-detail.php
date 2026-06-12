<?php
/**
 * LokaGest - Gestion de la Chambre / Unité
 * 
 * Ce fichier gère le détail d'une chambre (infos locataire actif, historique, actions).
 * Permet d'enregistrer des paiements en espèces, de gérer les charges,
 * de diviser le loyer en deux fois, et d'exécuter la clôture ou le départ non signalé.
 */

require_once __DIR__ . '/../lib/auth_guard.php';
require_once __DIR__ . '/../lib/supabase.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/pdf_generator.php';
require_once __DIR__ . '/../lib/whatsapp_sender.php';
require_once __DIR__ . '/../lib/sms_sender.php';

$user = $_SESSION['user'];
$userId = $user['id'];
$unitId = $_GET['id'] ?? null;

if (!$unitId) {
    header('Location: properties.php');
    exit;
}

// 1. Charger la chambre (Unit)
$unitRes = SupabaseClient::select('units', '*', 'id=eq.' . $unitId);
if ($unitRes['status'] !== 200 || empty($unitRes['data'])) {
    header('Location: properties.php');
    exit;
}
$unit = $unitRes['data'][0];

// 2. Charger la propriété associée pour validation RLS
$propertyRes = SupabaseClient::select('properties', '*', 'id=eq.' . $unit['property_id'] . '&user_id=eq.' . $userId);
if ($propertyRes['status'] !== 200 || empty($propertyRes['data'])) {
    header('Location: properties.php');
    exit;
}
$property = $propertyRes['data'][0];

// 3. Charger le locataire actif (s'il y en a un)
$tenant = null;
$tenantRes = SupabaseClient::select('tenants', '*', 'unit_id=eq.' . $unitId . '&statut=eq.actif');
if ($tenantRes['status'] === 200 && !empty($tenantRes['data'])) {
    $tenant = $tenantRes['data'][0];
}

$errorMsg = null;
$successMsg = null;
$moisEnCours = date('Y-m');

// -------------------------------------------------------------
// TRAITEMENT D'ENREGISTREMENT DE PAIEMENT ESPÈCES (F17 / F18)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_cash'])) {
    if (!$tenant) {
        $errorMsg = "Aucun locataire actif dans cette chambre.";
    } else {
        $montant = floatval($_POST['montant'] ?? 0);
        $moisReglement = trim($_POST['mois'] ?? $moisEnCours);
        
        if ($montant <= 0) {
            $errorMsg = "Veuillez entrer un montant valide.";
        } else {
            // F18 : Blocage double paiement
            $checkPay = SupabaseClient::select(
                'payments', 
                'id', 
                'unit_id=eq.' . $unitId . '&mois=eq.' . $moisReglement . '&statut=eq.confirme'
            );
            
            if ($checkPay['status'] === 200 && !empty($checkPay['data'])) {
                $errorMsg = "⚠️ Blocage : Un paiement confirmé existe déjà pour cette chambre sur le mois de " . date('m/Y', strtotime($moisReglement . '-01')) . ".";
            } else {
                // Génération numéro de reçu SHA-256 unique (F17)
                $numeroRecu = hash('sha256', $unitId . $tenant['id'] . $moisReglement . time());
                
                $newPayment = [
                    'unit_id' => $unitId,
                    'tenant_id' => $tenant['id'],
                    'numero_recu' => $numeroRecu,
                    'montant' => $montant,
                    'mois' => $moisReglement,
                    'mode' => 'especes',
                    'statut' => 'confirme',
                    'date_paiement' => date('Y-m-d H:i:s')
                ];
                
                $insertPayRes = SupabaseClient::insert('payments', $newPayment);
                
                if ($insertPayRes['status'] >= 200 && $insertPayRes['status'] < 300) {
                    creditOwnerWallet($userId, $montant);
                    $successMsg = "Paiement espèces enregistré avec succès ! Reçu N° " . substr($numeroRecu, 0, 8) . "...";
                    
                    // Générer le reçu PDF espèces (F17)
                    $pdfContent = PDFGenerator::generateReceiptPDF($newPayment, $tenant, $unit, $property);
                    
                    // Créer le dossier local pour stocker temporairement les reçus PDF
                    $pdfDestDir = __DIR__ . '/../uploads/receipts/';
                    if (!is_dir($pdfDestDir)) {
                        mkdir($pdfDestDir, 0755, true);
                    }
                    $pdfFileName = 'Recu_' . $numeroRecu . '.pdf';
                    file_put_contents($pdfDestDir . $pdfFileName, $pdfContent);
                    
                    // Envoi WhatsApp/SMS de confirmation au locataire via CallMeBot (F17)
                    $smsMessage = "LokaGest : Paiement de " . number_format($montant, 0, ',', ' ') . " FCFA recu en ESPECES pour le mois " . date('m/Y', strtotime($moisReglement . '-01')) . " (Chambre " . $unit['code_unique'] . "). Recu : " . APP_URL . "/uploads/receipts/" . $pdfFileName;
                    
                    WhatsAppSender::send($tenant['telephone'], $smsMessage, getCallMeBotKey());
                    
                    // Recharger la page pour rafraîchir l'affichage
                    header('Location: unit-detail.php?id=' . $unitId . '&success=pay');
                    exit;
                } else {
                    $errorMsg = "Erreur d'enregistrement du paiement : " . ($insertPayRes['error'] ?? 'Inconnue');
                }
            }
        }
    }
}

// -------------------------------------------------------------
// TRAITEMENT D'AJOUT DE CHARGES MENSUELLES (F33)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_charge'])) {
    $typeCharge = trim($_POST['type_charge'] ?? '');
    $montantCharge = floatval($_POST['montant_charge'] ?? 0);
    $moisCharge = trim($_POST['mois_charge'] ?? $moisEnCours);
    
    if (empty($typeCharge) || $montantCharge <= 0) {
        $errorMsg = "Veuillez renseigner le type et un montant positif pour la charge.";
    } else {
        $newCharge = [
            'unit_id' => $unitId,
            'mois' => $moisCharge,
            'type' => $typeCharge,
            'montant' => $montantCharge
        ];
        
        $insertChargeRes = SupabaseClient::insert('charges', $newCharge);
        
        if ($insertChargeRes['status'] >= 200 && $insertChargeRes['status'] < 300) {
            header('Location: unit-detail.php?id=' . $unitId . '&success=charge');
            exit;
        } else {
            $errorMsg = "Erreur lors de l'enregistrement de la charge : " . ($insertChargeRes['error'] ?? 'Inconnue');
        }
    }
}

// -------------------------------------------------------------
// TRAITEMENT DE CLÔTURE DE BAIL / DEPART NON SIGNALÉ (F24 / F26)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['close_lease']) || isset($_POST['unilateral_departure']))) {
    if (!$tenant) {
        $errorMsg = "Aucun locataire à clôturer.";
    } else {
        $isUnilateral = isset($_POST['unilateral_departure']);
        
        $cautionRestituee = isset($_POST['caution_restituee']) && $_POST['caution_restituee'] === '1';
        $loyersImpayes = floatval($_POST['loyers_impayes'] ?? 0);
        $motifRetenue = trim($_POST['motif_retenue'] ?? ($isUnilateral ? 'Départ non signalé unilatéral' : ''));
        
        // 1. Créer le bilan de clôture en PDF
        $closingData = [
            'caution_restituee' => $cautionRestituee,
            'loyers_impayes' => $loyersImpayes,
            'motif_retenue' => $motifRetenue
        ];
        
        $pdfClosing = PDFGenerator::generateClosingPDF($tenant, $unit, $property, $closingData);
        
        $pdfDestDir = __DIR__ . '/../uploads/closings/';
        if (!is_dir($pdfDestDir)) {
            mkdir($pdfDestDir, 0755, true);
        }
        $pdfFileName = 'Cloture_' . $tenant['id'] . '_' . time() . '.pdf';
        file_put_contents($pdfDestDir . $pdfFileName, $pdfClosing);
        
        // 2. Générer et enregistrer l'historique de l'unité (F27)
        $historyData = [
            'unit_id' => $unitId,
            'tenant_id' => $tenant['id'],
            'date_entree' => $tenant['date_debut'],
            'date_sortie' => date('Y-m-d'),
            'loyer' => $tenant['loyer_convenu'],
            'statut_sortie' => $isUnilateral ? 'unilateral' : 'regulier',
            'statut_caution_sortie' => $cautionRestituee ? 'restituee' : 'retenue'
        ];
        SupabaseClient::insert('unit_history', $historyData);
        
        // 3. Désactiver le QR code et le lien de paiement associé (F28)
        SupabaseClient::update(
            'payment_links',
            ['statut' => 'inactif', 'date_desactivation' => date('Y-m-d H:i:s')],
            'unit_id=eq.' . $unitId . '&tenant_id=eq.' . $tenant['id']
        );
        
        // 4. Mettre à jour le locataire comme étant parti
        SupabaseClient::update('tenants', ['statut' => 'parti'], 'id=eq.' . $tenant['id']);
        
        // 5. Libérer la chambre
        SupabaseClient::update('units', ['statut' => 'libre'], 'id=eq.' . $unitId);
        
        // 6. Notifier le locataire
        $notifMsg = "LokaGest : Votre fin de bail pour la chambre " . $unit['code_unique'] . " a été enregistrée. Document récapitulatif disponible : " . APP_URL . "/uploads/closings/" . $pdfFileName;
        WhatsAppSender::send($tenant['telephone'], $notifMsg, getCallMeBotKey());
        
        header('Location: unit-detail.php?id=' . $unitId . '&success=cloture');
        exit;
    }
}

// Charger l'historique des paiements de cette chambre (F15/F16)
$payments = [];
if ($tenant) {
    $paymentsRes = SupabaseClient::select('payments', '*', 'unit_id=eq.' . $unitId . '&tenant_id=eq.' . $tenant['id'] . '&order=date_paiement.desc');
    if ($paymentsRes['status'] === 200 && is_array($paymentsRes['data'])) {
        $payments = $paymentsRes['data'];
    }
}

// Charger l'historique des locataires successifs de cette chambre (F27)
$roomHistory = [];
$historyRes = SupabaseClient::select('unit_history', '*', 'unit_id=eq.' . $unitId . '&order=date_sortie.desc');
if ($historyRes['status'] === 200 && is_array($historyRes['data'])) {
    $roomHistory = $historyRes['data'];
}

// Charger les charges mensuelles du mois en cours (F33)
$charges = [];
$chargesRes = SupabaseClient::select('charges', '*', 'unit_id=eq.' . $unitId . '&mois=eq.' . $moisEnCours);
if ($chargesRes['status'] === 200 && is_array($chargesRes['data'])) {
    $charges = $chargesRes['data'];
}

$isLate = false;
$joursRetard = 0;
$paymentLink = null;
$payUrl = null;
$leaseInfo = null;
$qrFicheUrl = null;
if ($tenant) {
    $payCheck = SupabaseClient::select('payments', 'id', 'unit_id=eq.' . $unitId . '&mois=eq.' . $moisEnCours . '&statut=eq.confirme');
    $hasPaidThisMonth = ($payCheck['status'] === 200 && !empty($payCheck['data']));
    $jourActuel = (int) date('d');
    if (!$hasPaidThisMonth && $jourActuel > 5) {
        $isLate = true;
        $joursRetard = $jourActuel - 5;
    }
    $paymentLink = getActivePaymentLink($unitId, $tenant['id']);
    if ($paymentLink) {
        $payUrl = APP_URL . '/tenant/pay.php?token=' . $paymentLink['token'];
    }
    $leaseRes = SupabaseClient::select('leases', 'pdf_url,numero_bail', 'tenant_id=eq.' . $tenant['id'] . '&order=date_generation.desc&limit=1');
    if ($leaseRes['status'] === 200 && !empty($leaseRes['data'])) {
        $leaseInfo = $leaseRes['data'][0];
    }
    $qrFichePath = __DIR__ . '/../uploads/qrcodes/Fiche_Pay_' . $unit['code_unique'] . '.pdf';
    if (file_exists($qrFichePath)) {
        $qrFicheUrl = APP_URL . '/uploads/qrcodes/Fiche_Pay_' . $unit['code_unique'] . '.pdf';
    }
}

// Message de succès dynamique
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'pay') $successMsg = "Paiement espèces enregistré et reçu WhatsApp envoyé !";
    if ($_GET['success'] === 'charge') $successMsg = "Charge mensuelle ajoutée avec succès.";
    if ($_GET['success'] === 'cloture') $successMsg = "Bail clôturé, QR code désactivé et chambre libérée.";
}

$pageTitle = $unit['code_unique'];
$activeNav = 'properties';
$basePath = '..';
require_once __DIR__ . '/../includes/head.php';
?>

    <div class="app-container has-nav">
        <header class="app-header">
            <div class="header-inner d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <a href="property-detail.php?id=<?php echo $property['id']; ?>" class="header-back"><i class="bi bi-arrow-left"></i></a>
                    <div>
                        <h1 class="header-title mb-0"><?php echo htmlspecialchars($unit['code_unique']); ?> · <?php echo htmlspecialchars($property['nom']); ?></h1>
                        <div class="header-sub"><?php echo htmlspecialchars($unit['code_unique']); ?> · <?php echo htmlspecialchars($unit['type'] ?? 'Chambre'); ?></div>
                    </div>
                </div>
                <div>
                    <span class="badge bg-white text-success fw-bold px-2 py-1 rounded-pill small">
                        <?php echo htmlspecialchars($unit['statut']); ?>
                    </span>
                </div>
            </div>
        </header>

        <main class="app-main">
            
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
                <div class="tenant-card animate-fade-in">
                    <div class="tenant-card-label">LOCATAIRE ACTUEL</div>
                    <div class="tenant-card-name"><?php echo htmlspecialchars($tenant['prenom']); ?></div>
                    <div class="tenant-info-line"><i class="bi bi-telephone text-success"></i> <b>+229 <?php echo htmlspecialchars($tenant['telephone']); ?></b></div>
                    <div class="tenant-info-line"><i class="bi bi-cash text-success"></i> <b><?php echo number_format($tenant['loyer_convenu'], 0, ',', ' '); ?> FCFA</b> / mois</div>
                    <div class="tenant-info-line"><i class="bi bi-calendar text-success"></i> Bail depuis le <?php echo date('j', strtotime($tenant['date_debut'])); ?> <?php echo ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'][(int)date('n', strtotime($tenant['date_debut']))]; ?> <?php echo date('Y', strtotime($tenant['date_debut'])); ?></div>
                    <?php if (!empty($tenant['caution_montant']) && $tenant['caution_montant'] > 0): ?>
                    <div class="tenant-info-line"><i class="bi bi-lock text-success"></i> Caution : <b><?php echo number_format($tenant['caution_montant'], 0, ',', ' '); ?> FCFA</b> versée</div>
                    <?php endif; ?>
                    <?php if ($isLate): ?>
                    <div class="tenant-alert-late"><i class="bi bi-exclamation-triangle-fill"></i> Retard — <?php echo $joursRetard; ?> jour<?php echo $joursRetard > 1 ? 's' : ''; ?> ce mois</div>
                    <?php endif; ?>
                </div>

                <h6 class="small fw-bold text-muted text-uppercase mb-3" style="letter-spacing:0.06em;">Actions rapides</h6>
                <div class="action-list mb-4">
                    <button type="button" class="action-list-btn" data-bs-toggle="modal" data-bs-target="#payCashModal">
                        <span class="al-icon">💵</span>
                        <span class="al-label">Paiement espèces reçu</span>
                    </button>
                    <?php if ($payUrl): ?>
                    <a href="https://wa.me/229<?php echo htmlspecialchars($tenant['telephone']); ?>?text=<?php echo urlencode('Bonjour ' . $tenant['prenom'] . ', voici votre lien de paiement LokaGest : ' . $payUrl); ?>" target="_blank" class="action-list-btn">
                        <span class="al-icon">💬</span>
                        <span class="al-label">Envoyer lien WhatsApp</span>
                    </a>
                    <?php if ($qrFicheUrl): ?>
                    <a href="<?php echo htmlspecialchars($qrFicheUrl); ?>" target="_blank" class="action-list-btn">
                        <span class="al-icon">📱</span>
                        <span class="al-label">Télécharger QR code PDF</span>
                    </a>
                    <?php else: ?>
                    <a href="../api/qrcode.php?text=<?php echo urlencode($payUrl); ?>&download=1" target="_blank" class="action-list-btn">
                        <span class="al-icon">📱</span>
                        <span class="al-label">Télécharger QR code PDF</span>
                    </a>
                    <?php endif; ?>
                    <a href="https://wa.me/229<?php echo htmlspecialchars($tenant['telephone']); ?>?text=<?php echo urlencode('Bonjour ' . $tenant['prenom'] . ', rappel LokaGest : votre loyer du mois de ' . date('m/Y') . ' est en attente. ' . $payUrl); ?>" target="_blank" class="action-list-btn">
                        <span class="al-icon">📢</span>
                        <span class="al-label">Envoyer rappel manuel</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($leaseInfo && !empty($leaseInfo['pdf_url'])): ?>
                    <a href="<?php echo htmlspecialchars($leaseInfo['pdf_url']); ?>" target="_blank" class="action-list-btn">
                        <span class="al-icon">📄</span>
                        <span class="al-label">Voir le bail PDF</span>
                    </a>
                    <?php endif; ?>
                    <button type="button" class="action-list-btn danger" data-bs-toggle="modal" data-bs-target="#closeLeaseModal">
                        <span class="al-icon">🚪</span>
                        <span class="al-label">Clôturer le bail</span>
                    </button>
                </div>
                <?php if ($payUrl): ?>
                <input type="hidden" id="pay-url-value" value="<?php echo htmlspecialchars($payUrl); ?>">
                <?php endif; ?>

                <button type="button" class="btn btn-nimbus-outline w-100 btn-sm mb-4" data-bs-toggle="modal" data-bs-target="#addChargeModal">
                    <i class="bi bi-lightning"></i> Ajouter une charge mensuelle
                </button>

                <!-- Charges mensuelles (F33) -->
                <div class="card border-0 rounded-4 shadow-sm p-3 mb-4 text-start">
                    <h6 class="fw-bold text-dark mb-2"><i class="bi bi-lightning-charge text-warning me-1"></i>Charges de <?php echo $moisEnCours; ?></h6>
                    <?php if (empty($charges)): ?>
                        <p class="text-muted small mb-0">Aucune charge ajoutée pour ce mois.</p>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-1">
                            <?php foreach ($charges as $c): ?>
                                <div class="d-flex justify-content-between align-items-center border-bottom py-1 small">
                                    <span class="text-secondary"><?php echo htmlspecialchars($c['type']); ?></span>
                                    <span class="fw-bold"><?php echo number_format($c['montant'], 0, ',', ' '); ?> FCFA</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Suivi des paiements du locataire en cours -->
                <div class="mb-4 text-start">
                    <h6 class="fw-bold text-dark mb-3"><i class="bi bi-wallet2 text-success me-2"></i>Paiements récents</h6>
                    <?php if (empty($payments)): ?>
                        <p class="text-muted small text-center py-3">Aucun paiement enregistré pour ce locataire.</p>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($payments as $p): ?>
                                <div class="card border-0 rounded-3 shadow-sm p-2 px-3 d-flex flex-row justify-content-between align-items-center small">
                                    <div>
                                        <b class="text-dark">Mois : <?php echo date('m/Y', strtotime($p['mois'] . '-01')); ?></b>
                                        <span class="d-block text-muted" style="font-size: 0.72rem;">Reçu : <?php echo substr($p['numero_recu'], 0, 8); ?>... • <?php echo htmlspecialchars($p['mode']); ?></span>
                                    </div>
                                    <div class="text-end">
                                        <span class="fw-bold text-success d-block"><?php echo number_format($p['montant'], 0, ',', ' '); ?> F</span>
                                        <span class="badge bg-success-subtle text-success rounded-pill" style="font-size: 0.65rem;">Validé</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- Chambre libre -->
                <div class="card border-0 rounded-4 shadow-sm p-4 text-center mb-4">
                    <div class="text-muted display-4 mb-2"><i class="bi bi-house"></i></div>
                    <h5 class="fw-bold text-dark">Chambre actuellement libre</h5>
                    <p class="text-muted small px-3 mb-4">Cette chambre n'a aucun locataire actif. Vous pouvez en affecter un ou partager la fiche de la chambre libre.</p>
                    
                    <div class="d-flex flex-column gap-2">
                        <a href="add-tenant.php?unit_id=<?php echo $unitId; ?>" class="btn btn-nimbus w-100 d-block text-center text-decoration-none">
                            <i class="bi bi-person-plus me-2"></i>Affecter un Locataire
                        </a>
                        <button class="btn btn-outline-success py-2 rounded-3" onclick="shareFreeRoom()">
                            <i class="bi bi-share me-2"></i>Fiche de partage WhatsApp
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Historique des locataires passés (F27) -->
            <div class="text-start">
                <h6 class="fw-bold text-dark mb-3"><i class="bi bi-clock-history text-success me-2"></i>Historique des locataires passés</h6>
                <?php if (empty($roomHistory)): ?>
                    <p class="text-muted small text-center py-3">Aucun locataire précédent archivé.</p>
                <?php else: ?>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($roomHistory as $h): ?>
                            <!-- Récupérer le prénom du locataire archivé -->
                            <?php
                            $tRes = SupabaseClient::select('tenants', 'prenom', 'id=eq.' . $h['tenant_id']);
                            $tPrenom = ($tRes['status'] === 200 && !empty($tRes['data'])) ? $tRes['data'][0]['prenom'] : 'Locataire Archivé';
                            ?>
                            <div class="card border-0 rounded-3 shadow-sm p-3 small">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-secondary"><?php echo htmlspecialchars($tPrenom); ?></span>
                                    <span class="badge bg-secondary rounded-pill" style="font-size: 0.65rem;">Archivé</span>
                                </div>
                                <div class="d-flex justify-content-between text-muted" style="font-size: 0.72rem;">
                                    <span>Période : <?php echo date('d/m/Y', strtotime($h['date_entree'])); ?> au <?php echo date('d/m/Y', strtotime($h['date_sortie'])); ?></span>
                                    <span>Loyer : <?php echo number_format($h['loyer'], 0, ',', ' '); ?> F</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </main>

        <!-- Modale Enregistrer Reçu Espèces (F17) -->
        <?php if ($tenant): ?>
        <div class="modal fade" id="payCashModal" tabindex="-1" aria-labelledby="payCashModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered mx-3 max-width-480">
                <div class="modal-content rounded-4 border-0 shadow">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold" id="payCashModalLabel">Enregistrer Paiement Espèces</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="unit-detail.php?id=<?php echo $unitId; ?>" method="POST">
                        <div class="modal-body py-4">
                            <div class="mb-3 text-start">
                                <label for="montant" class="form-label small fw-semibold text-secondary">Montant perçu *</label>
                                <div class="input-group">
                                    <input type="number" class="form-control rounded-start-3" id="montant" name="montant" value="<?php echo intval($tenant['loyer_convenu']); ?>" required>
                                    <span class="input-group-text bg-light text-secondary rounded-end-3">FCFA</span>
                                </div>
                            </div>
                            
                            <div class="mb-3 text-start">
                                <label for="mois" class="form-label small fw-semibold text-secondary">Loyer du mois de *</label>
                                <input type="month" class="form-control rounded-3" id="mois" name="mois" value="<?php echo $moisEnCours; ?>" required>
                            </div>
                            
                            <div class="alert alert-danger border-0 small py-2 mb-0" role="alert">
                                ⚠️ <b>Attention :</b> Cette action va générer une quittance de reçu PDF officielle avec mention <b>ESPÈCES en rouge</b> et la notifier au locataire. Aucun remboursement n'est possible en ligne.
                            </div>
                        </div>
                        <div class="modal-footer border-0 pb-4">
                            <button type="button" class="btn btn-light rounded-3 px-3" data-bs-dismiss="modal">Fermer</button>
                            <button type="submit" name="pay_cash" class="btn btn-nimbus px-4">Valider</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modale Ajouter une Charge Mensuelle (F33) -->
        <div class="modal fade" id="addChargeModal" tabindex="-1" aria-labelledby="addChargeModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered mx-3 max-width-480">
                <div class="modal-content rounded-4 border-0 shadow">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold" id="addChargeModalLabel">Ajouter une Charge Mensuelle</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="unit-detail.php?id=<?php echo $unitId; ?>" method="POST">
                        <div class="modal-body py-4">
                            <div class="mb-3 text-start">
                                <label for="type_charge" class="form-label small fw-semibold text-secondary">Nature de la charge *</label>
                                <select class="form-select rounded-3" id="type_charge" name="type_charge" required>
                                    <option value="Électricité SBEE">Électricité SBEE</option>
                                    <option value="Eau SONEB">Eau SONEB</option>
                                    <option value="Gardiennage / Sécurité">Gardiennage / Sécurité</option>
                                    <option value="Entretien des parties communes">Entretien des communs</option>
                                </select>
                            </div>

                            <div class="mb-3 text-start">
                                <label for="montant_charge" class="form-label small fw-semibold text-secondary">Montant *</label>
                                <div class="input-group">
                                    <input type="number" class="form-control rounded-start-3" id="montant_charge" name="montant_charge" required placeholder="Ex: 5000">
                                    <span class="input-group-text bg-light text-secondary rounded-end-3">FCFA</span>
                                </div>
                            </div>
                            
                            <div class="mb-0 text-start">
                                <label for="mois_charge" class="form-label small fw-semibold text-secondary">Mois concerné *</label>
                                <input type="month" class="form-control rounded-3" id="mois_charge" name="mois_charge" value="<?php echo $moisEnCours; ?>" required>
                            </div>
                        </div>
                        <div class="modal-footer border-0 pb-4">
                            <button type="button" class="btn btn-light rounded-3 px-3" data-bs-dismiss="modal">Fermer</button>
                            <button type="submit" name="add_charge" class="btn btn-nimbus px-4">Ajouter</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modale Clôture de bail — questionnaire 3 étapes -->
        <div class="modal fade" id="closeLeaseModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable mx-3 max-width-480">
                <div class="modal-content rounded-4 border-0 shadow">
                    <div class="modal-header border-0 text-white rounded-top-4" style="background:#DC2626;">
                        <div>
                            <h6 class="modal-title fw-bold mb-0">Clôturer le bail</h6>
                            <small class="opacity-75"><?php echo htmlspecialchars($tenant['prenom']); ?> — <?php echo htmlspecialchars($unit['code_unique']); ?></small>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="unit-detail.php?id=<?php echo $unitId; ?>" method="POST" id="close-lease-form">
                        <div class="modal-body py-3 text-start">
                            <div class="alert alert-danger border small py-2 mb-3">
                                <b>Action irréversible</b> — Le QR code et le lien de paiement seront désactivés. Un document de clôture sera envoyé au locataire.
                            </div>

                            <div class="wizard-step">
                                <div class="wizard-step-label">Question 1 / 3</div>
                                <p class="fw-bold small mb-2">La caution est-elle restituée ?</p>
                                <label class="wizard-option selected-yes" data-group="caution">
                                    <input type="radio" name="caution_restituee" value="1" checked class="d-none">
                                    <span class="wo-radio"><i class="bi bi-check"></i></span>
                                    Restituée en totalité (<?php echo number_format($tenant['caution_montant'] ?? $tenant['loyer_convenu'], 0, ',', ' '); ?> FCFA)
                                </label>
                                <label class="wizard-option" data-group="caution">
                                    <input type="radio" name="caution_restituee" value="0" class="d-none">
                                    <span class="wo-radio"></span>
                                    Retenue (indiquer la raison)
                                </label>
                                <div id="motif_retenue_container" class="mt-2 d-none">
                                    <input type="text" class="form-control form-control-sm rounded-3" id="motif_retenue" name="motif_retenue" placeholder="Motif de retenue">
                                </div>
                            </div>

                            <div class="wizard-step">
                                <div class="wizard-step-label">Question 2 / 3</div>
                                <p class="fw-bold small mb-2">Y a-t-il un loyer impayé ?</p>
                                <label class="wizard-option selected-yes" data-group="impaye">
                                    <input type="radio" name="impaye_type" value="0" checked class="d-none">
                                    <span class="wo-radio"><i class="bi bi-check"></i></span>
                                    Tout est payé
                                </label>
                                <label class="wizard-option" data-group="impaye">
                                    <input type="radio" name="impaye_type" value="full" class="d-none">
                                    <span class="wo-radio"></span>
                                    Impayé définitif
                                </label>
                                <div id="loyers_impayes_wrap" class="mt-2 d-none">
                                    <input type="number" class="form-control form-control-sm rounded-3" id="loyers_impayes" name="loyers_impayes" value="0" placeholder="Montant impayé FCFA">
                                </div>
                            </div>

                            <div class="wizard-step">
                                <div class="wizard-step-label">Question 3 / 3</div>
                                <p class="fw-bold small mb-2">La chambre est-elle disponible immédiatement ?</p>
                                <label class="wizard-option selected-yes">
                                    <input type="checkbox" id="chambre_dispo" required checked class="d-none">
                                    <span class="wo-radio"><i class="bi bi-check"></i></span>
                                    Oui, disponible de suite
                                </label>
                            </div>
                        </div>
                        <div class="modal-footer border-0 pb-4">
                            <button type="button" class="btn btn-light rounded-3" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" name="close_lease" class="btn btn-danger rounded-pill px-4">Confirmer la clôture</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modale Départ non signalé (F26) -->
        <div class="modal fade" id="unilateralModal" tabindex="-1" aria-labelledby="unilateralModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered mx-3 max-width-480">
                <div class="modal-content rounded-4 border-0 shadow">
                    <div class="modal-header border-0 bg-dark text-white rounded-top-4">
                        <h6 class="modal-title fw-bold" id="unilateralModalLabel">Départ non signalé unilatéral</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="unit-detail.php?id=<?php echo $unitId; ?>" method="POST">
                        <input type="hidden" name="caution_restituee" value="0">
                        <input type="hidden" name="motif_retenue" value="Départ non signalé, caution conservée d'office.">
                        
                        <div class="modal-body py-4 text-start">
                            <p class="small text-muted">Cette action enregistre la fin de bail immédiate du locataire de façon <b>unilatérale</b> (par exemple s'il a fui sans rendre les clés et que vous devez libérer la chambre).</p>
                            
                            <div class="mb-3">
                                <label for="loyers_impayes_uni" class="form-label small fw-semibold text-secondary">Montant total d'impayés estimé</label>
                                <div class="input-group">
                                    <input type="number" class="form-control rounded-start-3" id="loyers_impayes_uni" name="loyers_impayes" value="0" required>
                                    <span class="input-group-text bg-light text-secondary rounded-end-3">FCFA</span>
                                </div>
                            </div>
                            
                            <div class="alert alert-warning border-0 small py-2 mb-0" role="alert">
                                ⚠️ <b>Conséquences :</b> La caution sera retenue d'office. Un document unilatéral de départ sera daté et horodaté et le lien de paiement/QR code sera instantanément désactivé.
                            </div>
                        </div>
                        <div class="modal-footer border-0 pb-4">
                            <button type="button" class="btn btn-light rounded-3 px-3" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" name="unilateral_departure" class="btn btn-dark rounded-3 px-4">Confirmer le départ</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php require_once __DIR__ . '/../includes/nav.php'; ?>
    </div>

    <script>
        document.querySelectorAll('.wizard-option[data-group]').forEach(opt => {
            opt.addEventListener('click', function() {
                const group = this.dataset.group;
                document.querySelectorAll('.wizard-option[data-group="' + group + '"]').forEach(o => {
                    o.classList.remove('selected-yes', 'selected-no');
                });
                const input = this.querySelector('input');
                if (input) input.checked = true;
                if (group === 'caution') {
                    const isRetenue = input?.value === '0';
                    this.classList.add(isRetenue ? 'selected-no' : 'selected-yes');
                    document.getElementById('motif_retenue_container')?.classList.toggle('d-none', !isRetenue);
                } else if (group === 'impaye') {
                    const hasImpaye = input?.value === 'full';
                    this.classList.add(hasImpaye ? 'selected-no' : 'selected-yes');
                    document.getElementById('loyers_impayes_wrap')?.classList.toggle('d-none', !hasImpaye);
                }
            });
        });

        // Partage WhatsApp chambre libre (F30)
        function copyPayLink() {
            const el = document.getElementById('pay-url-value');
            if (!el) return;
            navigator.clipboard.writeText(el.value).then(() => {
                alert('Lien de paiement copié !');
            });
        }

        function shareFreeRoom() {
            const text = "Chambre disponible chez LokaGest !\n\n🏠 Chambre : <?php echo htmlspecialchars($unit['code_unique']); ?>\n📍 Quartier : <?php echo htmlspecialchars($property['quartier']); ?>\n💰 Loyer : <?php echo number_format($unit['loyer_reference'], 0, ',', ' '); ?> FCFA / mois\n\nContactez-moi pour visiter !";
            const whatsappUrl = "https://wa.me/?text=" + encodeURIComponent(text);
            window.open(whatsappUrl, '_blank');
        }
    </script>
    
    <?php require_once __DIR__ . '/../includes/scripts.php'; ?>
</body>
</html>
