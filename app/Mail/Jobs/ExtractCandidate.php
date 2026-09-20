<?php

namespace App\Mail\Jobs;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Direction;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\Extractor;
use App\Mail\Extraction\ParkExtractor;
use App\Mail\Message;
use App\Mail\Scope;
use App\Offers\Offer;
use App\Park\Events\CandidateArrived;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Из входящего письма — кандидат в офферы или на стоянку; письма об одной ТС ложатся в одного кандидата, письмо по уже заведённой ТС кандидатом не становится. */
final class ExtractCandidate implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId)
    {
        $this->onConnection('database-long')->onQueue('mail');
    }

    public function handle(Extractor $extractor): void
    {
        $message = Message::with(['thread', 'account'])->find($this->messageId);
        if (! $message || $message->direction !== Direction::In) {
            return;
        }
        $park = $message->account->scope === Scope::Park;
        $body = $message->text_body ?: $message->html_body;
        $fields = $park ? (new ParkExtractor)->extract($message->subject, $body, $message->from_email, $message->date_at)
            : $extractor->extract($message->subject, $body, $message->from_email, $message->date_at);
        $code = Code::normalize($fields['code']['value'] ?? null);
        if ($park ? ! ParkExtractor::looksLikeRequest($fields) : ! Extractor::looksLikeOffer($fields)) {
            return;
        }
        // Машина уже заведена — по номеру, VIN или госномеру: кандидат не нужен, ветку к ней привяжет LinkThread::auto.
        if (self::known($park, $code, $fields)) {
            return;
        }

        $scope = $park ? Scope::Park : Scope::Offers;
        $identities = Candidate::identities($fields, $message->thread_id);
        // Открытых кандидатов десятки — сверяем тождества в PHP: совпасть может ключ, VIN из полей или ветка любого письма.
        $existing = Candidate::where('scope', $scope)->whereIn('state', [CandidateState::New, CandidateState::Rejected])->with('messages')->get()
            ->map(fn (Candidate $c) => [$c, array_intersect($identities, $c->allIdentities())])
            ->filter(fn ($pair) => $pair[1] !== [])
            ->sortBy(fn ($pair) => min(array_keys($pair[1])))
            ->map(fn ($pair) => $pair[0])->first();
        if (! $existing) {
            $candidate = Candidate::create([
                'scope' => $scope, 'code' => $code, 'key' => $identities[0] ?? 'message:'.$message->id, 'vendor_id' => $fields['vendor_id']['value'] ?? null,
                'message_id' => $message->id, 'thread_id' => $message->thread_id, 'subject' => $message->subject, 'extracted' => $fields,
                'messages_count' => 1, 'last_message_at' => $message->date_at ?? now(),
            ]);
            $candidate->messages()->attach($message->id, ['created_at' => now()]);
            ImportCandidateFiles::dispatch($candidate->id, $message->id);
            if ($park) {
                CandidateArrived::dispatch($candidate);
            }

            return;
        }
        // Ещё письмо о той же ТС: письмо в список, новые поля дописываются к прежним, свежие ложатся рядом,
        // ключ поднимается до самого сильного (ветка → VIN → номер). Владельца не дёргаем — ТС та же.
        $existing->messages()->syncWithoutDetaching([$message->id => ['created_at' => now()]]);
        $existing->update([
            'extracted' => $fields + ($existing->extracted ?? []), 'proposed' => $fields,
            'code' => $existing->code ?? $code, 'key' => Candidate::strongest([$existing->key, ...$identities]),
            'thread_id' => $existing->thread_id ?? $message->thread_id, 'vendor_id' => $existing->vendor_id ?? ($fields['vendor_id']['value'] ?? null),
            'messages_count' => $existing->messages()->count(), 'last_message_at' => max($existing->last_message_at, $message->date_at) ?? now(),
        ]);
        ImportCandidateFiles::dispatch($existing->id, $message->id);
    }

    /** ТС или предложение с таким номером, VIN или госномером уже есть. */
    private static function known(bool $park, ?string $code, array $fields): bool
    {
        $vin = isset($fields['vin']['value']) ? strtoupper((string) $fields['vin']['value']) : null;
        $plate = Candidate::plateKey($fields['plate']['value'] ?? null);
        if ($park) {
            // Выданная или отменённая ТС не в счёт: второй заезд той же машины — новая заявка.
            $live = fn () => Vehicle::whereNotIn('state', [VehicleState::Released, VehicleState::Cancelled]);

            return ($code && $live()->where('ref_key', self::key($code))->exists())
                || ($vin && $live()->where('vin', $vin)->exists())
                || ($plate && $live()->where('plate', $plate)->exists());
        }

        return ($code && Offer::where('claim_ref_key', self::key($code))->exists()) || ($vin && Offer::where('vin', $vin)->exists());
    }

    public static function key(string $code): string
    {
        return (string) Code::key($code);
    }
}
