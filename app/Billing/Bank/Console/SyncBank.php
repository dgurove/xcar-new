<?php

namespace App\Billing\Bank\Console;

use App\Billing\Bank\Actions\ImportStatement;
use App\Billing\Bank\Connection;
use App\Billing\Bank\SberApi;
use App\Billing\Bank\Transaction;
use App\Notifications\MoneyNotice;
use App\Support\HoldsSingleRun;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\BankDigest;
use App\Users\Role;
use App\Users\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Выписка Сбера: днём — сегодняшние операции, утром (`--days=1`) — вчерашний день целиком и сводка владельцу.
 * Новые поступления без счёта — сотрудникам в «Деньги». Без подключения — молча ничего.
 */
final class SyncBank extends Command
{
    use HoldsSingleRun;

    protected $signature = 'bank:sync {--days=0 : сколько прошлых дней догрузить} {--date= : конкретный день} {--digest : утренняя сводка владельцу}';

    protected $description = 'Загружает выписку Сбера и отмечает оплаты по счетам';

    public function handle(SberApi $api, ImportStatement $import): int
    {
        $connection = Connection::sber();
        if (! $api->configured() || ! $connection->connected() || ! $connection->account) {
            return self::SUCCESS;
        }

        return $this->holdingSingleRun('bank:sync', function () use ($connection, $import) {
            $days = $this->option('date') ? [Carbon::parse($this->option('date'))] : collect(range((int) $this->option('days'), 0))->map(fn ($d) => now()->subDays($d)->startOfDay())->all();
            $fresh = collect();
            foreach ($days as $day) {
                try {
                    $rows = $import($connection, $day);
                    if ($rows === null) {
                        $this->warn($day->toDateString().': банк ещё готовит выписку');

                        continue;
                    }
                    $fresh = $fresh->merge($rows);
                    $this->info($day->toDateString().': новых без счёта '.$rows->count());
                } catch (Throwable $e) {
                    Log::warning('bank:sync '.$day->toDateString().' — '.$e->getMessage());
                    $this->error($e->getMessage());
                }
            }
            if ($fresh->isNotEmpty()) {
                Notification::send(User::where('role', Role::Admin)->get(), MoneyNotice::bankUnmatched($fresh->count(), $fresh->sum('amount')));
            }
            if ($this->option('digest')) {
                $open = Transaction::where('state', Transaction::UNMATCHED);
                $digest = new BankDigest((clone $open)->count(), (float) (clone $open)->sum('amount'), $connection->fresh()->last_error,
                    $connection->refresh_expires_at ? (int) now()->diffInDays($connection->refresh_expires_at) : null);
                if ($digest->worthSending()) {
                    NotifyOwner::dispatch($digest);
                }
            }

            return self::SUCCESS;
        });
    }
}
