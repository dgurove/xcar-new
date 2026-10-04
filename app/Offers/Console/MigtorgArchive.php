<?php

namespace App\Offers\Console;

use App\Offers\Jobs\ImportMigtorgLot;
use App\Offers\Migtorg;
use App\Offers\Offer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Архив Мигторга: лоты, торги по которым кончились до того, как синхронизация начала их видеть (раньше 03.10.2026).
 * Поиска по номеру дела у Мигторга нет, поэтому номера лотов обходятся вниз, по карточке со входом, известные
 * индексу пропускаются. Кусок — до `--chunk` карточек раз в 5 минут по расписанию: выкладка убивает только текущий
 * кусок, следующий начинает с курсора (`migtorg_scan`). Конец — когда 50 карточек подряд старше границы
 * (`FLOOR_DAYS` назад от первого запуска). Номера, которых нет (404) или не наши (403), ложатся в индекс пустыми — второй раз
 * их не открываем. Найденное сверяется с предложениями: совпавшим — `auto()`, как у синхронизации.
 */
class MigtorgArchive extends Command
{
    public const FLOOR_DAYS = 60;

    private const OLD_IN_A_ROW = 50;

    protected $signature = 'migtorg:archive {--chunk=150 : сколько карточек открыть за раз}';

    protected $description = 'Кусок обхода архива Мигторга вниз по номерам лотов';

    /** Состояние обхода: курсор, граница, до какой даты торгов проверено, кончен ли. */
    public static function scan(): ?object
    {
        return DB::table('migtorg_scan')->first();
    }

    public function handle(Migtorg $migtorg): int
    {
        if (! Migtorg::ready()) {
            return self::SUCCESS;
        }
        $scan = self::scan() ?? $this->begin();
        if ($scan->done_at) {
            return self::SUCCESS;
        }
        $floor = Carbon::parse($scan->floor_date)->startOfDay();
        $known = DB::table('migtorg_lots')->where('id', '<', $scan->cursor_id)->where('id', '>=', $scan->cursor_id - 3000)->pluck('id')->flip();
        $state = ['cursor_id' => $scan->cursor_id, 'reached_at' => $scan->reached_at, 'checked' => $scan->checked, 'found' => $scan->found, 'misses' => $scan->misses];
        $keys = [];
        $opened = 0;
        while ($opened < (int) $this->option('chunk') && $state['cursor_id'] > 1) {
            $id = --$state['cursor_id'];
            if ($known->has($id)) {
                continue;
            }
            $opened++;
            $state['checked']++;
            try {
                $row = MigtorgSync::toIndex(Migtorg::row($migtorg->card($id)), now());
            } catch (RuntimeException $e) {
                if (Migtorg::paused() || ! Migtorg::ready() || ! in_array($e->getCode(), [403, 404], true)) {
                    // Придержали или сбой связи — номер не потерян: следующий кусок начнёт с него же.
                    $state['cursor_id']++;
                    $this->warn('Мигторг: '.$e->getMessage());

                    break;
                }
                DB::table('migtorg_lots')->insertOrIgnore(['id' => $id, 'claim_ref' => '', 'title' => '', 'seen_at' => now(), 'gone_at' => now()]);

                continue;
            }
            DB::table('migtorg_lots')->upsert([$row], ['id'], ['claim_ref', 'claim_ref_key', 'vin', 'title', 'city', 'ends_at', 'seen_at', 'gone_at']);
            if ($row['claim_ref_key']) {
                $keys[] = $row['claim_ref_key'];
                $state['found']++;
            }
            $ends = $row['ends_at'] ? Carbon::parse($row['ends_at']) : null;
            if ($ends && (! $state['reached_at'] || $ends->lt($state['reached_at']))) {
                $state['reached_at'] = $ends;
            }
            $state['misses'] = $ends && $ends->lt($floor) ? $state['misses'] + 1 : 0;
            if ($state['misses'] >= self::OLD_IN_A_ROW) {
                $state['done_at'] = now();

                break;
            }
        }
        DB::table('migtorg_scan')->where('id', $scan->id)->update($state + ['updated_at' => now()]);

        $started = $keys ? Offer::whereIn('state', ImportMigtorgLot::STATES)->whereIn('claim_ref_key', array_unique($keys))->get()
            ->filter(fn (Offer $offer) => ImportMigtorgLot::auto($offer))->count() : 0;
        $this->line("Открыто {$opened}, курсор {$state['cursor_id']}, с номером {$state['found']}, задач {$started}".(isset($state['done_at']) ? ', архив пройден' : ''));

        return self::SUCCESS;
    }

    /** Первый запуск: сверху — самый свежий известный лот, граница — FLOOR_DAYS назад. */
    private function begin(): object
    {
        DB::table('migtorg_scan')->insert([
            'cursor_id' => (int) DB::table('migtorg_lots')->max('id') + 1,
            'floor_date' => now()->subDays(self::FLOOR_DAYS)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return self::scan();
    }
}
