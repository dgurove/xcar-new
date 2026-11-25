<?php

namespace App\Offers\Console;

use App\Offers\Deal;
use Illuminate\Console\Command;

/**
 * «Для менеджера сделка закрыта» по идущим сделкам (25.11.2026, `Deal::syncManagerDone`): отметка ставится сама на входе в шаг и при
 * оплате, а сделки, что уже стоят на наших шагах (891799/2026 на «Оплата поставщику»), — этой командой. Без `--apply` — список.
 */
class ManagerDoneCommand extends Command
{
    protected $signature = 'deals:manager-done {--apply}';

    protected $description = 'Идущие сделки, где у менеджера ничего не осталось: отметить закрытыми для него';

    public function handle(): int
    {
        $n = 0;
        foreach (Deal::active()->whereNotNull('buyer_id')->with(['offer', 'buyer'])->orderBy('id')->get() as $deal) {
            $done = $deal->doneForManager();
            if ($done === ($deal->manager_done_at !== null)) {
                continue;
            }
            $n++;
            $this->line(($done ? '+ ' : '- ').$deal->offer?->number.' '.$deal->offer?->title().' — '.$deal->buyer?->name.', «'.$deal->offer?->stage()?->name.'»');
            $this->option('apply') && $deal->syncManagerDone();
        }
        $this->info(($this->option('apply') ? 'Отмечено: ' : 'Будет: ').$n);

        return self::SUCCESS;
    }
}
