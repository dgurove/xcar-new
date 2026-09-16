<?php

namespace App\Purchases;

use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['number', 'title', 'supplier', 'state', 'offers_close_at', 'hide_priced', 'source_file', 'imported_at', 'imported_by'])]
class Purchase extends Model
{
    protected function casts(): array
    {
        return ['state' => PurchaseState::class, 'offers_close_at' => 'datetime', 'imported_at' => 'datetime', 'hide_priced' => 'bool'];
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function cars(): HasMany
    {
        return $this->hasMany(Car::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /** Машины, которые видит витрина: опубликованные и, если так решено в закупке, ещё без нашей цены. */
    public function carsOnSite(): HasMany
    {
        return $this->cars()->where('is_published', true)->when($this->hide_priced, fn ($q) => $q->whereNull('price_final'));
    }

    public function showsOnSite(Car $car): bool
    {
        return $car->is_published && ! ($this->hide_priced && $car->price_final !== null);
    }

    /** Наружу — номер, группа и месяц: по названию с именем лизинговой компании покупатель уйдёт искать те же машины у неё. */
    public function publicTitle(?Group $group = null): string
    {
        return "Закупка № {$this->number}".($group ? ' '.$group->label() : '').', '.mb_strtolower($this->created_at->translatedFormat('F Y'));
    }

    /** Категории закупки, открытые этому человеку: без запрещённых ему и без пустых. */
    public function kindsFor(?User $user): array
    {
        $hidden = Restriction::hiddenFor($user);
        $present = $this->carsOnSite()->distinct()->pluck('kind')->map(fn ($k) => $k instanceof Kind ? $k : Kind::from($k))->all();

        return array_values(array_filter($present, fn (Kind $k) => ! in_array($k->value, $hidden, true)));
    }

    /**
     * Карточки закупки на витрине — по одной на группу, в которой человеку что-то открыто.
     * Названо — его собственными ценами: закупка у каждого своя.
     *
     * @return list<PurchaseCard>
     */
    public function cardsFor(?User $user): array
    {
        $kinds = $this->kindsFor($user);
        $cards = [];
        foreach (Group::cases() as $group) {
            $in = array_values(array_filter($kinds, fn (Kind $k) => Group::of($k) === $group));
            if (! $in) {
                continue;
            }
            $cars = $this->carsOnSite()->whereIn('kind', $in);
            $rated = $user ? (clone $cars)->whereHas('offers', fn ($o) => $o->where('user_id', $user->id)->whereIn('state', [OfferState::Active, OfferState::Chosen]))->count() : 0;
            $cards[] = new PurchaseCard($this, $group, $cars->count(), $rated);
        }

        return $cards;
    }

    /** @return list<PurchaseCard> */
    public static function showcase(?User $user): array
    {
        return self::where('state', PurchaseState::Open)->orderByDesc('number')->get()
            ->flatMap(fn (self $p) => $p->cardsFor($user))->values()->all();
    }

    public function acceptsOffers(): bool
    {
        return $this->state === PurchaseState::Open && (! $this->offers_close_at || $this->offers_close_at->isFuture());
    }

    /** Открыта, но срок прошёл: приём закрыт сам собой, «+15 мин / +1 ч» открывают заново. */
    public function closed(): bool
    {
        return $this->state === PurchaseState::Open && $this->offers_close_at?->isPast() === true;
    }

    public static function nextNumber(): int
    {
        return (int) (self::max('number') ?? 0) + 1;
    }
}
