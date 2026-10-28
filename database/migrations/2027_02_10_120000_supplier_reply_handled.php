<?php

use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Thread;
use App\Workflow\Position;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Зависшие «Разобрать ответ поставщика» (28.10.2026, 0385/046/07142/26: Альфа ответила 20.10, мы ответили «актуально»
 * дважды, а шаг всё «поставщик ответил»): наше письмо после ответа поставщика тогда не засчитывалось. Позиции с `reply_at`,
 * у которых в ветках их предложения есть наше ушедшее письмо позже ответа, — `Position::replyHandled` этим письмом.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fixed = [];
        Position::where('track', 'sale')->whereRaw("payload ?? 'reply_at'")->with(['offer', 'stage'])->each(function (Position $p) use (&$fixed) {
            if (! $p->offer?->deal?->isActive() || $p->letterStatus() !== 'replied') {
                return;
            }
            $after = Carbon::parse($p->payload['reply_at']);
            $letter = Message::whereIn('thread_id', Thread::where('offer_id', $p->offer_id)->select('id'))->where('direction', Direction::Out)
                ->whereNotNull('sent_at')->where('sent_at', '>', $after)->orderByDesc('sent_at')->first();
            if ($letter && $p->replyHandled($letter)) {
                $fixed[] = $p->offer->number;
            }
        });
        Log::info('supplier_reply_handled', ['offers' => $fixed]);
    }

    public function down(): void {}
};
