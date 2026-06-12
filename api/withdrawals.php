<?php
/**
 * LokaGest - API Retraits (Withdrawals)
 * 
 * Permet de lister les demandes de retrait de l'utilisateur en AJAX.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/auth_guard.php';
require_once __DIR__ . '/../lib/supabase.php';

$user = $_SESSION['user'];
$userId = $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // 1. Trouver le wallet de l'utilisateur
    $walletRes = SupabaseClient::select('wallets', 'id', 'user_id=eq.' . $userId);
    
    if ($walletRes['status'] === 200 && !empty($walletRes['data'])) {
        $walletId = $walletRes['data'][0]['id'];
        
        // 2. Lister les retraits associés
        $response = SupabaseClient::select('withdrawals', '*', 'wallet_id=eq.' . $walletId . '&order=date_retrait.desc');
        
        echo json_encode([
            'success' => $response['status'] === 200,
            'data' => $response['data'] ?? [],
            'error' => $response['error']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Portefeuille introuvable']);
    }
}
exit;
