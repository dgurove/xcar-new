<?php

use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\Actions\UpdateDealMoney;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Stage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Альфа, 18.10.2026 (владелец): Р.О.С. у предложения без НДС — «ошибка, перевести»: его счёт с НДС 22 %. Идущие сделки
 * на Р.О.С. без НДС, по которым ещё не платили, — на «Страховой напрямую» (`UpdateDealMoney`: сумма страховой —
 * закреплённая закупочная). Этап перевода с Р.О.С. теперь и для ПРАЙМ — «Перевод поставщику».
 */
return new class extends Migration
{
    public function up(): void
    {
        Stage::where('name', 'Перевод поставщику с Р.О.С.')->update(['name' => 'Перевод поставщику']);

        $by = User::withRole(Role::Admin)->orderBy('id')->first();
        if (! $by) {
            return;
        }
        $deals = Deal::where('state', 'active')->where('scheme', DealScheme::Ros->value)
            ->whereHas('offer', fn ($q) => $q->where('prices_include_vat', false))->with('offer.vendor')->get();
        foreach ($deals as $deal) {
            if (! $deal->moneyEditable()) {
                Log::warning('Р.О.С. без НДС: сделку '.$deal->id.' не перевели — по ней платили');

                continue;
            }
            app(UpdateDealMoney::class)($deal, $by, $deal->commission, CommissionMode::Withheld, DealScheme::Insurer);
        }
    }
};
