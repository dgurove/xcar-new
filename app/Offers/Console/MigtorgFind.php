<?php

namespace App\Offers\Console;

use App\Offers\Jobs\ImportMigtorgLot;
use App\Offers\Migtorg;
use App\Offers\Offer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Лоты предложений, которых нет в открытом списке Мигторга: торги прошли, лот снят. Поиска по номеру дела у них нет,
 * поэтому номера лотов обходятся вниз от самого свежего, по карточке на номер (вход, пауза перед каждым запросом),
 * известные индексу пропускаются; найденное ложится в `migtorg_lots`, обход кончается, как только нашлись все
 * номера. Совпавшим — `ImportMigtorgLot`: пустые поля, кадры — только в пустой ряд. Разово, руками:
 * `migtorg:find 1105 1106` или без номеров — черновики без лота за трое суток.
 */
class MigtorgFind extends Command
{
    protected $signature = 'migtorg:find {offers?* : номера предложений} {--max=4000 : сколько карточек открыть, не больше}';

    protected $description = 'Найти на Мигторге снятые лоты предложений по номеру дела и взять поля и фото';

    public function handle(Migtorg $migtorg): int
    {
        $offers = $this->offers();
        $wanted = $offers->pluck('claim_ref_key')->filter()->unique()->flip()->map(fn () => null)->all();
        if (! $wanted) {
            $this->line('Искать нечего');

            return self::SUCCESS;
        }
        $this->line('Ищем номеров: '.count($wanted));
        $known = DB::table('migtorg_lots')->pluck('id')->flip();
        $id = (int) DB::table('migtorg_lots')->max('id') + 50;
        $opened = 0;
        while ($opened < (int) $this->option('max') && in_array(null, $wanted, true) && $id > 0) {
            $id--;
            if ($known->has($id)) {
                continue;
            }
            $opened++;
            try {
                $row = MigtorgSync::toIndex(Migtorg::row($migtorg->card($id)), now());
            } catch (RuntimeException $e) {
                if (Migtorg::paused() || ! Migtorg::ready()) {
                    $this->error('Мигторг придержал: '.$e->getMessage());

                    break;
                }

                continue; // 404 — номера нет, 403 — не наш раздел
            }
            DB::table('migtorg_lots')->upsert([$row], ['id'], ['claim_ref', 'claim_ref_key', 'vin', 'title', 'ends_at', 'seen_at', 'gone_at']);
            if ($row['claim_ref_key'] && array_key_exists($row['claim_ref_key'], $wanted)) {
                $wanted[$row['claim_ref_key']] ??= $id;
                $this->line("{$row['claim_ref']} — лот {$id}, {$row['title']}");
            }
            $opened % 100 || $this->line("Открыто карточек: {$opened}, дошли до {$id}");
        }

        $started = 0;
        foreach ($offers as $offer) {
            $lot = ImportMigtorgLot::available($offer);
            if ($lot && ! $lot->offer_id) {
                ImportMigtorgLot::start($offer, $lot);
                $started++;
            }
        }
        $this->line('Открыто карточек: '.$opened.', найдено '.count(array_filter($wanted)).' из '.count($wanted).', задач: '.$started);

        return self::SUCCESS;
    }

    private function offers()
    {
        $numbers = $this->argument('offers');
        $query = Offer::whereIn('state', ImportMigtorgLot::STATES)->whereNotNull('claim_ref_key');

        return $numbers
            ? $query->whereIn('number', $numbers)->get()
            : $query->where('created_at', '>', now()->subDays(3))
                ->whereNotIn('claim_ref_key', DB::table('migtorg_lots')->whereNotNull('claim_ref_key')->select('claim_ref_key'))->get();
    }
}
