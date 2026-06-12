<?php
/**
 * Test WhatsApp — réservé à l'administration (pas visible aux propriétaires)
 */

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['admin_logged'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès réservé à l\'administration']);
    exit;
}

require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/whatsapp_sender.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST uniquement']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$phone = trim($input['phone'] ?? '');
$message = trim($input['message'] ?? 'Test LokaGest — WhatsApp OK');

if (empty($phone)) {
    echo json_encode(['success' => false, 'error' => 'Numéro manquant (format 229XXXXXXXX)']);
    exit;
}

if (!isWhatsAppConfigured()) {
    echo json_encode(['success' => false, 'error' => 'Aucun fournisseur WhatsApp configuré côté serveur.']);
    exit;
}

$sent = WhatsAppSender::send($phone, $message, getCallMeBotKey());
echo json_encode([
    'success' => $sent,
    'message' => $sent ? 'Message envoyé.' : 'Échec — voir les logs PHP.'
]);
