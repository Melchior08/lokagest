<?php
/**
 * LokaGest - API Portefeuille (Wallet)
 * 
 * Permet de récupérer le solde et les statistiques financières de l'utilisateur connecté.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/auth_guard.php';
require_once __DIR__ . '/../lib/supabase.php';

$user = $_SESSION['user'];
$userId = $user['id'];

$response = SupabaseClient::select('wallets', '*', 'user_id=eq.' . $userId);

if ($response['status'] === 200 && !empty($response['data'])) {
    echo json_encode([
        'success' => true,
        'data' => $response['data'][0]
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Portefeuille introuvable'
    ]);
}
exit;
