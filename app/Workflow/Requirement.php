<?php

namespace App\Workflow;

use App\Chats\Events\ChatMessageChanged;
use App\Chats\Message;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Support\FastAttributes;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/** Просьба к менеджеру на этапе, где его ход. Ответ — нажатая кнопка плюс поля или документ. */
#[Fillable(['offer_id', 'deal_id', 'stage_id', 'user_id', 'title', 'text', 'asks', 'fields', 'due_at', 'done_at', 'answer', 'prompted_at'])]
class Requirement extends Model implements HasMedia
{
    use FastAttributes;
    use InteractsWithMedia;

    protected function casts(): array
    {
        return [
            'asks' => Asks::class,
            'fields' => 'array',
            'answer' => 'array',
            'due_at' => 'datetime',
            'done_at' => 'datetime',
            'prompted_at' => 'datetime',
        ];
    }

    /**
     * Ответили, закрыли шагом, удалили — сообщение с кнопками этой просьбы в чате сделки перерисовывается (`Chats\StepAsk`):
     * вместо кнопок — ответ, у удалённой — просто текст. После коммита: чат перечитывает пузырь сразу.
     */
    protected static function booted(): void
    {
        static::updated(function (self $r) {
            $r->wasChanged('done_at') && self::redrawChat(Message::where('requirement_id', $r->id)->get());
        });
        static::deleting(function (self $r) {
            self::redrawChat(Message::where('requirement_id', $r->id)->get());
        });
    }

    /** Ответ поля словами: дата со временем — «14.11 11:00», остальное как есть. */
    public function shown(string $key, mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', $value);
        }
        $type = collect($this->fields)->firstWhere('key', $key)['type'] ?? null;
        if ($type === 'datetime' && preg_match('/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d/', (string) $value)) {
            return \App\Support\Ago::at(\Illuminate\Support\Carbon::parse($value), true, words: false);
        }

        return (string) $value;
    }

    /** Закрыли или удалили просьбы запросом мимо модели (`EnterStage`, `SyncHandover`) — их кнопки в чате тоже перерисовать. */
    public static function redrawFor(iterable $ids): void
    {
        $ids = collect($ids)->filter()->values();
        $ids->isNotEmpty() && self::redrawChat(Message::whereIn('requirement_id', $ids)->get());
    }

    /** @param  Collection<int, Message>  $messages */
    private static function redrawChat($messages): void
    {
        if ($messages->isEmpty()) {
            return;
        }
        DB::afterCommit(fn () => $messages->each(fn (Message $m) => ChatMessageChanged::dispatch($m->fresh(['chat']) ?? $m->load('chat'))));
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('files')->useDisk('private');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Просьбы маршрута машины — без просьб параллельных сделок (07.11.2026, `Offers\Parallels`): у тех свой теневой шаг,
     * шаг главной их не закрывает и не сдвигает их сроки.
     */
    public function scopeOfRoute($q)
    {
        return $q->where(fn ($w) => $w->whereNull('deal_id')->orWhereNotExists(fn ($d) => $d->selectRaw('1')->from('deals')
            ->whereColumn('deals.id', 'requirements.deal_id')->where('deals.state', DealState::Parallel->value)));
    }

    public function isOpen(): bool
    {
        return $this->done_at === null;
    }

    /**
     * Закрыть открытую просьбу менеджера на этапе ответом, который дал не он сам (28.10.2026, одна дверь): деньги пришли
     * (`AdvanceOnPayment`, `closed_by: payment`), заявил оплату (`AdvanceOnClaim`), ответил админ «за менеджера»
     * (`RouteController::forManager`, `closed_by: staff`, `by`). Вид ответа читают путь CRM и «Уже отвечено» в Telegram.
     *
     * @param  array<string, mixed>  $answer
     */
    public static function closeOpen(Deal $deal, int $stageId, array $answer): ?self
    {
        $r = $deal->openRequirement()->where('stage_id', $stageId)->first();
        $r?->update(['done_at' => now(), 'answer' => $answer]);

        return $r;
    }

    /** Просьба менеджеру сделки на этапе, со сроком позиции. Зовут вход в этап и выставленный счёт на этапе оплаты. */
    public static function askFor(Deal $deal, Stage $stage, Position $position): self
    {
        return self::create([
            'offer_id' => $deal->offer_id,
            'deal_id' => $deal->id,
            'stage_id' => $stage->id,
            'user_id' => $deal->buyer_id,
            'title' => $stage->managerTitle($deal),
            // Гаражная сделка цены не называла: «по Вашей цене» из общего текста ей не про неё.
            'text' => $deal->isGarage() ? preg_replace('/ по Вашей цене/u', '', (string) $stage->managerText()) ?: null : $stage->managerText(),
            'asks' => $stage->asks,
            'fields' => $stage->fields ?? [],
            'due_at' => $position->deadline_at,
        ]);
    }
}
