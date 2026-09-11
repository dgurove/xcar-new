<?php

namespace App\Purchases;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Поставщик: карточка на www.carcade.com лежит готовым JSON в __NEXT_DATA__,
 * фото — в Nextcloud expo.carcade.com по WebDAV (токен ссылки — логин,
 * пароль пустой). Без браузерного User-Agent облако отвечает 403; папку
 * архивом качать нельзя; .zip отдаётся только папочным ?accept=zip.
 * Все запросы — через CARCADE_PROXY: адрес прода у них в бане.
 */
final class Carcade
{
    public const AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    private const CLOUD = 'https://expo.carcade.com';

    public static function transport(): array
    {
        $proxy = config('xcar.carcade_proxy');

        return $proxy ? ['proxy' => $proxy] : [];
    }

    /** @throws Gone когда машина снята */
    public function specs(string $url): array
    {
        $response = Http::withOptions(self::transport())->withHeaders(['User-Agent' => self::AGENT])->timeout(30)->retry(2, 500, throw: false)->get($url);
        if ($response->status() === 404) {
            throw new Gone('Карточка отдала 404');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Сайт поставщика ответил '.$response->status());
        }

        return $this->parse($response->body());
    }

    public function parse(string $html): array
    {
        if (! preg_match('~<script id="__NEXT_DATA__"[^>]*>(.*?)</script>~s', $html, $m)) {
            throw new RuntimeException('На странице нет данных карточки');
        }
        $json = json_decode($m[1], true);
        $position = $json['props']['pageProps']['position'] ?? null;
        if (! is_array($position)) {
            throw new Gone('Машина снята с продажи у поставщика');   // каталог вместо карточки
        }
        $used = $position['used']['used'] ?? [];
        if (! is_array($used) || ! $used) {
            throw new Gone('В карточке нет машины');
        }
        $pairs = fn ($list) => collect(is_array($list) ? $list : [])->filter(fn ($i) => is_array($i) && isset($i['name']))->mapWithKeys(fn ($i) => [(string) $i['name'] => (string) ($i['value'] ?? '')])->all();
        $attrs = $pairs($used['attributes'] ?? []);
        $chars = $pairs($used['characteristics'] ?? []);
        $location = is_array($used['location'] ?? null) ? $used['location'] : [];
        $int = fn ($v) => $v === null ? null : ((int) preg_replace('/\D+/u', '', (string) $v) ?: null);
        $str = fn ($v) => trim((string) ($v ?? '')) ?: null;

        return [
            'dl' => $str($used['dl'] ?? null),
            'vin' => preg_match('~\b([A-HJ-NPR-Z0-9]{17})\b~', (string) ($position['seo']['title'] ?? ''), $v) ? $v[1] : null,
            'brand' => $str($used['brand'] ?? null),
            'model' => $str($used['model'] ?? null),
            'year' => $int($used['year'] ?? $attrs['Год'] ?? null),
            'mileage' => $int($attrs['Пробег'] ?? null),
            'transmission' => $str($attrs['Коробка'] ?? null),
            'engine_power' => $int($attrs['Мощность, л.с.'] ?? null),
            'engine_volume' => $int($attrs['Рабочий объем, см3'] ?? null),
            'color' => $str($chars['Цвет кузова'] ?? null),
            'fuel' => $str($chars['Тип топлива'] ?? null),
            'keys' => $str($chars['Наличие ключей'] ?? null),
            'steering' => $str($chars['Расположение руля'] ?? null),
            'condition' => $str($chars['Состояние'] ?? $used['state'] ?? null),
            'fssp' => match (mb_strtolower(trim((string) ($chars['Ограничения ФССП'] ?? '')))) { 'да' => true, 'нет' => false, default => null },
            'city' => $str($location['city'] ?? null),
            'address' => $str($used['city'] ?? null),
            'pictures' => array_values(array_unique(array_map(fn ($p) => str_starts_with(trim((string) $p), '//') ? 'https:'.trim((string) $p) : trim((string) $p), array_filter((array) ($used['pictures'] ?? []))))),
        ];
    }

    public static function cloudToken(string $url): ?string
    {
        return preg_match('~/s/([A-Za-z0-9]+)~', $url, $m) ? $m[1] : null;
    }

    /** @return list<array{url: string, name: string, bytes: int, type: ?string}> */
    public function cloudFiles(string $url): array
    {
        $token = self::cloudToken($url) ?? throw new RuntimeException('В ссылке на облако нет токена');
        $response = Http::withOptions(self::transport())->withHeaders(['User-Agent' => self::AGENT, 'Depth' => 'infinity'])->withBasicAuth($token, '')
            ->timeout(60)->send('PROPFIND', self::CLOUD.'/public.php/dav/files/'.$token.'/');
        if ($response->status() === 404) {
            throw new Gone('Папки в облаке больше нет');
        }
        if ($response->status() !== 207) {
            throw new RuntimeException('Облако ответило '.$response->status());
        }
        try {
            $dav = new SimpleXMLElement($response->body());
        } catch (Throwable) {
            throw new RuntimeException('Облако ответило не списком файлов');
        }
        $dav->registerXPathNamespace('d', 'DAV:');
        $files = [];
        foreach ($dav->xpath('//d:response') ?: [] as $node) {
            $node->registerXPathNamespace('d', 'DAV:');
            $href = (string) ($node->xpath('d:href')[0] ?? '');
            $length = $node->xpath('.//d:getcontentlength');
            if ($href === '' || ! $length) {
                continue;   // папка
            }
            $type = $node->xpath('.//d:getcontenttype');
            $files[] = ['url' => self::CLOUD.$href, 'name' => rawurldecode(basename($href)), 'bytes' => (int) (string) $length[0], 'type' => $type ? (string) $type[0] : null];
        }

        return $files;
    }

    public function download(string $fileUrl, string $to): void
    {
        $response = Http::withOptions(self::transport())->withHeaders(['User-Agent' => self::AGENT])->timeout(300)->sink($to)->retry(3, 1000, throw: false)->get($fileUrl);
        if (! $response->successful()) {
            @unlink($to);
            throw new RuntimeException('Облако ответило '.$response->status());
        }
    }

    /** Архив — только папочным запросом: прямая ссылка на .zip у них всегда 403. */
    public function downloadPacked(string $folderUrl, string $name, string $to): void
    {
        $token = self::cloudToken($folderUrl) ?? throw new RuntimeException('В ссылке на облако нет токена');
        $response = Http::withOptions(self::transport())->withHeaders(['User-Agent' => self::AGENT])->withBasicAuth($token, '')->timeout(300)->sink($to)
            ->retry(2, 1000, throw: false)->get(self::CLOUD.'/public.php/dav/files/'.$token.'/', ['accept' => 'zip', 'files' => json_encode([$name], JSON_UNESCAPED_UNICODE)]);
        if (! $response->successful()) {
            @unlink($to);
            throw new RuntimeException('Архив '.$name.': облако ответило '.$response->status());
        }
    }
}
