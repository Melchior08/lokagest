<?php
/**
 * LokaGest - Configuration notifications WhatsApp (UltraMsg, Green API, CallMeBot)
 * Surchargeable via variables d'environnement (Apache SetEnv ou .htaccess)
 */

define('ULTRAMSG_INSTANCE_ID', getenv('ULTRAMSG_INSTANCE_ID') ?: 'instance180435');
define('ULTRAMSG_TOKEN', getenv('ULTRAMSG_TOKEN') ?: '4a36454has03iv84');
define('ULTRAMSG_API_URL', getenv('ULTRAMSG_API_URL') ?: 'https://api.ultramsg.com/' . ULTRAMSG_INSTANCE_ID);

define('GREEN_API_INSTANCE_ID', getenv('GREEN_API_INSTANCE_ID') ?: '');
define('GREEN_API_TOKEN', getenv('GREEN_API_TOKEN') ?: '');

define('CALLMEBOT_API_KEY_DEFAULT', getenv('CALLMEBOT_API_KEY') ?: '');
