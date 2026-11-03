<?php

namespace App\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Служба WhatsApp в Цюрихе (`zurich/whatsapp`): связанное устройство номера владельца, умеет одно — отправить сообщение в
 * одну группу, зашитую в её настройках (`TARGET`). Отсюда — через туннель (`WHATSAPP_VIA`, на проде `http://zurich:9444`),
 * с ключом `WHATSAPP_TOKEN`. Повтор с тем же `key` второй раз не отправляет: служба помнит ключи у себя.
 */
final class Gateway
{
    public function configured(): bool
    {
        return trim((string) config('xcar.whatsapp.via')) !== '';
    }

    /** @return array{connected: bool, logged_in: bool, stopped: ?string, in_group: bool, group: ?string} */
    public function status(): array
    {
        return $this->http()->timeout(15)->get('/status')->throw()->json();
    }

    /**
     * @param  array{0: string, 1: string}|null  $pdf  содержимое и имя файла; null — текстом
     * @param  ?string  $thumb  JPEG превью документа
     * @return string id сообщения в WhatsApp
     */
    public function send(string $key, string $caption, ?array $pdf, ?string $thumb): string
    {
        $request = $this->http()->timeout(120)->asMultipart();
        if ($pdf !== null) {
            $request = $request->attach('pdf', $pdf[0], $pdf[1]);
            if ($thumb !== null) {
                $request = $request->attach('thumb', $thumb, 'thumb.jpg');
            }
        }

        return (string) $request->post('/send', ['key' => $key, 'caption' => $caption, 'filename' => $pdf[1] ?? ''])
            ->throw()->json('id');
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('xcar.whatsapp.via'), '/'))->withToken((string) config('xcar.whatsapp.token'))
            ->connectTimeout(3)->acceptJson();
    }
}
