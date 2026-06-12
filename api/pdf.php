<?php
/**
 * LokaGest - API Génération et Téléchargement de PDF
 * 
 * Permet de télécharger des reçus de paiement, baux de location,
 * documents de caution ou attestations de clôture en PDF.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/supabase.php';
require_once __DIR__ . '/../lib/pdf_generator.php';

$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? '';

if (empty($action) || empty($id)) {
    header('HTTP/1.1 400 Bad Request');
    echo "Paramètres manquants.";
    exit;
}

// Nettoyer la sortie
ob_clean();

// Action publique : Téléchargement de reçu
if ($action === 'receipt') {
    // Récupérer le paiement
    $payRes = SupabaseClient::select('payments', '*', 'id=eq.' . $id . '&statut=eq.confirme');
    if ($payRes['status'] !== 200 || empty($payRes['data'])) {
        // Tenter par le numéro de reçu SHA256 directement
        $payRes = SupabaseClient::select('payments', '*', 'numero_recu=eq.' . $id . '&statut=eq.confirme');
    }
    
    if ($payRes['status'] === 200 && !empty($payRes['data'])) {
        $payment = $payRes['data'][0];
        
        // Charger le locataire, la chambre et la propriété
        $tenantRes = SupabaseClient::select('tenants', '*', 'id=eq.' . $payment['tenant_id']);
        $tenant = ($tenantRes['status'] === 200 && !empty($tenantRes['data'])) ? $tenantRes['data'][0] : null;
        
        $unitRes = SupabaseClient::select('units', '*', 'id=eq.' . $payment['unit_id']);
        $unit = ($unitRes['status'] === 200 && !empty($unitRes['data'])) ? $unitRes['data'][0] : null;
        
        $property = null;
        if ($unit) {
            $propRes = SupabaseClient::select('properties', '*', 'id=eq.' . $unit['property_id']);
            if ($propRes['status'] === 200 && !empty($propRes['data'])) {
                $property = $propRes['data'][0];
            }
        }
        
        if ($tenant && $unit && $property) {
            $pdfBin = PDFGenerator::generateReceiptPDF($payment, $tenant, $unit, $property);
            
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="Recu_' . $payment['numero_recu'] . '.pdf"');
            echo $pdfBin;
            exit;
        }
    }
    
    header('HTTP/1.1 404 Not Found');
    echo "Reçu introuvable ou non confirmé.";
    exit;
}

// Actions sécurisées (Requiert d'être connecté en session propriétaire)
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('HTTP/1.1 403 Forbidden');
    echo "Accès refusé. Veuillez vous connecter.";
    exit;
}

$user = $_SESSION['user'];
$userId = $user['id'];

// Téléchargement de contrat de bail
if ($action === 'lease') {
    $leaseRes = SupabaseClient::select('leases', '*', 'id=eq.' . $id);
    if ($leaseRes['status'] === 200 && !empty($leaseRes['data'])) {
        $lease = $leaseRes['data'][0];
        
        // Valider l'accès propriétaire
        $unitRes = SupabaseClient::select('units', '*', 'id=eq.' . $lease['unit_id']);
        $unit = ($unitRes['status'] === 200 && !empty($unitRes['data'])) ? $unitRes['data'][0] : null;
        
        if ($unit) {
            $propCheck = SupabaseClient::select('properties', 'id, user_id', 'id=eq.' . $unit['property_id'] . '&user_id=eq.' . $userId);
            if ($propCheck['status'] === 200 && !empty($propCheck['data'])) {
                $property = $propCheck['data'][0];
                
                $tenantRes = SupabaseClient::select('tenants', '*', 'id=eq.' . $lease['tenant_id']);
                $tenant = ($tenantRes['status'] === 200 && !empty($tenantRes['data'])) ? $tenantRes['data'][0] : null;
                
                if ($tenant) {
                    // Recharger la vraie propriété complète pour les coordonnées
                    $propFull = SupabaseClient::select('properties', '*', 'id=eq.' . $unit['property_id']);
                    $property = $propFull['data'][0];
                    
                    $pdfBin = PDFGenerator::generateLeasePDF($tenant, $unit, $property, $lease['numero_bail']);
                    
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="Bail_' . $lease['numero_bail'] . '.pdf"');
                    echo $pdfBin;
                    exit;
                }
            }
        }
    }
}

// Téléchargement de quittance de caution
if ($action === 'deposit') {
    $tenantRes = SupabaseClient::select('tenants', '*', 'id=eq.' . $id);
    if ($tenantRes['status'] === 200 && !empty($tenantRes['data'])) {
        $tenant = $tenantRes['data'][0];
        
        $unitRes = SupabaseClient::select('units', '*', 'id=eq.' . $tenant['unit_id']);
        $unit = ($unitRes['status'] === 200 && !empty($unitRes['data'])) ? $unitRes['data'][0] : null;
        
        if ($unit) {
            $propCheck = SupabaseClient::select('properties', '*', 'id=eq.' . $unit['property_id'] . '&user_id=eq.' . $userId);
            if ($propCheck['status'] === 200 && !empty($propCheck['data'])) {
                $property = $propCheck['data'][0];
                
                $pdfBin = PDFGenerator::generateDepositReceiptPDF($tenant, $unit, $property, 'reception');
                
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="Quittance_Caution_' . $tenant['prenom'] . '.pdf"');
                echo $pdfBin;
                exit;
            }
        }
    }
}

header('HTTP/1.1 404 Not Found');
echo "Document non trouvé.";
exit;
