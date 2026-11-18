<?php

namespace App\Notifications;

use App\Telegram\Messages\Message as TelegramMessage;
use App\Vendors\Vendor;
use Illuminate\Support\Str;

/**
 * Сообщение, что уходило админам только в Telegram (`NotifyOwner`: приём закрыт, слот, новый покупатель или менеджер), —
 * уведомлением сотрудникам (18.11.2026, начальник: «он Telegram не читает, всё, что туда, — в центр уведомлений»): в ленту, пушем
 * и в Telegram тем же текстом и кнопками решения, по категории и настройкам человека.
 */
final class StaffNotice extends Notice
{
    /** @var array{title: string, lines: list<?string>, decisions: list<array>, link: array{text: string, url: string}}|null */
    private ?array $parts = null;

    public function __construct(TelegramMessage $message, private string $category, private string $label, private bool $loud = true, private ?Vendor $from = null)
    {
        $this->telegramMessage = $message;
    }

    private function parts(): array
    {
        return $this->parts ??= $this->telegramMessage->parts();
    }

    public function kind(): string
    {
        return 'StaffNotice:'.class_basename($this->telegramMessage);
    }

    public function label(): string
    {
        return $this->label;
    }

    public function title(): string
    {
        return $this->parts()['title'];
    }

    public function text(): ?string
    {
        $lines = array_filter(array_map(fn ($l) => trim((string) $l), $this->parts()['lines']), fn ($l) => $l !== '');

        return $lines ? Str::limit(implode(', ', $lines), 300) : null;
    }

    public function href(): string
    {
        return $this->parts()['link']['url'];
    }

    public function category(): string
    {
        return $this->category;
    }

    public function important(): bool
    {
        return $this->loud;
    }

    public function vendor(): ?Vendor
    {
        return $this->from;
    }
}
