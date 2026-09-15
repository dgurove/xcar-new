<?php

namespace App\Offers\Actions;

use App\Cars\Vin\RememberVin;
use App\Media\Jobs\StampPhotos;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use App\Workflow\Actions\StartRoute;
use App\Workflow\DeadlineSource;
use App\Workflow\Position;

final class UpdateOffer
{
    public function __construct(private StartRoute $startRoute) {}

    public function __invoke(Offer $offer, array $data, User $by): Offer
    {
        if (array_key_exists('vin', $data)) {
            $data['vin'] = $data['vin'] ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $data['vin'])) : null;
        }
        // Круг менеджеров идёт отдельным списком: сужен — синхронизируем, снят — список чистим.
        $managers = $data['managers'] ?? null;
        unset($data['managers']);
        $offer->fill($data);
        $changed = array_keys($offer->getDirty());
        $offer->save();
        if (array_key_exists('managers_limited', $data)) {
            $before = $offer->managers()->pluck('users.id')->sort()->values()->all();
            $after = $offer->managers_limited ? collect($managers ?? [])->map(fn ($v) => (int) $v)->unique()->sort()->values()->all() : [];
            if ($before !== $after) {
                $offer->managers()->sync($after);
                $changed[] = 'managers';
            }
        }
        if ($changed) {
            $offer->log(OfferEventType::Updated, $by, ['fields' => $changed]);
            (new RememberVin)($offer);
        }
        if (in_array('share_locked', $changed, true)) {
            StampPhotos::dispatch($offer);
        }
        if (in_array('insurer_deadline_at', $changed, true)) {
            // Срок от страховой могли вписать после того, как оффер встал на этап.
            Position::where('offer_id', $offer->id)->whereHas('stage', fn ($s) => $s->where('deadline_source', DeadlineSource::InsurerDeadline))
                ->update(['deadline_at' => $offer->insurer_deadline_at?->copy()->endOfDay(), 'reminded_at' => null, 'overdue_at' => null]);
        }
        if ($offer->insurer_id) {
            $offer = ($this->startRoute)($offer->load('insurer.workflows'), $by);
        }

        return $offer;
    }
}
