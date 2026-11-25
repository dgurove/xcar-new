<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Offers\Signing;
use App\Users\User;
use App\Workflow\Actions\PlaceOnStage;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;

/**
 * Подписание не в Москве — без записи (25.11.2026, владелец: у Альфы по записи только в Москве, в других городах покупатель
 * приходит в офис в рабочее время): «Время подписания» и «Запись на подписание» проходятся сразу на «Подписание», письма «запишите
 * на …» нет. Зовут: вход в «Время подписания» (`Listeners\SkipSigningBooking`) и смена города на этих шагах (`SetSigningCity`).
 */
final class SkipSigningBooking
{
    public function __construct(private PlaceOnStage $place) {}

    /** Да — шаги записи пройдены. */
    public function __invoke(Deal $deal, User $by): bool
    {
        if (! $deal->isActive() || $deal->signsByAppointment()) {
            return false;
        }
        $offer = $deal->offer()->first();
        $stage = $offer?->position(Track::Sale)?->stage?->loadMissing('exits.to.exits');
        $signing = $stage ? self::signingStage($stage) : null;
        if (! $signing) {
            return false;
        }
        $city = $deal->signingCity()->value('name');
        Requirement::closeOpen($deal, $stage->id, ['closed_by' => 'staff', 'by' => $by->id, 'exit' => 'Подписание без записи']);
        ($this->place)($offer, $signing, $by, payload: ['label' => 'Подписание '.self::in($city).' без записи']);

        return true;
    }

    /** С шага выбора времени или записи — шаг самого подписания: «Время согласовано» → «Запись подтверждена» → он. */
    public static function signingStage(Stage $stage): ?Stage
    {
        if (Signing::picks($stage)) {
            $stage = $stage->loadedExits()->first(fn ($e) => Signing::pickExit($e))?->to?->loadMissing('exits.to');
        }
        if (! $stage || ! Signing::awaitsSlots($stage)) {
            return null;
        }

        return $stage->loadedExits()->first(fn ($e) => Signing::confirmExit($e))?->to;
    }

    /** «в Белгороде» — предлогом с названием как есть: склонять названия городов без словаря нельзя. */
    public static function in(?string $city): string
    {
        return $city ? 'в г. '.$city : 'не в Москве';
    }
}
