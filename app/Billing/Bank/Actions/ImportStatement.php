<?php

namespace App\Billing\Bank\Actions;

use App\Billing\Bank\Connection;
use App\Billing\Bank\SberApi;
use App\Billing\Bank\Transaction;
use App\Support\Nav;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Выписка за день → строки операций. Уже виденные (по id банка) не трогаются — и когда две загрузки
 * идут разом (расписание и «Загрузить сейчас»): вставка `createOrFirst` по уникальному id. Новые входящие
 * сразу пробуются в счёт. Возвращает новые непривязанные — о них скажут сотрудникам. null — банк ещё
 * готовит выписку. Сбой записывается в подключение и летит дальше.
 */
final class ImportStatement
{
    public function __construct(private SberApi $api, private MatchTransaction $match) {}

    public function __invoke(Connection $connection, Carbon $day): ?Collection
    {
        try {
            $rows = $this->api->transactions($connection, $day);
        } catch (Throwable $e) {
            $connection->update(['last_error' => mb_substr($e->getMessage(), 0, 500), 'failed_at' => $connection->failed_at ?? now()]);

            throw $e;
        }
        if ($rows === null) {
            return null;
        }
        $fresh = collect();
        foreach ($rows as $row) {
            $id = (string) ($row['uuid'] ?? $row['operationId'] ?? '');
            if ($id === '') {
                continue;
            }
            $in = ($row['direction'] ?? '') === 'CREDIT';
            $t = $row['rurTransfer'] ?? [];
            $tx = Transaction::createOrFirst(['external_id' => $id], [
                'account' => $connection->account,
                'booked_at' => Carbon::parse($row['operationDate'] ?? $row['documentDate'] ?? $day)->toDateString(),
                'direction' => $in ? 'in' : 'out', 'amount' => (float) data_get($row, 'amountRub.amount', data_get($row, 'amount.amount', 0)),
                'counterparty' => $in ? ($t['payerName'] ?? null) : ($t['payeeName'] ?? null),
                'counterparty_inn' => $in ? ($t['payerInn'] ?? null) : ($t['payeeInn'] ?? null),
                'counterparty_account' => $in ? ($t['payerAccount'] ?? null) : ($t['payeeAccount'] ?? null),
                'counterparty_bank' => $in ? ($t['payerBankName'] ?? null) : ($t['payeeBankName'] ?? null),
                'doc_number' => $row['number'] ?? null, 'purpose' => $row['paymentPurpose'] ?? null,
                'state' => $in ? Transaction::UNMATCHED : Transaction::OUTGOING, 'payload' => $row,
            ]);
            if (! $tx->wasRecentlyCreated) {
                continue;
            }
            if ($in) {
                $tx = ($this->match)($tx);
                if ($tx->state === Transaction::UNMATCHED) {
                    $fresh->push($tx);
                }
            }
        }
        $connection->update(['synced_at' => now(), 'last_error' => null, 'failed_at' => null]);
        Nav::forgetStaffCounts();

        return $fresh;
    }
}
