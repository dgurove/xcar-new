<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Support\Phone;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Кто заберёт машину с нашей парковки (18.10.2026, владелец: «решает менеджер в сделке»): сам менеджер, его покупатель
 * или другой человек по ФИО и телефону (кабинет ему не заводим), и когда приедет. Пропуск и заявку парковки правит
 * `SyncParkRelease`: прежний пропуск гаснет, новый — новый код.
 */
final class SetReceiver
{
    public function __construct(private SyncParkRelease $sync) {}

    /** @param array{who: string, name?: ?string, phone?: ?string, at?: ?string} $data */
    public function __invoke(Deal $deal, User $by, array $data): Deal
    {
        abort_unless($deal->isActive(), 422, 'Сделка закончилась');
        $who = (string) ($data['who'] ?? 'me');
        $fields = ['receiver_user_id' => null, 'receiver_name' => null, 'receiver_phone' => null];
        if ($who === 'me') {
            $fields['receiver_user_id'] = $deal->buyer_id;
        } elseif ($who === 'other') {
            $name = trim((string) ($data['name'] ?? ''));
            $phone = Phone::normalize($data['phone'] ?? null);
            if (! preg_match('/\S+\s+\S+/u', $name)) {
                throw ValidationException::withMessages(['name' => 'Фамилия и имя — как в паспорте']);
            }
            if (! $phone) {
                throw ValidationException::withMessages(['phone' => 'Телефон, по нему позвонят с парковки']);
            }
            $fields['receiver_name'] = mb_substr($name, 0, 160);
            $fields['receiver_phone'] = Phone::format($phone);
        } else {
            // Свой покупатель менеджера сделки — не любой человек системы.
            $buyer = User::whereKey((int) $who)->where('manager_id', $deal->buyer_id)->first();
            if (! $buyer) {
                throw ValidationException::withMessages(['who' => 'Выберите, кто заберёт']);
            }
            $fields['receiver_user_id'] = $buyer->id;
        }
        $fields['receiver_at'] = filled($data['at'] ?? null) ? Carbon::parse($data['at'], 'Europe/Moscow') : null;
        if ($fields['receiver_at']?->lt(now()->subHour())) {
            throw ValidationException::withMessages(['at' => 'Это время уже прошло']);
        }
        $deal->update($fields);
        ($this->sync)($deal->fresh(), $by, explicit: true);

        return $deal->fresh();
    }
}
