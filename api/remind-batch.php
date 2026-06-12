<?php
/**
 * Relance WhatsApp groupée des locataires en retard (depuis le dashboard)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/app_helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non connecté']);
    exit;
}

$userId = $_SESSION['user']['id'];
$moisEnCours = date('Y-m');
$nomMois = date('m/Y');
$sent = 0;
$errors = [];

$propRes = SupabaseClient::select('properties', 'id', 'user_id=eq.' . $userId);
if ($propRes['status'] !== 200 || empty($propRes['data'])) {
    echo json_encode(['success' => true, 'sent' => 0, 'message' => 'Aucune propriété']);
    exit;
}

$propIds = array_column($propRes['data'], 'id');
$unitsRes = SupabaseClient::select('units', '*', 'property_id=in.(' . implode(',', $propIds) . ')');
if ($unitsRes['status'] !== 200 || empty($unitsRes['data'])) {
    echo json_encode(['success' => true, 'sent' => 0]);
    exit;
}

$units = [];
foreach ($unitsRes['data'] as $u) {
    $units[$u['id']] = $u;
}

$unitIds = implode(',', array_keys($units));
$tenantsRes = SupabaseClient::select('tenants', '*', 'unit_id=in.(' . $unitIds . ')&statut=eq.actif');
if ($tenantsRes['status'] !== 200 || empty($tenantsRes['data'])) {
    echo json_encode(['success' => true, 'sent' => 0]);
    exit;
}

$jour = (int) date('d');
if ($jour <= 5) {
    echo json_encode(['success' => false, 'error' => 'Les relances groupées sont disponibles après le 5 du mois.']);
    exit;
}

foreach ($tenantsRes['data'] as $tenant) {
    $unit = $units[$tenant['unit_id']] ?? null;
    if (!$unit) {
        continue;
    }

    $payCheck = SupabaseClient::select(
        'payments',
        'id',
        'unit_id=eq.' . $unit['id'] . '&mois=eq.' . $moisEnCours . '&statut=eq.confirme'
    );
    if ($payCheck['status'] === 200 && !empty($payCheck['data'])) {
        continue;
    }

    $link = getActivePaymentLink($unit['id'], $tenant['id']);
    if (!$link) {
        $errors[] = $tenant['prenom'];
        continue;
    }

    $payUrl = APP_URL . '/tenant/pay.php?token=' . $link['token'];
    if (sendRentReminder($tenant, $unit, $payUrl, $_SESSION['user']['telephone'] ?? null)) {
        $sent++;
    }
}

$configured = isWhatsAppConfigured();
echo json_encode([
    'success' => true,
    'sent' => $sent,
    'simulated' => !$configured,
    'message' => $configured
        ? "$sent relance(s) envoyée(s) pour $nomMois."
        : "Service de notification temporairement indisponible. Réessayez plus tard."
]);
