<?php

namespace App\Support\Facets;

use App\Billing\Party;
use App\Cars\Category;
use App\Cars\Settlement;
use App\Park\Yard;
use App\Users\User;
use App\Vendors\Vendor;

/** Чипы, которые повторяются в списках: вендор, менеджер, город, тип ТС, парковка, контрагент. */
final class Common
{
    public static function vendor(string $expr, string $key = 'vendor'): Facet
    {
        return Facet::column($key, 'Вендор', ['вендор', 'вендора', 'вендоров'], $expr)
            ->labels(fn (array $ids) => Vendor::whereIn('id', self::ints($ids))->get(['id', 'name'])
                ->mapWithKeys(fn (Vendor $v) => [(string) $v->id => new Option((string) $v->id, $v->name, vendor: $v)])->all());
    }

    public static function manager(string $expr, string $key = 'manager', string $title = 'Менеджер'): Facet
    {
        return Facet::column($key, $title, ['менеджер', 'менеджера', 'менеджеров'], $expr)
            ->labels(fn (array $ids) => User::whereIn('id', self::ints($ids))->with(User::withAvatar())->get()
                ->mapWithKeys(fn (User $u) => [(string) $u->id => new Option((string) $u->id, $u->name ?: ($u->login ?? '№ '.$u->id), user: $u)])->all());
    }

    public static function city(string $expr): Facet
    {
        return Facet::column('city', 'Город', ['город', 'города', 'городов'], $expr)
            ->labels(fn (array $ids) => Settlement::whereIn('id', self::ints($ids))->get()
                ->mapWithKeys(fn (Settlement $s) => [(string) $s->id => new Option((string) $s->id, $s->title())])->all());
    }

    public static function category(string $expr, ?string $none = null): Facet
    {
        $f = Facet::column('category', 'Тип ТС', ['тип', 'типа', 'типов'], $expr)
            ->labels(fn (array $keys) => collect(Category::cases())->filter(fn (Category $c) => in_array($c->value, $keys, true))
                ->mapWithKeys(fn (Category $c) => [$c->value => new Option($c->value, $c->label(), category: $c)])->all());

        return $none ? $f->none($none) : $f;
    }

    public static function yard(string $expr): Facet
    {
        return Facet::column('yard', 'Парковка', ['парковка', 'парковки', 'парковок'], $expr)
            ->none('Без парковки')
            ->labels(fn (array $ids) => Yard::whereIn('id', self::ints($ids))->orderBy('name')->get(['id', 'name'])
                ->mapWithKeys(fn (Yard $y) => [(string) $y->id => new Option((string) $y->id, $y->name)])->all());
    }

    public static function party(string $expr): Facet
    {
        return Facet::column('party', 'Контрагент', ['контрагент', 'контрагента', 'контрагентов'], $expr)
            ->labels(fn (array $ids) => Party::whereIn('id', self::ints($ids))->get(['id', 'name'])
                ->mapWithKeys(fn (Party $p) => [(string) $p->id => new Option((string) $p->id, $p->name, vendor: Vendor::ofParty($p->id))])->all());
    }

    /** @return list<int> */
    public static function ints(array $keys): array
    {
        return array_values(array_map('intval', array_filter($keys, fn ($k) => ctype_digit((string) $k))));
    }
}
