<?php

namespace App\Purchases;

use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Collection;

/**
 * Кто по скольким машинам закупки назвал цену, а по скольким — нет.
 *
 * Считается по опубликованным машинам и с оглядкой на запреты: менеджеру,
 * которому грузовые не показывают, они и в «без цены» не идут. Один расчёт
 * на экран и на xlsx.
 */
final class OffersSummary
{
    /** @var Collection<int, Car> */
    public readonly Collection $cars;

    /** @var Collection<int, User> */
    public readonly Collection $managers;

    /** @var array<int, array{visible:int, offered:int, missing:int, chosen:int}> по id пользователя */
    public readonly array $stats;

    public function __construct(public readonly Purchase $purchase)
    {
        // Только цены и люди: сводка считается по всем машинам, связи для показа догружает экран у своей страницы.
        $this->cars = $purchase->cars()->where('is_published', true)->with('offers.user')->orderBy('dl')->get();
        $offered = $this->cars->flatMap(fn (Car $c) => $c->activeOfferList()->map->user)->unique('id');
        $managers = User::where('role', Role::Manager)->get()->concat($offered)->unique('id')->values();
        $hidden = Restriction::whereIn('user_id', $managers->pluck('id'))->pluck('hidden_kinds', 'user_id');
        $stats = [];
        foreach ($managers as $user) {
            $mine = $this->offersOf($user);
            $visible = $this->cars->reject(fn (Car $c) => in_array($c->kind->value, $hidden[$user->id] ?? [], true))->count();
            $stats[$user->id] = [
                'visible' => $visible,
                'offered' => $mine->count(),
                'missing' => max(0, $visible - $mine->count()),
                'chosen' => $mine->where('state', OfferState::Chosen)->count(),
            ];
        }
        $this->stats = $stats;
        $this->managers = $managers->sortBy([fn ($a, $b) => $stats[$b->id]['offered'] <=> $stats[$a->id]['offered'], fn ($a, $b) => strcmp($a->name, $b->name)])->values();
    }

    /** Активные и выбранные цены этого человека по машинам закупки, в порядке файла. */
    public function offersOf(User $user): Collection
    {
        return $this->cars->map(fn (Car $c) => $c->activeOfferList()->firstWhere('user_id', $user->id)?->setRelation('car', $c))->filter()->values();
    }

    private ?Collection $unpriced = null;

    /** Машины, по которым цены не назвал никто. */
    public function unpriced(): Collection
    {
        return $this->unpriced ??= $this->cars->filter(fn (Car $c) => $c->activeOfferList()->isEmpty())->values();
    }

    public function priced(): int
    {
        return $this->cars->count() - $this->unpriced()->count();
    }
}
