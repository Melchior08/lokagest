<?php
/**
 * LokaGest - Helpers applicatifs (wallet, notifications)
 */

require_once __DIR__ . '/supabase.php';

function getCallMeBotKey(): ?string {
    require_once __DIR__ . '/../config/notifications.php';
    $env = getenv('CALLMEBOT_API_KEY');
    if ($env !== false && $env !== '') {
        return $env;
    }
    return CALLMEBOT_API_KEY_DEFAULT ?: null;
}

/**
 * Crédite le portefeuille du propriétaire après un paiement confirmé
 */
function creditOwnerWallet($ownerUserId, float $amount): bool {
    if ($amount <= 0) {
        return false;
    }
    $walletRes = SupabaseClient::select('wallets', '*', 'user_id=eq.' . $ownerUserId);
    if ($walletRes['status'] !== 200 || empty($walletRes['data'])) {
        return false;
    }
    $wallet = $walletRes['data'][0];
    $update = SupabaseClient::update(
        'wallets',
        [
            'solde' => floatval($wallet['solde']) + $amount,
            'total_entre' => floatval($wallet['total_entre']) + $amount
        ],
        'id=eq.' . $wallet['id']
    );
    return $update['status'] >= 200 && $update['status'] < 300;
}

/**
 * Récupère le lien de paiement actif d'un locataire
 */
function getActivePaymentLink(string $unitId, string $tenantId): ?array {
    $res = SupabaseClient::select(
        'payment_links',
        '*',
        'unit_id=eq.' . $unitId . '&tenant_id=eq.' . $tenantId . '&statut=eq.actif&order=date_creation.desc&limit=1'
    );
    if ($res['status'] === 200 && !empty($res['data'])) {
        return $res['data'][0];
    }
    return null;
}

/**
 * Envoie un rappel de loyer par WhatsApp (multi-fournisseurs)
 */
function sendRentReminder(array $tenant, array $unit, string $payUrl, ?string $ownerPhone = null): bool {
    require_once __DIR__ . '/whatsapp_sender.php';

    $mois = date('m/Y');
    $msg = "LokaGest : Bonjour " . $tenant['prenom'] . ", votre loyer de " . $mois
        . " pour la chambre " . $unit['code_unique'] . " est en attente.\n\n"
        . "Payez en 1 clic MoMo : " . $payUrl;

    return WhatsAppSender::send($tenant['telephone'], $msg, getCallMeBotKey());
}

function isWhatsAppConfigured(): bool {
    require_once __DIR__ . '/whatsapp_sender.php';
    return WhatsAppSender::isConfigured();
}
