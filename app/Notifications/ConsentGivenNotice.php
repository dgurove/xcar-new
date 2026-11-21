<?php

namespace App\Notifications;

use App\Offers\Deal;

/**
 * Менеджер взял сделку в работу — теперь письмо поставщику (27.10.2026, владелец: у Альфы сначала согласие менеджера, потом
 * «актуально»; с 15.11.2026 и у Т-Страхования — просьба о контактах; после письма отказаться нельзя). Сотрудникам громко и в Telegram, ссылка — на сделку, где шаг
 * с письмом поставщику лаймовой «Написать поставщику».
 */
final class ConsentGivenNotice extends Notice
{
    public function __construct(private Deal $deal) {}

    /** Лицо капсулы и строки ленты — менеджер сделки (18.11.2026). */
    public function actor(): ?\App\Users\User
    {
        return $this->deal->buyer;
    }

    public function label(): string
    {
        return 'Менеджер берёт сделку';
    }

    public function title(): string
    {
        return ($this->deal->buyer?->name ?? 'Менеджер').' берёт '.$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        // Альфе — «актуально», Т-Страхованию — просьба о контактах (15.11.2026): письмо шага, общее слово.
        return 'Можно писать поставщику';
    }

    /**
     * Сразу окно письма с черновиком шага (19.11.2026, Андрей по AUT-26-369145: «взял в работу — даже не предложило написать
     * письмо»), пока письмо не ушло; ушло или шаг без письма — сделка.
     */
    public function href(): string
    {
        $offer = $this->deal->offer;
        $stage = $offer?->stage();

        return $stage?->isLetterStep() && $offer->position()?->letterStatus() === 'unsent' && ($url = $offer->vendorLetterUrl($stage))
            ? $url : '/work/deals/'.$this->deal->id;
    }

    public function offerNumber(): ?int
    {
        return $this->deal->offer->number;
    }

    public function category(): string
    {
        return 'taking';
    }

    public function subject(): string
    {
        return '/work/deals/'.$this->deal->id;
    }

    /** С 21.11.2026 выключаемо у админа своей строкой `taking` (`Categories::ADMIN`), по умолчанию включено. */
    public function critical(): bool
    {
        return false;
    }

    public function important(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => [$this->text()], 'button' => str_starts_with($this->href(), '/work/mail') ? 'Написать поставщику' : 'Открыть сделку'];
    }
}
