<?php
/**
 * LokaGest - API Génération de QR Code (image PNG)
 */

require_once __DIR__ . '/../lib/qr_generator.php';

$text = $_GET['text'] ?? $_GET['url'] ?? '';

if (empty($text)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Paramètre text ou url manquant.');
}

$base64 = QRGenerator::generate($text);

if (strpos($base64, 'data:image/png;base64,') === 0) {
    header('Content-Type: image/png');
    echo base64_decode(substr($base64, 22));
    exit;
}

if (strpos($base64, 'data:image/svg+xml;base64,') === 0) {
    header('Content-Type: image/svg+xml');
    echo base64_decode(substr($base64, 26));
    exit;
}

header('HTTP/1.1 500 Internal Server Error');
exit('QR indisponible');
