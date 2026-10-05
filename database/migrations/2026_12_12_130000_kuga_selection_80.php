<?php

use App\Offers\Actions\IssueSelectionInvoice;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Kuga (№ 2610041164): счёт «Подбор ТС» выставился на 100 000 со строкой вознаграждения 20 000 зачётом, и ссылка
 * осталась на 100 000 — менеджер не понял, почему с него 100 000 (05.10.2026). Перевыставляем одной строкой на 80 000:
 * старый гаснет вместе со ссылкой, новый со ссылкой на 80 000. Оплат по старой не было.
 */
return new class extends Migration
{
    public function up(): void
    {
        $deal = Offer::where('number', 2610041164)->first()?->deal()->first();
        if (! $deal || ! $deal->isDkp()) {
            return;
        }
        app(IssueSelectionInvoice::class)($deal, User::withRole(Role::Admin)->orderBy('id')->firstOrFail());
    }

    public function down(): void {}
};
