<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Offers\Signing;
use App\Users\User;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** «Поставщик прислал время»: свободное время и адрес филиала — сделке, шаг к менеджеру «Выберите время» (`Signing`). */
final class OfferSigningSlots
{
    public function __construct(private TakeExit $take) {}

    /** @param  list<string>  $slots */
    public function __invoke(Deal $deal, Outcome $exit, User $by, array $slots, ?string $address): Deal
    {
        abort_unless(Signing::slotsExit($exit) && $deal->isActive() && $deal->offer?->position()?->stage_id === $exit->stage_id, 404);
        $times = collect($slots)->map(fn ($s) => trim((string) $s))->filter()->map(function (string $s) {
            try {
                return Carbon::parse($s);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['slots' => 'Не разобрать время: '.$s]);
            }
        })->filter(fn (Carbon $c) => $c->isFuture())->unique(fn (Carbon $c) => $c->format('Y-m-d H:i'))->sort()->values();
        if ($times->isEmpty()) {
            throw ValidationException::withMessages(['slots' => 'Добавьте хотя бы одно время']);
        }
        // Часы филиала вендора (`vendors.signing_office`) — время вне них, скорее всего, опечатка.
        if ($deal->offer?->vendor?->signing_office && ($why = $times->map(fn (Carbon $c) => Signing::outsideHours($deal, $c))->filter()->first())) {
            throw ValidationException::withMessages(['slots' => ucfirst($why)]);
        }

        return DB::transaction(function () use ($deal, $exit, $by, $times, $address) {
            $deal->update([
                'signing_slots' => $times->map(fn (Carbon $c) => $c->format('Y-m-d H:i'))->all(),
                'signing_address' => trim((string) $address) ?: null,
                'signing_at' => null,
            ]);
            ($this->take)($deal->offer, $exit, Actor::Staff, $by);

            return $deal->refresh();
        });
    }
}
