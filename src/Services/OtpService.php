<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;

final class OtpService
{
    public static function canal(): string
    {
        return OTP_CHANNEL === 'whatsapp' ? 'whatsapp' : 'email';
    }

    public static function emetteur(string $canal): OtpSenderInterface
    {
        return $canal === 'whatsapp' ? new WhatsAppOtpSender() : new EmailOtpSender();
    }

    /** Crée et envoie un code ; le précédent code non utilisé de cet identifiant devient caduc. */
    public static function emettre(string $identifiant, string $destinataire, string $canal, ?string $emailDestination = null): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        // Les adresses *.test ne reçoivent aucun email : en développement, leur code est fixe.
        if (APP_ENV === 'dev' && OTP_CODE_TEST !== '' && str_ends_with($destinataire, '.test')) {
            $code = OTP_CODE_TEST;
        }
        Database::run('UPDATE otp_codes SET utilise_le = UTC_TIMESTAMP() WHERE identifiant = ? AND utilise_le IS NULL', [$identifiant]);
        Database::insert('otp_codes', [
            'identifiant' => $identifiant,
            'email_destination' => $emailDestination,
            'code_hash' => password_hash($code, PASSWORD_BCRYPT, ['cost' => 8]),
            'canal' => $canal,
            'expire_le' => gmdate('Y-m-d H:i:s', time() + OTP_TTL_SECONDS),
        ]);
        if (APP_ENV !== 'prod') {
            $masque = substr(hash('sha256', $identifiant), 0, 12);
            file_put_contents(BASE_PATH . '/storage/logs/otp.log', gmdate('c') . " $masque $code\n", FILE_APPEND);
        }
        if (!self::emetteur($canal)->envoyer($destinataire, $code)) {
            throw new ApiException(503, 'envoi_impossible', 'Le code n\'a pas pu être envoyé. Réessayez dans un instant.');
        }
    }

    /** @return array la ligne otp validée */
    public static function verifier(string $identifiant, string $code): array
    {
        $otp = Database::one(
            'SELECT * FROM otp_codes WHERE identifiant = ? AND utilise_le IS NULL AND expire_le > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1',
            [$identifiant]
        );
        if ($otp === null) {
            throw new ApiException(401, 'code_invalide', 'Code incorrect ou expiré.');
        }
        if ((int) $otp['tentatives'] >= OTP_MAX_ATTEMPTS) {
            Database::run('UPDATE otp_codes SET utilise_le = UTC_TIMESTAMP() WHERE id = ?', [$otp['id']]);
            throw new ApiException(429, 'trop_de_tentatives', 'Trop de tentatives : demandez un nouveau code.');
        }
        if (!password_verify($code, $otp['code_hash'])) {
            Database::run('UPDATE otp_codes SET tentatives = tentatives + 1 WHERE id = ?', [$otp['id']]);
            throw new ApiException(401, 'code_invalide', 'Code incorrect ou expiré.');
        }
        Database::run('UPDATE otp_codes SET utilise_le = UTC_TIMESTAMP() WHERE id = ?', [$otp['id']]);
        return $otp;
    }
}
