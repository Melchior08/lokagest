<?php
/**
 * LokaGest - Webhook de Notification FedaPay & Validation de Simulation
 * 
 * Traite les notifications asynchrones de transaction (réelles via FedaPay webhook
 * ou fictives via notre écran de simulation push USSD).
 * Valide le paiement, crédite le wallet propriétaire, génère le reçu de loyer PDF,
 * et notifie le locataire (WhatsApp/SMS) et le propriétaire (F40/F47/F48/F49).
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/supabase.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/pdf_generator.php';
require_once __DIR__ . '/../lib/whatsapp_sender.php';
require_once __DIR__ . '/../lib/sms_sender.php';

$success = false;
$fedapayId = null;
$errorMsg = null;
$isSimulation = false;
$token = $_GET['token'] ?? '';
$confirmedPayment = null;

// 1. Détecter s'il s'agit de notre simulation locale (F44/F45/F46)
if (isset($_GET['simulation_id'])) {
    $isSimulation = true;
    $fedapayId = $_GET['simulation_id'];
    $status = $_GET['status'] ?? '';
    
    if ($status === 'approved') {
        $success = true;
    } else {
        $errorMsg = "Transaction de test annulée par l'utilisateur.";
    }
} else {
    // 2. Traitement du Webhook FedaPay réel (Requête POST de FedaPay)
    // Récupérer le contenu JSON brut envoyé en POST
    $payload = file_get_contents('php://input');
    $sigHeader = $_SERVER['HTTP_X_FEDAPAY_SIGNATURE'] ?? ''; // Signature de sécurité FedaPay optionnelle
    
    $event = json_decode($payload, true);
    
    if (isset($event['entity']) && $event['entity'] === 'transaction') {
        $fedapayId = $event['id'];
        $status = $event['status'];
        
        if ($status === 'approved') {
            $success = true;
        } else {
            $errorMsg = "Transaction FedaPay échouée ou déclinée. Statut : $status";
        }
    }
}

if ($fedapayId) {
    // 3. Charger le paiement en_attente dans Supabase par fedapay_id
    $payResponse = SupabaseClient::select('payments', '*', 'fedapay_id=eq.' . $fedapayId);
    
    if ($payResponse['status'] === 200 && !empty($payResponse['data'])) {
        $payment = $payResponse['data'][0];
        
        if ($payment['statut'] === 'en_attente') {
            if ($success) {
                SupabaseClient::update('payments', ['statut' => 'confirme'], 'id=eq.' . $payment['id']);
                $confirmedPayment = $payment;
                
                // Charger le locataire, la chambre et la propriété
                $tenantRes = SupabaseClient::select('tenants', '*', 'id=eq.' . $payment['tenant_id']);
                $tenant = ($tenantRes['status'] === 200 && !empty($tenantRes['data'])) ? $tenantRes['data'][0] : null;
                
                $unitRes = SupabaseClient::select('units', '*', 'id=eq.' . $payment['unit_id']);
                $unit = ($unitRes['status'] === 200 && !empty($unitRes['data'])) ? $unitRes['data'][0] : null;
                
                $property = null;
                $owner = null;
                
                if ($unit) {
                    $propRes = SupabaseClient::select('properties', '*', 'id=eq.' . $unit['property_id']);
                    if ($propRes['status'] === 200 && !empty($propRes['data'])) {
                        $property = $propRes['data'][0];
                        
                        // Charger le propriétaire
                        $ownerRes = SupabaseClient::select('users', '*', 'id=eq.' . $property['user_id']);
                        if ($ownerRes['status'] === 200 && !empty($ownerRes['data'])) {
                            $owner = $ownerRes['data'][0];
                        }
                    }
                }
                
                if ($tenant && $unit && $property && $owner) {
                    // B. Créditer le portefeuille (Wallet) du propriétaire (F19)
                    $walletRes = SupabaseClient::select('wallets', '*', 'user_id=eq.' . $owner['id']);
                    if ($walletRes['status'] === 200 && !empty($walletRes['data'])) {
                        $wallet = $walletRes['data'][0];
                        
                        $nouveauSolde = $wallet['solde'] + $payment['montant'];
                        $nouveauTotalEntre = $wallet['total_entre'] + $payment['montant'];
                        
                        SupabaseClient::update(
                            'wallets',
                            ['solde' => $nouveauSolde, 'total_entre' => $nouveauTotalEntre],
                            'id=eq.' . $wallet['id']
                        );
                    }
                    
                    // C. Générer le reçu PDF (F47)
                    $pdfContent = PDFGenerator::generateReceiptPDF($payment, $tenant, $unit, $property);
                    
                    $pdfDestDir = __DIR__ . '/../uploads/receipts/';
                    if (!is_dir($pdfDestDir)) {
                        mkdir($pdfDestDir, 0755, true);
                    }
                    $pdfFileName = 'Recu_' . $payment['numero_recu'] . '.pdf';
                    file_put_contents($pdfDestDir . $pdfFileName, $pdfContent);
                    $pdfUrl = APP_URL . '/uploads/receipts/' . $pdfFileName;
                    
                    // D. Notifier le locataire par WhatsApp (F48) / SMS (F49)
                    $tenantMsg = "LokaGest : Votre paiement MoMo de " . number_format($payment['montant'], 0, ',', ' ') . " FCFA pour le mois " . date('m/Y', strtotime($payment['mois'] . '-01')) . " (Chambre " . $unit['code_unique'] . ") a ete valide.\n\n📄 Telecharger votre recu PDF (Preuve legale) : " . $pdfUrl;
                    
                    WhatsAppSender::send($tenant['telephone'], $tenantMsg, getCallMeBotKey());
                    
                    // E. Notifier le propriétaire (F40 - push/notification WhatsApp)
                    // Notification push WhatsApp : prénom + chambre + montant + mois
                    $ownerMsg = "LokaGest : Paiement recu de " . $tenant['prenom'] . " (Chambre " . $unit['code_unique'] . ") d'un montant de " . number_format($payment['montant'], 0, ',', ' ') . " FCFA pour le mois " . date('m/Y', strtotime($payment['mois'] . '-01')) . ". Le solde de votre portefeuille a ete mis a jour.";
                    WhatsAppSender::send($owner['telephone'], $ownerMsg);
                }
            } else {
                // Transaction échouée ou annulée : changer le statut du paiement en base à 'echoue'
                SupabaseClient::update('payments', ['statut' => 'echoue'], 'id=eq.' . $payment['id']);
            }
        }
    }
}

if ($isSimulation) {
    if ($success && $confirmedPayment) {
        header('Location: ' . APP_URL . '/tenant/payment-success.php?recu=' . urlencode($confirmedPayment['numero_recu']) . '&token=' . urlencode($token));
    } else {
        header('Location: ' . APP_URL . '/tenant/pay.php?token=' . urlencode($token) . '&error=cancelled');
    }
    exit;
} else {
    // Si c'est un appel webhook standard (FedaPay), on répond un code HTTP 200 standard à FedaPay
    http_response_code(200);
    echo json_encode(['status' => 'success']);
    exit;
}
?>
