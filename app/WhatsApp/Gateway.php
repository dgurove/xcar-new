<?php

namespace App\WhatsApp;

use App\Support\Routes;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Служба WhatsApp в Цюрихе (`zurich/whatsapp`): связанное устройство номера владельца. Шлёт в группу, зашитую в её настройках
 * (`TARGET`), и в личные — только тем, кто сам написал первым (код привязки, `LinkCode`); такие сообщения она и отдаёт
 * (`inbox`). Отсюда — через туннели (`WHATSAPP_VIA`, на проде `http://zurich:9444,http://zurich:9446`: держит Цюрих и держит
 * прод, переключение — `Support\Routes`), с ключом `WHATSAPP_TOKEN`. Повтор с тем же `key` второй раз не отправляет: служба
 * помнит ключи у себя, поэтому `send` по второму пути безопасен.
 */
final class Gateway
{
    public function configured(): bool
    {
        return self::routes() !== [];
    }

    /** @return list<string> */
    public static function routes(): array
    {
        return Routes::parse(config('xcar.whatsapp.via'));
    }

    /** @template T @param callable(PendingRequest): T $request @return T */
    private function over(callable $request): mixed
    {
        return Routes::over('whatsapp', self::routes(), fn (string $via) => $request($this->http($via)));
    }

    /**
     * in_group: null — WhatsApp не ответил про группу (`group_error`), служба знает о ней только из прошлого ответа или ничего.
     * invite — ссылка-приглашение группы.
     *
     * @return array{connected: bool, logged_in: bool, stopped: ?string, in_group: ?bool, group: ?string, invite?: ?string, group_error?: string}
     */
    public function status(): array
    {
        return $this->over(fn (PendingRequest $http) => $http->timeout(15)->get('/status')->throw()->json());
    }

    /**
     * @param  array{0: string, 1: string}|null  $pdf  содержимое и имя файла; null — текстом
     * @param  ?string  $thumb  JPEG превью документа
     * @param  ?string  $to  JID личного чата; null — группа
     * @return string id сообщения в WhatsApp
     */
    public function send(string $key, string $caption, ?array $pdf, ?string $thumb, ?string $to = null): string
    {
        return $this->over(function (PendingRequest $request) use ($key, $caption, $pdf, $thumb, $to) {
            $request = $request->timeout(120)->asMultipart();
            if ($pdf !== null) {
                $request = $request->attach('pdf', $pdf[0], $pdf[1]);
                if ($thumb !== null) {
                    $request = $request->attach('thumb', $thumb, 'thumb.jpg');
                }
            }

            return (string) $request->post('/send', ['key' => $key, 'caption' => $caption, 'filename' => $pdf[1] ?? '', 'to' => $to ?? ''])
                ->throw()->json('id');
        });
    }

    /**
     * Входящие с кодом привязки после `after`: служба держит запрос до `wait` секунд, пока не придёт новое.
     *
     * @return list<array{id: int, jid: string, phone: string, name: string, text: string, at: int}>
     */
    public function inbox(int $after, int $wait): array
    {
        return $this->over(fn (PendingRequest $http) => (array) $http->timeout($wait + 10)->get('/inbox', ['after' => $after, 'wait' => $wait])->throw()->json());
    }

    private function http(string $via): PendingRequest
    {
        return Http::baseUrl(rtrim($via, '/'))->withToken((string) config('xcar.whatsapp.token'))
            ->connectTimeout(3)->acceptJson();
    }
}
