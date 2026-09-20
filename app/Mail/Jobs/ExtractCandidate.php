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
use App\Mail\Thread;
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
        self::run($message, $extractor, force: false);
    }

    /**
     * Письмо → кандидат. Сначала ищется открытый кандидат с тем же номером, VIN, госномером или веткой:
     * письмо «ч.2» без VIN и от неопознанного отправителя всё равно его. Нового кандидата заводим, только
     * если письмо похоже на заявку (`force` — руками из почты: заводим как есть).
     */
    public static function run(Message $message, ?Extractor $extractor = null, bool $force = false, bool $quiet = false): ?Candidate
    {
        $extractor ??= app(Extractor::class);
        $park = $message->account->scope === Scope::Park;
        $body = $message->text_body ?: $message->html_body;
        $message->loadMissing('attachments');
        $fields = $park ? app(ParkExtractor::class)->extract($message->subject, $body, $message->from_email, $message->date_at, $message->attachments->pluck('filename')->all(), $message->attachments)
            : $extractor->extract($message->subject, $body, $message->from_email, $message->date_at);
        $code = Code::normalize($fields['code']['value'] ?? null);
        $scope = $park ? Scope::Park : Scope::Offers;
        $identities = [...Candidate::identities($fields, $message->thread_id), ...($message->thread?->keys ?? [])];
        $identities = array_values(array_unique($identities));
        // Открытых кандидатов десятки — сверяем тождества в PHP: совпасть может ключ, VIN из полей или ветка любого письма.
        $existing = Candidate::where('scope', $scope)->whereIn('state', [CandidateState::New, CandidateState::Rejected])->with('messages')->get()
            ->map(fn (Candidate $c) => [$c, array_intersect($identities, $c->allIdentities())])
            ->filter(fn ($pair) => $pair[1] !== [])
            ->sortBy(fn ($pair) => min(array_keys($pair[1])))
            ->map(fn ($pair) => $pair[0])->first();
        if ($existing) {
            if ($existing->messages->contains('id', $message->id)) {
                return $existing;
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
            self::adopt($existing, $message);
            ImportCandidateFiles::dispatch($existing->id, $message->id);

            return $existing;
        }
        if (! $force && ($park ? ! ParkExtractor::looksLikeRequest($fields) : ! Extractor::looksLikeOffer($fields))) {
            return null;
        }
        // Машина уже заведена — по номеру, VIN или госномеру: кандидат не нужен, ветку к ней привяжет LinkThread.
        if (! $force && self::known($park, $code, $fields)) {
            return null;
        }
        $candidate = Candidate::create([
            'scope' => $scope, 'code' => $code, 'key' => Candidate::identities($fields, $message->thread_id)[0] ?? 'message:'.$message->id, 'vendor_id' => $fields['vendor_id']['value'] ?? null,
            'message_id' => $message->id, 'thread_id' => $message->thread_id, 'subject' => $message->subject, 'extracted' => $fields,
            'messages_count' => 1, 'last_message_at' => $message->date_at ?? now(),
        ]);
        $candidate->messages()->attach($message->id, ['created_at' => now()]);
        self::adopt($candidate, $message);
        ImportCandidateFiles::dispatch($candidate->id, $message->id);
        if ($park && ! $quiet) {
            CandidateArrived::dispatch($candidate);
        }

        return $candidate;
    }

    /** Ветка письма — за кандидатом (секция в почте); кандидат в архиве — ветка тоже. */
    private static function adopt(Candidate $candidate, Message $message): void
    {
        if ($message->thread_id) {
            Thread::whereKey($message->thread_id)->whereNull('candidate_id')->update(['candidate_id' => $candidate->id]);
        }
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
