<?php

namespace App\Offers\Console;

use App\Http\Admin\OfferController;
use App\Mail\Candidate;
use App\Mail\Thread;
use App\Offers\Actions\DeleteDraft;
use App\Offers\Favorite;
use App\Offers\Interest;
use App\Offers\Migtorg;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Разовая чистка (30.10.2026, владелец: «ПВУ это не наше», «с Минторга только с раздела каско, остальное мусор»): черновики,
 * что `MigtorgDrafts` сам завёл по лотам ОСАГО за 26–30.10; на проде прошла 30.10.2026 — удалено 412. Удаляется только
 * нетронутое во вкладке «Без закупочной цены» (`Offer::crmTab`): без интереса, избранного, писем, правок и заметок людей
 * (`DeleteDraft`, машина и кадры уходят с ним). Раздел лота — из индекса, у ушедших — по открытой карточке Мигторга; не
 * узнали или Мигторг нас придержал — не трогаем. Без `--apply` только показывает и ничего не пишет. Повтор безопасен:
 * черновики КАСКО и тронутые людьми он не трогает.
 */
class MigtorgDropOsago extends Command
{
    protected $signature = 'migtorg:drop-osago {--apply : удалить, а не только показать}';

    protected $description = 'Разово: удалить нетронутые черновики, заведённые Мигторгом по лотам ОСАГО (повтор безопасен)';

    public function handle(Migtorg $migtorg, DeleteDraft $delete): int
    {
        $apply = (bool) $this->option('apply');
        $admin = User::withRole(Role::Admin)->orderBy('id')->firstOrFail();
        $kept = [];
        $dropped = 0;
        foreach (Offer::where('created_via', 'migtorg')->orderBy('id')->get() as $offer) {
            $why = $this->busy($offer, $admin);
            $lot = $why ? null : DB::table('migtorg_lots')->where('claim_ref_key', $offer->claim_ref_key)->orderByDesc('id')->first();
            $section = $lot ? ($lot->section ?? $this->sectionOf($migtorg, $lot->id, $apply)) : null;
            $why ??= match ($section) {
                'osago' => null,
                'casco' => 'КАСКО',
                default => 'раздел не узнали',
            };
            if (! $why && $apply) {
                try {
                    $delete($offer, $admin);
                } catch (Throwable $e) {
                    $why = 'не удалился';
                    $this->error("№ {$offer->number}: {$e->getMessage()}");
                }
            }
            if ($why) {
                $kept[$why] = ($kept[$why] ?? 0) + 1;
                $why !== 'КАСКО' && $this->line("— № {$offer->number} {$offer->claim_ref} {$offer->title()}: {$why}");

                continue;
            }
            $dropped++;
            $this->line("ОСАГО: № {$offer->number} {$offer->claim_ref} {$offer->title()}");
        }
        $rest = collect($kept)->map(fn ($n, $why) => "{$why} {$n}")->implode(', ') ?: 'ничего';
        $this->info(($apply ? 'удалено: ' : 'удалится: ')."{$dropped}; остаются: {$rest}".($apply ? '' : '; с --apply удалит'));

        return self::SUCCESS;
    }

    /** Почему черновик не трогаем: не во вкладке «Без закупочной цены» или с ним уже работали. */
    private function busy(Offer $offer, User $admin): ?string
    {
        return match (true) {
            $offer->crmTab($admin) !== OfferController::PRESETS['nofloor'] => 'не в «Без закупочной цены»',
            ! $offer->isDeletableBy($admin) => 'есть подтверждения, сделка или закупка',
            Interest::where('offer_id', $offer->id)->exists() || Favorite::where('offer_id', $offer->id)->exists() => 'интерес или избранное',
            Thread::where('offer_id', $offer->id)->exists() || Candidate::where('offer_id', $offer->id)->exists() => 'есть письма',
            DB::table('car_events')->where('car_id', $offer->car_id)->whereNotNull('user_id')->exists() => 'правили люди',
            default => null,
        };
    }

    /** Раздел ушедшего лота по карточке; Мигторг нас придержал — не спрашиваем дальше (пауза общая с синхронизацией). */
    private function sectionOf(Migtorg $migtorg, int $lot, bool $apply): ?string
    {
        if (Migtorg::paused()) {
            return null;
        }
        try {
            $section = $migtorg->section($lot);
        } catch (Throwable $e) {
            $this->warn("Лот {$lot}: {$e->getMessage()}");

            return null;
        }
        $apply && $section && DB::table('migtorg_lots')->where('id', $lot)->update(['section' => $section]);

        return $section;
    }
}
