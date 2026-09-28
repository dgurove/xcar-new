<?php

namespace App\Billing\Acquiring\Console;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\Actions\SettleAcquiring;
use App\Billing\Acquiring\Gateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Подстраховка уведомлений: незавершённые попытки последних суток перечитываются у провайдера.
 * Уведомление потерялось или сервер был недоступен — оплата всё равно ляжет в счёт в пределах пяти минут.
 */
final class SyncAcquiring extends Command
{
    protected $signature = 'acquiring:sync {--hours=24}';

    protected $description = 'Сверяет незавершённые оплаты по ссылкам с ЮKassa';

    public function handle(Gateway $gateway, SettleAcquiring $settle): int
    {
        if (! $gateway->configured()) {
            return self::SUCCESS;
        }
        $pending = AcquiringPayment::whereIn('status', ['pending', 'waiting_for_capture'])
            ->where('created_at', '>', now()->subHours((int) $this->option('hours')))->orderBy('id')->get();
        foreach ($pending as $attempt) {
            try {
                $settle($attempt);
            } catch (Throwable $e) {
                Log::warning('acquiring: '.$attempt->external_id.' — '.$e->getMessage());
            }
        }
        // Старше суток ЮKassa их уже отменила: не спрашиваем, чтобы список не рос.
        AcquiringPayment::whereIn('status', ['pending'])->where('created_at', '<=', now()->subHours((int) $this->option('hours')))->update(['status' => 'canceled']);
        $this->info('Проверено: '.$pending->count());

        return self::SUCCESS;
    }
}
