<?php

namespace App\Offers;

use App\Users\User;
use Illuminate\Support\Carbon;

/**
 * Кто заберёт машину по сделке с нашей парковки (18.10.2026, владелец: «решает менеджер в сделке»): выбранный менеджером
 * (он сам, его покупатель, новый по ФИО и телефону), иначе по умолчанию — `Deal::receiver()`.
 */
final readonly class Receiver
{
    /** @param 'manager'|'buyer'|'person'|'executor' $kind */
    public function __construct(
        public string $name,
        public ?string $phone,
        public ?User $user,
        public string $kind,
        public ?Carbon $at = null,
        public bool $chosen = false,
    ) {}

    /** Подпись для менеджера и парковки: «Вы», «Иван Петров, покупатель», «Сергей Э., водитель». */
    public function labelFor(?User $viewer): string
    {
        if ($viewer && $this->user?->is($viewer)) {
            return 'Вы';
        }

        return $this->name.match ($this->kind) {
            'buyer' => ', покупатель',
            'executor' => ', водитель',
            'manager' => ', менеджер',
            default => '',
        };
    }

    /** Пропуск уже на это лицо и время — заявку и пропуск не трогаем. */
    public function matches(?string $name, ?string $phone, ?Carbon $at): bool
    {
        return $name === $this->name && $phone === $this->phone && $at?->getTimestamp() === $this->at?->getTimestamp();
    }
}
