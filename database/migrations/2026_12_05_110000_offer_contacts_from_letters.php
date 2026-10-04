<?php

use App\Mail\Message;
use App\Mail\Thread;
use App\Offers\Actions\TakeContactFromLetter;
use App\Offers\Offer;
use Illuminate\Database\Migrations\Migration;

/**
 * Идущие сделки, у которых контакт владельца пришёл письмом уже после заведения предложения (04.10.2026, сделка
 * Тужикова): пустые страхователь, телефон и адрес — из последнего письма вендора, где они есть (`TakeContactFromLetter`).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Offer::whereHas('deal')->where(fn ($q) => $q->whereNull('insured_phone')->orWhereNull('insured_name'))->get() as $offer) {
            $message = Message::whereIn('thread_id', Thread::where('offer_id', $offer->id)->select('id'))->where('direction', 'in')
                ->whereNotNull('parsed->fields->insured_phone')->orderByDesc('date_at')->first();
            if ($message) {
                app(TakeContactFromLetter::class)($offer, $message);
            }
        }
    }

    public function down(): void {}
};
