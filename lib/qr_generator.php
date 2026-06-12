<?php
/**
 * LokaGest - Générateur de QR Code (phpqrcode)
 * 
 * Fournit des méthodes pour générer les QR Codes uniques associés aux liens
 * de paiement des locataires. Supporte un fallback vers Google Chart API en cas d'absence
 * de la bibliothèque locale.
 */

class QRGenerator {

    /**
     * Initialise et inclut la bibliothèque phpqrcode de façon sécurisée
     */
    private static function initQRCode() {
        $qrDir = __DIR__ . '/phpqrcode';
        $qrFile = $qrDir . '/qrlib.php';
        
        if (!class_exists('QRcode')) {
            if (file_exists($qrFile)) {
                require_once $qrFile;
            } else {
                // Tenter de télécharger la version autonome légère si connectée au net
                if (!is_dir($qrDir)) {
                    mkdir($qrDir, 0755, true);
                }
                
                // URL stable du fichier simple qrlib.php
                $url = 'https://raw.githubusercontent.com/tomek-o/phpqrcode/master/qrlib.php';
                
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                $content = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode === 200 && !empty($content)) {
                    file_put_contents($qrFile, $content);
                    require_once $qrFile;
                } else {
                    // Fallback transparent : on utilisera Google Charts API
                    // Nous n'incluons rien, la méthode de génération basculera sur le mode API
                }
            }
        }
    }

    /**
     * Génère un QR code pour un texte donné
     * 
     * @param string $text Le contenu à encoder (ex: URL de paiement)
     * @param string|null $outputPath Chemin local optionnel pour sauvegarder l'image
     * @return string L'image sous forme de données Base64 (prête pour balise <img src="...">)
     */
    public static function generate(string $text, ?string $outputPath = null): string {
        self::initQRCode();
        
        // Si phpqrcode est disponible localement
        if (class_exists('QRcode')) {
            // Activer la capture de sortie pour phpqrcode qui écrit directement ou dans un fichier
            if ($outputPath) {
                QRcode::png($text, $outputPath, QR_ECLEVEL_L, 6, 2);
                $binData = file_get_contents($outputPath);
                return 'data:image/png;base64,' . base64_encode($binData);
            } else {
                // Capturer le flux binaire PNG généré
                ob_start();
                QRcode::png($text, null, QR_ECLEVEL_L, 6, 2);
                $binData = ob_get_clean();
                return 'data:image/png;base64,' . base64_encode($binData);
            }
        } else {
            // Fallback : Utilisation de l'API sécurisée et stable de Google Charts QR Code
            $apiUrl = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($text);
            
            // Récupérer le binaire de l'image
            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $binData = curl_exec($ch);
            curl_close($ch);
            
            if ($binData) {
                if ($outputPath) {
                    file_put_contents($outputPath, $binData);
                }
                return 'data:image/png;base64,' . base64_encode($binData);
            }
            
            // Si même l'appel curl échoue (hors ligne total), on renvoie une image SVG minimale blanche
            return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="150" height="150"><rect width="150" height="150" fill="white"/><text x="10" y="80" font-family="sans-serif" font-size="10" fill="red">QR Code Indisponible (Offline)</text></svg>');
        }
    }
}
