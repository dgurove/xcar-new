<?php

namespace App\Users\Actions;

use App\Support\Passwords;
use App\Support\Phone;
use App\Support\Surface;
use App\Users\Invite;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Человек сразу, без ссылки (18.10.2026, владелец: «везде не хватает возможности создавать покупателя самому, а не через
 * ссылку, чтобы ему пароль сгенерился и мы отправили данные; и исполнителя вывоза тоже — и в CRM, и в xcar, и в logistics»).
 * Та же ссылка, что сделал бы создающий (`IssueInvite` — роль, группы, «что видит покупатель», доступ модератора, логист
 * исполнителя), и тут же регистрация по ней (`AcceptInvite`) с введёнными данными: всё, что делает роль, — одной дверью.
 * Ссылка одноразовая и сразу использована, в списке ссылок её нет (`Invite::scopeManageableBy` — без `manual`).
 * Вход — по телефону и паролю (`Passwords::readable`); пароль показывается один раз шторкой `x-users.credentials`.
 */
final class AddPerson
{
    /** Имя покупателя, заведённого по одному скану паспорта: настоящее встанет, когда скан прочитается (`ReadPassport`). */
    public const UNNAMED = 'Новый покупатель';

    public function __construct(private AcceptInvite $accept) {}

    /**
     * `$contact` — покупатель менеджера (05.11.2026, владелец: «менеджеры не хотят прикладывать данные покупателей, потому что
     * просит телефон — убери; почту тоже не надо»): заводится по имени, без телефона и входа; вход дают потом («Дать вход»
     * на странице покупателя, `GiveBuyerAccess`).
     */
    public static function rules(bool $contact = false): array
    {
        return [
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['nullable', 'string', 'max:60'],
            'person_phone' => [$contact ? 'nullable' : 'required', 'string', 'max:40'],
            'person_email' => $contact ? ['prohibited'] : ['nullable', 'email', 'max:255'],
        ];
    }

    /**
     * @param  callable(): Invite  $invite  ссылка, по которой человек «пришёл»: её делает та же дверь, что «Ссылкой»
     * @return array{0: User, 1: ?array<string, string>} человек и данные для шторки входа (без телефона входа нет — null)
     */
    public function __invoke(callable $invite, array $data, array $extra = [], bool $contact = false): array
    {
        $typed = trim((string) ($data['person_phone'] ?? '')) !== '';
        $phone = Phone::normalize($data['person_phone'] ?? null);
        if (! $phone && ($typed || ! $contact)) {
            throw ValidationException::withMessages(['person_phone' => 'Телефон: +7 и 10 цифр']);
        }
        if ($phone && User::where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['person_phone' => 'Этот телефон уже занят']);
        }
        $email = mb_strtolower(trim((string) ($data['person_email'] ?? ''))) ?: null;
        if ($email && User::whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['person_email' => 'Эта почта уже занята']);
        }
        $password = Passwords::readable();

        $user = DB::transaction(function () use ($invite, $data, $phone, $email, $password, $extra) {
            /** @var Invite $made */
            $made = $invite();
            $made->forceFill(['fields' => ['phone' => true, 'email' => true], 'max_uses' => 1, 'manual' => true])->save();
            $user = ($this->accept)($made, [
                'first_name' => $data['first_name'], 'last_name' => (string) ($data['last_name'] ?? ''),
                'login' => null, 'password' => $password, 'phone' => $phone, 'email' => $email,
            ]);
            if ($extra) {
                $user->update($extra);
            }

            return $user;
        });

        return [$user, $phone ? self::credentials($user, $password) : null];
    }

    /**
     * Данные шторки входа: кому, где входить, телефон, пароль и готовое сообщение (`text`, без адреса — его кладёт
     * `copy_controller` следом). Одна дверь для шторки `x-users.credentials` и панели «Новый …» `x-people.pick` (28.10.2026:
     * текст жил в Blade и JS двумя копиями).
     */
    public static function credentials(User $user, string $password): array
    {
        // Входят телефоном, а покупатель без телефона — почтой (05.11.2026, владелец: «покупатели могут входить по почте»).
        [$via, $login] = $user->phone ? ['Телефон', Phone::format($user->phone)] : ['Почта', (string) $user->email];

        // «Отправить на почту» в шторке (06.11.2026): одноразовый ключ, под ним — кому и какой пароль, 15 минут.
        $mail = null;
        if ($user->email) {
            $mail = \Illuminate\Support\Str::random(40);
            \Illuminate\Support\Facades\Cache::put('credentials-mail:'.$mail, ['user' => $user->id, 'via' => $via, 'login' => $login, 'password' => $password, 'url' => self::loginUrl($user)], now()->addMinutes(15));
        }

        return [
            'mail' => $mail, 'email' => $user->email,
            'name' => $user->name,
            'url' => self::loginUrl($user),
            'via' => $via,
            'phone' => $login,
            'password' => $password,
            'text' => "Вам открыт доступ к XCar\n{$via}: {$login}\nПароль: {$password}\nВход:",
        ];
    }

    /** Где человеку входить: сотрудникам — CRM, кому сайт не нужен — его хост (`Surface::onlyFor`), остальным — сайт. */
    public static function loginUrl(User $user): string
    {
        return ($user->isStaff() ? Surface::Crm : (Surface::onlyFor($user) ?? Surface::Site))->url('/login');
    }
}
