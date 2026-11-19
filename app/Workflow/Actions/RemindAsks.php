<?php

namespace App\Workflow\Actions;

use App\Chats\Actions\OpenChat;
use App\Chats\Actions\PlatformMessage;
use App\Chats\StepAsk;
use App\Notifications\ExtraAskDecisionNotice;
use App\Notifications\YourTurnNotice;
use App\Offers\Deal;
use App\Offers\ExtraAsk;
use App\Offers\OfferEventType;
use App\Users\User;
use App\Workflow\Requirement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «Напомнить через…» (19.11.2026, владелец: под «Беру в работу» не тихая «Я передумал» по центру, а две кнопки — «Я передумал» и
 * «Напомнить через…», «он нажимает и выбирает кол-во часов, чтобы ему снова уведомление и сообщение в чате и Telegram пришло через
 * это время»). Менеджер откладывает вопрос шага (`requirements.remind_at`) или доп. просьбы (`extra_asks.remind_at`); в срок
 * `offers:tick` присылает его снова тем же, чем в первый раз: уведомление (лента, пуш, Telegram с кнопками) и сообщение в чат
 * сделки (у вопроса шага — с кнопками ответа, `x-chat.step-keys` рисует их только под последним). Срок шага не трогается —
 * это его напоминание, а не отсрочка. Ответил — напоминание гаснет вместе с вопросом.
 */
final class RemindAsks
{
    /** Через сколько — значение формы => подпись строки шторки. */
    public const CHOICES = ['1' => '1 час', '2' => '2 часа', '3' => '3 часа', '6' => '6 часов', '12' => '12 часов', 'tomorrow' => 'Завтра в 10:00'];

    /** Когда напомнить по выбору шторки; «завтра» — в 10:00 по Москве. */
    public static function at(string $choice): ?Carbon
    {
        return match (true) {
            $choice === 'tomorrow' => now()->addDay()->setTime(10, 0),
            isset(self::CHOICES[$choice]) => now()->addHours((int) $choice),
            default => null,
        };
    }

    /** «в 18:30», «завтра в 10:00», «21.11 в 10:00» — в тосте и шторке; `short` — на кнопке в полширины: «в 18:30», «завтра 10:00». */
    public static function when(\DateTimeInterface $at, bool $short = false): string
    {
        $at = Carbon::instance($at);
        $in = $short ? ' ' : ' в ';

        return match (true) {
            $at->isToday() => 'в '.$at->format('H:i'),
            $at->isTomorrow() => 'завтра'.$in.$at->format('H:i'),
            default => $at->format('d.m').$in.$at->format('H:i'),
        };
    }

    /** Отложить вопрос шага (или снять напоминание — `null`). */
    public static function set(Requirement|ExtraAsk $ask, ?Carbon $at, User $by): void
    {
        $ask->update(['remind_at' => $at]);
        $ask->deal?->offer?->log(OfferEventType::Note, $by, ['text' => $at ? 'Напомнить '.self::when($at) : 'Напоминание снято']);
    }

    /** Созревшие напоминания — разом из `offers:tick`. @return int сколько ушло */
    public function __invoke(): int
    {
        $sent = 0;
        foreach (Requirement::whereNotNull('remind_at')->where('remind_at', '<=', now())->whereNull('done_at')->pluck('id') as $id) {
            $sent += (int) $this->requirement($id);
        }
        foreach (ExtraAsk::whereNotNull('remind_at')->where('remind_at', '<=', now())->whereNull('decided_at')->pluck('id') as $id) {
            $sent += (int) $this->extra($id);
        }

        return $sent;
    }

    private function requirement(int $id): bool
    {
        // Под замком строки: два тика подряд не пришлют дважды.
        $r = DB::transaction(function () use ($id) {
            $r = Requirement::whereKey($id)->lockForUpdate()->first();
            if (! $r?->remind_at || $r->remind_at->isFuture()) {
                return null;
            }
            $r->update(['remind_at' => null]);

            return $r;
        });
        $deal = $r?->deal;
        if (! $r || $r->done_at || ! $deal?->isActive() || ! $deal->buyer || $deal->openRequirement?->id !== $r->id) {
            return false;
        }
        $deal->buyer->notify(new YourTurnNotice($r->load('offer')));
        if ($deal->offer->chatOpenFor($deal->buyer)) {
            app(PlatformMessage::class)(app(OpenChat::class)($deal->offer, $deal->buyer, greet: false), StepAsk::text($r), $r);
        }
        $deal->offer->log(OfferEventType::Note, null, ['text' => 'Напомнили менеджеру: '.$r->title]);

        return true;
    }

    private function extra(int $id): bool
    {
        $ask = DB::transaction(function () use ($id) {
            $ask = ExtraAsk::whereKey($id)->lockForUpdate()->first();
            if (! $ask?->remind_at || $ask->remind_at->isFuture()) {
                return null;
            }
            $ask->update(['remind_at' => null]);

            return $ask;
        });
        $deal = $ask?->deal;
        if (! $ask || $ask->phase() !== ExtraAsk::MANAGER || ! $deal instanceof Deal || ! $deal->isActive() || ! $deal->buyer || ExtraAsk::holding($deal)?->id !== $ask->id) {
            return false;
        }
        $deal->buyer->notify(new ExtraAskDecisionNotice($ask));
        if ($deal->offer->chatOpenFor($deal->buyer)) {
            app(PlatformMessage::class)(app(OpenChat::class)($deal->offer, $deal->buyer, greet: false),
                ExtraAskDecisionNotice::question($ask)."\n".$deal->offer->titleWithYear().($deal->offer->vin ? ', VIN '.$deal->offer->vin : ''));
        }
        $deal->offer->log(OfferEventType::Note, null, ['text' => 'Напомнили менеджеру: '.ExtraAskDecisionNotice::question($ask)]);

        return true;
    }
}
