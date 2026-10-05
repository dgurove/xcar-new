<?php

use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Events\InsurerReplied;
use App\Offers\InsurerReplies;
use App\Offers\OfferEventType;
use Illuminate\Database\Migrations\Migration;

/**
 * Ответы страховой, что пришли на deal@ по идущим сделкам до 05.10.2026 (Ford Kuga: контакт страхователя и архив
 * документов): менеджеру — их текст и файлы, сделке — шаг дальше, как у нового письма. Страховой сами не пишем
 * (`live: false`): документы у неё уже просили руками.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Deal::where('state', DealState::Active)->with('offer')->get() as $deal) {
            foreach (InsurerReplies::for($deal)->reverse() as $message) {
                if ($deal->offer->events()->where('type', OfferEventType::InsurerReplied)->where('payload->letter', $message->id)->exists()) {
                    continue;
                }
                InsurerReplied::dispatch($deal->fresh(), $message, false);
            }
        }
    }

    public function down(): void {}
};
