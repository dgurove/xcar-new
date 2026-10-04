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

    /** Строка индекса `migtorg_lots` из строки списка или карточки (`Migtorg::row`). Не опубликованный — сразу ушедший. */
    public static function toIndex(array $lot, Carbon $now): array
    {
        return [
            'id' => $lot['id'],
            'claim_ref' => mb_substr($lot['claim_ref'], 0, 80),
            'claim_ref_key' => $lot['claim_ref'] !== '' ? mb_substr((string) Code::key($lot['claim_ref']), 0, 80) : null,
            'vin' => $lot['vin'] ? mb_substr($lot['vin'], 0, 20) : null,
            'title' => mb_substr($lot['title'], 0, 160),
            'ends_at' => $lot['ends_at'] ? Carbon::parse($lot['ends_at'], 'Europe/Moscow') : null,
            'seen_at' => $now,
            'gone_at' => ($lot['status'] ?? 'PUBLISHED') === 'PUBLISHED' ? null : $now,
        ];
    }

    public function handle(Migtorg $migtorg): int
    {
        if (Migtorg::paused()) {
            $this->line('Мигторг отказал недавно, пауза');

            return self::SUCCESS;
        }
        $now = now();
        $rows = [];
        try {
            foreach ($migtorg->lots() as $lot) {
                $rows[$lot['id']] = self::toIndex($lot, $now);
            }
        } catch (Throwable $e) {
            // Список не дочитан — ушедшими никого не помечаем, индекс как был.
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('migtorg_lots')->upsert($chunk, ['id'], ['claim_ref', 'claim_ref_key', 'vin', 'title', 'ends_at', 'seen_at', 'gone_at']);
        }
        $gone = DB::table('migtorg_lots')->whereNull('gone_at')->where('seen_at', '<', $now)->update(['gone_at' => $now]);

        $keys = DB::table('migtorg_lots')->whereNotNull('claim_ref_key')->whereNull('offer_id')->distinct()->pluck('claim_ref_key');
        $started = Offer::whereIn('state', ImportMigtorgLot::STATES)->whereIn('claim_ref_key', $keys)->get()
            ->filter(fn (Offer $offer) => ImportMigtorgLot::auto($offer))->count();
        $this->line('Лотов: '.count($rows).", ушли: {$gone}, взяты предложениями: {$started}");

        return self::SUCCESS;
    }
}
