<?php

namespace App\Notifications;

use App\Offers\Deal;

/**
 * Менеджер взял сделку в работу — теперь письмо поставщику (27.10.2026, владелец: у Альфы сначала согласие менеджера, потом
 * «актуально»; с 15.11.2026 и у Т-Страхования — просьба о контактах; после письма отказаться нельзя). Сотрудникам громко и в Telegram, ссылка — на сделку, где шаг
 * с письмом поставщику лаймовой «Написать поставщику». У админа выключаемо строкой `taking` (21.11.2026).
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
     * Сделка, а не окно письма (22.11.2026, владелец: «все действия в сделке»; было окно письма с 19.11.2026). Письмо
     * поставщику — лаймовой «Написать поставщику» в шаге сделки.
     */
    public function href(): string
    {
        return '/work/deals/'.$this->deal->id;
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
        return ['title' => $this->title(), 'lines' => [$this->text()], 'button' => 'Открыть сделку'];
    }
}
