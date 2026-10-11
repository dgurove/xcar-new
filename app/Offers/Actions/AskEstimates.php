<?php

namespace App\Offers\Actions;

use App\Media\Jobs\StampPhotos;
use App\Offers\Estimate;
use App\Offers\EstimateState;
use App\Offers\Events\EstimatesAsked;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Отдать менеджеру на оценку» (11.10.2026, начальник): черновик без цены продажи — одному или нескольким менеджерам, у
 * каждого 48 часов. Черновик с ценой («Оцененные», «Спросить менеджеров») — на корректировку: «Согласен» или своя цена. Пока машина на оценке, шеринг запрещён и на фото знак: запрет ставит оценка и помнит это
 * (`share_locked_by_estimate`), чтобы снять его самой (`CloseEstimates`); запрет, поставленный админом, она не трогает.
 */
final class AskEstimates
{
    /** @param  iterable<User>  $managers */
    public function __invoke(Offer $offer, iterable $managers, ?User $by): Collection
    {
        // Без цены продажи — оценка; с ценой (вкладка «Оцененные», 11.10.2026) — корректировка: её цена в `base_price`.
        if ($offer->state !== OfferState::Draft || $offer->isScheduled()) {
            throw ValidationException::withMessages(['managers' => 'Спросить можно черновик, пока он не в слоте']);
        }
        $base = $offer->asking_price ?: null;
        $managers = collect($managers)->filter(fn (User $u) => $u->isManager())->unique('id');
        if ($managers->isEmpty()) {
            throw ValidationException::withMessages(['managers' => 'Выберите менеджера']);
        }

        $created = DB::transaction(function () use ($offer, $managers, $by, $base) {
            $open = $offer->estimates()->open()->pluck('user_id')->all();
            $created = $managers->reject(fn (User $u) => in_array($u->id, $open, true))->map(fn (User $u) => Estimate::create([
                'offer_id' => $offer->id, 'user_id' => $u->id, 'asked_by' => $by?->id,
                'state' => EstimateState::Asked, 'due_at' => now()->addHours(Estimate::HOURS), 'base_price' => $base,
            ]))->values();
            if ($created->isNotEmpty() && ! $offer->share_locked) {
                $offer->forceFill(['share_locked' => true, 'share_locked_by_estimate' => true])->save();
                StampPhotos::dispatch($offer);
            }
            if ($created->isNotEmpty()) {
                $offer->log(OfferEventType::Estimate, $by, array_filter(['do' => 'asked', 'users' => $created->map(fn ($e) => $e->user_id)->all(), 'base' => $base]));
            }

            return $created;
        });

        if ($created->isNotEmpty()) {
            EstimatesAsked::dispatch($offer, $created->each->setRelation('offer', $offer), $by);
        }

        return $created;
    }
}
