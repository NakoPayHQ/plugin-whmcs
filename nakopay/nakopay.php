<?php
/**
 * NakoPay\Client - API client + DB helpers + template loader.
 *
 * One small class to keep the surface area auditable. All HTTP calls go
 * through ::request(); all storage goes through Capsule.
 */

namespace NakoPay;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use Exception;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../includes/gatewayfunctions.php';

class Client
{
    const VERSION       = '0.2.0';
    const GATEWAY_NAME  = 'nakopay';
    const BASE_URL      = 'https://daslrxpkbkqrbnjwouiq.supabase.co/functions/v1/';
    const ORDERS_TABLE  = 'mod_nakopay_orders';
    const SIG_TOLERANCE = 300; // seconds

    /* ---------------------------------------------------------------- meta */

    public function getVersion(): string
    {
        return self::VERSION;
    }

    public function getLangFilePath(string $lang = 'english'): string
    {
        $lang = preg_replace('/[^a-z]/i', '', $lang) ?: 'english';
        $path = __DIR__ . '/lang/' . $lang . '.php';
        if (!file_exists($path)) {
            $path = __DIR__ . '/lang/english.php';
        }
        return $path;
    }

    public function getGatewayParams(): array
    {
        $params = getGatewayVariables(self::GATEWAY_NAME);
        if (empty($params['type'])) {
            throw new Exception('NakoPay gateway is not activated.');
        }
        return $params;
    }

    public function getApiKey(): string
    {
        $params = $this->getGatewayParams();
        $key = trim((string) ($params['ApiKey'] ?? ''));
        if ($key === '') {
            throw new Exception('NakoPay API key is not configured.');
        }
        return $key;
    }

    public function getWebhookSecret(): string
    {
        $params = $this->getGatewayParams();
        return trim((string) ($params['WebhookSecret'] ?? ''));
    }

    public function getConfirmations(): int
    {
        $params = $this->getGatewayParams();
        return max(0, (int) ($params['Confirmations'] ?? 1));
    }

    public function getWebhookUrl(): string
    {
        return rtrim(\App::getSystemURL(), '/') . '/modules/gateways/callback/nakopay.php';
    }

    /* ----------------------------------------------------------------- HTTP */

    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = self::BASE_URL . ltrim($path, '/');
        $ch  = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $this->getApiKey(),
            'Accept: application/json',
            'User-Agent: NakoPay-WHMCS/' . self::VERSION,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body !== null ? json_encode($body) : null,
        ]);
        $raw    = curl_exec($ch);
        $err    = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['_ok' => false, '_status' => 0, '_error' => $err ?: 'network error'];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['_ok' => false, '_status' => $status, '_error' => 'invalid json', '_raw' => $raw];
        }
        $decoded['_ok']     = $status >= 200 && $status < 300;
        $decoded['_status'] = $status;
        return $decoded;
    }

    public function createInvoice(array $args): array
    {
        return $this->request('POST', 'invoices-create', [
            'amount'         => (string) $args['amount'],
            'currency'       => strtoupper((string) ($args['currency'] ?? 'USD')),
            'coin'           => strtoupper((string) ($args['coin'] ?? 'BTC')),
            'description'    => (string) ($args['description'] ?? 'WHMCS invoice'),
            'customer_email' => (string) ($args['customer_email'] ?? ''),
            'metadata'       => array_filter([
                'whmcs_invoice_id' => $args['whmcs_invoice_id'] ?? null,
                'source'           => 'whmcs',
            ], fn($v) => $v !== null && $v !== ''),
        ]);
    }

    public function getInvoice(string $id): array
    {
        return $this->request('GET', 'invoices-get?id=' . rawurlencode($id));
    }

    /* ----------------------------------------------------------- webhook sig */

    public function verifyWebhook(string $rawBody, string $sigHeader, ?string $secretOverride = null): bool
    {
        $secret = $secretOverride ?? $this->getWebhookSecret();
        if ($secret === '' || $sigHeader === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $sigHeader) as $kv) {
            $kv = trim($kv);
            if ($kv === '' || strpos($kv, '=') === false) continue;
            [$k, $v] = explode('=', $kv, 2);
            $parts[trim($k)] = trim($v);
        }
        if (empty($parts['t']) || empty($parts['v1'])) {
            return false;
        }

        $t = (int) $parts['t'];
        if (abs(time() - $t) > self::SIG_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
        return hash_equals($expected, $parts['v1']);
    }

    /* ------------------------------------------------------------------ DB */

    public function createOrderTableIfNotExist(): void
    {
        try {
            if (Capsule::schema()->hasTable(self::ORDERS_TABLE)) {
                return;
            }
            Capsule::schema()->create(self::ORDERS_TABLE, function ($table) {
                $table->increments('id');
                $table->unsignedInteger('whmcs_invoice_id')->index();
                $table->string('nakopay_invoice_id', 64)->unique();
                $table->string('address', 128)->nullable();
                $table->string('coin', 16)->default('BTC');
                $table->string('currency', 8)->default('USD');
                $table->decimal('amount_fiat', 20, 8)->default(0);
                $table->decimal('amount_crypto', 20, 8)->default(0);
                $table->string('status', 32)->default('pending');
                $table->string('tx_hash', 128)->nullable();
                $table->text('checkout_url')->nullable();
                $table->text('bip21')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
            });
        } catch (Exception $e) {
            error_log('[NakoPay] could not create orders table: ' . $e->getMessage());
        }
    }

    public function saveOrder(array $row): void
    {
        $row['updated_at'] = date('Y-m-d H:i:s');
        Capsule::table(self::ORDERS_TABLE)->updateOrInsert(
            ['nakopay_invoice_id' => $row['nakopay_invoice_id']],
            $row
        );
    }

    public function getOrderByNakoId(string $id): ?array
    {
        $row = Capsule::table(self::ORDERS_TABLE)->where('nakopay_invoice_id', $id)->first();
        return $row ? (array) $row : null;
    }

    public function getOpenOrderForInvoice(int $whmcsInvoiceId): ?array
    {
        $row = Capsule::table(self::ORDERS_TABLE)
            ->where('whmcs_invoice_id', $whmcsInvoiceId)
            ->whereNotIn('status', ['paid', 'expired', 'cancelled'])
            ->orderBy('id', 'desc')
            ->first();
        return $row ? (array) $row : null;
    }

    public function updateStatus(string $id, string $status, ?string $txHash = null): void
    {
        $update = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
        if ($txHash !== null && $txHash !== '') {
            $update['tx_hash'] = $txHash;
        }
        Capsule::table(self::ORDERS_TABLE)->where('nakopay_invoice_id', $id)->update($update);
    }

    /* ------------------------------------------------------------- security */

    public function checkAdmin(): void
    {
        $cls = '\\WHMCS\\Authentication\\CurrentUser';
        if (class_exists($cls)) {
            $u = (new $cls)->admin();
            if (!$u) die('Admin only.');
            return;
        }
        if (!isset($_SESSION['adminid'])) die('Admin only.');
    }
}
