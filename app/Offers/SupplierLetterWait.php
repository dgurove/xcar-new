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
     * «Цена за Лот» из последнего письма поставщика об этой машине (15.11.2026: в ответ Т-Страхованию начальник ставит её, а не
     * закупочную — по Omoda AUT-26-920056 исправил 550 444 на 561 918 руками; по Mercedes ушла закупочная 500 000 при лоте 833 000).
     */
    public static function lotPrice(Offer $offer): ?int
    {
        $threads = Thread::where('offer_id', $offer->id)->select('id');

        $price = Message::whereIn('thread_id', $threads)->where('direction', Direction::In->value)->orderByDesc('date_at')->get()
            ->filter(fn (Message $m) => ($m->isFromVendor() || $m->isForwardedByStaff()) && FillFromLetter::about($offer, $m))
            ->map(fn (Message $m) => (int) $m->field('floor_price'))->first(fn (int $p) => $p >= Pricing::MIN);

        return $price ?: null;
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
