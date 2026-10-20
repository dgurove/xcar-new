<?php

namespace App\Http;

use App\Cars\Settlement;
use App\Logistics\Client;
use App\Logistics\Depot;
use App\Offers\Deal;
use App\Support\People;
use App\Support\Phone;
use App\Support\Surface;
use App\Users\Actions\AddPerson;
use App\Users\Actions\IssueInvite;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;

/**
 * «+ Новый …» в шторке выбора человека (`x-people.pick`, 18.10.2026, владелец: «в сделке для ДКП нужно выбрать покупателя,
 * а его ещё нет — и приходится идти в кабинет в пользователей, это бред»): человек заводится тут же, без ухода со
 * страницы, ответ — JSON со строкой для списка и данными входа (или ссылкой). Кого можно — по месту и правам:
 * менеджер — своего покупателя; логист — своего исполнителя и заказчика; админ — логиста и нашего водителя.
 */
class PeopleController
{
    public const KINDS = ['buyer', 'contractor', 'logist', 'driver', 'client', 'depot'];

    public function store(Request $request, AddPerson $add, IssueInvite $issue)
    {
        $me = $request->user();
        $kind = (string) $request->input('kind');
        abort_unless(in_array($kind, self::KINDS, true) && self::allows($me, $kind), 403);
        $data = (array) $request->input('_person', []);
        $request->merge(['_person' => $data]);

        if ($kind === 'client') {
            $v = validator($data, ['name' => ['required', 'string', 'max:200'], 'contact_name' => ['nullable', 'string', 'max:120'],
                'phone' => ['nullable', 'string', 'max:40'], 'address' => ['nullable', 'string', 'max:500']], ['name.required' => 'Название'])->validate();
            $client = Client::create(['logist_id' => $me->id, 'kind' => 'company', 'phone' => Phone::normalize($v['phone'] ?? null)] + array_diff_key($v, ['phone' => 1]));

            return response()->json(self::answer(People::row($client) + ['address' => $client->address]) + ['address' => $client->address]);
        }

        // Стоянка логиста (18.10.2026): промежуточная точка вывоза — заводится там, где её выбирают.
        if ($kind === 'depot') {
            $v = validator($data, ['name' => ['required', 'string', 'max:120'], 'city' => ['nullable', 'string', 'max:120'], 'address' => ['nullable', 'string', 'max:500'],
                'contact_name' => ['nullable', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:40']], ['name.required' => 'Название'])->validate();
            $owner = $request->filled('owner') && $me->leadsLogistics() ? User::whereKey($request->integer('owner'))->first() : null;
            $depot = Depot::create(['logist_id' => ($owner?->isLogist() ? $owner : $me)->id, 'name' => trim($v['name']),
                'settlement_id' => ($v['city'] ?? null) ? (Settlement::named($v['city'])['id'] ?? null) : null, 'address' => trim((string) ($v['address'] ?? '')) ?: null,
                'contact_name' => trim((string) ($v['contact_name'] ?? '')) ?: null, 'phone' => Phone::normalize($v['phone'] ?? null)]);

            return response()->json(self::answer(People::row($depot->load('settlement'))));
        }

        // Админ в CRM заводит покупателя менеджеру сделки (20.10.2026, владелец: «пока менеджер не укажет покупателя, мы не
        // можем закрыть сделку»): своих покупателей у админа нет — новый встаёт в «Покупатели» менеджера и в эту сделку.
        $forDeal = $kind === 'buyer' && self::staffForDeal($me);
        $deal = $kind === 'buyer' && $request->filled('deal')
            ? Deal::whereKey($request->integer('deal'))->unless($forDeal, fn ($q) => $q->where('buyer_id', $me->id))->first() : null;
        abort_if($forDeal && ! $deal?->buyer?->isManager(), 403);
        $owner = $forDeal ? $deal->buyer : $me;
        $invite = fn () => match ($kind) {
            'buyer' => $issue->buyer($owner, $deal?->id),
            'contractor' => $issue->contractor($me, $data['first_name'] ?? null),
            default => $issue($me, ['role' => $kind, 'expires_at' => now()->addWeek()->format('Y-m-d H:i')]),
        };

        if ($request->input('mode') === 'link') {
            $made = $invite();

            return response()->json(['link' => ['url' => $made->url(), 'text' => self::linkText($kind)]]);
        }

        $v = validator($data, AddPerson::rules() + ($kind === 'contractor'
            ? ['logistics_city' => ['nullable', 'string', 'max:120'], 'cdek_point' => ['nullable', 'string', 'max:300']] : []),
            ['first_name.required' => 'Имя', 'person_phone.required' => 'Телефон'])->validate();
        [$user, $credentials] = $add($invite, $v, array_filter([
            'logistics_city' => $v['logistics_city'] ?? null, 'cdek_point' => $v['cdek_point'] ?? null,
        ]));

        return response()->json(self::answer(People::row($user->fresh())) + ['credentials' => $credentials]);
    }

    /** Кто кого заводит. */
    public static function allows(User $me, string $kind): bool
    {
        return match ($kind) {
            'buyer' => ($me->isManager() && ! $me->isSuperBuyer()) || self::staffForDeal($me),
            'contractor', 'client' => $me->isLogist() && Surface::current() === Surface::Logistics,
            'depot' => ($me->isLogist() || $me->leadsLogistics()) && Surface::current() === Surface::Logistics,
            'logist', 'driver' => $me->isAdmin(),
            default => false,
        };
    }

    /** Админ в CRM: покупателя заводит не себе, а менеджеру сделки (`deal` обязателен). */
    private static function staffForDeal(User $me): bool
    {
        return $me->isAdmin() && Surface::current() === Surface::Crm;
    }

    /** Строка нового человека — та же разметка, что у списка шторки. */
    private static function answer(array $row): array
    {
        return ['id' => $row['id'], 'name' => $row['name'], 'html' => Blade::render('<x-people.option :person="$p" name="__name__" checked/>', ['p' => $row])];
    }

    private static function linkText(string $kind): string
    {
        return match ($kind) {
            'buyer' => 'Вас приглашают в XCar: откройте ссылку и зарегистрируйтесь',
            'contractor' => 'Вас приглашают в Logistics XCar: откройте ссылку и зарегистрируйтесь',
            default => 'Вас приглашают в XCar: откройте ссылку и зарегистрируйтесь',
        };
    }
}
