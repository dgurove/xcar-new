<?php

namespace App\Offers\Export;

use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Столбцы выгрузки предложений — одно место для окна «Выгрузка» (`x-offer.export-sheet`), файла и буфера (21.10.2026,
 * владелец: «выбирать, какие столбцы включить, и скопировать, например, только номера убытков и даты заведения»).
 * Списка два: «Предложения» вендора (`vendor`, с 15.10.2026 — просьба Т-Страхования) и «Предложения» CRM (`crm`).
 * Значения — числа целыми, даты `d.m.Y`, пусто — null: файл получает числовые ячейки, буфер — те же строки.
 */
final class OfferColumns
{
    /** Порядок столбцов списка; у вендора — как в файле до окна, чтобы выгрузка у тех, кто её уже берёт, не поменялась. */
    private const ORDER = [
        'vendor' => ['claim', 'number', 'model', 'year', 'vin', 'state', 'bids', 'best', 'interests', 'floor', 'price', 'until', 'binding', 'created', 'published', 'requested', 'city', 'author'],
        'crm' => ['number', 'vendor', 'claim', 'model', 'year', 'vin', 'city', 'state', 'floor', 'price', 'bids', 'best', 'interests', 'until', 'binding', 'created', 'published', 'requested', 'author'],
    ];

    /** Отмечены, пока человек не выбрал своё. */
    private const DEFAULTS = [
        'vendor' => ['claim', 'number', 'model', 'year', 'vin', 'state', 'bids', 'best', 'interests', 'floor', 'price', 'binding', 'created', 'published', 'requested'],
        'crm' => ['number', 'vendor', 'claim', 'model', 'year', 'vin', 'city', 'state', 'floor', 'price', 'created'],
    ];

    /** Подтверждения, интерес и срок приёма видит только админ CRM — модератору этих столбцов нет. */
    private const ADMIN = ['bids', 'best', 'interests', 'until'];

    /** @return array<string, string> ключ → подпись */
    private static function labels(): array
    {
        return [
            'number' => '№ предложения', 'vendor' => 'Вендор', 'claim' => '№ убытка', 'model' => 'Марка, модель', 'year' => 'Год',
            'vin' => 'VIN', 'city' => 'Город', 'state' => 'Где сейчас', 'floor' => 'Закупочная, ₽', 'price' => 'Цена продажи, ₽',
            'bids' => 'Подтверждений', 'best' => 'Лучшее подтверждение, ₽', 'interests' => 'Интересов', 'until' => 'Приём до',
            'binding' => 'Срок обязывающего', 'created' => 'Заведено', 'published' => 'Вышло в продажу',
            'requested' => 'Запрос отправлен', 'author' => 'Кто завёл',
        ];
    }

    /** @return Closure(Offer): (string|int|null) */
    private static function value(string $key): Closure
    {
        return match ($key) {
            'number' => fn (Offer $o) => $o->state === OfferState::Draft ? null : $o->number,
            'vendor' => fn (Offer $o) => $o->vendor?->name,
            'claim' => fn (Offer $o) => $o->claim_ref,
            'model' => fn (Offer $o) => $o->carRecord()->title() ?: null,
            'year' => fn (Offer $o) => $o->year,
            'vin' => fn (Offer $o) => $o->vin,
            'city' => fn (Offer $o) => $o->settlement?->title(),
            'state' => fn (Offer $o) => $o->crmTab(),
            'floor' => fn (Offer $o) => $o->floor_price,
            'price' => fn (Offer $o) => $o->asking_price,
            'bids' => fn (Offer $o) => (int) $o->bids_count ?: null,
            'best' => fn (Offer $o) => $o->best_bid ? (int) $o->best_bid : null,
            'interests' => fn (Offer $o) => (int) $o->interests_count ?: null,
            'until' => fn (Offer $o) => $o->state === OfferState::Open ? $o->bids_close_at?->format('d.m.Y H:i') : null,
            'binding' => fn (Offer $o) => $o->binding_until?->format('d.m.Y H:i'),
            'created' => fn (Offer $o) => $o->created_at?->format('d.m.Y'),
            'published' => fn (Offer $o) => $o->published_at?->format('d.m.Y'),
            'requested' => fn (Offer $o) => $o->claimRequest?->created_at?->format('d.m.Y'),
            'author' => fn (Offer $o) => $o->moderator?->name,
        };
    }

    /**
     * Столбцы списка для человека: ключ, подпись, отмечен ли по умолчанию.
     *
     * @return list<array{key: string, label: string, on: bool}>
     */
    public static function for(string $list, User $user): array
    {
        $labels = self::labels();

        return array_values(array_map(fn ($k) => ['key' => $k, 'label' => $labels[$k], 'on' => in_array($k, self::DEFAULTS[$list], true)],
            array_filter(self::ORDER[$list], fn ($k) => $user->canManageCrm() || ! in_array($k, self::ADMIN, true))));
    }

    /**
     * Выбранные из доступных, в порядке списка; не выбрано ничего — отмеченные по умолчанию.
     *
     * @param  list<string>  $asked
     * @return list<string>
     */
    public static function pick(string $list, User $user, array $asked): array
    {
        $all = self::for($list, $user);
        $keys = array_values(array_filter(array_column($all, 'key'), fn ($k) => in_array($k, $asked, true)));

        return $keys ?: array_column(array_filter($all, fn ($c) => $c['on']), 'key');
    }

    /** Что нужно запросу для столбцов: подтверждения (любые, хоть отклонённые), интерес, лучшая цена, кто завёл, запрос вендору. */
    public static function load(Builder $q): Builder
    {
        return $q->with(['vendor:id,name', 'moderator:id,name', 'bids:id,offer_id,state', 'claimRequest:id,created_at'])
            ->withCount(['bids', 'interests'])->withMax('bids as best_bid', 'amount');
    }

    /**
     * @param  Collection<int, Offer>  $offers
     * @param  list<string>  $keys
     * @return list<list<string|int|null>>
     */
    public static function rows(Collection $offers, array $keys): array
    {
        $values = array_map(fn ($k) => self::value($k), $keys);

        return $offers->map(fn (Offer $o) => array_map(fn ($v) => $v($o), $values))->all();
    }

    /** @param list<string> $keys @return list<string> */
    public static function head(array $keys): array
    {
        $labels = self::labels();

        return array_map(fn ($k) => $labels[$k], $keys);
    }
}
