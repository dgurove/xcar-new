<?php

namespace App\Offers;

use App\Mail\Message;
use App\Support\FastAttributes;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Письмо страховой по идущей сделке ждёт админа (03.11.2026, владелец: «сделка падает в CRM в „Разобрать ответ“»): менеджер о
 * нём не знает ничего, пока админ не покажет выбранное (`Actions\Disclose`) или не решит «Ничего не показывать». Ставит
 * `Listeners\OnInsurerReply::queue`, письмо — одно на строку.
 */
#[Fillable(['deal_id', 'offer_id', 'message_id', 'state', 'done_by', 'done_at'])]
class ReplyReview extends Model
{
    use FastAttributes;

    public const PENDING = 'pending';

    public const DONE = 'done';

    protected function casts(): array
    {
        return ['done_at' => 'datetime'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('reply_reviews.state', self::PENDING);
    }

    /**
     * Ждущие разбора по предложениям: offer_id → когда пришло первое (списки «Сделок» спрашивают одним запросом, как
     * `RepriceAsk::dealDue`). @return Collection<int, Carbon>
     */
    public static function waiting(): Collection
    {
        $load = fn () => self::pending()->selectRaw('offer_id, min(created_at) as at')->groupBy('offer_id')
            ->pluck('at', 'offer_id')->map(fn ($at) => Carbon::parse($at));

        return app()->runningInConsole() ? $load() : once($load);
    }
}
