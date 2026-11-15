<?php

namespace App\Offers;

use App\Mail\Direction;
use App\Mail\Extraction\Intent;
use App\Mail\Message;
use App\Mail\Thread;
use App\Offers\Actions\FillFromLetter;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;

/**
 * «Ждём письмо поставщика» перед «Согласием менеджера» (15.11.2026, Т-Страхование, ПСБ, РСХБ, ЗЕСТ; владелец: «ждать письма,
 * потому что менеджеру для принятия решения нужен VIN, а он только в письме, которое мы ждём»; вопрос менеджеру по Kaiyi
 * AUT-26-881707 без письма Т-Страхования ушёл зря). Т-Страхование присылает «Предлагаем принять участие в оценке ТС» с VIN.
 * Само дальше не идёт (владелец: «как и у АльфаСтрахования, по нажатию кнопки сотрудником, а не сразу»): письма нет — ход
 * поставщика; есть (пришло до «Принять» — `Listeners\MarkSupplierLetterWait` при входе, после — `OnMessage`) — ход наш, у шага
 * «Спросить менеджера» (исход к согласию, `x-route.path`).
 */
final class SupplierLetterWait
{
    public const STAGE = 'Ждём письмо поставщика';

    public const EXIT = 'Письмо поставщика пришло';

    public static function exitOf(Stage $stage): ?Outcome
    {
        return $stage->loadMissing('exits')->exits->firstWhere('label', self::EXIT);
    }

    /** Письмо поставщика об этой машине — его ящик или пересылка сотрудника, когда бы ни пришло. */
    public static function letter(Offer $offer): ?Message
    {
        $threads = Thread::where('offer_id', $offer->id)->select('id');

        return Message::whereIn('thread_id', $threads)->where('direction', Direction::In->value)->orderBy('date_at')->get()
            ->first(fn (Message $m) => ($m->isFromVendor() || $m->isForwardedByStaff()) && ! Intent::silent($m->intent) && FillFromLetter::about($offer, $m));
    }

    /**
     * Сделка маршрута с ожиданием письма поставщика (Т-Страхование) ещё не дошла до нашего ответа: стоит на ожидании, на
     * согласии или на «Письме о покупке», которое не ушло. Переоценке по их письму тогда свой «Ответ поставщику» не нужен —
     * ответом будет это письмо с новой закупочной (`RepriceFlow::answer`, 15.11.2026).
     */
    public static function beforeOurLetter(Offer $offer): bool
    {
        $position = $offer->position(Track::Sale);
        $stage = $position?->stage;
        if (! $stage || ! Stage::where('workflow_id', $stage->workflow_id)->where('name', self::STAGE)->exists()) {
            return false;
        }

        return self::exitOf($stage) || $stage->asksConsent()
            || ($stage->isLetterStep() && in_array($position->letterStatus(), ['unsent', 'failed', 'bounced'], true));
    }

    /** Сделка ждёт письма, а оно есть — ход наш: «Спросить менеджера». Отметили — true. */
    public static function mark(Offer $offer): bool
    {
        $position = $offer->unsetRelation('positions')->position(Track::Sale);
        if (! $position?->stage || ! self::exitOf($position->stage) || $position->waits_for === WaitsFor::Us
            || ! $offer->deal?->isActive() || ! self::letter($offer)) {
            return false;
        }
        $position->update(['waits_for' => WaitsFor::Us, 'deadline_at' => null, 'reminded_at' => null, 'overdue_at' => null]);

        return true;
    }
}
