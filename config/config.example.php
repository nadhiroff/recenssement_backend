<?php
// Copier vers config/config.php puis adapter. Ne jamais versionner config.php.
define('APP_NAME', 'Recensement ZOCLI');
define('APP_ENV', getenv('ZOCLI_TEST_DB') ? 'test' : 'dev');   // 'dev' | 'prod' ('test' : lanceur de tests uniquement)
define('APP_URL', 'http://localhost/recenssement_famille-Backend');
define('PWA_URL', APP_URL . '/app');

define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', getenv('ZOCLI_TEST_DB') ?: 'recensement_zocli');
define('DB_USER', 'CHANGER');
define('DB_PASS', 'CHANGER');
define('DB_CHARSET', 'utf8mb4');

define('MAIL_DRIVER', getenv('ZOCLI_TEST_DB') ? 'log' : 'smtp');   // 'smtp' | 'log' (aucun envoi, écrit dans storage/logs/mail.log)
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_ENCRYPTION', 'tls');
define('MAIL_USERNAME', 'CHANGER');
define('MAIL_PASSWORD', 'CHANGER');
define('MAIL_FROM_ADDRESS', 'CHANGER');
define('MAIL_FROM_NAME', APP_NAME);

define('PUSH_DRIVER', getenv('ZOCLI_TEST_DB') ? 'log' : 'expo');   // 'expo' | 'log' (aucun envoi)

// Connexion admin : mot de passe puis code par email. Sans code en développement seulement.
define('ADMIN_OTP_REQUIS', APP_ENV !== 'dev');
define('OTP_CHANNEL', 'email');          // 'email' | 'whatsapp'
define('OTP_TTL_SECONDS', 600);
define('OTP_MAX_ATTEMPTS', 5);
define('TOKEN_TTL_DAYS', 30);
define('INVITATION_TTL_DAYS', 30);
define('CONSENTEMENT_VERSION', 'v1-2026-11');

// Limites de débit : [nombre de requêtes, fenêtre en secondes]
define('RATE_OTP_TELEPHONE', [5, 900]);
define('RATE_OTP_IP', [30, 900]);
define('RATE_LOGIN_ADMIN', [8, 900]);
define('RATE_RECHERCHE', [120, 300]);
define('RATE_CONFIRMATION_IP', [30, 900]);

define('CORS_ALLOWED_ORIGINS', ['http://localhost:8081', 'http://localhost:19006']);

// WhatsApp (squelette, voir WhatsAppOtpSender)
define('WHATSAPP_API_URL', '');
define('WHATSAPP_API_TOKEN', '');
define('WHATSAPP_TEMPLATE_OTP', '');
