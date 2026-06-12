<?php
/**
 * LokaGest - Envoi de SMS de secours (SMS Fallback)
 * 
 * Ce fichier gère l'envoi de SMS de secours aux locataires en cas d'indisponibilité
 * ou d'échec de remise des notifications par WhatsApp.
 */

class SMSSender {

    /**
     * Envoie un SMS de secours
     * 
     * @param string $phone Numéro de téléphone récepteur (format international, ex: 229XXXXXXXX)
     * @param string $message Le contenu du message
     * @return bool Vrai si l'envoi a réussi (ou simulation enregistrée)
     */
    public static function send(string $phone, string $message): bool {
        // Formater le numéro
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) === 8) {
            $phone = '229' . $phone;
        }

        // Récupérer des clés API de secours (ex: Twilio ou autre fournisseur SMS)
        $twilioSid = getenv('TWILIO_ACCOUNT_SID');
        $twilioAuthToken = getenv('TWILIO_AUTH_TOKEN');
        $twilioNumber = getenv('TWILIO_NUMBER');

        if (!empty($twilioSid) && !empty($twilioAuthToken) && !empty($twilioNumber)) {
            // Exemple d'intégration Twilio SMS standard
            $url = "https://api.twilio.com/2010-04-01/Accounts/$twilioSid/Messages.json";
            $data = [
                'From' => $twilioNumber,
                'To' => '+' . $phone,
                'Body' => $message
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, "$twilioSid:$twilioAuthToken");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 201 || $httpCode === 200) {
                return true;
            }
            
            error_log("[LokaGest SMS Error] Échec de l'envoi Twilio à +$phone (HTTP $httpCode) : $response");
            return false;
        } else {
            // Fallback Simulation locale en local ou si pas d'API configurée
            // Évite de planter l'application et enregistre dans le journal d'erreur PHP du serveur
            error_log("[LokaGest SMS Mock] SMS envoyé à +$phone : $message");
            return true;
        }
    }
}
