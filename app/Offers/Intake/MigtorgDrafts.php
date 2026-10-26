<?php

namespace App\Offers\Intake;

use App\Cars\CarText;
use App\Cars\IdentityTaken;
use App\Mail\Extraction\CodeMatcher;
use App\Offers\Jobs\ImportMigtorgLot;
use App\Offers\Migtorg;
use App\Vendors\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Новый лот Мигторга — черновик сам (26.10.2026, владелец: модераторы открывали все лоты вкладками и заводили Альфу и
 * Т-Страхование по номеру руками). Шаг `migtorg:sync` после индекса: живой лот с номером дела, по которому предложения нет
 * ни в каком состоянии (архив и отмена — решение человека), а вендор по виду номера (`CarText::vendorOf`) — с галкой
 * «Черновики с Мигторга» (`vendors.drafts_from_migtorg`). Заводит дверь `OpenDraft` (`migtorg`, без автора), поля — из
 * индекса, кадры — `ImportMigtorgLot` (карточка со входом только днём). Лот, по которому заводили, отмечен `drafted_at`:
 * удалённый человеком черновик не возвращается.
 *
 * Аккаунт: новых лотов мы не ищем по сайту — их даёт открытый список; вход тратится на одну карточку на машину, и не
 * больше `PER_RUN` машин за прогон (первый прогон с хвостом расходится за несколько прогонов).
 */
final class MigtorgDrafts
{
    public const PER_RUN = 10;

    /** @return int сколько заведено */
    public static function run(): int
    {
        $vendors = Vendor::where('drafts_from_migtorg', true)->pluck('id')->all();
        if (! $vendors || ! Migtorg::ready()) {
            return 0;
        }
        $lots = DB::table('migtorg_lots as l')->whereNull('l.gone_at')->whereNull('l.drafted_at')->whereNotNull('l.claim_ref_key')
            ->whereNotExists(fn ($q) => $q->from('cars')->join('offers', 'offers.car_id', '=', 'cars.id')->whereColumn('cars.claim_ref_key', 'l.claim_ref_key'))
            ->orderBy('l.id')->get();
        // Вендор по виду номера: запрос на вид, а не на лот — видов десятки, лотов сотни.
        $byPattern = [];
        $made = 0;
        foreach ($lots as $lot) {
            $pattern = CodeMatcher::patternOf($lot->claim_ref);
            if ($pattern === null) {
                continue;
            }
            $vendor = $byPattern[$pattern] ??= Vendor::forCrm(CarText::vendorOf($lot->claim_ref));
            if (! in_array($vendor, $vendors, true)) {
                continue;
            }
            if ($made >= self::PER_RUN) {
                break;
            }
            DB::table('migtorg_lots')->where('id', $lot->id)->update(['drafted_at' => now()]);
            try {
                [$offer, $outcome] = app(OpenDraft::class)(new DraftSource('migtorg', $lot->claim_ref, fields: ['vendor_id' => $vendor],
                    created: ['migtorg' => $lot->id], why: 'Новый лот Мигторга'), null);
            } catch (IdentityTaken) {
                continue;
            } catch (Throwable $e) {
                Log::warning("Мигторг: черновик по лоту {$lot->id} не заведён — {$e->getMessage()}");

                continue;
            }
            if ($outcome === OpenDraft::CREATED) {
                ImportMigtorgLot::start($offer->refresh(), $lot);
                $made++;
            }
        }

        return $made;
    }
}
