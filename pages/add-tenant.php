<?php
/**
 * LokaGest - Ajouter un Locataire
 * 
 * Permet d'affecter un nouveau locataire à une chambre libre.
 * Déclenche automatiquement la génération du bail PDF (SHA-256), du QR Code unique,
 * de la fiche A5 d'impression de paiement et l'envoi par WhatsApp (CallMeBot).
 */

require_once __DIR__ . '/../lib/auth_guard.php';
require_once __DIR__ . '/../lib/supabase.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/pdf_generator.php';
require_once __DIR__ . '/../lib/qr_generator.php';
require_once __DIR__ . '/../lib/whatsapp_sender.php';
require_once __DIR__ . '/../lib/sms_sender.php';

$user = $_SESSION['user'];
$userId = $user['id'];

$unitId = $_GET['unit_id'] ?? null;
$selectedUnit = null;

// Charger la chambre si spécifiée
if ($unitId) {
    $unitRes = SupabaseClient::select('units', '*', 'id=eq.' . $unitId);
    if ($unitRes['status'] === 200 && !empty($unitRes['data'])) {
        $selectedUnit = $unitRes['data'][0];
        
        // Sécurité : Vérifier que la chambre appartient à une propriété de cet utilisateur
        $propCheck = SupabaseClient::select('properties', 'id', 'id=eq.' . $selectedUnit['property_id'] . '&user_id=eq.' . $userId);
        if ($propCheck['status'] !== 200 || empty($propCheck['data'])) {
            header('Location: properties.php');
            exit;
        }
        
        // Bloquer si la chambre n'est pas libre
        if ($selectedUnit['statut'] !== 'libre') {
            header('Location: unit-detail.php?id=' . $unitId);
            exit;
        }
    }
}

// Récupérer la liste de toutes les chambres libres de l'utilisateur pour le sélecteur
$freeUnits = [];
$propertiesRes = SupabaseClient::select('properties', 'id', 'user_id=eq.' . $userId);
if ($propertiesRes['status'] === 200 && !empty($propertiesRes['data'])) {
    $propIds = array_column($propertiesRes['data'], 'id');
    $propIdsList = implode(',', $propIds);
    
    $freeUnitsRes = SupabaseClient::select('units', '*', 'property_id=in.(' . $propIdsList . ')&statut=eq.libre');
    if ($freeUnitsRes['status'] === 200 && is_array($freeUnitsRes['data'])) {
        $freeUnits = $freeUnitsRes['data'];
    }
}

