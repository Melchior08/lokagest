<?php
/**
 * LokaGest - API WhatsApp
 * 
 * Permet de déclencher l'envoi de messages WhatsApp à un locataire en AJAX.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/auth_guard.php';
require_once __DIR__ . '/../lib/whatsapp_sender.php';
require_once __DIR__ . '/../lib/app_helpers.php';

$user = $_SESSION['user'];
$userId = $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    
    $phone = trim($input['phone'] ?? '');
    $message = trim($input['message'] ?? '');
    
    if (empty($phone) || empty($message)) {
        echo json_encode(['success' => false, 'error' => 'Numéro et message obligatoires']);
        exit;
    }
    
    $sent = WhatsAppSender::send($phone, $message, getCallMeBotKey());
    
    if ($sent) {
        echo json_encode(['success' => true, 'message' => 'Message WhatsApp envoyé.']);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Impossible d\'envoyer le message pour le moment.'
        ]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
}
exit;
