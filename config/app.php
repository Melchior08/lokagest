<?php
/**
 * LokaGest - Configuration Générale
 */

// Fuseau horaire du Bénin
date_default_timezone_set('Africa/Porto-Novo');

// Informations de l'application
define('APP_NAME', 'LokaGest');
define('APP_VERSION', '1.0.0');

// URL de base de l'application
if (getenv('APP_URL')) {
    define('APP_URL', rtrim(getenv('APP_URL'), '/'));
} else {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
    $appRoot = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: '');
    $basePath = '';
    if ($docRoot !== '' && $appRoot !== '' && strpos($appRoot, $docRoot) === 0) {
        $basePath = substr($appRoot, strlen($docRoot));
    }
    define('APP_URL', rtrim($protocol . '://' . $host . $basePath, '/'));
}

// Redirections d'authentification
define('AUTH_REDIRECT_URI', APP_URL . '/auth/callback.php');

// Durée de vie de la session (7 jours en secondes)
define('SESSION_LIFETIME', 604800);

// Configuration de sécurité des sessions
$isLocalhost = ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1' || strpos($_SERVER['HTTP_HOST'], 'localhost:') === 0);

if (!$isLocalhost) {
    ini_set('session.cookie_lifetime', SESSION_LIFETIME);
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_httponly', 1);
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
    ini_set('session.cookie_samesite', 'Lax');
}

// Mode Debug
define('APP_DEBUG', getenv('APP_ENV') !== 'production');

if (APP_DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// Dossier de session — fallback vers /tmp sur Render (filesystem éphémère)
$sessionPath = __DIR__ . '/../sessions';
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0755, true);
}
if (is_writable($sessionPath)) {
    session_save_path($sessionPath);
} else {
    session_save_path(sys_get_temp_dir());
}

// Démarrer la session globale
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
