<?php

namespace App\Offers\Actions;

use App\Offers\AudienceRules;
use App\Offers\Binding;
use App\Offers\Exclusive;
use App\Offers\Jobs\RetractOfferNotices;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Offers\OfferViewer;
use App\Support\Ago;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Публикация машины в сделке для других менеджеров (12.11.2026, владелец: показ менеджерам, волны и «Опубликовать для других»
 * — «по сути одно и то же», а где-то не хватало срока и «всем, кроме»; прежде — «показ всем», пока менеджер сделки думает о
 * закупочной, 04.11.2026, и белый список сделки, 12.10.2026). Машина остаётся в сделке (и гаражная, пока не привезли); кому —
 * те же правила «кто → когда», что волны предложения (`deal_audience_rules`, пусто — всем сразу), от `deal_published_at`;
 * до когда — `deal_show_until`, срок приёма для них. Кто когда видит — `offer_viewers` (`SyncViewers`), «Новое предложение» —
 * с волной (`NotifyViewers`), подтверждение встаёт резервом (`PlaceBid` в `Sold`). Менеджер сделки и супер-покупатели её не
 * видят. Включают шторка «Опубликовать для других менеджеров» (`x-offer.deal-publish-sheet`) и «Спросить» в переоценке; снимают
 * «Снять публикацию», «Согласен» (`AnswerReprice`) и «Отдать» (`Give`).
 */
final class PublishDeal
{
    /** «Снять публикацию» везде одним вопросом: пункт «⋯», шторка публикации, шаг «Переоценка», вопрос по сделке. */
    public const STOP_CONFIRM = 'Снять публикацию для других менеджеров?';

    public const STOP_TEXT = 'У тех, кто уже подтвердил, машина останется';

    public function __construct(private SyncViewers $sync, private NotifyViewers $notify) {}

    /** Можно ли опубликовать: идущая сделка (и гаражная, пока машину не привезли), не аукцион, не демо. */
    public static function allows(Offer $offer): bool
    {
        return $offer->state === OfferState::Sold && $offer->deal?->isActive() && ! $offer->deal->isAuction() && ! $offer->is_demo;
    }

    /**
     * Опубликовать или поправить идущую публикацию: кому (правила) и до когда. Правка не переписывает момент публикации — волны
     * идут от прежнего; выпавших из круга `SyncViewers` убирает и отзывает их анонс.
     */
    public function __invoke(Offer $offer, mixed $rules, CarbonInterface $until, ?User $by, ?string $why = null): void
    {
        if (! self::allows($offer)) {
            return;
        }
        $rules = AudienceRules::normalize($rules ?: AudienceRules::everyone());
        // «Выбранным» и никого не выбрали — это не публикация: её никто не увидит, а шапка скажет «у других менеджеров».
        if (AudienceRules::nobody($rules)) {
            throw ValidationException::withMessages(['audience_rules' => 'Выберите, кому показывать']);
        }
        $running = $offer->showsDealAsOpen();
        $stored = AudienceRules::isEveryone($rules) ? null : $rules;
        // Открыли идущую и сохранили как было — ни пересчёта, ни строки в «Истории».
        if ($running && $offer->deal_audience_rules == $stored && $offer->deal_show_until?->equalTo($until)) {
            return;
        }
        $offer->forceFill(['deal_audience_rules' => $stored, 'deal_show_until' => $until] + ($running ? [] : ['deal_published_at' => now()]))->save();
        ($this->sync)($offer);
        if (! $running) {
            // Кому показывали в продаже или в прошлую публикацию — снова «не писали»: «Новое предложение» придёт с его волной.
            // Строки продажи исключённых (раньше публикации) — история показа, их не трогаем.
            OfferViewer::where('offer_id', $offer->id)->where('opens_at', '>=', $offer->deal_published_at)
                ->update(['notified_at' => null, 'closing_reminded_at' => null, 'last_reminded_at' => null]);
        }
        ($this->notify)($offer, live: true);
        $what = AudienceRules::summary($rules).', приём до '.Ago::at($until, true, words: false);
        $offer->log(OfferEventType::Updated, $by, ['show_all' => ($why ?? ($running ? 'Публикация для других менеджеров' : 'Опубликовано для других менеджеров')).': '.$what]);
    }

