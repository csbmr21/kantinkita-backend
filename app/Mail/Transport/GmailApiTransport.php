<?php

namespace App\Mail\Transport;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GmailApiTransport extends AbstractTransport
{
    protected $clientId;
    protected $clientSecret;
    protected $refreshToken;
    protected $senderEmail;

    public function __construct($clientId, $clientSecret, $refreshToken, $senderEmail)
    {
        parent::__construct();
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->refreshToken = $refreshToken;
        $this->senderEmail = $senderEmail;
    }

    protected function doSend(SentMessage $message): void
    {
        // Obtain a fresh access token using the refresh token
        $accessToken = $this->getAccessToken();

        if (!$accessToken) {
            throw new \Exception('Failed to obtain Gmail API access token. Verify GOOGLE_REFRESH_TOKEN.');
        }

        // Get the complete raw MIME message (RFC 2822)
        $rawMessage = $message->toString();
        
        // Base64URL encode the message as required by the Gmail API
        $safeRawMessage = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

        // Send using the Gmail API messages.send endpoint
        $response = Http::withToken($accessToken)
            ->post('https://gmail.googleapis.com/v1/users/me/messages/send', [
                'raw' => $safeRawMessage
            ]);

        if ($response->failed()) {
            Log::error('Gmail API send failed: ' . $response->body());
            throw new \Exception('Gmail API sending failed: ' . ($response->json('error.message') ?? $response->body()));
        }
    }

    protected function getAccessToken(): ?string
    {
        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->refreshToken)) {
            Log::error('Gmail API Credentials missing. Check GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, and GOOGLE_REFRESH_TOKEN.');
            return null;
        }

        $response = Http::post('https://oauth2.googleapis.com/token', [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        if ($response->failed()) {
            Log::error('Gmail API token refresh failed: ' . $response->body());
            return null;
        }

        return $response->json('access_token');
    }

    public function __toString(): string
    {
        return 'gmail-api';
    }
}
