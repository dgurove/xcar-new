<?php

namespace App\Offers;

use App\Mail\Account;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;

/**
 * Куда писать поставщику по машине — одна дверь для окна письма (`MailController::compose`) и автомата («не актуально»,
 * `TellSupplierNotActual`, 19.11.2026): ответом на его последнее письмо в ветках «Предложений» (а пока он не писал — на наше
 * последнее ему), кому — контакт «Реализация» вендора, иначе ответственный по убытку, иначе общий адрес по продаже; копии —
 * его «всегда в копии»; с какого ящика — ящик вендора, иначе тот, что отвечает на письмо ветки.
 */
final class SupplierAddress
{
    /** Письмо, на которое отвечать. */
    public static function letter(Offer $offer): ?Message
    {
        $threads = Thread::where('offer_id', $offer->id)->whereIn('account_id', Account::where('scope', Scope::Offers)->select('id'))->pluck('id');
        if ($threads->isEmpty()) {
            return null;
        }
        $letters = Message::whereIn('thread_id', $threads)->with(['account.replyAccount', 'addresses', 'thread'])->orderByDesc('date_at')->orderByDesc('id')->get();

        // Его последнее письмо, а пока он не писал — наше последнее ему (28.10.2026): «Подтверждаем актуальность» ответом
        // на «Выражаем интерес», одной веткой «Re: …», а не новым письмом, которое у страховой потеряется.
        return $letters->first(fn (Message $m) => $m->direction === Direction::In && $m->isFromVendor())
            ?? $letters->first(fn (Message $m) => $m->direction === Direction::Out && $m->message_id);
    }

    /** Кому: «Реализация» вендора главнее ответственного по убытку (Альфа — implementation@, 08.10.2026). */
    public static function to(Offer $offer): ?string
    {
        $offer->loadMissing('vendor.contacts');

        return $offer->vendor?->saleTo() ?? $offer->contact_email ?? $offer->vendor?->saleAddressee();
    }

    /** @return list<string> */
    public static function cc(Offer $offer): array
    {
        $offer->loadMissing('vendor.contacts');

        return $offer->vendor?->ccEmails(sale: true) ?? [];
    }

    /** С какого ящика: ящик вендора, иначе отвечающий за ящик ветки, иначе deal@ «Предложений». */
    public static function account(Offer $offer, ?Message $parent): ?Account
    {
        return $offer->vendor?->mailAccount?->sender() ?? $parent?->account?->sender()
            ?? Account::where('scope', Scope::Offers)->sending()->orderBy('id')->first();
    }
}
