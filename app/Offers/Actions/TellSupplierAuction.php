<?php

namespace App\Offers\Actions;

use App\Http\Admin\MailController;
use App\Mail\Composer;
use App\Mail\Message;
use App\Mail\Template;
use App\Notifications\Notify;
use App\Notifications\RefusalNotice;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\SupplierAddress;
use App\Users\User;

/**
 * Письмо поставщику «прошу передать на аукцион» (27.11.2026, владелец: «когда передают на аукцион — чтобы письмо уходило само»):
 * шаблон «Поставщику: передать на аукцион» (`Template::toAuction`, правится в настройках почты), ответом в ветку поставщика по
 * машине, адресаты и ящик — как у окна письма (`SupplierAddress`), как `TellSupplierNotActual`. Писать некому или не с чего —
 * письма нет: в «Историю» и админам громко, чтобы написали сами. Зовёт `SendToAuction::now` у вендоров со сроком обязывающего.
 */
final class TellSupplierAuction
{
    public function __construct(private Composer $composer) {}

    public function __invoke(Offer $offer, ?User $by = null): ?Message
    {
        $offer->loadMissing('vendor.contacts', 'vendor.mailAccount');
        $template = Template::toAuction();
        $values = MailController::placeholders($offer) + ['to' => SupplierAddress::to($offer) ?? '', 'cc' => implode(', ', SupplierAddress::cc($offer))];
        $parent = SupplierAddress::letter($offer);
        $account = SupplierAddress::account($offer, $parent);
        $data = null;
        if ($account) {
            $data = $parent ? $this->composer->replyWith($parent, $account, $template, $values) : $this->composer->fresh($account, $template, $values);
            if ($sale = $offer->vendor?->saleTo()) {
                $data['to'] = $sale;
            }
        }
        if (! $data || blank($data['to'])) {
            $offer->log(OfferEventType::Note, $by, ['text' => 'Письмо поставщику «передать на аукцион» не ушло: нет адреса, напишите сами']);
            Notify::toStaff(new RefusalNotice($offer, 'Не ушло «передать на аукцион»', 'Нет адреса поставщика, напишите сами'));

            return null;
        }
        $message = $this->composer->create($account, $data, $parent, $by);
        if ($message->thread && ! $message->thread->offer_id) {
            $message->thread->update(['offer_id' => $offer->id]);
        }
        $offer->log(OfferEventType::Note, $by, ['text' => 'Поставщику: «передать на аукцион»', 'letter' => $message->id]);

        return $message;
    }
}
