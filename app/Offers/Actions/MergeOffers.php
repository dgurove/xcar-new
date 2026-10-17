<?php

namespace App\Offers\Actions;

use App\Cars\Actions\JoinCars;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Support\Nav;
use App\Support\Rows;
use App\Users\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Два предложения одной машины («Это она» у черновика, `POST /offers/{n}/into/{m}`) — в одно. Предложения разных
 * записей машины, поэтому это объединение машин (`JoinCars`): ТС парковки, лоты закупки, фото и документы (повтор по
 * отпечатку `sha` не переносится) — к машине оставшегося, а само предложение второго (`lanes`) отдаёт оставшемуся
 * подтверждения, сделки, историю, письма, лоты Мигторга и пустые поля продажи. Его номер становится псевдонимом —
 * старые ссылки открывают оставшееся. Связи ищутся по внешним ключам базы, а не списком: новая таблица не потеряется.
 */
final class MergeOffers
{
    /** Поля продажи, которые второе заполняет у оставшегося, если там пусто (поля машины сливает `JoinCars`). */
    private const FILL = ['description', 'settlement_id', 'inspection_address', 'floor_price', 'owner_price', 'publish_price', 'asking_price',
        'min_bid_price', 'vendor_id', 'insurer_deadline_at', 'car_place', 'answer_by', 'contact_name', 'contact_email'];

    public function __construct(private JoinCars $join) {}

    /** Что остаётся: с идущей сделкой → с любой сделкой → в продаже и дальше → с ТС парковки → с подтверждениями → старше. */
    public static function pick(Collection $offers): Offer
    {
        return $offers->sortByDesc(fn (Offer $o) => [
            Deal::where('offer_id', $o->id)->where('state', DealState::Active)->exists() ? 2 : (Deal::where('offer_id', $o->id)->exists() ? 1 : 0),
            match ($o->state) {
                OfferState::Archived, OfferState::Cancelled => 0,
                OfferState::Draft => 1,
                default => 2,
            },
            $o->parkVehicle()->exists() ? 1 : 0,
            $o->bids()->exists() ? 1 : 0,
            -$o->id,
        ])->first();
    }

    /** @return array{photos: int, dropped: int} перенесено кадров и сколько повторов не понадобилось */
    public function __invoke(Offer $keep, Offer $drop, ?User $by = null): array
    {
        if ($keep->is($drop)) {
            throw ValidationException::withMessages(['offer' => 'Это одно и то же предложение']);
        }
        self::guard($keep, $drop);
        $result = ($this->join)($drop->carRecord(), $keep->carRecord(), $by, keepOffer: $keep);
        Nav::forgetStaffCounts();

        return $result;
    }

    public static function guard(Offer $keep, Offer $drop): void
    {
        if (Deal::where('offer_id', $keep->id)->where('state', DealState::Active)->exists() && Deal::where('offer_id', $drop->id)->where('state', DealState::Active)->exists()) {
            throw ValidationException::withMessages(['offer' => 'Сделка идёт у обоих, объединять руками']);
        }
    }

    /** Предложение второго — в оставшееся (машины сливает `JoinCars`, он и зовёт). */
    public static function lanes(Offer $keep, Offer $drop, ?User $by = null): void
    {
        self::guard($keep, $drop);
        $dropNumber = $drop->number;
        $fill = collect(self::FILL)->filter(fn ($f) => blank($keep->getAttribute($f)) && filled($drop->getAttribute($f)))
            ->mapWithKeys(fn ($f) => [$f => $drop->getAttribute($f)])->all();
        $tags = array_values(array_unique([...($keep->tags ?? []), ...($drop->tags ?? [])]));
        $colors = ($keep->tag_colors ?? []) + ($drop->tag_colors ?? []);
        foreach (Rows::referencing('offers') as [$table, $column]) {
            Rows::repoint($table, $column, $drop->id, $keep->id);
        }
        Rows::repoint('vin_facts', 'source_id', $drop->id, $keep->id, "source_type = 'offer'");
        foreach (['subject', 'href'] as $key) {
            DatabaseNotification::where("data->{$key}", '/offers/'.$dropNumber)->get()
                ->each(fn ($n) => $n->forceFill(['data' => [$key => '/offers/'.$keep->number] + $n->data])->save());
        }
        // Второе больше ничего не держит: его запись уходит без событий — машину сливает `JoinCars`.
        Offer::withoutEvents(fn () => Offer::withoutGlobalScopes()->whereKey($drop->id)->delete());
        DB::table('offer_number_aliases')->insertOrIgnore(['number' => $dropNumber, 'offer_id' => $keep->id]);
        $keep->forceFill($fill + ['tags' => $tags, 'tag_colors' => $colors])->saveQuietly();
        $keep->log(OfferEventType::Note, $by, ['text' => "Объединено с №{$dropNumber}"]);
    }
}
