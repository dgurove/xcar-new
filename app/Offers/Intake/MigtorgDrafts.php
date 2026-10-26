<?php

namespace App\Offers\Intake;

use App\Cars\Actions\FillCar;
use App\Cars\CarText;
use App\Cars\Identity;
use App\Cars\IdentityTaken;
use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\CodeMatcher;
use App\Offers\Jobs\ImportMigtorgLot;
use App\Offers\Migtorg;
use App\Vendors\Vendor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Новый лот Мигторга — черновик сам (26.10.2026, владелец: модераторы открывали все лоты вкладками и заводили Альфу и
 * Т-Страхование по номеру руками). Шаг `migtorg:sync` после индекса: живой лот с номером дела, по которому предложения нет
 * ни в каком состоянии (архив и отмена — решение человека), а вендор по виду номера (`CarText::vendorOf`) — с галкой
 * «Черновики с Мигторга» (`vendors.drafts_from_migtorg`). Заводит дверь `OpenDraft` (`migtorg`, без автора), поля — из
 * индекса, кадры — `ImportMigtorgLot`; входа на Мигторг нет — черновик с полями, кадры возьмёт `migtorg:sync`, когда вход
 * вернётся (`ImportMigtorgLot::auto`). Лот, по которому заводили, отмечен `drafted_at`: удалённый человеком черновик не
 * возвращается. Заведено — всплывашка сотрудникам CRM (событие `migtorg` темы `staff`, `x-migtorg.island`).
 *
 * Аккаунт: новых лотов мы не ищем по сайту — их даёт открытый список; вход тратится на одну карточку на машину, задачи
 * кадров идут по одной (очередь `migtorg`).
 */
final class MigtorgDrafts
{
    /** Потолок прогона: хвост в сотню лотов расходится за несколько прогонов, прогон не тянется минутами. */
    public const PER_RUN = 30;

    /** @return int сколько заведено */
    public static function run(): int
    {
        $vendors = Vendor::where('drafts_from_migtorg', true)->pluck('id')->all();
        if (! $vendors) {
            return 0;
        }
        $lots = DB::table('migtorg_lots as l')->whereNull('l.gone_at')->whereNull('l.drafted_at')->whereNotNull('l.claim_ref_key')
            ->whereNotExists(fn ($q) => $q->from('cars')->join('offers', 'offers.car_id', '=', 'cars.id')->whereColumn('cars.claim_ref_key', 'l.claim_ref_key'))
            ->orderBy('l.id')->get();
        $ready = Migtorg::ready();
        // Т-Страхование: тот же убыток с другим годом в номере (AUT-25 в CRM, AUT-26 на Мигторге) — та же машина, второй не
        // заводим; сверит человек («Это она»).
        $anyYear = Identity::offersAnyYear($lots->pluck('claim_ref_key')->all());
        $made = [];
        foreach ($lots as $lot) {
            $vendor = self::vendorOf($lot->claim_ref);
            if (! in_array($vendor, $vendors, true)) {
                continue;
            }
            if (isset($anyYear[$lot->claim_ref_key])) {
                self::mark($lot->id);

                continue;
            }
            if (count($made) >= self::PER_RUN) {
                break;
            }
            try {
                [$offer, $outcome] = app(OpenDraft::class)(new DraftSource('migtorg', $lot->claim_ref, fields: ['vendor_id' => $vendor],
                    created: ['migtorg' => $lot->id], why: 'Новый лот Мигторга'), null);
            } catch (IdentityTaken) {
                // Номер или VIN — у другой машины: решать человеку, сам больше не пробуем.
                self::mark($lot->id);

                continue;
            } catch (Throwable $e) {
                // Замок занят, сбой базы — попробуем следующим прогоном.
                Log::warning("Мигторг: черновик по лоту {$lot->id} не заведён — {$e->getMessage()}");

                continue;
            }
            self::mark($lot->id);
            if ($outcome !== OpenDraft::CREATED) {
                continue;
            }
            $offer->refresh();
            $ready ? ImportMigtorgLot::start($offer, $lot) : app(FillCar::class)($offer, ImportMigtorgLot::fieldsOf($lot), null, FillCar::EMPTY, log: ['source' => 'migtorg', 'lot' => $lot->id]);
            $made[] = $offer->refresh()->title() ?: $lot->title;
        }
        if ($made) {
            app(Publisher::class)(Topics::STAFF, 'migtorg', ['count' => count($made), 'cars' => array_slice($made, 0, 3)]);
        }

        return count($made);
    }

    /** CRM-вендор номера по виду: вид — от записи, как её читает разбор (`Code::normalize`), ответ держится час. */
    private static function vendorOf(string $ref): ?int
    {
        $pattern = CodeMatcher::patternOf((string) Code::normalize($ref));
        if ($pattern === null) {
            return null;
        }
        $id = Cache::remember('migtorg:vendor-of:'.md5($pattern), 3600, fn () => Vendor::forCrm(CarText::vendorOf((string) Code::normalize($ref))) ?? 0);

        return $id ?: null;
    }

    private static function mark(int $lot): void
    {
        DB::table('migtorg_lots')->where('id', $lot)->update(['drafted_at' => now()]);
    }
}
