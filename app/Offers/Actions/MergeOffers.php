<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Park\PhotoStage;
use App\Park\Vehicle;
use App\Support\Nav;
use App\Support\Rows;
use App\Users\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Два предложения на одну машину (номер убытка или VIN, `Cars\Identity`) — в одно. Остаётся то, что дальше ушло
 * (`pick`), второе отдаёт ему всё: подтверждения, сделки, историю, письма, лоты Мигторга, ТС парковки, фото и
 * документы (кадр, что уже есть по отпечатку `sha`, не дублируется), пустые поля. Его номер становится псевдонимом —
 * старые ссылки открывают оставшееся. Связи ищутся по внешним ключам базы, а не списком: новая таблица не потеряется.
 */
final class MergeOffers
{
    /** Поля, которые второе заполняет у оставшегося, если там пусто. */
    private const FILL = ['brand_id', 'model_id', 'year', 'mileage', 'vin', 'body', 'transmission', 'drive', 'fuel', 'engine_volume',
        'engine_power', 'color', 'damage_cause', 'damage_zones', 'papers', 'incident_date', 'description', 'settlement_id',
        'inspection_address', 'floor_price', 'owner_price', 'value', 'publish_price', 'asking_price', 'min_bid_price', 'vendor_id',
        'claim_ref', 'insurer_deadline_at', 'car_place', 'answer_by', 'insured_name', 'insured_phone', 'holder', 'contact_name',
        'contact_email', 'evacuator_id', 'evacuation_to'];

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
            Vehicle::where('offer_id', $o->id)->exists() ? 1 : 0,
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
        if (Deal::where('offer_id', $keep->id)->where('state', DealState::Active)->exists() && Deal::where('offer_id', $drop->id)->where('state', DealState::Active)->exists()) {
            throw ValidationException::withMessages(['offer' => 'Сделка идёт у обоих — объединять руками']);
        }
        $keepVehicle = Vehicle::where('offer_id', $keep->id)->first();
        $dropVehicle = Vehicle::where('offer_id', $drop->id)->first();
        if ($keepVehicle && $dropVehicle) {
            throw ValidationException::withMessages(['offer' => 'У обоих своя ТС парковки — сначала объединить ТС']);
        }

        $result = DB::transaction(function () use ($keep, $drop, $keepVehicle, $dropVehicle, $by) {
            $dropNumber = $drop->number;
            $fill = collect(self::FILL)->filter(fn ($f) => blank($keep->getAttribute($f)) && filled($drop->getAttribute($f)))
                ->mapWithKeys(fn ($f) => [$f => $drop->getAttribute($f)])->all();
            $tags = array_values(array_unique([...($keep->tags ?? []), ...($drop->tags ?? [])]));
            $colors = ($keep->tag_colors ?? []) + ($drop->tag_colors ?? []);

            // ТС парковки второго — к оставшемуся: его кадры, принесённые вторым, теперь кадры оставшегося.
            if ($dropVehicle) {
                Media::where('model_type', Vehicle::class)->where('model_id', $dropVehicle->id)->where('custom_properties->offer', (string) $drop->id)
                    ->get()->each(fn (Media $m) => $m->setCustomProperty('offer', (string) $keep->id)->save());
                DB::table('park_vehicles')->where('id', $dropVehicle->id)->update(['offer_id' => null]);
                $keepVehicle = $dropVehicle;
            }
            $photos = $this->media($keep, $drop, $keepVehicle);

            // ТС парковки перенесена выше: `offer_id` у неё уникален.
            foreach (array_filter(Rows::referencing('offers'), fn ($r) => $r[0] !== 'park_vehicles') as [$table, $column]) {
                Rows::repoint($table, $column, $drop->id, $keep->id);
            }
            Rows::repoint('vin_facts', 'source_id', $drop->id, $keep->id, "source_type = 'offer'");
            foreach (['subject', 'href'] as $key) {
                DatabaseNotification::where("data->{$key}", '/offers/'.$dropNumber)->get()
                    ->each(fn ($n) => $n->forceFill(['data' => [$key => '/offers/'.$keep->number] + $n->data])->save());
            }

            // Номер убытка и VIN второго у оставшегося встают только после его удаления: до того они ещё его.
            $identity = array_intersect_key($fill, array_flip(['claim_ref', 'vin']));
            $keep->fill(array_diff_key($fill, $identity) + ['tags' => $tags, 'tag_colors' => $colors])->save();
            $keep->log(OfferEventType::Note, $by, ['text' => "Объединено с №{$dropNumber}".($photos['photos'] ? ', фото и документов перенесено '.$photos['photos'] : '')]);

            // Второе больше ничего не держит: удаляется (кадры-повторы — spatie вместе с ним).
            $drop->refresh()->delete();
            DB::table('offer_number_aliases')->insertOrIgnore(['number' => $dropNumber, 'offer_id' => $keep->id]);
            if ($identity) {
                $keep->fill($identity)->save();
            }
            if ($dropVehicle) {
                $dropVehicle->update(['offer_id' => $keep->id]);
            }

            return $photos;
        });
        Nav::forgetStaffCounts();

        return $result;
    }

    /**
     * Кадры и документы второго — к оставшемуся (а у него ТС парковки — к ТС, как `Park\Sale::bring`); те, что уже
     * есть по отпечатку, остаются у второго и уйдут вместе с ним.
     */
    private function media(Offer $keep, Offer $drop, ?Vehicle $vehicle): array
    {
        $home = Media::where(fn ($q) => $q->where('model_type', Offer::class)->where('model_id', $keep->id))
            ->when($vehicle, fn ($q) => $q->orWhere(fn ($w) => $w->where('model_type', Vehicle::class)->where('model_id', $vehicle->id)))
            ->get();
        $known = $home->flatMap(fn (Media $m) => array_filter([$m->getCustomProperty('sha'), $m->getCustomProperty('sent_sha')]))->flip();
        $moved = $dropped = 0;
        // ТС пришла от второго: свои кадры оставшегося, что уже есть у неё, при связи (`Sale::bring`) стали бы вторыми.
        if ($vehicle && $vehicle->offer_id !== $keep->id) {
            $own = Media::where('model_type', Vehicle::class)->where('model_id', $vehicle->id)->get()
                ->flatMap(fn (Media $m) => array_filter([$m->getCustomProperty('sha'), $m->getCustomProperty('sent_sha')]))->flip();
            Media::where('model_type', Offer::class)->where('model_id', $keep->id)->get()
                ->filter(fn (Media $m) => $own->has((string) $m->getCustomProperty('sha')))->each->delete();
        }
        foreach (Media::where('model_type', Offer::class)->where('model_id', $drop->id)->get() as $media) {
            $sha = $media->getCustomProperty('sha');
            if ($sha && $known->has($sha)) {
                $dropped++;

                continue;
            }
            if ($vehicle) {
                if ($media->collection_name === 'photos' && ! $media->getCustomProperty('stage')) {
                    $media->setCustomProperty('stage', PhotoStage::Vendor->value);
                }
                $media->setCustomProperty('offer', (string) $keep->id);
                $media->forceFill(['model_type' => Vehicle::class, 'model_id' => $vehicle->id]);
            } else {
                $media->forceFill(['model_id' => $keep->id]);
            }
            $media->save();
            $sha && $known->put($sha, true);
            $moved++;
        }

        return ['photos' => $moved, 'dropped' => $dropped];
    }
}
