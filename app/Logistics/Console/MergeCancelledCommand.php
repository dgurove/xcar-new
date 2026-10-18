<?php

namespace App\Logistics\Console;

use App\Logistics\Transport;
use App\Logistics\TransportEvent;
use App\Logistics\TransportState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разово (18.10.2026, 483511/2024): исполнителя меняли отменой вывоза и новой задачей — по машине копились отменённые.
 * Теперь меняют в той же задаче, а «Вывезти» после отмены открывает прежнюю (`OpenPickup`). Отменённые незабранные задачи
 * машины, у которой есть другая (живая или доставленная) того же вида, вливаются в неё историей и кадрами и удаляются.
 */
final class MergeCancelledCommand extends Command
{
    protected $signature = 'logistics:merge-cancelled {--apply : слить, без него — только список}';

    protected $description = 'Слить отменённые задачи вывоза в живую задачу той же машины';

    public function handle(): int
    {
        $cancelled = Transport::where('state', TransportState::Cancelled)->whereNull('picked_at')->whereNull('invoice_id')->orderBy('id')->get();
        $n = 0;
        foreach ($cancelled as $c) {
            $into = Transport::where('car_id', $c->car_id)->where('origin', $c->origin)->where('kind', $c->kind)
                ->where('state', '!=', TransportState::Cancelled)->whereKeyNot($c->id)->latest('id')->first();
            if (! $into) {
                continue;
            }
            $this->line("{$c->id} → {$into->id}: {$into->title()}");
            $n++;
            if (! $this->option('apply')) {
                continue;
            }
            DB::transaction(function () use ($c, $into) {
                TransportEvent::where('transport_id', $c->id)->update(['transport_id' => $into->id]);
                foreach ($c->photos() as $media) {
                    $media->setCustomProperty('transport', $into->id)->save();
                }
                $c->delete();
            });
        }
        $this->info(($this->option('apply') ? 'Слито: ' : 'Будет слито: ').$n);

        return self::SUCCESS;
    }
}
