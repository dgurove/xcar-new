<?php

namespace App\Park\Actions;

use App\Offers\Actions\MergeOffers;
use App\Offers\Offer;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Nav;
use App\Support\Rows;
use App\Users\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Две ТС парковки на одну машину (номер убытка или VIN, `Cars\Identity`) — в одну. Остаётся та, что дальше ушла
 * (`pick`), вторая отдаёт ей заявки, историю, осмотры, пропуска, счета, письма, бумаги, кадры (повтор по отпечатку
 * `sha` не переносится) и пустые поля. У обеих своё предложение — они той же машины и объединяются следом
 * (`MergeOffers`). Связи ищутся по внешним ключам базы.
 */
final class MergeVehicles
{
    private const FILL = ['ref', 'vin', 'brand_id', 'model_id', 'year', 'plate', 'color', 'category', 'vendor_id', 'yard_id', 'spot',
        'contact_name', 'contact_phone', 'value', 'policy_no', 'mileage', 'pts', 'sts', 'owner_party_id', 'contract_kind',
        'contract_no', 'contract_at', 'assigned_price', 'pickup_name', 'pickup_phone', 'buyer_party_id', 'accepted_at'];

    /** Что остаётся: на парковке или прошла её → ждём → отменена; при равных — с деньгами, с предложением, старше. */
    public static function pick(Collection $vehicles): Vehicle
    {
        return $vehicles->sortByDesc(fn (Vehicle $v) => [
            match ($v->state) {
                VehicleState::Cancelled => 0,
                VehicleState::Expected => 1,
                default => 2,
            },
            $v->invoices()->exists() || $v->charges()->exists() ? 1 : 0,
            $v->offer_id ? 1 : 0,
            -$v->id,
        ])->first();
    }

    public function __invoke(Vehicle $keep, Vehicle $drop, ?User $by = null): void
    {
        if ($keep->is($drop)) {
            throw ValidationException::withMessages(['vehicle' => 'Это одна и та же ТС']);
        }
        $offers = null;
        DB::transaction(function () use ($keep, $drop, $by, &$offers) {
            $fill = collect(self::FILL)->filter(fn ($f) => blank($keep->getAttribute($f)) && filled($drop->getAttribute($f)))
                ->mapWithKeys(fn ($f) => [$f => $drop->getAttribute($f)])->all();

            // Предложение второй: у оставшейся своего нет — едет к ней, есть — два предложения одной машины, объединим следом.
            if ($drop->offer_id && $drop->offer_id !== $keep->offer_id) {
                if ($keep->offer_id) {
                    $offers = [$keep->offer_id, $drop->offer_id];
                    Media::where('model_type', Vehicle::class)->where('model_id', $drop->id)->where('custom_properties->offer', (string) $drop->offer_id)
                        ->get()->each(fn (Media $m) => $m->setCustomProperty('offer', (string) $keep->offer_id)->save());
                } else {
                    $fill['offer_id'] = $drop->offer_id;
                }
                DB::table('park_vehicles')->where('id', $drop->id)->update(['offer_id' => null]);
            }

            $known = Media::where('model_type', Vehicle::class)->where('model_id', $keep->id)->get()
                ->flatMap(fn (Media $m) => array_filter([$m->getCustomProperty('sha'), $m->getCustomProperty('sent_sha')]))->flip();
            $moved = 0;
            foreach (Media::where('model_type', Vehicle::class)->where('model_id', $drop->id)->get() as $media) {
                $sha = $media->getCustomProperty('sha');
                if ($sha && $known->has($sha)) {
                    continue;
                }
                $media->forceFill(['model_id' => $keep->id])->save();
                $sha && $known->put($sha, true);
                $moved++;
            }

            foreach (Rows::referencing('park_vehicles') as [$table, $column]) {
                Rows::repoint($table, $column, $drop->id, $keep->id);
            }
            Rows::repoint('vin_facts', 'source_id', $drop->id, $keep->id, "source_type = 'park_vehicle'");
            DatabaseNotification::where('data->subject', '/cars/'.$drop->id)->get()
                ->each(fn ($n) => $n->forceFill(['data' => ['subject' => '/cars/'.$keep->id] + $n->data])->save());

            $identity = array_intersect_key($fill, array_flip(['ref', 'vin', 'offer_id']));
            $keep->fill(array_diff_key($fill, $identity))->save();
            $keep->log(EventType::Note, $by, ['text' => 'Объединена с ТС #'.$drop->id.($moved ? ', кадров и документов перенесено '.$moved : '')]);
            DB::table('park_vehicles')->where('id', $drop->id)->update(['pickup_code' => null]);
            $drop->refresh()->delete();
            if ($identity) {
                $keep->fill($identity)->save();
            }
        });
        if ($offers) {
            [$keepOffer, $dropOffer] = [Offer::withoutGlobalScopes()->find($offers[0]), Offer::withoutGlobalScopes()->find($offers[1])];
            app(MergeOffers::class)($keepOffer, $dropOffer, $by);
        }
        Nav::forgetStaffCounts();
    }
}
