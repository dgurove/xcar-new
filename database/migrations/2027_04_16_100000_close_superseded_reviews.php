<?php

use App\Offers\Disclosure;
use App\Offers\OfferEventType;
use App\Offers\ReplyReview;
use Illuminate\Database\Migrations\Migration;

/**
 * Письмо ждёт разбора, а менеджеру после него уже показали (17.11.2026, владелец по сделке 123: «показано же менеджеру, зачем всё
 * ещё висит „Показать менеджеру?“»): показ из окна писем разбирал только своё письмо. Теперь он разбирает и письма не новее себя
 * (`Disclose`); прежние такие разборы — разобраны тем показом: кто показал и когда, в «Истории» машины — строкой.
 */
return new class extends Migration
{
    public function up(): void
    {
        ReplyReview::pending()->with(['message', 'deal.offer'])->get()->each(function (ReplyReview $r) {
            $at = $r->message?->date_at;
            $shown = $at ? Disclosure::where('deal_id', $r->deal_id)->where('created_at', '>', $at)->orderBy('id')->first() : null;
            if (! $shown) {
                return;
            }
            $r->forceFill(['state' => ReplyReview::DONE, 'done_by' => $shown->created_by, 'done_at' => $shown->created_at])->save();
            $r->deal?->offer?->log(OfferEventType::Note, $shown->author, ['text' => 'Письмо разобрано показом менеджеру']);
        });
    }

    public function down(): void {}
};
