<?php

use App\Mail\Direction;
use App\Mail\Message;
use App\Offers\Deal;
use App\Offers\ExtraAsk;
use App\Offers\Offer;
use App\Offers\OfferEvent;
use App\Offers\OfferEventType;
use App\Offers\OfferFiles;
use App\Offers\ReplyReview;
use App\Workflow\Outcome;
use App\Workflow\Position;
use App\Workflow\Stage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Доп. просьба — своей записью с ходом (18.11.2026, `Offers\ExtraAsk`): пока не решена, ход сделки стоит. Прошлые просьбы (с
 * 17.11.2026, письма с `mail_messages.requests`) — по записям «Истории»: ответ — первое письмо поставщика после неё, не автоответ;
 * отвеченные — решены (менеджер там уже дальше), без ответа — ждут поставщика. Этап Альфы «Осмотр перед оплатой» снят: осмотр до
 * «Беру в работу» и после — один модуль (владелец). Разбор автоответов из «Разобрать ответ» снят.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extra_asks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('message_id')->nullable()->constrained('mail_messages')->nullOnDelete();
            $t->string('text', 500);
            $t->boolean('asks_manager')->default(false);
            $t->foreignId('asked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('asked_at');
            $t->timestamp('since_at');
            $t->timestamp('frozen_at')->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->foreignId('answer_message_id')->nullable()->constrained('mail_messages')->nullOnDelete();
            $t->timestamp('shown_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision', 20)->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['offer_id', 'decided_at']);
            $t->index(['deal_id', 'decided_at']);
        });

        foreach (OfferEvent::where('type', OfferEventType::ExtraRequest)->orderBy('id')->get() as $event) {
            $offer = Offer::find($event->offer_id);
            $message = Message::find($event->payload['letter'] ?? 0);
            $text = (string) ($event->payload['text'] ?? '');
            if (! $offer || ! $message || $text === '') {
                continue;
            }
            $at = $message->date_at ?? $event->created_at;
            $deal = Deal::where('offer_id', $offer->id)->active()->where('created_at', '<=', $at)->first();
            $answer = collect(OfferFiles::letters($offer, null)['messages'])->first(fn (Message $m) => $m->direction === Direction::In
                && $m->isFromVendor() && ! $m->isAutomatic() && $m->date_at && $m->date_at->gt($at));
            ExtraAsk::create([
                'offer_id' => $offer->id, 'deal_id' => $deal?->id, 'message_id' => $message->id, 'text' => $text,
                'asks_manager' => $deal && $deal->buyer_id && ! $deal->supplierPaid(),
                'asked_by' => $event->user_id, 'asked_at' => $at, 'since_at' => $at, 'frozen_at' => $at,
                'answered_at' => $answer?->date_at, 'answer_message_id' => $answer?->id,
                'shown_at' => $answer?->date_at, 'decided_at' => $answer ? now() : null, 'decision' => $answer ? ExtraAsk::ANSWERED : null,
            ]);
        }

        // Автоответ «отсутствую на рабочем месте» встал в «Разобрать ответ» (Z691/046/13570/26) — разбирать нечего.
        foreach (ReplyReview::pending()->with('message')->get() as $review) {
            if ($review->message?->isAutomatic()) {
                $review->update(['state' => ReplyReview::DONE, 'done_at' => now()]);
            }
        }

        // «Осмотр перед оплатой» (Альфа, 17.11.2026) — теперь доп. просьба: исходы «Поставщик согласовал осмотр» и сам этап снимаются.
        foreach (Stage::where('name', 'Осмотр перед оплатой')->get() as $stage) {
            if (Position::where('stage_id', $stage->id)->exists()) {
                continue;
            }
            DB::transaction(function () use ($stage) {
                Outcome::where('to_stage_id', $stage->id)->delete();
                Outcome::where('stage_id', $stage->id)->delete();
                $stage->delete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('extra_asks');
    }
};
