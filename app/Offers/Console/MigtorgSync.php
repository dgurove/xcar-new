<?php

namespace App\Offers\Console;

use App\Mail\Extraction\Code;
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
 */
class MigtorgSync extends Command
{
    protected $signature = 'migtorg:sync';

    protected $description = 'Лоты Мигторга по номеру дела, поля и фото совпавшим предложениям';

    /** Колонки индекса, которые обновляет повторное чтение лота. */
    public const COLUMNS = ['claim_ref', 'claim_ref_key', 'vin', 'title', 'city', 'ends_at', 'seen_at', 'gone_at', 'data'];

    /** Строка индекса `migtorg_lots` из строки списка или карточки (`Migtorg::row`). Не опубликованный — сразу ушедший. */
    public static function toIndex(array $lot, Carbon $now): array
    {
        return [
            'id' => $lot['id'],
            'claim_ref' => mb_substr($lot['claim_ref'], 0, 80),
            'claim_ref_key' => $lot['claim_ref'] !== '' ? mb_substr((string) Code::key($lot['claim_ref']), 0, 80) : null,
            'vin' => $lot['vin'] ? mb_substr($lot['vin'], 0, 20) : null,
            'title' => mb_substr($lot['title'], 0, 160),
            'city' => isset($lot['city']) ? mb_substr($lot['city'], 0, 80) : null,
            'ends_at' => $lot['ends_at'] ? Carbon::parse($lot['ends_at'], 'Europe/Moscow') : null,
            'seen_at' => $now,
            'gone_at' => ($lot['status'] ?? 'PUBLISHED') === 'PUBLISHED' ? null : $now,
            'data' => isset($lot['data']) ? json_encode($lot['data'], JSON_UNESCAPED_UNICODE) : null,
        ];
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
            DB::table('migtorg_lots')->upsert($chunk, ['id'], self::COLUMNS);
        }
        self::media($photos);
        $gone = DB::table('migtorg_lots')->whereNull('gone_at')->where('seen_at', '<', $now)->update(['gone_at' => $now]);

        $keys = DB::table('migtorg_lots')->whereNotNull('claim_ref_key')->whereNull('offer_id')->distinct()->pluck('claim_ref_key');
        $started = Offer::whereIn('state', ImportMigtorgLot::STATES)->whereIn('claim_ref_key', $keys)->get()
            ->filter(fn (Offer $offer) => ImportMigtorgLot::auto($offer))->count();
        $this->line('Лотов: '.count($rows).", ушли: {$gone}, взяты предложениями: {$started}");

        return self::SUCCESS;
    }
}
