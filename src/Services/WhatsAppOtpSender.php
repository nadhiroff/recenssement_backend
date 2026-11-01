<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Squelette pour l'API WhatsApp Business Cloud (ou un prestataire).
 * Activer avec OTP_CHANNEL = 'whatsapp' une fois le modèle de message approuvé.
 */
final class WhatsAppOtpSender implements OtpSenderInterface
{
    public function envoyer(string $destinataire, string $code): bool
    {
        if (WHATSAPP_API_URL === '' || WHATSAPP_API_TOKEN === '') {
            error_log('WhatsAppOtpSender : API non configurée');
            return false;
        }
        $charge = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => ltrim($destinataire, '+'),
            'type' => 'template',
            'template' => [
                'name' => WHATSAPP_TEMPLATE_OTP,
                'language' => ['code' => 'fr'],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]]],
            ],
        ]);
        $contexte = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . WHATSAPP_API_TOKEN . "\r\n",
            'content' => $charge,
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $reponse = @file_get_contents(WHATSAPP_API_URL, false, $contexte);
        return $reponse !== false && str_contains($http_response_header[0] ?? '', ' 200');
    }
}