$errorMsg = null;

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unitId = $_POST['unit_id'] ?? $unitId;
    $prenom = trim($_POST['prenom'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $loyerConvenu = floatval($_POST['loyer_convenu'] ?? 0);
    $dateDebut = trim($_POST['date_debut'] ?? '');
    $dureeMois = intval($_POST['duree_mois'] ?? 12);
    $cautionMontant = floatval($_POST['caution_montant'] ?? 0);
    
    if (empty($unitId) || empty($prenom) || empty($telephone) || $loyerConvenu <= 0 || empty($dateDebut)) {
        $errorMsg = "Veuillez remplir correctement tous les champs obligatoires.";
    } else {
        // Charger l'unité choisie
        $unitRes = SupabaseClient::select('units', '*', 'id=eq.' . $unitId);
        $unit = ($unitRes['status'] === 200 && !empty($unitRes['data'])) ? $unitRes['data'][0] : null;
        
        // Charger la propriété
        $propRes = SupabaseClient::select('properties', '*', 'id=eq.' . $unit['property_id']);
        $property = ($propRes['status'] === 200 && !empty($propRes['data'])) ? $propRes['data'][0] : null;
        
        if (!$unit || !$property) {
            $errorMsg = "Chambre ou propriété introuvable.";
        } else {
            // Nettoyer le numéro de téléphone locataire
            $telephone = preg_replace('/[^0-9]/', '', $telephone);
            if (strlen($telephone) === 8) {
                $telephone = '229' . $telephone;
            }
            // Retirer l'indicatif international si présent au début pour le stockage propre à 8 chiffres (et wa.me)
            if (strpos($telephone, '229') === 0 && strlen($telephone) === 11) {
                $telephone = substr($telephone, 3);
            }
            
            // 1. Insérer le locataire
            $newTenant = [
                'unit_id' => $unitId,
                'prenom' => $prenom,
                'telephone' => $telephone,
                'loyer_convenu' => $loyerConvenu,
                'date_debut' => $dateDebut,
                'duree_mois' => $dureeMois,
                'caution_montant' => $cautionMontant,
                'statut' => 'actif',
                'score_ponctualite' => 100,
                'date_creation' => date('Y-m-d H:i:s')
            ];
            
            $insertTenantRes = SupabaseClient::insert('tenants', $newTenant);
            
            if ($insertTenantRes['status'] >= 200 && $insertTenantRes['status'] < 300) {
                $tenant = $insertTenantRes['data'][0] ?? null;
                
                // Si l'ID inséré n'a pas été retourné (selon configuration Supabase), on le récupère
                if (!$tenant || !isset($tenant['id'])) {
                    $refetch = SupabaseClient::select('tenants', 'id', 'unit_id=eq.' . $unitId . '&statut=eq.actif');
                    if ($refetch['status'] === 200 && !empty($refetch['data'])) {
                        $tenant = array_merge($newTenant, ['id' => $refetch['data'][0]['id']]);
                    }
                }
                
                if ($tenant) {
                    $tenantId = $tenant['id'];
                    
                    // 2. Mettre à jour la chambre à "occupée"
                    SupabaseClient::update('units', ['statut' => 'occupee'], 'id=eq.' . $unitId);
                    
                    // 3. Générer le numéro de bail unique en SHA-256 (F10)
                    $numeroBail = 'LKG-BAIL-' . strtoupper(substr(hash('sha256', $tenantId . $unitId . time()), 0, 8));
                    
                    // 4. Générer le bail PDF
                    $pdfLease = PDFGenerator::generateLeasePDF($tenant, $unit, $property, $numeroBail);
                    
                    $pdfDestDir = __DIR__ . '/../uploads/leases/';
                    if (!is_dir($pdfDestDir)) {
                        mkdir($pdfDestDir, 0755, true);
                    }
                    $leaseFileName = 'Bail_' . $numeroBail . '.pdf';
                    file_put_contents($pdfDestDir . $leaseFileName, $pdfLease);
                    
                    $pdfUrl = APP_URL . '/uploads/leases/' . $leaseFileName;
                    
                    // 5. Enregistrer le bail en BDD (leases table)
                    $leaseRecord = [
                        'unit_id' => $unitId,
                        'tenant_id' => $tenantId,
                        'numero_bail' => $numeroBail,
                        'duree' => $dureeMois,
                        'caution_montant' => $cautionMontant,
                        'statut_caution' => 'non_restituee',
                        'pdf_url' => $pdfUrl,
                        'date_generation' => date('Y-m-d H:i:s')
                    ];
                    SupabaseClient::insert('leases', $leaseRecord);
                    
                    // 6. Générer un token signé pour le lien de paiement et l'enregistrer (F11)
                    $paymentToken = bin2hex(random_bytes(16));
                    $paymentLink = [
                        'unit_id' => $unitId,
                        'tenant_id' => $tenantId,
                        'token' => $paymentToken,
                        'statut' => 'actif',
                        'date_creation' => date('Y-m-d H:i:s')
                    ];
                    SupabaseClient::insert('payment_links', $paymentLink);
                    
                    $payUrl = APP_URL . '/tenant/pay.php?token=' . $paymentToken;
                    
                    // 7. Générer le QR Code (F11)
                    $qrBase64 = QRGenerator::generate($payUrl);
                    
                    // 8. Générer la fiche A5 QR Code (F12)
                    $pdfQRCodeA5 = PDFGenerator::generateQRCodeA5PDF($unit, $property, $prenom, $payUrl, $qrBase64);
                    
                    $qrDestDir = __DIR__ . '/../uploads/qrcodes/';
                    if (!is_dir($qrDestDir)) {
                        mkdir($qrDestDir, 0755, true);
                    }
                    $qrFileName = 'Fiche_Pay_' . $unit['code_unique'] . '.pdf';
                    file_put_contents($qrDestDir . $qrFileName, $pdfQRCodeA5);
                    
                    // 9. Générer également la quittance de caution d'entrée (F31)
                    if ($cautionMontant > 0) {
                        $pdfCaution = PDFGenerator::generateDepositReceiptPDF($tenant, $unit, $property, 'reception');
                        $cautionDestDir = __DIR__ . '/../uploads/deposits/';
                        if (!is_dir($cautionDestDir)) {
                            mkdir($cautionDestDir, 0755, true);
                        }
                        $cautionFileName = 'Caution_Entree_' . $tenantId . '.pdf';
                        file_put_contents($cautionDestDir . $cautionFileName, $pdfCaution);
                    }
                    
                    // 10. Envoi WhatsApp automatique via CallMeBot (F10)
                    $welcomeMsg = "Bienvenue sur LokaGest !\n\nM./Mme " . $prenom . ", votre contrat de bail pour la chambre " . $unit['code_unique'] . " a ete valide.\n\n📄 Votre contrat de bail PDF : " . $pdfUrl . "\n💳 Lien pour payer votre loyer MoMo : " . $payUrl . "\n\nMerci de votre confiance !";
                    
                    WhatsAppSender::send($telephone, $welcomeMsg, getCallMeBotKey());
                    
                    header('Location: unit-detail.php?id=' . $unitId . '&success=1');
                    exit;
                } else {
                    $errorMsg = "Erreur de chargement du profil locataire.";
                }
            } else {
                $errorMsg = "Erreur d'enregistrement du locataire : " . ($insertTenantRes['error'] ?? 'Inconnue');
            }
        }
    }
}

$pageTitle = 'Ajouter un locataire';
$activeNav = 'properties';
$basePath = '..';
require_once __DIR__ . '/../includes/head.php';
?>

    <div class="app-container has-nav">
        <header class="app-header">
            <div class="header-inner d-flex align-items-center gap-3">
                <a href="javascript:history.back()" class="header-back"><i class="bi bi-arrow-left"></i></a>
                <div>
                    <h1 class="header-title mb-0">Ajouter un locataire<?php echo $selectedUnit ? ' — ' . htmlspecialchars($selectedUnit['code_unique']) : ''; ?></h1>
                    <div class="header-sub">Bail automatique & QR paiement</div>
                </div>
            </div>
        </header>

        <main class="app-main text-start">
            
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger border-0 shadow-sm small py-2 mb-3" role="alert">
                    <?php echo htmlspecialchars($errorMsg); ?>
                </div>
            <?php endif; ?>

            <form action="add-tenant.php<?php echo $selectedUnit ? '?unit_id=' . $selectedUnit['id'] : ''; ?>" method="POST" class="animate-fade-in">
                <?php if ($selectedUnit): ?>
                    <input type="hidden" name="unit_id" value="<?php echo $selectedUnit['id']; ?>">
                <?php endif; ?>

                <div class="section-card">
                    <div class="section-card-title"><i class="bi bi-person text-success"></i> Informations du locataire</div>
                    <?php if (!$selectedUnit): ?>
                    <div class="mb-3">
                        <label for="unit_id" class="form-label">Choisir une chambre libre *</label>
                        <select class="form-select rounded-3" id="unit_id" name="unit_id" required>
                            <option value="">— Sélectionner —</option>
                            <?php foreach ($freeUnits as $fu): ?>
                                <option value="<?php echo $fu['id']; ?>"><?php echo htmlspecialchars($fu['code_unique']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="prenom" class="form-label">Prénom</label>
                        <input type="text" class="form-control rounded-3" id="prenom" name="prenom" placeholder="Ex: Koffi, Aïcha, Jean…" required>
                    </div>
                    <div class="mb-0">
                        <label for="telephone" class="form-label">Numéro WhatsApp</label>
                        <input type="tel" class="form-control rounded-3" id="telephone" name="telephone" placeholder="+229 97 00 00 00" pattern="[0-9]{8}" required>
                        <div class="form-text small">Le bail et les reçus seront envoyés sur ce numéro</div>
                    </div>
                </div>

                <div class="section-card">
                    <div class="section-card-title"><i class="bi bi-cash text-success"></i> Conditions du bail</div>
                    <div class="mb-3">
                        <label for="loyer_convenu" class="form-label">Montant du loyer mensuel (FCFA)</label>
                        <input type="number" class="form-control rounded-3" id="loyer_convenu" name="loyer_convenu" value="<?php echo $selectedUnit ? intval($selectedUnit['loyer_reference']) : '35000'; ?>" required>
                        <div class="form-text small">Peut différer du loyer de référence</div>
                    </div>
                    <div class="mb-3">
                        <label for="caution_montant" class="form-label">Caution versée (FCFA)</label>
                        <input type="number" class="form-control rounded-3" id="caution_montant" name="caution_montant" value="<?php echo $selectedUnit ? intval($selectedUnit['loyer_reference']) : '35000'; ?>">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label for="date_debut" class="form-label">Date de début</label>
                            <input type="date" class="form-control rounded-3" id="date_debut" name="date_debut" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label for="duree_mois" class="form-label">Durée (mois)</label>
                            <input type="number" class="form-control rounded-3" id="duree_mois" name="duree_mois" value="12" min="1" required>
                        </div>
                    </div>
                </div>

                <div class="alert alert-success border-0 small py-3 mb-4">
                    <b>Ce qui sera généré automatiquement :</b><br>
                    ✓ Bail PDF envoyé par WhatsApp<br>
                    ✓ QR code de paiement MoMo<br>
                    ✓ Lien de paiement unique
                </div>

                <button type="submit" class="btn btn-nimbus w-100 mb-3">
                    <i class="bi bi-person-check-fill me-2"></i>Enregistrer le locataire
                </button>
            </form>

        </main>

        <?php require_once __DIR__ . '/../includes/nav.php'; ?>
    </div>
    <?php require_once __DIR__ . '/../includes/scripts.php'; ?>
</body>
</html>
