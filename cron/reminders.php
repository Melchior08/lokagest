<?php
/**
 * LokaGest - Rappels automatiques de loyer (Semaine 7)
 *
 * À planifier chaque jour à 8h via le Planificateur de tâches Windows ou cron Linux :
 * php c:\wamp64\www\LokaGest\cron\reminders.php
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/whatsapp_sender.php';
require_once __DIR__ . '/../lib/sms_sender.php';

$jour = (int) date('d');
if ($jour < 3) {
    echo "Rappels désactivés avant le 3 du mois.\n";
    exit(0);
}

$moisEnCours = date('Y-m');
$sent = 0;
$skipped = 0;

$tenantsRes = SupabaseClient::select('tenants', '*', 'statut=eq.actif');
if ($tenantsRes['status'] !== 200 || empty($tenantsRes['data'])) {
    echo "Aucun locataire actif.\n";
    exit(0);
}

foreach ($tenantsRes['data'] as $tenant) {
    $unitRes = SupabaseClient::select('units', '*', 'id=eq.' . $tenant['unit_id']);
    if ($unitRes['status'] !== 200 || empty($unitRes['data'])) {
        continue;
    }
    $unit = $unitRes['data'][0];
    if ($unit['statut'] !== 'occupee') {
        continue;
    }

    $payCheck = SupabaseClient::select(
        'payments',
        'id',
        'unit_id=eq.' . $unit['id'] . '&mois=eq.' . $moisEnCours . '&statut=eq.confirme'
    );
    if ($payCheck['status'] === 200 && !empty($payCheck['data'])) {
        $skipped++;
        continue;
    }

    $link = getActivePaymentLink($unit['id'], $tenant['id']);
    if (!$link) {
        $skipped++;
        continue;
    }

    $payUrl = APP_URL . '/tenant/pay.php?token=' . $link['token'];

    if (sendRentReminder($tenant, $unit, $payUrl)) {
        $sent++;
        echo "Rappel envoyé : " . $tenant['prenom'] . " (" . $unit['code_unique'] . ")\n";
    }
}

echo "Terminé — $sent rappel(s) envoyé(s), $skipped ignoré(s).\n";
