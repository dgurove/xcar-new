<?php

namespace App\Notifications;

use App\Offers\Estimate;
use App\Support\Ago;
use App\Support\Money;
use App\Telegram\Text;

/**
 * Уведомления менеджеру об оценке цены продажи (11.10.2026). Номера в заголовке нет: у черновика он временный. Сообщение
 * в Telegram помечено просьбой (`estimate:{id}`) — закрыли просьбу, и «Оцените» удаляется (`RetractEstimateNotices`).
 */
abstract class EstimateNotice extends Notice
{
    public function __construct(protected Estimate $estimate) {}

    public function href(): string
    {
        return '/offers/'.$this->estimate->offer->number;
    }

    public function category(): string
    {
        return 'offers';
    }

    public function critical(): bool
    {
        return true;
    }

    public function icon(): string
    {
        return 'cat-car';
    }

    public function telegramSubject(): ?string
    {
        return self::subjectOf($this->estimate->id);
    }

    public static function subjectOf(int $estimateId): string
    {
        return 'estimate:'.$estimateId;
    }

    protected function due(): string
    {
        return 'до '.Ago::day($this->estimate->due_at, true);
    }

    /** @return list<mixed> */
    protected function lines(?string ...$middle): array
    {
        $offer = $this->estimate->offer;
        // Заявленную менеджеру скрыли при просьбе — и в уведомлении её нет (11.10.2026).
        $declared = $this->estimate->showsDeclared() ? $offer->declaredPrice() : null;

        return array_values(array_filter([Text::vin($offer), $offer->settlement?->title(), $declared ? 'Заявленная '.Money::rub($declared) : null, ...$middle]));
    }
}
