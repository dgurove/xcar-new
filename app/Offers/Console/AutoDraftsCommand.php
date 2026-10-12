<?php

namespace App\Offers\Console;

use App\Offers\Actions\AutoOffer;
use Illuminate\Console\Command;

/**
 * Хвост «Из писем» CRM, что пришёл до того, как предложения стали заводиться сами (`AutoOffer`, 12.10.2026). Без
 * `--apply` только показывает: куда встанет черновик («Из таблицы Кати», «Без продажной», «Без закупочной») или почему
 * останется человеку. `--pace` — пауза между черновиками: у тех, кто смотрит список, строки входят по одной.
 */
class AutoDraftsCommand extends Command
{
    protected $signature = 'offers:auto-drafts {--apply : завести, а не только показать} {--old : и по письмам старше недели} {--pace=2 : секунд между черновиками}';

    protected $description = 'Завести черновики предложений по ждущим цепочкам «Из писем»';

    private const TABS = ['legacy' => 'Из таблицы Кати', 'unpriced' => 'Без продажной цены', 'nofloor' => 'Без закупочной цены'];

    public function handle(AutoOffer $auto): int
    {
        $apply = (bool) $this->option('apply');
        $old = (bool) $this->option('old');
        $pace = max(0, (int) $this->option('pace'));
        $tabs = [];
        $skipped = 0;
        foreach ($auto->waiting() as $candidate) {
            $candidate->load('messages');
            if ($why = $auto->blocker($candidate, $old)) {
                $skipped++;
                $this->line("— {$candidate->id} {$candidate->code} {$candidate->title()}: {$why}");

                continue;
            }
            $tab = $auto->tab($candidate);
            $offer = $apply ? $auto($candidate, $old) : null;
            if ($apply && ! $offer) {
                $skipped++;
                $this->line("— {$candidate->id} {$candidate->code} {$candidate->title()}: письма к уже заведённому или не завелось");

                continue;
            }
            $tabs[$tab] = ($tabs[$tab] ?? 0) + 1;
            $this->line(self::TABS[$tab].': '.($offer ? "№ {$offer->number} " : '')."{$candidate->code} {$candidate->title()}");
            if ($offer && $pace) {
                sleep($pace);
            }
        }
        $summary = collect($tabs)->map(fn ($n, $t) => self::TABS[$t].' '.$n)->implode(', ') ?: 'ничего';
        $this->info(($apply ? 'заведено: ' : 'заведётся: ').$summary.", остаётся людям {$skipped}".($apply ? '' : '; с --apply заведёт'));

        return self::SUCCESS;
    }
}
