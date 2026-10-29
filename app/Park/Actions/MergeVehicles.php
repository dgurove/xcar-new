<?php

namespace App\Park\Actions;

use App\Cars\Actions\JoinCars;
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

/**
 * Две ТС парковки одной машины — в одну. ТС разных записей машины, поэтому это объединение машин (`JoinCars`):
 * предложение, лоты закупки, фото и документы (повтор по `sha` не переносится) — к машине оставшейся, а сама вторая ТС
 * (`lanes`) отдаёт оставшейся заявки, историю, осмотры, пропуска, счета, письма, бумаги и пустые поля хранения. Связи
 * ищутся по внешним ключам базы.
 */
final class MergeVehicles
{
    /** Поля хранения, которые вторая заполняет у оставшейся, если там пусто (поля машины сливает `JoinCars`). */
    private const FILL = ['vendor_id', 'yard_id', 'spot', 'owner_party_id', 'contract_kind', 'contract_no', 'contract_at', 'assigned_price',
        'pickup_name', 'pickup_phone', 'buyer_party_id', 'accepted_at', 'fuel'];

    public function __construct(private JoinCars $join) {}

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
            $v->offer()->exists() ? 1 : 0,
            -$v->id,
        ])->first();
    }

    public function __invoke(Vehicle $keep, Vehicle $drop, ?User $by = null): void
    {
        if ($keep->is($drop)) {
            throw ValidationException::withMessages(['vehicle' => 'Это одна и та же ТС']);
        }
        ($this->join)($drop->carRecord(), $keep->carRecord(), $by, keepVehicle: $keep);
        Nav::forgetStaffCounts();
    }

    /** Вторая ТС — в оставшуюся (машины сливает `JoinCars`, он и зовёт). */
    public static function lanes(Vehicle $keep, Vehicle $drop, ?User $by = null): void
    {
        $fill = collect(self::FILL)->filter(fn ($f) => blank($keep->getAttribute($f)) && filled($drop->getAttribute($f)))
            ->mapWithKeys(fn ($f) => [$f => $drop->getAttribute($f)])->all();
        foreach (Rows::referencing('park_vehicles') as [$table, $column]) {
            Rows::repoint($table, $column, $drop->id, $keep->id);
        }
        DatabaseNotification::where('data->subject', '/cars/'.$drop->id)->get()
            ->each(fn ($n) => $n->forceFill(['data' => ['subject' => '/cars/'.$keep->id] + $n->data])->save());
        // Вторая больше ничего не держит: её запись уходит без событий — машину сливает `JoinCars`.
        DB::table('park_vehicles')->where('id', $drop->id)->delete();
        $keep->forceFill($fill)->saveQuietly();
        $keep->log(EventType::Note, $by, ['text' => 'Объединена с ТС #'.$drop->id]);
    }
}
