<?php

namespace App\Billing\Bank;

use App\Support\Surface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sber API (СберБизнес) без SDK: OAuth через СберБизнес ID, все запросы — с сертификатом клиента (mTLS).
 * access живёт час, refresh — 180 дней и меняется при каждом обновлении (старый ещё два часа годен),
 * поэтому обновление — под замком, и новый refresh тут же пишется в базу.
 * Выписка: за прошлый день — `statement/transactions`, за сегодня — `statement/increment` с утра до сейчас;
 * 202 значит «выписка готовится» — повторим следующим проходом.
 */
final class SberApi
{
    public function configured(): bool
    {
        return filled(config('xcar.sber.client_id')) && filled(config('xcar.sber.cert')) && filled($this->secret());
    }

    public function redirectUri(): string
    {
        return Surface::Crm->url('/settings/bank/callback');
    }

    /** Куда отправить директора за согласием; state запоминается в подключении и сверяется при возврате. */
    public function authorizeUrl(Connection $connection): string
    {
        $state = Str::random(40);
        $connection->update(['state' => $state]);

        return config('xcar.sber.auth_url').'?'.http_build_query([
            'response_type' => 'code', 'client_id' => config('xcar.sber.client_id'), 'redirect_uri' => $this->redirectUri(),
            'scope' => config('xcar.sber.scope'), 'state' => $state, 'nonce' => Str::random(24),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(Connection $connection, string $code): void
    {
        $this->store($connection, $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->redirectUri()]));
    }

    /** Живой access-токен: протух — обновить по refresh под замком, чтобы два процесса не сожгли один refresh. */
    public function token(Connection $connection): string
    {
        if ($connection->access_token && $connection->access_expires_at?->gt(now()->addMinute())) {
            return $connection->access_token;
        }

        return Cache::lock('sber:token', 30)->block(20, function () use ($connection) {
            $connection->refresh();
            if ($connection->access_token && $connection->access_expires_at?->gt(now()->addMinute())) {
                return $connection->access_token;
            }
            if (! $connection->refresh_token) {
                throw new RuntimeException('СберБизнес не подключён');
            }
            $this->store($connection, $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token]));

            return $connection->access_token;
        });
    }

    /**
     * Операции за день: прошлые — полной выпиской, сегодня — «с утра до сейчас».
     * null — банк ещё готовит выписку (202), спросить позже.
     *
     * @return ?list<array>
     */
    public function transactions(Connection $connection, Carbon $day): ?array
    {
        $today = $day->isToday();
        $all = [];
        for ($page = 1; $page <= 50; $page++) {
            $response = $this->api($connection)->get($today ? '/fintech/api/v2/statement/increment' : '/fintech/api/v2/statement/transactions', [
                'accountNumber' => $connection->account, 'statementDate' => $day->toDateString(), 'page' => $page,
            ]);
            if ($response->status() === 202) {
                return null;
            }
            $this->failIfBad($response);
            array_push($all, ...($response->json('transactions') ?? []));
            if (! collect($response->json('_links') ?? [])->contains('rel', 'next')) {
                break;
            }
        }

        return $all;
    }

    /** Разовая замена client_secret (живёт 40 дней) на бессрочный. Новый — в базу, старый больше не годится. */
    public function perpetualSecret(Connection $connection): void
    {
        $response = $this->http()->asJson()->post(config('xcar.sber.api_url').'/fintech/api/applications/secrets/v1/refresh-client-secret', [
            'clientId' => config('xcar.sber.client_id'), 'clientSecret' => $this->secret(),
        ]);
        $this->failIfBad($response);
        $connection->update(['client_secret' => $response->json('clientSecret'), 'secret_rotated_at' => now()]);
    }

    private function secret(): ?string
    {
        return Connection::where('provider', 'sber')->first()?->client_secret ?: config('xcar.sber.client_secret');
    }

    private function tokenRequest(array $params): array
    {
        $response = $this->http()->asForm()->post(config('xcar.sber.api_url').'/ic/sso/api/v2/oauth/token', $params + [
            'client_id' => config('xcar.sber.client_id'), 'client_secret' => $this->secret(),
        ]);
        if ($response->failed()) {
            throw new RuntimeException('Сбер не выдал токен: '.($response->json('error_description') ?? $response->json('error') ?? $response->status()));
        }

        return $response->json();
    }

    private function store(Connection $connection, array $token): void
    {
        $connection->update([
            'access_token' => $token['access_token'], 'access_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
            'refresh_expires_at' => isset($token['refresh_token']) ? now()->addDays(180) : $connection->refresh_expires_at,
            'state' => null,
        ]);
    }

    private function api(Connection $connection): PendingRequest
    {
        return $this->http()->baseUrl(config('xcar.sber.api_url'))->withToken($this->token($connection));
    }

    private function http(): PendingRequest
    {
        $cert = (string) config('xcar.sber.cert');
        $options = ['verify' => config('xcar.sber.ca') ?: true, 'cert' => config('xcar.sber.cert_password') ? [$cert, config('xcar.sber.cert_password')] : $cert];
        if (preg_match('/\.(p12|pfx)$/i', $cert)) {
            $options['curl'] = [CURLOPT_SSLCERTTYPE => 'P12'];
        }

        return Http::withOptions($options)->acceptJson()->timeout(40)->connectTimeout(10);
    }

    private function failIfBad(Response $response): void
    {
        if ($response->failed()) {
            throw new RuntimeException('Sber API '.$response->status().': '.($response->json('message') ?? $response->json('cause') ?? Str::limit($response->body(), 200)));
        }
    }
}
