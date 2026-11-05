<?php

namespace App\Users\Actions;

use App\Support\Phone;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Вход покупателю, которого менеджер завёл по имени (05.11.2026, владелец: телефон при добавлении «отпугивает»; «покупатели
 * могут без телефона входить по почте»): одно поле — телефон или почта, что есть, тем и входит. Пароль — как у «Ввести
 * данные» (`NewPassword`), показывает шторка входа.
 *
 * @return array<string, string> данные шторки входа
 */
final class GiveBuyerAccess
{
    public function __construct(private NewPassword $password) {}

    public function __invoke(User $buyer, ?string $contact): array
    {
        $contact = trim((string) $contact);
        if (str_contains($contact, '@')) {
            $email = mb_strtolower($contact);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['contact' => 'Почта: имя@домен']);
            }
            if (User::whereRaw('lower(email) = ?', [$email])->whereKeyNot($buyer->id)->exists()) {
                throw ValidationException::withMessages(['contact' => 'Эта почта уже занята']);
            }
            $fill = ['email' => $email];
        } else {
            $phone = Phone::normalize($contact);
            if (! $phone) {
                throw ValidationException::withMessages(['contact' => 'Телефон +7 и 10 цифр или почта']);
            }
            if (User::where('phone', $phone)->whereKeyNot($buyer->id)->exists()) {
                throw ValidationException::withMessages(['contact' => 'Этот телефон уже занят']);
            }
            $fill = ['phone' => $phone];
        }
        $buyer->forceFill($fill + ['contact_fields' => array_values(array_unique([...($buyer->contact_fields ?? []), ...array_keys($fill)]))])->save();

        return ($this->password)($buyer);
    }
}
