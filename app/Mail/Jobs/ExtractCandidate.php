<?php

namespace App\Mail\Jobs;

use App\Mail\Actions\LinkThread;
use App\Mail\Candidate;
use App\Mail\CandidateStage;
use App\Mail\CandidateState;
use App\Mail\Direction;
use App\Mail\Extraction\CandidateStages;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\CodeMatcher;
use App\Mail\Extraction\Extractor;
use App\Mail\Extraction\Intent;
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
use Illuminate\Support\Facades\Cache;

/** Из входящего письма — кандидат в офферы или на стоянку; письма об одной ТС ложатся в одного кандидата, письмо по уже заведённой ТС кандидатом не становится. */
final class ExtractCandidate implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId, public bool $quiet = false)
    {
        $this->onConnection('database-long')->onQueue('mail');
    }

    public function handle(Extractor $extractor): void
    {
        $message = Message::with(['thread', 'account'])->find($this->messageId);
        if (! $message || $message->direction !== Direction::In) {
            return;
        }
        // Два письма одной ветки на двух воркерах заводили двух кандидатов — по одному письму за раз.
        Cache::lock('extract-candidate', 60)->block(60, fn () => self::run($message, $extractor, force: false, quiet: $this->quiet));
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
        if ($park && $message->intent === null) {
            $message->forceFill(['intent' => Intent::ofMessage($message)->value])->saveQuietly();
        }
        // Отчёт-акт, счета, сверка — про десятки машин сразу: ни кандидат, ни письмо к кандидату.
        if ($park && ! $force && $message->intent === Intent::Billing->value) {
            return null;
        }
        $fields = $park ? app(ParkExtractor::class)->extract($message->subject, $body, $message->from_email, $message->date_at, $message->attachments->pluck('filename')->all(), $message->attachments)
            : $extractor->extract($message->subject, $body, $message->from_email, $message->date_at);
        $code = Code::normalize($fields['code']['value'] ?? null);
        $scope = $park ? Scope::Park : Scope::Offers;
        $identities = [...Candidate::identities($fields, $message->thread_id), ...($message->thread?->keys ?? [])];
        // «Выдать ТС покупателю 7892/046/07439/25 и 7814/046/00123/26» — письмо о нескольких машинах, оно каждой.
        $codes = $park ? array_map(fn ($c) => 'code:'.Code::key($c), (new CodeMatcher)->findAll($message->subject)) : [];
        $identities = array_values(array_unique([...$identities, ...$codes]));
        // Открытых кандидатов десятки — сверяем тождества в PHP: совпасть может ключ, VIN из полей или ветка любого письма.
        $matches = Candidate::where('scope', $scope)->whereIn('state', [CandidateState::New, CandidateState::Rejected])->with('messages')->get()
            ->map(fn (Candidate $c) => [$c, array_intersect($identities, $c->allIdentities())])
            ->filter(fn ($pair) => $pair[1] !== [])
            ->sortBy(fn ($pair) => min(array_keys($pair[1])))
            ->map(fn ($pair) => $pair[0])->values();
        $existing = $matches->first();
        if ($existing) {
            if ($existing->messages->contains('id', $message->id)) {
                return $existing;
            }
            // Остальные машины из письма о нескольких — им письмо тоже в цепочку (этап «продана» у каждой).
            if (count($codes) > 1) {
                foreach ($matches->slice(1)->filter(fn (Candidate $c) => $c->code && in_array('code:'.Code::key($c->code), $codes, true)) as $other) {
                    $other->messages()->syncWithoutDetaching([$message->id => ['created_at' => now()]]);
                    $other->update(['messages_count' => $other->messages()->count(), 'last_message_at' => max($other->last_message_at, $message->date_at) ?? now()]);
                    app(CandidateStages::class)->refresh($other);
                }
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
            $park && app(LinkThread::class)->forCandidate($existing->fresh());
            $park && app(CandidateStages::class)->refresh($existing);
            // Выданная цепочка ушла в архив — её файлы на диске не нужны.
            if ($existing->fresh()->stage !== CandidateStage::Released) {
                ImportCandidateFiles::dispatch($existing->id, $message->id);
            }

            return $existing;
        }
        if (! $force && ($park ? ! ParkExtractor::looksLikeRequest($fields) : ! Extractor::looksLikeOffer($fields))) {
            return null;
        }
        // Новый кандидат — из письма о ТС: рассылка, автоответ или «прочее» не от вендора кандидатом не становится.
        if ($park && ! $force && ! isset($fields['vendor_id']) && in_array($message->intent, [Intent::Other->value, Intent::Auto->value, Intent::Billing->value], true)) {
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
        $park && app(LinkThread::class)->forCandidate($candidate);
        $park && app(CandidateStages::class)->refresh($candidate);
        if ($candidate->fresh()->stage !== CandidateStage::Released) {
            ImportCandidateFiles::dispatch($candidate->id, $message->id);
        }
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
