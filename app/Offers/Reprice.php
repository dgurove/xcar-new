<?php

namespace App\Offers;

use App\Mail\Message;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Переоценка закупочной на решение админа (28.10.2026, владелец: «переоценка часто по письму страховой — по такому-то
 * письму такая-то переоценка, принять или отменить»; «у тех, кто сейчас подтвердил, и в сделке — оставить прежнюю цену
 * закупа или обновить»). Заводят письмо (`Actions\DetectReprice`) и любая правка закупочной, когда уже есть подтвердившие
 * (`UpdateOffer`); решает админ шторкой — `Actions\ApplyReprice` / `DismissReprice`. Ждущая у предложения одна: новая
 * снимает прежнюю (`superseded`).
 *
 * `choices` после решения: `{"bids": {"12": "update", "13": "keep"}, "deal": "keep"}`.
 */
#[Fillable(['offer_id', 'from_price', 'to_price', 'value', 'source', 'message_id', 'state', 'choices', 'created_by', 'decided_by', 'decided_at'])]
class Reprice extends Model
{
    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const DISMISSED = 'dismissed';

    public const SUPERSEDED = 'superseded';

    /** Откуда: письмо страховой, руками в редакторе, «Оценить из текста», лот Мигторга. */
    public const SOURCES = ['letter', 'manual', 'valuation', 'migtorg', 'file'];

    protected $table = 'offer_reprices';

    protected function casts(): array
    {
        return ['from_price' => 'int', 'to_price' => 'int', 'value' => 'int', 'choices' => 'array', 'decided_at' => 'datetime'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    /**
     * Кого касается: живые подтверждения (и гаражные) — у каждого своя закупочная с его подтверждения, и идущая сделка
     * (`Offer::costDeal`: сделка или гараж, пока машина не продана). Принятое подтверждение — это сделка, отдельно не идёт.
     *
     * @return array{bids: Collection<int, Bid>, deal: ?Deal}
     */
    public static function affected(Offer $offer): array
    {
        $bids = $offer->bids()->where('state', BidState::Active)->with('user')->orderBy('id')->get();
        $deal = $offer->costDeal();

        return ['bids' => $bids, 'deal' => $deal && $deal->state === DealState::Active ? $deal : null];
    }

    public static function hasAffected(Offer $offer): bool
    {
        $a = self::affected($offer);

        return $a['bids']->isNotEmpty() || $a['deal'] !== null;
    }

    /** Откуда словами: «по письму поставщика, 12 окт», «руками, Катя К.», «из текста оценки». */
    public function sourceLabel(): string
    {
        return match ($this->source) {
            'letter' => 'по письму поставщика'.($this->message?->date_at ? ', '.$this->message->date_at->translatedFormat('j M') : ''),
            'valuation' => 'из текста оценки',
            'migtorg' => 'с Мигторга',
            'file' => 'из файла',
            default => 'руками'.($this->author ? ', '.$this->author->shortName() : ''),
        };
    }

    /** Строкой в историю: «Переоценка по письму поставщика, 12 окт». */
    public function why(): string
    {
        return 'Переоценка '.$this->sourceLabel();
    }
}
