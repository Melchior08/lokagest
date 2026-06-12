<?php
require_once __DIR__ . '/../config/notifications.php';

/**
 * LokaGest - Envoi WhatsApp multi-fournisseurs
 *
 * Fournisseurs supportés (par ordre de tentative) :
 * 1. Green API   — recommandé, fiable (green-api.com)
 * 2. UltraMsg    — alternative simple (ultramsg.com)
 * 3. CallMeBot   — gratuit mais limité (callmebot.com)
 */

class WhatsAppSender {

    /**
     * Envoie un message WhatsApp en essayant chaque fournisseur configuré.
     */
    public static function send(string $phone, string $message, ?string $apiKey = null): bool {
        $phone = self::formatPhone($phone);
        if ($phone === '') {
            return false;
        }

        $providers = self::getActiveProviders($apiKey);
        if (empty($providers)) {
            error_log("[LokaGest WhatsApp] Aucun fournisseur configuré. Message pour +$phone non envoyé.");
            error_log("[LokaGest WhatsApp Mock] $message");
            return false;
        }

        foreach ($providers as $provider) {
            $ok = match ($provider['type']) {
                'greenapi'  => self::sendViaGreenApi($phone, $message, $provider),
                'ultramsg'  => self::sendViaUltraMsg($phone, $message, $provider),
                'callmebot' => self::sendViaCallMeBot($phone, $message, $provider['api_key']),
                default     => false,
            };

            if ($ok) {
                error_log("[LokaGest WhatsApp] Envoyé via {$provider['type']} → +$phone");
                return true;
            }
        }

        error_log("[LokaGest WhatsApp] Échec tous fournisseurs pour +$phone");
        return false;
    }

    private static function formatPhone(string $phone): string {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) === 8) {
            return '229' . $phone;
        }
        if (strlen($phone) === 10 && strpos($phone, '229') !== 0) {
            return '229' . substr($phone, -8);
        }
        return $phone;
    }

    /** @return list<array{type: string, ...}> */
    private static function getActiveProviders(?string $callmebotKey): array {
        $list = [];

        $greenId = self::envOrConst('GREEN_API_INSTANCE_ID', GREEN_API_INSTANCE_ID);
        $greenToken = self::envOrConst('GREEN_API_TOKEN', GREEN_API_TOKEN);
        if ($greenId && $greenToken) {
            $list[] = ['type' => 'greenapi', 'instance_id' => $greenId, 'token' => $greenToken];
        }

        $ultraInstance = self::envOrConst('ULTRAMSG_INSTANCE_ID', ULTRAMSG_INSTANCE_ID);
        $ultraToken = self::envOrConst('ULTRAMSG_TOKEN', ULTRAMSG_TOKEN);
        if ($ultraInstance && $ultraToken) {
            $list[] = ['type' => 'ultramsg', 'instance_id' => $ultraInstance, 'token' => $ultraToken];
        }

        $cmbKey = $callmebotKey ?: self::envOrConst('CALLMEBOT_API_KEY', CALLMEBOT_API_KEY_DEFAULT);
        if ($cmbKey) {
            $list[] = ['type' => 'callmebot', 'api_key' => $cmbKey];
        }

        return $list;
    }

    /** Clés lues uniquement côté serveur (env / config), jamais depuis l'interface propriétaire */
    private static function envOrConst(string $envKey, ?string $constant = null): ?string {
        $env = getenv($envKey);
        if ($env !== false && $env !== '') {
            return $env;
        }
        if ($constant !== null && $constant !== '') {
            return $constant;
        }
        return null;
    }

    private static function curlRequest(string $url, string $method = 'GET', ?array $jsonBody = null, array $headers = []): array {
        $ch = curl_init($url);
        $hdrs = array_merge(['Content-Type: application/json'], $headers);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
        }
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $response];
    }

    /** Green API — https://green-api.com */
    private static function sendViaGreenApi(string $phone, string $message, array $cfg): bool {
        $id = $cfg['instance_id'];
        $token = $cfg['token'];
        $url = "https://api.green-api.com/waInstance{$id}/sendMessage/{$token}";

        $res = self::curlRequest($url, 'POST', [
            'chatId'  => $phone . '@c.us',
            'message' => $message,
        ]);

        if ($res['code'] === 200) {
            $data = json_decode($res['body'], true);
            return isset($data['idMessage']);
        }
        error_log("[Green API] HTTP {$res['code']} : {$res['body']}");
        return false;
    }

    /** UltraMsg — https://ultramsg.com */
    private static function sendViaUltraMsg(string $phone, string $message, array $cfg): bool {
        $instance = $cfg['instance_id'];
        $token = $cfg['token'];
        $url = rtrim(ULTRAMSG_API_URL, '/') . '/messages/chat';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'token' => $token,
                'to'    => '+' . $phone,
                'body'  => $message,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200) {
            $data = json_decode($response, true);
            if (($data['sent'] ?? '') === 'true' || ($data['sent'] ?? false) === true) {
                return true;
            }
            if (isset($data['id'])) {
                return true;
            }
        }
        error_log("[UltraMsg] HTTP $code : $response");
        return false;
    }

    /** CallMeBot — gratuit */
    private static function sendViaCallMeBot(string $phone, string $message, string $apiKey): bool {
        $url = 'https://api.callmebot.com/whatsapp.php?phone=' . urlencode($phone)
            . '&text=' . urlencode($message)
            . '&apikey=' . urlencode($apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && stripos((string) $response, 'error') === false) {
            return true;
        }
        error_log("[CallMeBot] HTTP $code : $response");
        return false;
    }

    /** Indique si au moins un fournisseur WhatsApp est configuré */
    public static function isConfigured(): bool {
        return !empty(self::getActiveProviders(null));
    }
}
