<?php

namespace App\Notifications;

use App\Offers\Estimate;
use App\Support\Ago;
use Illuminate\Support\Collection;

/**
 * Пачка просьб оценить одному менеджеру — «Отдать на оценку» галочками из «Без продажной цены» (22.11.2026, владелец: «одно
 * уведомление на пачку»; было по сообщению на каждую машину). Заголовок — «Оценить 12 ТС», ниже срок и марки, ведёт на
 * «Предложения» сайта, где сверху «Нужно оценить». Одна машина в пачке — обычное `EstimateAskedNotice`. Каналы, тема и
 * важность — как у просьбы по одной (`EstimateNotice`).
 */
final class EstimatesPackNotice extends Notice
{
    /** @param  Collection<int, Estimate>  $estimates  просьбы одному менеджеру */
    public function __construct(private Collection $estimates) {}

    public function label(): string
    {
        return 'Просьба оценить';
    }

    public function title(): string
    {
        $n = $this->estimates->count();

        return 'Оценить '.$n.' ТС';
    }

    public function text(): ?string
    {
        return implode(', ', array_filter([$this->due(), $this->brands()]));
    }

    public function href(): string
    {
        return '/offers';
    }

    public function category(): string
    {
        return 'offers';
    }

    public function topic(): string
    {
        return 'estimates';
    }

    public function critical(): bool
    {
        return true;
    }

    public function icon(): string
    {
        return 'cat-car';
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => array_values(array_filter([$this->due(), $this->brands()])), 'button' => 'Оценить'];
    }

    private function due(): ?string
    {
        $due = $this->estimates->min('due_at');

        return $due ? 'Срок до '.Ago::day($due, true) : null;
    }

    /** Марки, частые первыми: «Kia ×3, Haval, Chery и ещё 4 марки». */
    private function brands(): ?string
    {
        $counts = $this->estimates->map(fn (Estimate $e) => $e->offer?->carRecord()->brand?->name)->filter()->countBy()->sortDesc();
        $names = $counts->map(fn (int $n, string $brand) => $n > 1 ? $brand.' ×'.$n : $brand)->values()->all();

        return NewOffersNotice::brandsLine($names);
    }
}
