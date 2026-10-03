<?php

namespace App\Offers;

use App\Purchases\Carcade;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * migtorg.com — приложение Angular поверх JSON API, впереди DDoS-Guard. Список лотов открыт и сразу несёт номер дела
 * (`lot.insurance_deal_number`), 100 на страницу — потолок; карточка с полным набором фото — только с токеном; оригинал
 * кадра (`/api/media/{uuid}`) открыт и без их знака. Ходим как их приложение: по одному запросу с паузой, токен живёт
 * в кэше (частый вход похож на перебор пароля), отказ DDoS-Guard — час тишины.
 */
final class Migtorg
{
    private const API = 'https://site-prod-back.migtorg.com/api';

    private const SITE = 'https://www.migtorg.com';

    /** Битые по КАСКО и по ОСАГО: у обоих номер дела страховой. Целые без номера. */
    private const SECTIONS = ['casco', 'osago'];

    private const TOKEN = 'migtorg:token';

    private const QUIET = 'migtorg:quiet';

    private CookieJar $jar;

    public function __construct()
    {
        $this->jar = new CookieJar;
    }

    /**
     * Все опубликованные лоты: id, номер дела, VIN, название, время правки.
     *
     * @return \Generator<int, array{id: int, claim_ref: string, vin: ?string, title: string, updated_at: ?string}>
     */
    public function lots(): \Generator
    {
        foreach (self::SECTIONS as $section) {
            $page = 1;
            do {
                $json = $this->get('/auctions', ['page' => $page, 'limit' => 100, 'insurance_type' => $section])->json();
                foreach ($json['data'] ?? [] as $auction) {
                    $lot = $auction['lot'] ?? [];
                    yield [
                        'id' => (int) $auction['id'],
                        'claim_ref' => trim((string) ($lot['insurance_deal_number'] ?? '')),
                        'vin' => strtoupper(trim((string) ($lot['vin'] ?? ''))) ?: null,
                        'title' => trim(($lot['brand']['title'] ?? '').' '.($lot['model']['title'] ?? '').' '.($lot['year'] ?? '')),
                        'updated_at' => $auction['updated_at'] ?? null,
                    ];
                }
                $last = (int) ($json['meta']['last_page'] ?? 1);
            } while ($page++ < $last);
        }
    }

    /** uuid кадров лота по порядку: главный, затем остальные — как в галерее на сайте. */
    public function photos(int $id): array
    {
        $auction = $this->get("/auctions/{$id}", auth: true)->json();
        $auction = $auction['data'] ?? $auction;
        $order = fn (array $list) => collect($list)->sortBy('order_column')->pluck('uuid')->all();

        return array_values(array_unique(array_filter([
            ...$order($auction['media']['main_photos'] ?? []),
            ...$order($auction['media']['photos'] ?? []),
        ])));
    }

    public function download(string $uuid, string $to): void
    {
        $this->get('/media/'.rawurlencode($uuid), sink: $to);
    }

    /** Можно ходить за фото: вход задан и нас не придержали. */
    public static function ready(): bool
    {
        return config('xcar.migtorg_email') && config('xcar.migtorg_password') && ! self::paused();
    }

    public static function paused(): bool
    {
        return Cache::has(self::QUIET);
    }

    private function get(string $path, array $query = [], bool $auth = false, ?string $sink = null): Response
    {
        if (self::paused()) {
            throw new RuntimeException('Мигторг отказал в доступе, пауза час');
        }
        // Пауза до запроса, а не после: подряд идущие страницы списка и кадры не уходят пачкой.
        usleep(random_int(800, 1800) * 1000);
        $send = function () use ($path, $query, $auth, $sink) {
            $request = $this->http();
            $auth && $request->withToken($this->token());
            $sink && $request->sink($sink)->timeout(120);

            return $request->get(self::API.$path, $query);
        };
        $response = $send();
        if ($auth && $response->status() === 401) {
            Cache::forget(self::TOKEN);
            $response = $send();
        }
        if ($response->successful()) {
            return $response;
        }
        $sink && @unlink($sink);
        // 429 или 403 не из их API (страница DDoS-Guard) — нас придержали: замолчать на час, а не долбить.
        if ($response->status() === 429 || ($response->status() === 403 && ! str_contains((string) $response->header('Content-Type'), 'json'))) {
            Cache::put(self::QUIET, true, 3600);
            Log::warning("Мигторг: {$response->status()} на {$path}, пауза час");
        }

        throw new RuntimeException("Мигторг ответил {$response->status()} на {$path}");
    }

    private function token(): string
    {
        return Cache::remember(self::TOKEN, now()->addDay(), function () {
            [$email, $password] = [config('xcar.migtorg_email'), config('xcar.migtorg_password')];
            if (! $email || ! $password) {
                throw new RuntimeException('Нет MIGTORG_EMAIL / MIGTORG_PASSWORD');
            }
            usleep(random_int(800, 1800) * 1000);
            $response = $this->http()->post(self::API.'/auth/login', ['email' => $email, 'password' => $password]);

            return $response->json('token') ?: throw new RuntimeException("Вход на Мигторг не удался: {$response->status()}");
        });
    }

    private function http(): PendingRequest
    {
        $proxy = config('xcar.migtorg_proxy');

        return Http::withOptions(['cookies' => $this->jar] + ($proxy ? ['proxy' => $proxy] : []))
            ->withHeaders([
                'User-Agent' => Carcade::AGENT,
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
                'Origin' => self::SITE,
                'Referer' => self::SITE.'/',
            ])
            ->timeout(30)
            // Обрыв связи и 5xx — ещё два раза с растущей паузой; 4xx не повторяем.
            ->retry([2000, 6000], when: fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }
}
