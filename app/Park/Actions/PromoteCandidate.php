<?php

namespace App\Park\Actions;

use App\Mail\Actions\LinkThread;
use App\Mail\Actions\MarkThreadRead;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Scope;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Nav;
use Illuminate\Support\Collection;

/**
 * Письма о хранении → ТС и заявка заводятся формой `/requests/new?candidate=` с полями из письма;
 * здесь — что после этого: кандидат помечен, кадр карточки больше не нужен, все его ветки привязаны, их файлы едут в дело.
 * Если такую ТС уже завели руками, второй не будет: `existing()` находит её по убытку, VIN, госномеру.
 */
final class PromoteCandidate
{
    public function __construct(private LinkThread $link, private MarkThreadRead $read) {}

    public function attach(Candidate $candidate, Vehicle $vehicle): void
    {
        // У выданной (заведена по факту уже выданной) файлы из писем не нужны — ветки привязываются без импорта.
        $files = $vehicle->state !== VehicleState::Released;
        $candidate->update(['state' => CandidateState::Promoted, 'vehicle_id' => $vehicle->id, 'closed_at' => null]);
        foreach ($candidate->threads() as $thread) {
            if ($thread->vehicle_id !== $vehicle->id) {
                ($this->link)($thread, $vehicle, $files);
            }
            // Заведена — письма обработаны, непрочитанных в ветке не остаётся.
            ($this->read)($thread);
        }
        // Письма с тем же номером, которые кандидату не достались (наши ответы, «ч.2» до разбора), тоже к ТС.
        $this->link->forVehicle($vehicle, $files);
    }

    /**
     * ТС завели руками, а цепочка о ней уже ждала в «Из писем» — она заводится вместе с ТС: письма к делу,
     * из «Ждут» уходит. Письма о заведённой ТС цепочку не начинают (`ChainBuilder::known`), так что остаётся
     * только этот случай: ТС появилась позже первого письма.
     */
    public function forVehicle(Vehicle $vehicle): int
    {
        // Выданная и отменённая не в счёт: письмо о той же машине — новый заезд, ему место в «Из писем» (как в `ChainBuilder::known`).
        if ($vehicle->state->isFinal()) {
            return 0;
        }
        $candidates = $this->openFor($vehicle);
        foreach ($candidates as $candidate) {
            $this->attach($candidate, $vehicle);
        }
        if ($candidates->isNotEmpty()) {
            Nav::forgetStaffCounts();
        }

        return $candidates->count();
    }

    /** ТС с тем же номером убытка, VIN или госномером, не выданная и не отменённая. */
    public function existing(Candidate $candidate): ?Vehicle
    {
        $live = fn () => Vehicle::whereNotIn('state', [VehicleState::Released, VehicleState::Cancelled])->latest();
        $vin = $candidate->value('vin') ? strtoupper((string) $candidate->value('vin')) : null;
        $plate = Candidate::plateKey($candidate->value('plate'));

        return ($candidate->code ? $live()->where('ref_key', Vehicle::keyFor($candidate->code))->first() : null)
            ?? ($vin ? $live()->where('vin', $vin)->first() : null)
            ?? ($plate ? $live()->where('plate', $plate)->first() : null);
    }

    /**
     * Ждущие цепочки стоянки с тождеством этой ТС: грубо запросом, точно — тем же `Code::key`.
     * Архив («Не заявка») не трогаем: это решение человека, и письма там заморожены.
     *
     * @return Collection<int, Candidate>
     */
    private function openFor(Vehicle $vehicle): Collection
    {
        $plate = Candidate::plateKey($vehicle->plate);
        if (! $vehicle->ref_key && ! $vehicle->vin && ! $plate) {
            return collect();
        }

        return Candidate::where('scope', Scope::Park)->where('state', CandidateState::New)
            ->where(function ($q) use ($vehicle, $plate) {
                $q->whereRaw('false');
                if ($vehicle->ref_key) {
                    $q->orWhereRaw("lower(regexp_replace(coalesce(code, ''), '[^[:alnum:]]', '', 'g')) = ?", [$vehicle->ref_key]);
                }
                if ($vehicle->vin) {
                    $q->orWhereRaw("upper(extracted->'vin'->>'value') = ?", [$vehicle->vin]);
                }
                if ($plate) {
                    $q->orWhereRaw("upper(replace(extracted->'plate'->>'value', ' ', '')) = ?", [$plate]);
                }
            })->get()
            ->filter(fn (Candidate $c) => ($vehicle->ref_key && $c->code && Vehicle::keyFor($c->code) === $vehicle->ref_key)
                || ($vehicle->vin && $c->value('vin') && strtoupper((string) $c->value('vin')) === $vehicle->vin)
                || ($plate && Candidate::plateKey($c->value('plate')) === $plate))
            ->values();
    }
}
