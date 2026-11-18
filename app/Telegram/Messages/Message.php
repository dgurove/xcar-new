<?php

namespace App\Telegram\Messages;

use App\Support\Ago;
use Illuminate\Support\Carbon;

/** Сообщение владельцу: заголовок, строки, кнопки решения и ссылка в CRM. */
abstract class Message
{
    abstract protected function title(): string;

    /** @return list<?string> */
    abstract protected function lines(): array;

    /** @return list<array{text: string, callback_data: string}> */
    abstract protected function decisions(): array;

    /** @return array{text: string, url: string} */
    abstract protected function link(): array;

    public function text(?string $trace = null): string
    {
        $lines = array_filter($this->lines(), fn ($l) => $l !== null && $l !== '');
        $text = '<b>'.e($this->title()).'</b>'."\n".implode("\n", array_map(fn ($l) => e($l), $lines));

        return $trace ? $text."\n\n".e($trace) : $text;
    }

    /** Кнопки решения по две в ряд, ссылка в CRM последней строкой. */
    public function keyboard(): array
    {
        return [...array_chunk($this->decisions(), 2), [$this->link()]];
    }

    /**
     * Технический сбой (ошибка 500 и т. п.) — только главному администратору (владелец, 03.11.2026: «ошибки только мне,
     * им не надо»), без остальных админов. Деловые сообщения (приём закрыт, оплаты, сделки) — всем, как было.
     */
    public function ownerOnly(): bool
    {
        return false;
    }

    /**
     * Части сообщения — для уведомления сотрудникам (`Notifications\StaffNotice`, 18.11.2026: всё, что админ получает в Telegram,
     * — ещё и в ленту и пушем): заголовок, строки, кнопки решения, ссылка.
     *
     * @return array{title: string, lines: list<?string>, decisions: list<array{text: string, callback_data: string}>, link: array{text: string, url: string}}
     */
    public function parts(): array
    {
        return ['title' => $this->title(), 'lines' => $this->lines(), 'decisions' => $this->decisions(), 'link' => $this->link()];
    }

    /** Машина сообщения — первая строка капсулы уведомления; без машины — null. */
    public function offer(): ?\App\Offers\Offer
    {
        return null;
    }

    public function afterDecision(): array
    {
        return [[$this->link()]];
    }

    protected static function moment(\DateTimeInterface $at): string
    {
        return Ago::day(Carbon::instance($at)->timezone(config('app.timezone')), true);
    }
}
