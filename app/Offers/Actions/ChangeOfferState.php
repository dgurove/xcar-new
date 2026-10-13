<?php

namespace App\Offers\Actions;

use App\Garage\Actions\CloseGarageDeal;
use App\Garage\Car as GarageCar;
use App\Offers\BidState;
use App\Offers\DealAudience;
use App\Offers\DealState;
use App\Offers\Events\OfferPublished;
use App\Offers\Events\OfferStateChanged;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Offers\Slots;
use App\Users\User;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Requirement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Единственная дверь для смены состояния оффера: проверка перехода, метки
 * времени, сделка, лента, событие. Если оффер идёт по маршруту и с текущего
 * этапа есть наш исход на этап с таким состоянием — маршрут догоняет кнопку.
 * `at` — публикация слотом (часы, `PublishDueSlots`): выход и срок приёма считаются от слота, а не от минуты тика.
 * `batch` — «сейчас» пачкой из «Оцененных»: уведомление, как у слота, — одно на человека тиком часов.
 */
final class ChangeOfferState
{
    public function __invoke(Offer $offer, OfferState $next, ?User $by, bool $followRoute = true, ?Carbon $at = null, bool $batch = false): Offer
    {
        return DB::transaction(function () use ($offer, $next, $by, $followRoute, $at, $batch) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            // Слот могли убрать или перенести в последнюю секунду — тогда часы опоздали, публиковать нечего.
            if ($at && ! $offer->slot_at?->equalTo($at)) {
                return $offer;
            }
            $from = $offer->state;
            // Гаражная сделка кончается не выдачей покупателю, а гаражом: конец маршрута («Сделка закрыта») ставит
            // машину менеджеру в гараж. Одна дверь — здесь, маршруты и их конечные этапы о гараже не знают.
            if ($next === OfferState::Delivered && $offer->deal()->first()?->isGarage()) {
                $next = OfferState::Garage;
            }

            if ($from === $next) {
                return $offer;
            }
            if (! $from->allows($next)) {
                throw ValidationException::withMessages(['state' => "Из «{$from->label()}» нельзя в «{$next->label()}»"]);
            }
            // Машину из гаража уводит только «Отдали по ошибке»: иначе строка гаража с расходами осталась бы без хозяина.
            if ($from === OfferState::Garage && GarageCar::where('offer_id', $offer->id)->exists()) {
                throw ValidationException::withMessages(['state' => 'ТС в гараже: сначала «Отдали по ошибке»']);
            }
            if ($next === OfferState::Open) {
                self::assertReady($offer);
                // Номер — до даты выхода: `issue` выдаёт его только тому, кто ещё не выходил наружу.
                OfferNumber::issue($offer);
                if ($at) {
                    // Слотом — ровно в 16:00: от этого времени считаются волны показа и срок приёма.
                    $offer->published_at = $at;
                    $offer->bids_close_at = Slots::closeFor($at);
                } else {
                    // Волны показа — от этого выхода в продажу (13.10.2026): вернули из черновика или сделки — «остальным
                    // через 5 дней» считаются заново, а не от первой публикации, когда всё давно «наступило».
                    $offer->published_at = now();
                    if (! $offer->bids_close_at || $offer->bids_close_at->isPast()) {
                        // Срок приёма по умолчанию — через 3 дня в 17:00 (владелец 04.10.2026), а не текущая минута.
                        $offer->bids_close_at = Slots::closeFor(now());
                    }
                }
            }
            // «Вышло слотом» — только у публикации слотом; любой другой переход слот снимает, иначе возврат в продажу
            // (менеджер отказался) молча пропустил бы рассылку, а снятый в черновик вышел бы сам.
            if (! $at) {
                $offer->slot_at = null;
                $offer->slot_by = null;
            }
            if ($next === OfferState::Gallery) {
                self::readyForGallery($offer);
                OfferNumber::issue($offer);
                $offer->published_at ??= now();
            }

            // «На аукцион»: ушло из продажи — план снят (`SendToAuction`); черновик ушёл дальше — и с аукциона тоже.
            if ($from === OfferState::Open && $next !== OfferState::Open) {
                $offer->auction_planned_at = null;
                // «Срочно» — на этот выход в продажу (13.10.2026): вернулось потом — волны снова свои, а не всем сразу.
                $offer->urgent_at = null;
                $offer->urgent_by = null;
            }
            if ($from === OfferState::Draft && $next !== OfferState::Draft) {
                $offer->auction_at = null;
                // «Предложено из заявок на приём»: опубликовали или убрали, не нажав «Принять», — решение принято, плашка
                // не вернётся, когда черновик снова снимут с продажи.
                $offer->proposed_at = null;
            }
            $offer->state = $next;
            // Черновик ушёл дальше (в продажу, сделку, гараж) — окно галереи закрывается сейчас, как «Убрать из
            // галереи» (`ShowInGallery::hide`): из галереи ушло, интерес остался — в «Откликах» CRM и рядом с
            // подтверждениями. Начало окна хранится — по нему «Отклики» находят побывавшие в галерее.
            $galleryClosed = $from === OfferState::Draft && $next !== OfferState::Draft && $offer->gallery_until?->isFuture();
            if ($galleryClosed) {
                $offer->gallery_until = now();
            }
            $offer->save();
            // Ушли из сделки куда угодно (продажа, черновик, архив) — белый список «Кому показывать в сделке»
            // стирается: дальше работают обычные волны. «Отдать» другому сделку не трогает — состояние то же.
            if ($from === OfferState::Sold && $next !== OfferState::Sold) {
                DealAudience::clear($offer);
            }
            // Кто видит — до событий: уведомление о публикации берёт наступившие волны.
            app(SyncViewers::class)($offer);
            $offer->log(OfferEventType::StateChanged, $by, ['from' => $from->value, 'to' => $next->value]);
            if ($galleryClosed) {
                $offer->log(OfferEventType::Gallery, $by);
            }
            // Черновик ушёл дальше — просьбы оценить его снимаются (11.10.2026).
            if ($from === OfferState::Draft && $next !== OfferState::Draft) {
                app(CloseEstimates::class)($offer, $by);
            }
            $this->settleDeal($offer, $next, $by);

            OfferStateChanged::dispatch($offer, $by);
            if ($next === OfferState::Open) {
                OfferPublished::dispatch($offer, $by, $at !== null || $batch);
            }

            $stage = $offer->stage();
            if ($followRoute && ($exit = $stage?->exitInto($next, $offer->deal()->first()))) {
                $offer = app(EnterStage::class)($offer, $exit->to, $by, [], $exit);
            } elseif ($followRoute && $stage && $from === OfferState::Sold && in_array($next, [OfferState::Open, OfferState::Draft], true)) {
                // Сделку отменили руками: у этапа сделки выхода в продажу или черновик нет — маршрут встаёт на первый этап
                // этого состояния (черновику — на начало), иначе он висел бы на шаге отменённой сделки.
                $stages = $stage->workflow->stages()->with(['exits', 'block'])->get();
                $to = $stages->first(fn ($s) => $s->offer_state === $next) ?? ($next === OfferState::Draft ? $stages->first() : null);
                if ($to && $to->id !== $stage->id) {
                    $offer = app(EnterStage::class)($offer, $to, $by);
                }
            }

            return $offer;
        });
    }

    /** В галерее без цены, но с чем показать: марка, модель, фото. */
    public static function readyForGallery(Offer $offer): void
    {
        $missing = array_keys(array_filter([
            'марка' => ! $offer->brand_id,
            'модель' => ! $offer->model_id,
            'фотографии' => $offer->visiblePhotos()->isEmpty(),
        ]));
        if ($missing) {
            throw ValidationException::withMessages(['state' => 'Для галереи не хватает: '.implode(', ', $missing)]);
        }
    }

    /** Готово ли к продаже: марка, цена продажи, фото. Зовёт и постановка в слот — чтобы не выяснять это в 16:00. */
    public static function assertReady(Offer $offer): void
    {
        if ($missing = self::missing($offer)) {
            throw ValidationException::withMessages(['state' => 'Для публикации не хватает: '.implode(', ', $missing)]);
        }
    }

    /**
     * Чего не хватает для публикации — и для ошибки, и для строки «Оцененных» («нет фото» вместо галочки).
     *
     * @return list<string>
     */
    public static function missing(Offer $offer): array
    {
        return array_keys(array_filter([
            'марка' => ! $offer->brand_id,
            'цена продажи' => ! $offer->asking_price,
            'фотографии' => $offer->visiblePhotos()->isEmpty(),
        ]));
    }

    /**
     * Сделка живёт, пока оффер в сделке: выдан (гаражная — в гараже) — завершена, всё остальное — сорвалась. Резерв (остальные живые
     * подтверждения) ждёт до выдачи или архива и закрывается тихо, без уведомлений: снятие с продажи и черновик
     * его не трогают — к подтвердившим возвращаются, если выбранный передумал.
     */
    private function settleDeal(Offer $offer, OfferState $next, ?User $by = null): void
    {
        $deal = $offer->deal()->first();
        $garage = $next === OfferState::Garage && $deal?->isGarage();
        if ($garage || in_array($next, [OfferState::Delivered, OfferState::Archived], true)) {
            $offer->bids()->where('state', BidState::Active)->update(['state' => BidState::Declined, 'decided_at' => now(), 'decided_by' => $by?->id]);
        }
        if (! $deal || in_array($next, [OfferState::Sold], true)) {
            return;
        }
        if ($next === OfferState::Delivered || $garage) {
            $deal->update(['state' => DealState::Done, 'closed_at' => now()]);
            Requirement::where('deal_id', $deal->id)->whereNull('done_at')->update(['done_at' => now(), 'answer' => json_encode(['closed_by' => 'deal'])]);
            if ($garage) {
                app(CloseGarageDeal::class)($deal, $by);
            }
        } else {
            app(CancelDeal::class)($deal, $by);
        }
        $offer->unsetRelation('deal');
    }
}
