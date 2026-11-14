<?php

namespace App\Vendors\Console;

use App\Vendors\Actions\MailClaimDigest;
use App\Vendors\ClaimRequest;
use Illuminate\Console\Command;

/**
 * Уже сделанные запросы номеров — одним письмом «Согласование реализации» вендору (14.11.2026: запросы 26 и 27 ушли Андрею, а
 * Т-Страхованию — нет). Номера уже помечены, повтор и Telegram — как у письма по расписанию.
 */
final class MailClaimRequestsCommand extends Command
{
    protected $signature = 'claims:mail-requests {ids* : номера запросов}';

    protected $description = 'Запросы номеров одним письмом вендору';

    public function handle(MailClaimDigest $digest): int
    {
        $requests = ClaimRequest::with('vendor')->whereIn('id', $this->argument('ids'))->orderBy('id')->get();
        if ($requests->isEmpty() || $requests->pluck('vendor_id')->unique()->count() > 1) {
            $this->error('Нет запросов или они разных вендоров');

            return self::FAILURE;
        }
        $refs = array_unique(array_merge(...$requests->map(fn ($r) => (array) $r->refs)->all()));
        $this->info($requests->first()->vendor->name.': '.count($refs).' номеров — '.($digest->mail($requests->all()) ? 'в очереди' : 'не ушло, номера в Telegram'));

        return self::SUCCESS;
    }
}