    /**
     * «Не снимать с продажи» (18.11.2026, начальник: отдать в сделку или в гараж, а остальные ещё 12 часов видят машину в
     * предложениях; «Опубликовать для других» после — новая публикация, всем «Новое предложение»). Продажа продолжается
     * публикацией сделки с теми же волнами от того же момента (`$show` — `saleShow` до «Отдать»): у видевших `opens_at` тот же,
     * новой в каталоге машина не становится, уведомлений нет — кому волна уже наступила, отмечены уведомлёнными (и прежний
     * покупатель при «Отдать другому»), будущие волны придут своим ходом. До `$until`, не позже срока обязывающего.
     *
     * @param  array{rules: ?array, since: CarbonInterface}  $show
     */
    public function keep(Offer $offer, array $show, CarbonInterface $until, ?User $by): void
    {
        if (! self::allows($offer)) {
            return;
        }
        $until = Binding::clampClose($offer, Carbon::instance($until));
        $offer->forceFill(['deal_audience_rules' => $show['rules'], 'deal_published_at' => $show['since'], 'deal_show_until' => $until])->save();
        ($this->sync)($offer);
        OfferViewer::where('offer_id', $offer->id)->whereNull('notified_at')->where('opens_at', '<=', now())->update(['notified_at' => now()]);
        $offer->log(OfferEventType::Updated, $by, ['show_all' => 'Не сняли с продажи: приём до '.Ago::at($until, true, words: false)]);
    }

    /**
     * Как машину показывают сейчас, до «Отдать» (`keep`): идёт публикация сделки — её волны, идёт приём — волны продажи
     * (срочное — всем сразу, как в `SyncViewers`). Приёма нет — `null`: продолжать нечего.
     *
     * @return ?array{rules: ?array, since: CarbonInterface}
     */
    public static function saleShow(Offer $offer): ?array
    {
        if ($offer->showsDealAsOpen()) {
            return ['rules' => $offer->deal_audience_rules, 'since' => $offer->deal_published_at];
        }
        if (! $offer->bidsOpen() || ! $offer->published_at || $offer->is_demo) {
            return null;
        }
        $rules = AudienceRules::of($offer);
        if ($offer->isUrgent() && Exclusive::running($offer)->isEmpty()) {
            $rules = array_map(fn ($r) => $r['delay'] === null ? $r : ['delay' => 0] + $r, $rules);
        }

        return ['rules' => AudienceRules::isEveryone($rules) ? null : $rules, 'since' => $offer->published_at];
    }

    /** Снять публикацию: машина уходит из предложений у всех, кроме тех, кто уже подтвердил (резерв держит страницу). */
    public function stop(Offer $offer, ?User $by, string $why = 'Публикация для других менеджеров снята'): void
    {
        if ($offer->deal_published_at === null) {
            return;
        }
        $offer->forceFill(['deal_published_at' => null, 'deal_show_until' => null])->save();
        $offer->log(OfferEventType::Updated, $by, ['show_all' => $why]);
        RetractOfferNotices::dispatch($offer->id);
    }

    /** Сделка кончилась или машина вернулась в продажу — публикации нет, кому показывать — тоже. */
    public static function clear(Offer $offer): void
    {
        if ($offer->deal_published_at !== null || $offer->deal_show_until !== null || $offer->deal_audience_rules !== null) {
            Offer::whereKey($offer->id)->update(['deal_published_at' => null, 'deal_show_until' => null, 'deal_audience_rules' => null]);
            $offer->deal_published_at = $offer->deal_show_until = $offer->deal_audience_rules = null;
            $offer->syncOriginalAttributes(['deal_published_at', 'deal_show_until', 'deal_audience_rules']);
        }
    }
}
