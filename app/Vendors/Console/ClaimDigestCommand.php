<?php

namespace App\Vendors\Console;

use App\Vendors\Actions\MailClaimDigest;
use Illuminate\Console\Command;

/** Номера «К запросу» письмом в слоты `xcar.claim_digest.at` (`MailClaimDigest::tick`), каждые 5 минут — один раз на слот. */
final class ClaimDigestCommand extends Command
{
    protected $signature = 'claims:digest';

    protected $description = 'Номера «К запросу» вендоров письмом вендору в 9:00 и 18:00, повтор неушедших';

    public function handle(MailClaimDigest $digest): int
    {
        $n = $digest->tick();
        $n && $this->info("Писем: {$n}");
        // Письмо запроса не ушло — снова в очередь (до трёх попыток).
        $r = $digest->retry();
        $r && $this->info("Снова в очереди: {$r}");

        return self::SUCCESS;
    }
}
