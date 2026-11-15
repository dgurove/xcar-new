<?php

namespace App\Offers\Console;

use App\Mail\Extraction\Code;
use App\Mail\Extraction\CodeMatcher;
use App\Offers\Intake\MigtorgDrafts;
use App\Offers\Jobs\ImportMigtorgLot;
use App\Offers\Migtorg;
use App\Offers\Offer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Номера дел всех лотов Мигторга — открытым списком (5 запросов на всё), затем поля и фото тем предложениям, чей номер
 * убытка нашёлся. Так ловятся предложения из любой двери (форма, письма, закупки) и лоты, вышедшие позже предложения.
 * Новый лот КАСКО вендора с «Черновики с Мигторга» — черновик сам (`MigtorgDrafts`).
 */
class MigtorgSync extends Command
{
    protected $signature = 'migtorg:sync';

    protected $description = 'Лоты Мигторга по номеру дела, поля и фото совпавшим предложениям';

    /** Колонки индекса, которые обновляет повторное чтение лота. */
    public const COLUMNS = ['claim_ref', 'claim_ref_key', 'vin', 'title', 'city', 'ends_at', 'seen_at', 'gone_at', 'data'];

    /** Обновление строки индекса при повторном чтении: неизвестный раздел не затирает известный. */
    public static function updates(): array
    {
        return [...self::COLUMNS, 'section' => DB::raw('coalesce(excluded.section, migtorg_lots.section)')];
    }

    /** Строка индекса `migtorg_lots` из строки списка или карточки (`Migtorg::row`). Не опубликованный — сразу ушедший. */
    public static function toIndex(array $lot, Carbon $now): array
    {
        $ref = self::ref($lot['claim_ref']);

        return [
            'id' => $lot['id'],
            'claim_ref' => mb_substr($ref, 0, 80),
            'claim_ref_key' => $ref !== '' ? mb_substr((string) Code::key($ref), 0, 80) : null,
            'vin' => $lot['vin'] ? mb_substr($lot['vin'], 0, 20) : null,
            'title' => mb_substr($lot['title'], 0, 160),
            'city' => isset($lot['city']) ? mb_substr($lot['city'], 0, 80) : null,
            'ends_at' => $lot['ends_at'] ? Carbon::parse($lot['ends_at'], 'Europe/Moscow') : null,
            'seen_at' => $now,
            'gone_at' => ($lot['status'] ?? 'PUBLISHED') === 'PUBLISHED' ? null : $now,
            'section' => $lot['section'] ?? null,
            'data' => isset($lot['data']) ? json_encode($lot['data'], JSON_UNESCAPED_UNICODE) : null,
        ];
    }

    /**
     * Номер дела без хвоста: Альфа пишет на Мигторге «6592/PVU/03331/26 САМАРА+», «0760/046/14575/26 ГОТС+» (26.10.2026:
     * ключ с хвостом не совпадал ни с одним предложением — фото не шли, черновик не заводился). Первое слово — номер, если
     * это и есть номер, который находит разбор (`CodeMatcher`); иначе строка как есть.
     */
    public static function ref(string $raw): string
    {
        $raw = trim($raw);
        $head = preg_split('/\s+/u', $raw, 2)[0];
        if ($head === $raw) {
            return $raw;
        }
        $found = (new CodeMatcher)->findAll($raw)[0] ?? null;

        return $found !== null && Code::key($found) === Code::key($head) ? $head : $raw;
    }

    /**
     * Кадры лотов в `migtorg_media`: скачанный с их сайта кадр (по имени файла) сам называет кадр и лот.
     * Перевыставленный лот несёт те же кадры под новым номером — `$newest` (синхронизация, карточка) отдаёт кадр ему,
     * обход архива вниз — не трогает.
     *
     * @param  array<int, array<string, string>>  $photos  лот → файл → uuid кадра
     */
    public static function media(array $photos, bool $newest = true): void
    {
        $rows = [];
        foreach ($photos as $lotId => $uuids) {
            foreach ($uuids as $file => $uuid) {
                $rows[$file] = ['file' => $file, 'uuid' => $uuid, 'lot_id' => $lotId];
            }
        }
        foreach (array_chunk(array_values($rows), 1000) as $chunk) {
            $newest ? DB::table('migtorg_media')->upsert($chunk, ['file'], ['uuid', 'lot_id']) : DB::table('migtorg_media')->insertOrIgnore($chunk);
        }
    }

    public function handle(Migtorg $migtorg): int
    {
        if (Migtorg::paused()) {
            $this->line('Мигторг отказал недавно, пауза');

            return self::SUCCESS;
        }
        $now = now();
        $rows = [];
        $photos = [];
        try {
            foreach ($migtorg->lots() as $lot) {
                $rows[$lot['id']] = self::toIndex($lot, $now);
                $photos[$lot['id']] = $lot['photos'];
            }
        } catch (Throwable $e) {
            // Список не дочитан — ушедшими никого не помечаем, индекс как был.
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('migtorg_lots')->upsert($chunk, ['id'], self::updates());
        }
        self::media($photos);
        $gone = DB::table('migtorg_lots')->whereNull('gone_at')->where('seen_at', '<', $now)->update(['gone_at' => $now]);

        // Подзапросом, а не списком: ключей без предложения — десятки тысяч, раз в 10 минут.
        $keys = DB::table('migtorg_lots')->whereNotNull('claim_ref_key')->whereNull('offer_id')->select('claim_ref_key');
        $started = Offer::whereIn('state', ImportMigtorgLot::STATES)->whereCar(fn ($c) => $c->whereIn('claim_ref_key', $keys))->get()
            ->filter(fn (Offer $offer) => ImportMigtorgLot::auto($offer))->count();
        $drafted = MigtorgDrafts::run();
        $this->line('Лотов: '.count($rows).", ушли: {$gone}, взяты предложениями: {$started}, новых черновиков: {$drafted}");

        return self::SUCCESS;
    }
}
