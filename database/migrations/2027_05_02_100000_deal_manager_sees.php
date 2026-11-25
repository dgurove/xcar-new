<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Что видит менеджер — живые данные сделки, а не снимки (25.11.2026, владелец: «окошко, где видно, что он видит, а что нет, с
 * возможностью убирать, добавлять»; `Offers\ManagerView`). `deals.manager_sees`: ключ → когда показали (`contact`). Документы —
 * как были, флагом файла (`ForManagers`), наш счёт и договор — `shown_at`.
 * Переход: сделки, где менеджеру показывали телефон, — контакт показан с того показа; показанные имя и телефон, которых нет в
 * карточке, — в пустые поля машины (контакт теперь живой, из карточки).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->jsonb('manager_sees')->nullable();
        });
        $rows = DB::table('deal_disclosures')->whereRaw("items @> '[{\"kind\":\"phone\"}]'")->orderBy('created_at')->get(['deal_id', 'items', 'created_at']);
        foreach ($rows->groupBy('deal_id') as $dealId => $list) {
            DB::table('deals')->where('id', $dealId)->update(['manager_sees' => json_encode(['contact' => (string) $list->last()->created_at])]);
            $deal = \App\Offers\Deal::find($dealId);
            $offer = $deal?->offer;
            if (! $offer) {
                continue;
            }
            $items = collect(json_decode($list->last()->items, true) ?: []);
            $name = $items->firstWhere('kind', 'name')['value'] ?? null;
            $phones = $items->where('kind', 'phone')->pluck('value')->values();
            $fill = array_filter([
                'insured_name' => blank($offer->insured_name) ? $name : null,
                'insured_phone' => blank($offer->insured_phone) ? $phones->get(0) : null,
                'insured_phone2' => blank($offer->insured_phone2) && blank($offer->insured_phone) ? $phones->get(1) : null,
            ]);
            if ($fill) {
                $offer->fill($fill)->save();
                $offer->log(\App\Offers\OfferEventType::Note, null, ['text' => 'Контакт из показанного менеджеру — в карточку']);
            }
        }
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('manager_sees'));
    }
};
