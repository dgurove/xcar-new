<?php

namespace App\Billing\Bank\Console;

use App\Billing\Bank\Actions\ImportStatement;
use App\Billing\Bank\Connection;
use App\Billing\Bank\SberApi;
use App\Billing\Bank\Transaction;
use App\Support\HoldsSingleRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Выписка за прошлое (14.11.2026, владелец: «подгрузи за всё время со Сбера»): день за днём назад от `--to` (по умолчанию —
 * день до первой строки, что уже есть) до `--from`, а без него — пока 60 дней подряд пусто (счёт тогда ещё не открыт).
 * Строки — `history`: только для дат сделок, без счетов и оплат; до июня 2026 без машины не храним (`ImportStatement`).
 */
final class HistoryCommand extends Command
{
    use HoldsSingleRun;

    protected $signature = 'bank:history {--from= : с какого дня} {--to= : по какой день}';

    protected $description = 'Догружает старую выписку Сбера для дат сделок';

    public function handle(SberApi $api, ImportStatement $import): int
    {
        $connection = Connection::sber();
        if (! $api->configured() || ! $connection->connected() || ! $connection->account) {
            $this->error('СберБизнес не подключён');

            return self::FAILURE;
        }
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : Carbon::parse(Transaction::whereNot('state', 'history')->min('booked_at') ?? now())->subDay();
        $from = $this->option('from') ? Carbon::parse($this->option('from')) : null;

        return $this->holdingSingleRun('bank:sync', function () use ($connection, $import, $to, $from) {
            $empty = 0;
            for ($day = $to->copy()->startOfDay(); ! $from || $day->gte($from); $day->subDay()) {
                try {
                    $rows = $import($connection, $day->copy(), history: true);
                } catch (Throwable $e) {
                    Log::warning('bank:history '.$day->toDateString().' — '.$e->getMessage());
                    $this->error($day->toDateString().': '.$e->getMessage());
                    $rows = null;
                    $empty++;
                }
                $count = Transaction::where('state', 'history')->whereDate('booked_at', $day)->count();
                $this->line($day->toDateString().': операций '.($rows?->count() ?? '?').', сохранено '.$count);
                $empty = $rows !== null && $rows->isNotEmpty() ? 0 : $empty + 1;
                if (! $from && $empty >= 60) {
                    $this->info('60 дней пусто — дальше выписки нет');
                    break;
                }
            }

            return self::SUCCESS;
        });
    }
}
