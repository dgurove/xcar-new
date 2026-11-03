<?php

use App\Offers\DealScheme;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Кто и когда выбрал схему сделки (03.11.2026, владелец: у Альфы 781R/046/01529/26 стоял «ПРАЙМ по счёту», «а кто нажал, что
 * ПРАЙМ, я не понял»). Прошлое — из журнала машины: «Принять» и «Отдать» (`bid_accepted` со схемой) и заметки «Деньги»
 * («ПРАЙМ по счёту, вознаграждение…», «Страховой 387 000 ₽…»): последняя запись, что ставила нынешнюю схему.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->foreignId('scheme_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('scheme_at')->nullable();
        });

        $prefixes = [
            DealScheme::Prime->value => ['ПРАЙМ по счёту'],
            DealScheme::Ros->value => ['Р.О.С. по счёту'],
            DealScheme::Insurer->value => ['Страховой'],
            DealScheme::OwnerDkp->value => ['Собственнику по ДКП', 'Страхователю по ДКП'],
        ];
        foreach (DB::table('deals')->select('id', 'offer_id', 'scheme', 'created_at')->get() as $deal) {
            $events = DB::table('car_events')->where('offer_id', $deal->offer_id)
                ->where('created_at', '>=', \Illuminate\Support\Carbon::parse($deal->created_at)->subMinute())
                ->whereIn('type', ['bid_accepted', 'note'])->orderByDesc('created_at')->orderByDesc('id')
                ->get(['type', 'user_id', 'created_at', 'payload']);
            $set = $events->first(function ($e) use ($deal, $prefixes) {
                $p = json_decode((string) $e->payload, true) ?: [];

                return $e->type === 'bid_accepted'
                    ? ($p['scheme'] ?? DealScheme::Prime->value) === $deal->scheme
                    : collect($prefixes[$deal->scheme] ?? [])->contains(fn ($pre) => str_starts_with((string) ($p['text'] ?? ''), $pre));
            });
            DB::table('deals')->where('id', $deal->id)->update(['scheme_by' => $set?->user_id, 'scheme_at' => $set?->created_at ?? $deal->created_at]);
        }
    }

    public function down(): void {}
};
