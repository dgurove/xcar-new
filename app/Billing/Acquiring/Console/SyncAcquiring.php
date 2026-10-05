<?php

namespace App\Billing\Acquiring\Console;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\Actions\SettleAcquiring;
use App\Billing\Acquiring\Actions\SettleRefund;
use App\Billing\Acquiring\Gateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Подстраховка уведомлений: всё незавершённое перечитывается у провайдера — уведомление потерялось или сервер лежал,
 * оплата всё равно ляжет в счёт в пределах пяти минут. Отменой попытку помечает только ответ провайдера, не возраст:
 * брошенные ЮKassa отменяет сама за час-другой, и список не растёт. Старше суток — раз в час, чтобы не долбить API.
 * Заодно дочитываются чек успешных (пока не пробит, трое суток) и возвраты «в обработке».
 */
final class SyncAcquiring extends Command
{
    protected $signature = 'acquiring:sync';

    protected $description = 'Сверяет незавершённые оплаты по ссылкам с ЮKassa';

    public function handle(Gateway $gateway, SettleAcquiring $settle, SettleRefund $refund): int
    {
        if (! $gateway->configured()) {
            return self::SUCCESS;
        }
        $due = fn ($q) => $q->where('created_at', '>', now()->subDay())->orWhereNull('checked_at')->orWhere('checked_at', '<', now()->subHour());
        $open = AcquiringPayment::whereIn('status', ['pending', 'waiting_for_capture'])->where($due)->orderBy('id')->get();
        $receipts = AcquiringPayment::where('status', 'succeeded')->where('receipt_status', 'pending')->where('created_at', '>', now()->subDays(3))->orderBy('id')->get();
        foreach ($open->concat($receipts) as $attempt) {
            try {
                $settle($attempt);
            } catch (Throwable $e) {
                Log::warning('acquiring: '.$attempt->external_id.' — '.$e->getMessage());
            }
        }
        foreach (AcquiringPayment::where('refund_status', 'pending')->whereNotNull('refund_id')->get() as $attempt) {
            try {
                $refund($gateway->fetchRefund($attempt->refund_id));
            } catch (Throwable $e) {
                Log::warning('acquiring: возврат '.$attempt->refund_id.' — '.$e->getMessage());
            }
        }
        $this->info('Проверено: '.$open->count().', чеков: '.$receipts->count());

        return self::SUCCESS;
    }
}
