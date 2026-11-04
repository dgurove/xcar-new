<?php

namespace App\Mail\Extraction;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Файлы по ссылке из письма страховой (05.10.2026, Т-Страхование: «В архиве https://data.tbank.ru/s/… пароль: …»):
 * публичная ссылка Nextcloud (`/s/{токен}`), пароль — в том же письме. Как человек в браузере: открыть ссылку, ввести
 * пароль формой (WebDAV с паролем у них 401), забрать всё одним архивом — «Download all files» (`/s/{токен}/download`).
 * Сертификат data.tbank.ru выдан «Russian Trusted Root CA» Минцифры, которому образ не доверяет: на ошибке проверки
 * повторяем с ним (`resources/certs/russian-trusted-ca.pem`, корень сверен с gu-st.ru), а не без проверки.
 */
final class CloudShare
{
    /** Больше — не наш архив документов: скачивание обрывается, чтобы письмо не забило диск. */
    private const MAX_BYTES = 300 * 1024 * 1024;

    private const AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    /**
     * Ссылки на облако в тексте письма и пароль к ним («пароль: Z?X9aK(W]-»): один на письмо.
     *
     * @return list<array{url: string, base: string, token: string, password: ?string, kind?: string}>
     */
    public static function links(string $text): array
    {
        // Только https и публичный адрес: ссылку пишет внешний отправитель, по ней ходит наш сервер.
        preg_match_all('~(https://[^\s/<>"]+)/(?:index\.php/)?s/([A-Za-z0-9]{8,})~u', $text, $m, PREG_SET_ORDER);
        $password = preg_match('/парол[ьяе]\w*\s*[:\-–—]?\s*(\S+)/iu', $text, $p) ? $p[1] : null;
        $links = [];
        // «Большие файлы» Mail.ru (04.11.2026, ТИТ: «К этому письму приложены ссылки на следующие файлы: Фото торги.rar
        // (189.6 Мб) https://cloud.mail.ru/stock/…») — своя страница без пароля, файл забирается по токену из неё (`stock`).
        preg_match_all('~https://cloud\.mail\.ru/stock/([A-Za-z0-9]{12,})~u', $text, $stock, PREG_SET_ORDER);
        foreach ($stock as $hit) {
            $links[$hit[1]] = ['url' => $hit[0], 'base' => 'https://cloud.mail.ru', 'token' => $hit[1], 'password' => null, 'kind' => 'mailru'];
        }
        foreach ($m as $hit) {
            if (! self::publicHost((string) parse_url($hit[1], PHP_URL_HOST))) {
                continue;
            }
            $links[$hit[2]] = ['url' => $hit[0], 'base' => $hit[1], 'token' => $hit[2], 'password' => $password];
        }

        return array_values($links);
    }

    /** Имя хоста, а не IP, и все его адреса — публичные (не localhost, не внутренняя сеть сервера). */
    private static function publicHost(string $host): bool
    {
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) || ! str_contains($host, '.')) {
            return false;
        }
        $ips = gethostbynamel($host) ?: [];

        return $ips !== [] && collect($ips)->every(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
    }

    /**
     * Скачать всё по ссылке в $to. Имя файла — из ответа облака (папка приходит архивом `.zip`).
     *
     * @param  array{url: string, base: string, token: string, password: ?string}  $link
     */
    public function download(array $link, string $to): string
    {
        try {
            return $this->fetch($link, $to, true);
        } catch (ConnectionException $e) {
            if (! str_contains($e->getMessage(), 'certificate')) {
                throw $e;
            }

            return $this->fetch($link, $to, resource_path('certs/russian-trusted-ca.pem'));
        }
    }

    private function fetch(array $link, string $to, bool|string $verify): string
    {
        $jar = new CookieJar;
        $http = fn (): PendingRequest => Http::withOptions(['cookies' => $jar, 'verify' => $verify, 'allow_redirects' => ['max' => 5, 'protocols' => ['https']],
            'progress' => function ($total, $now) {
                if ($total > self::MAX_BYTES || $now > self::MAX_BYTES) {
                    throw new RuntimeException('Архив по ссылке больше 300 МБ');
                }
            }])->withHeaders(['User-Agent' => self::AGENT])->timeout(60);
        if (($link['kind'] ?? null) === 'mailru') {
            return $this->mailru($http, $link, $to);
        }
        $share = $link['base'].'/s/'.$link['token'];

        $page = $http()->get($share);
        if ($page->status() === 404) {
            throw new RuntimeException('Ссылки на облако больше нет');
        }
        // Под паролем облако уводит на форму: вводим его, как человек, с токеном формы.
        if (str_contains((string) $page->effectiveUri(), '/authenticate') || str_contains($page->body(), 'password-input-form')) {
            if (! $link['password']) {
                throw new RuntimeException('Облако просит пароль, а в письме его нет');
            }
            preg_match('/name="requesttoken" value="([^"]+)"/', $page->body(), $token);
            preg_match('/name="sharingType" value="([^"]*)"/', $page->body(), $type);
            $page = $http()->asForm()->post($share.'/authenticate/showShare', [
                'requesttoken' => $token[1] ?? '', 'password' => $link['password'], 'sharingToken' => $link['token'], 'sharingType' => $type[1] ?? '3',
            ]);
            if (str_contains($page->body(), 'password-input-form')) {
                throw new RuntimeException('Пароль к облаку не подошёл');
            }
        }
        $file = $http()->timeout(600)->sink($to)->get($share.'/download');
        if (! $file->successful()) {
            @unlink($to);
            throw new RuntimeException('Облако ответило '.$file->status());
        }
        $disposition = (string) $file->header('Content-Disposition');

        return preg_match("/filename\\*=UTF-8''([^;]+)/i", $disposition, $n) ? rawurldecode(trim($n[1], '"'))
            : (preg_match('/filename="([^"]+)"/i', $disposition, $n) ? $n[1] : $link['token'].'.zip');
    }

    /**
     * Страница «Почтовые вложения» Mail.ru: в её данных — файлы ссылки (`dwl_token`, имя, размер) и адрес раздачи
     * (`"stock":{"url":…}`); файл — `{раздача}/stock/get/{dwl_token}/{имя}`, так собирает ссылку сама страница. Несколько
     * файлов — одним zip, как «Download all» у Nextcloud.
     */
    private function mailru(\Closure $http, array $link, string $to): string
    {
        $page = $http()->get($link['url']);
        if (! $page->successful()) {
            throw new RuntimeException($page->status() === 404 ? 'Ссылки на облако больше нет' : 'Облако ответило '.$page->status());
        }
        $html = $page->body();
        preg_match('~"stock":\{"count":"?\d+"?,"url":"(https://[^"]+)"~', $html, $host);
        preg_match_all('~"name":"([^"]+)","stock":"[^"]*","size":(\d+),"dwl_token":"([A-Za-z0-9]+)"~u', $html, $files, PREG_SET_ORDER);
        $files = collect($files)->unique(3)->values();
        if (! $host || $files->isEmpty()) {
            throw new RuntimeException('Файлов на странице облака не нашли — ссылка истекла или страница поменялась');
        }
        $origin = (string) preg_replace('~^(https://[^/]+).*$~', '$1', $host[1]);
        if (! self::publicHost((string) parse_url($origin, PHP_URL_HOST))) {
            throw new RuntimeException('Адрес раздачи облака не публичный');
        }
        if ($files->sum(fn ($f) => (int) $f[2]) > self::MAX_BYTES) {
            throw new RuntimeException('Файлы по ссылке больше 300 МБ');
        }
        $get = fn (array $f, string $path) => $http()->timeout(900)->sink($path)->get($origin.'/stock/get/'.$f[3].'/'.rawurlencode(json_decode('"'.$f[1].'"') ?? $f[1]));
        if ($files->count() === 1) {
            $f = $files->first();
            if (! $get($f, $to)->successful()) {
                @unlink($to);
                throw new RuntimeException('Облако не отдало файл');
            }

            return json_decode('"'.$f[1].'"') ?? $f[1];
        }
        $zip = new \ZipArchive;
        $zip->open($to, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $parts = [];
        foreach ($files as $f) {
            $part = $to.'-'.count($parts);
            if ($get($f, $part)->successful()) {
                $zip->addFile($part, json_decode('"'.$f[1].'"') ?? $f[1]);
                $parts[] = $part;
            }
        }
        $zip->close();
        array_map('unlink', $parts);

        return $link['token'].'.zip';
    }
}
