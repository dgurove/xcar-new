<?php

use App\Offers\Actions\SyncDealInvoices;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Position;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;

/**
 * Идущие сделки — по своим схемам (05.10.2026). Счета, что известны, встают сами (`SyncDealInvoices`): гаражным
 * «платит менеджер» у ПРАЙМ — счёт за машину на закупочную. Кто стоит на оплате без счёта, переходит на свой же шаг
 * заново: ПРАЙМ без покупателя — ход менеджера «Укажите покупателя» вместо нашего «Выставите счёт».
 */
return new class extends Migration
{
    public function up(): void
    {
        $by = User::withRole(Role::Admin)->orderBy('id')->first();
        if (! $by) {
            return;
        }
        foreach (Deal::where('state', DealState::Active)->where('is_demo', false)->with(['offer', 'buyer'])->get() as $deal) {
            app(SyncDealInvoices::class)($deal, $by);
            $position = Position::where('offer_id', $deal->offer_id)->where('track', Track::Sale)->with('stage.exits')->first();
            if ($position?->stage->isPayStep() && ! $deal->fresh()->hasManagerInvoice()) {
                app(EnterStage::class)($deal->offer->fresh(), $position->stage, $by);
            }
        }
    }

    public function down(): void {}
};
