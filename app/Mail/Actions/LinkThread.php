<?php

namespace App\Mail\Actions;

use App\Mail\Extraction\CodeMatcher;
use App\Mail\Extraction\Keys;
use App\Mail\Extraction\ParkExtractor;
use App\Mail\Jobs\ExtractCandidate;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Offers\Offer;
use App\Park\Vehicle;
use App\Park\VehicleState;

/**
 * Ветка ↔ машина (оффер или машина стоянки): по коду убытка в теме или теле,
 * по VIN, либо руками. Одна дверь: как бы ни привязали, файлы ветки едут в
 * медиатеку машины (ImportThreadFiles).
 */
final class LinkThread
{
    public function __construct(private CodeMatcher $matcher) {}

    public function __invoke(Thread $thread, Offer|Vehicle $to): void
    {
        $thread->update(($to instanceof Offer ? ['offer_id' => $to->id] : ['vehicle_id' => $to->id]) + ['unlinked_at' => null]);
        ImportThreadFiles::dispatch($thread->id);
    }

    /**
     * ТС завели (руками или из письма): все непривязанные ветки с её номером, VIN или госномером — к ней,
     * включая наши ответы из почтового клиента и письма «ч.2», пришедшие отдельной веткой.
     */
    public function forVehicle(Vehicle $vehicle): int
    {
        $keys = Keys::ofVehicle($vehicle);
        $threads = Thread::withAnyKey($keys)->whereNull('vehicle_id')->whereNull('unlinked_at')
            ->whereHas('account', fn ($a) => $a->where('scope', Scope::Park))->get();
        foreach ($threads as $thread) {
            $this($thread, $vehicle);
        }

        return $threads->count();
    }

    public function forOffer(Offer $offer): int
    {
        $keys = Keys::ofOffer($offer);
        $threads = Thread::withAnyKey($keys)->whereNull('offer_id')->whereNull('unlinked_at')
            ->whereHas('account', fn ($a) => $a->where('scope', Scope::Offers))->get();
        foreach ($threads as $thread) {
            $this($thread, $offer);
        }

        return $threads->count();
    }

    /** Отвязать руками: ссылка обнуляется, автопривязка по номеру или VIN эту ветку больше не трогает; файлы и blobs остаются. */
    public function unlink(Thread $thread): void
    {
        $thread->update(['offer_id' => null, 'vehicle_id' => null, 'unlinked_at' => now()]);
    }

    public function auto(Message $message): Offer|Vehicle|null
    {
        $thread = $message->thread;
        if (! $thread) {
            return null;
        }
        if ($message->account->scope === Scope::Park) {
            return $this->autoPark($message, $thread);
        }
        if ($thread->offer_id || $thread->unlinked_at) {
            return null;
        }
        $codes = array_unique([...$this->matcher->findAll($message->subject), ...$this->matcher->findAll($message->text_body ?: $message->html_body)]);
        foreach ($codes as $code) {
            if ($offer = Offer::where('claim_ref_key', ExtractCandidate::key($code))->first()) {
                $this($thread, $offer);

                return $offer;
            }
        }
        if ($vin = $this->vin($message)) {
            if ($offer = Offer::where('vin', $vin)->latest()->first()) {
                $this($thread, $offer);

                return $offer;
            }
        }

        return null;
    }

    /** Стоянка: ветка ↔ машина по номеру убытка, VIN или госномеру. */
    private function autoPark(Message $message, Thread $thread): ?Vehicle
    {
        if ($thread->vehicle_id || $thread->unlinked_at) {
            return null;
        }
        $text = $message->subject.' '.($message->text_body ?: $message->html_body);
        $fields = app(ParkExtractor::class)->extract($message->subject, $message->text_body ?: $message->html_body, $message->from_email, null, $message->attachments->pluck('filename')->all(), $message->attachments);
        // Только живая ТС: письмо о выданной или отменённой — новый заезд, ему место в «Из писем».
        $live = fn () => Vehicle::whereNotIn('state', [VehicleState::Released, VehicleState::Cancelled])->latest();
        $vehicle = null;
        if ($code = $fields['code']['value'] ?? null) {
            $vehicle = $live()->where('ref_key', Vehicle::keyFor($code))->first();
        }
        $vehicle ??= ($vin = $this->vin($message)) ? $live()->where('vin', $vin)->first() : null;
        $vehicle ??= ($plate = $fields['plate']['value'] ?? null) ? $live()->where('plate', mb_strtoupper($plate))->first() : null;
        if ($vehicle) {
            $this($thread, $vehicle);
        }

        return $vehicle;
    }

    private function vin(Message $message): ?string
    {
        $text = mb_strtoupper($message->subject.' '.($message->text_body ?: ''));

        return preg_match('/\b[A-HJ-NPR-Z0-9]{17}\b/', $text, $m) && strlen(count_chars($m[0], 3)) > 1 ? $m[0] : null;
    }
}
