Here is a clean, production-ready Laravel service class that implements the methods to fetch all users, call history by user (extension), and call history filtered by a specific target phone number.

### 1. Install official SDK

First, install the official RingCentral PHP SDK via Composer:

```bash
composer require ringcentral/ringcentral-php

```

### 2. Configure Environment Variables (`.env`)

Add your RingCentral credentials to your `.env` file:

```env
RINGCENTRAL_CLIENT_ID=VBQYokDRiqsdTa52Ccgrby
RINGCENTRAL_CLIENT_SECRET=your_client_secret_here
RINGCENTRAL_SERVER_URL=https://platform.ringcentral.com
RINGCENTRAL_JWT=your_jwt_token_here

```

---

### 3. Service Implementation (`app/Services/RingCentralService.php`)

```php
namespace App\Services;

use RingCentral\SDK\SDK;
use Exception;

class RingCentralService
{
    protected $sdk;
    protected $platform;

    public function __construct()
    {
        $this->sdk = new SDK(
            config('services.ringcentral.client_id'),
            config('services.ringcentral.client_secret'),
            config('services.ringcentral.server_url')
        );

        $this->platform = $this->sdk->platform();
        $this->authenticate();
    }

    /**
     * Authenticate using JWT
     */
    protected function authenticate(): void
    {
        if (!$this->platform->loggedIn()) {
            $this->platform->login([
                'jwt' => config('services.ringcentral.jwt')
            ]);
        }
    }

    /**
     * Get all users / extensions
     */
    public function getAllUsers(int $perPage = 100): array
    {
        $response = $this->platform->get('/account/~/extension', [
            'type' => 'User',
            'status' => 'Enabled',
            'perPage' => $perPage
        ]);

        return $response->json()->records;
    }

    /**
     * Get call history for a specific user (extension)
     */
    public function getCallHistoryByUser(string $extensionId = '~', array $filters = []): array
    {
        $params = array_merge([
            'view' => 'Simple',
            'perPage' => 100
        ], $filters);

        $response = $this->platform->get("/account/~/extension/{$extensionId}/call-log", $params);

        return $response->json()->records;
    }

    /**
     * Get call history filtered by a target phone number ("To" / Callee)
     * Note: Phone numbers should be in E.164 format without '+' (e.g., 14155552671)
     */
    public function getCallHistoryToNumber(string $phoneNumber, string $extensionId = '~'): array
    {
        // Sanitize phone number to remove '+' if present
        $cleanNumber = ltrim($phoneNumber, '+');

        $response = $this->platform->get("/account/~/extension/{$extensionId}/call-log", [
            'phoneNumber' => $cleanNumber,
            'direction' => 'Outbound', // Calls sent 'To' this number
            'view' => 'Simple',
            'perPage' => 100
        ]);

        return $response->json()->records;
    }
}

```

---

### 4. Register Configuration (`config/services.php`)

Register the keys in `config/services.php`:

```php
'ringcentral' => [
    'client_id'     => env('RINGCENTRAL_CLIENT_ID'),
    'client_secret' => env('RINGCENTRAL_CLIENT_SECRET'),
    'server_url'    => env('RINGCENTRAL_SERVER_URL', 'https://platform.ringcentral.com'),
    'jwt'           => env('RINGCENTRAL_JWT'),
],

```

---

### 5. Controller Usage Example (`app/Http/Controllers/CallLogController.php`)

```php
namespace App\Http\Controllers;

use App\Services\RingCentralService;
use Illuminate\Http\JsonResponse;

class CallLogController extends Controller
{
    protected $ringCentral;

    public function __construct(RingCentralService $ringCentral)
    {
        $this->ringCentral = $ringCentral;
    }

    // 1. Get all users
    public function users(): JsonResponse
    {
        $users = $this->ringCentral->getAllUsers();
        return response()->json($users);
    }

    // 2. Get call history by user / extension
    public function userCalls(string $extensionId): JsonResponse
    {
        $calls = $this->ringCentral->getCallHistoryByUser($extensionId);
        return response()->json($calls);
    }

    // 3. Get call history by "To" phone number
    public function callsToNumber(string $phone): JsonResponse
    {
        $calls = $this->ringCentral->getCallHistoryToNumber($phone);
        return response()->json($calls);
    }
}

```