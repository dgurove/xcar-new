<?php

namespace App\Users\Actions;

use App\Live\Publisher;
use App\Live\Topics;
use App\Support\Phone;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Доступ к предложениям покупателю без доступа (11.11.2026, владелец: «покупатели с доступом и без доступа», «как
 * покупателю без доступа выдать доступ»; раньше — «Дать вход» заведённому по имени, 05.11.2026): телефон или почта —
 * только если их ещё нет (одно поле, чем входит — тем и указан), что видит — все предложения менеджера или выбранные,
 * новый пароль (`NewPassword`) — шторкой входа (`x-users.credentials`). `users.buyer_access_at` — с этой минуты.
 * Менеджер — своему покупателю, админ CRM — любому.
 *
 * @return array<string, string> данные шторки входа
 */
final class GiveBuyerAccess
{
    public function __construct(private NewPassword $password) {}

    public function __invoke(User $buyer, ?string $contact, string $sees = 'all'): array
    {
        $fill = $buyer->hasSignInContact() ? [] : self::contact($buyer, $contact);
        $buyer->forceFill($fill + [
            'contact_fields' => array_values(array_unique([...($buyer->contact_fields ?? []), ...array_keys($fill)])),
            'buyer_sees' => $sees === 'picked' ? 'picked' : 'all',
            'buyer_access_at' => now(),
        ])->save();
        app(Publisher::class)->refresh(Topics::user($buyer->id), ['/offers', '/account']);

        return ($this->password)($buyer);
    }

    /**
     * Чем войдёт — телефон или почта из одного поля, с проверкой занятости. Новый покупатель (`$buyer = null`) проверяется
     * до того, как его завели (`Cabinet\InviteController::store`), — иначе ошибка оставила бы его без доступа.
     *
     * @return array{email?: string, phone?: string}
     */
    public static function contact(?User $buyer, ?string $contact): array
    {
        $contact = trim((string) $contact);
        if ($contact === '') {
            throw ValidationException::withMessages(['contact' => 'Телефон или почта — чем он будет входить']);
        }
        if (str_contains($contact, '@')) {
            $email = mb_strtolower($contact);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['contact' => 'Почта: имя@домен']);
            }
            if (User::whereRaw('lower(email) = ?', [$email])->when($buyer, fn ($q) => $q->whereKeyNot($buyer->id))->exists()) {
                throw ValidationException::withMessages(['contact' => 'Эта почта уже занята']);
            }

            return ['email' => $email];
        }
        $phone = Phone::normalize($contact);
        if (! $phone) {
            throw ValidationException::withMessages(['contact' => 'Телефон +7 и 10 цифр или почта']);
        }
        if (User::where('phone', $phone)->when($buyer, fn ($q) => $q->whereKeyNot($buyer->id))->exists()) {
            throw ValidationException::withMessages(['contact' => 'Этот телефон уже занят']);
        }

        return ['phone' => $phone];
    }
}
