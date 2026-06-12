<?php
/**
 * LokaGest - API Locataires
 * 
 * Permet d'interagir avec les locataires en AJAX (détails, clôture).
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/auth_guard.php';
require_once __DIR__ . '/../lib/supabase.php';

$user = $_SESSION['user'];
$userId = $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $tenantId = $_GET['id'] ?? '';
    if (empty($tenantId)) {
        echo json_encode(['success' => false, 'error' => 'Identifiant locataire requis']);
        exit;
    }
    
    // Récupérer le locataire
    $response = SupabaseClient::select('tenants', '*', 'id=eq.' . $tenantId);
    
    if ($response['status'] === 200 && !empty($response['data'])) {
        $tenant = $response['data'][0];
        
        // Valider l'accès propriétaire
        $unitRes = SupabaseClient::select('units', 'property_id', 'id=eq.' . $tenant['unit_id']);
        if ($unitRes['status'] === 200 && !empty($unitRes['data'])) {
            $unit = $unitRes['data'][0];
            $propCheck = SupabaseClient::select('properties', 'id', 'id=eq.' . $unit['property_id'] . '&user_id=eq.' . $userId);
            if ($propCheck['status'] !== 200 || empty($propCheck['data'])) {
                echo json_encode(['success' => false, 'error' => 'Accès interdit']);
                exit;
            }
        }
        
        echo json_encode([
            'success' => true,
            'data' => $tenant
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Locataire introuvable'
        ]);
    }
}
exit;
