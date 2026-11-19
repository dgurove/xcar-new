<?php

namespace App\Offers\Actions;

use App\Http\Admin\MailController;
use App\Mail\Composer;
use App\Mail\Message;
use App\Mail\Template;
use App\Notifications\Notify;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\SupplierAddress;
use App\Users\User;

/**
 * Письмо поставщику «не актуально» (19.11.2026, начальник: менеджер отказался, за отпущенное время никто не подтвердил — «поставщику
 * автоматически отправляется письмо, что не актуально»). Шаблон «Поставщику: не актуально» (`Template::notActual`, правится в
 * настройках почты), ответом в ветку поставщика по машине, адресаты и ящик — как у окна письма (`SupplierAddress`). Писать
 * некому или не с чего — письма нет: в «Историю» и админам громко, чтобы написали сами.
 */
final class TellSupplierNotActual
{
    public function __construct(private Composer $composer) {}

    public function __invoke(Offer $offer, ?User $by = null): ?Message
    {
        $offer->loadMissing('vendor.contacts', 'vendor.mailAccount');
        $template = Template::notActual();
        $values = MailController::placeholders($offer) + ['to' => SupplierAddress::to($offer) ?? '', 'cc' => implode(', ', SupplierAddress::cc($offer))];
        $parent = SupplierAddress::letter($offer);
        $account = SupplierAddress::account($offer, $parent);
        $data = null;
        if ($account) {
            $data = $parent ? $this->composer->replyWith($parent, $account, $template, $values) : $this->composer->fresh($account, $template, $values);
            // «Реализация» вендора — адресат письма по продаже, кто бы ни писал последним (как в окне письма).
            if ($sale = $offer->vendor?->saleTo()) {
                $data['to'] = $sale;
            }
        }
        if (! $data || blank($data['to'])) {
            $offer->log(OfferEventType::Note, $by, ['text' => 'Письмо поставщику «не актуально» не ушло: нет адреса, напишите сами']);
            Notify::toStaff(new \App\Notifications\RefusalNotice($offer, 'Не ушло «не актуально»', 'Нет адреса поставщика, напишите сами'));

            return null;
        }
        $message = $this->composer->create($account, $data, $parent, $by);
        $offer->log(OfferEventType::Note, $by, ['text' => 'Поставщику: «не актуально»', 'letter' => $message->id]);

        return $message;
    }
}
