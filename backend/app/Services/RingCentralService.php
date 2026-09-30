<?php

namespace App\Services;

use Exception;
use RingCentral\SDK\SDK;

class RingCentralService
{
    protected ?SDK $sdk = null;

    protected $platform = null;

    public function __construct()
    {
        $clientId = config('services.ringcentral.client_id');
        $clientSecret = config('services.ringcentral.client_secret');
        $serverUrl = config('services.ringcentral.server_url', 'https://platform.ringcentral.com');

        if (! empty($clientId) && ! empty($clientSecret)) {
            $this->sdk = new SDK($clientId, $clientSecret, $serverUrl);
            $this->platform = $this->sdk->platform();
        }
    }

    /**
     * Authenticate using JWT
     *
     * @throws Exception
     */
    protected function authenticate(): void
    {
        if (! $this->platform) {
            throw new Exception('RingCentral non configuré (RINGCENTRAL_CLIENT_ID ou CLIENT_SECRET manquant).');
        }

        $jwt = config('services.ringcentral.jwt');
        if (empty($jwt)) {
            throw new Exception('RingCentral non authentifié (RINGCENTRAL_JWT manquant).');
        }

        if (! $this->platform->loggedIn()) {
            $this->platform->login([
                'jwt' => $jwt,
            ]);
        }
    }

    /**
     * Get all users / extensions
     *
     * @throws Exception
     */
    public function getAllUsers(int $perPage = 100): array
    {
        $this->authenticate();

        $response = $this->platform->get('/account/~/extension', [
            'type' => 'User',
            'status' => 'Enabled',
            'perPage' => $perPage,
        ]);

        return $response->json()->records ?? [];
    }

    /**
     * Get call history for a specific user (extension)
     *
     * @throws Exception
     */
    public function getCallHistoryByUser(string $extensionId = '~', array $filters = []): array
    {
        $this->authenticate();

        $params = array_merge([
            'view' => 'Simple',
            'perPage' => 100,
        ], $filters);

        $response = $this->platform->get("/account/~/extension/{$extensionId}/call-log", $params);

        return $response->json()->records ?? [];
    }

    /**
     * Get call history filtered by a target phone number ("To" / Callee)
     * Note: Phone numbers should be in E.164 format without '+' (e.g., 14155552671)
     *
     * @throws Exception
     */
    public function getCallHistoryToNumber(string $phoneNumber, string $extensionId = '~'): array
    {
        $this->authenticate();

        // Sanitize phone number to remove '+' if present
        $cleanNumber = ltrim($phoneNumber, '+');

        $response = $this->platform->get("/account/~/extension/{$extensionId}/call-log", [
            'phoneNumber' => $cleanNumber,
            'direction' => 'Outbound', // Calls sent 'To' this number
            'view' => 'Simple',
            'perPage' => 100,
        ]);

        return $response->json()->records ?? [];
    }
}
