<?php

use App\Billing\Actions\VoidInvoice;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealState;
use App\Garage\GaragePayer;
use App\Offers\OfferEventType;
use App\Users\Role;
use App\Users\User;
use App\Vendors\DealFormat;
use App\Vendors\Vendor;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Offers\CarPlace;
use App\Workflow\Events\StageEntered;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/**
 * Совкомбанк: сначала забрать, потом покупатель, счёт ПРАЙМ с договором, оплата, ПРАЙМ платит Совкомбанку (10.10.2026,
 * владелец: «менеджер забирает тачку без оплаты… потом менеджер или его покупатель платит на ООО ПРАЙМ, ПРАЙМ платит
 * совкому»). Маршрут правится на месте — строки этапов переиспользуются, чтобы не потерять просьбы и журнал:
 * «Менеджер забирает автомобиль» встаёт сразу за принятием, «Счёт выставлен менеджеру» — «Оплата по счёту ПРАЙМ»,
 * проверка оплаты — наша, «Подписание документов» — «Подписанный договор», в конце «Оплата поставщику». Места
 * подписания, приём, СДЭК и передача уходят.
 *
 * Живые сделки — на новый путь тихо (решение владельца): неоплаченные счета аннулируются (счёт и договор — после
 * покупателя), гаражные «платит менеджер» теряют подставленного покупателя договора, «платим мы» с менеджером (Kia
 * Cerato) — «платит менеджер»; «взяли под себя» без менеджера (Tenet T4) не трогаем. Машина уже у менеджера — сразу оплата.
 */
return new class extends Migration
{
    public function up(): void
    {
        $vendor = Vendor::where('deal_format', DealFormat::Commission)->whereRaw("name ilike '%совком%'")->first();
        $workflow = $vendor?->workflow(Track::Sale);
        if (! $workflow) {
            return;
        }
        $stages = Stage::where('workflow_id', $workflow->id)->with('exits')->get()->keyBy('name')->toBase();
        $pickup = $stages['Менеджер забирает автомобиль'] ?? null;
        $invoice = $stages['Счёт выставлен менеджеру'] ?? null;
        $check = $stages['Подтверждение оплаты поставщиком'] ?? null;
        $signing = $stages['Подписание документов'] ?? null;
        $closed = $stages['Сделка закрыта'] ?? null;
        if (! $pickup || ! $invoice || ! $check || ! $signing || ! $closed) {
            return;
        }
        $doomed = $stages->only(['Место подписания документов', 'Запись на приём', 'Адрес для отправки оригиналов', 'Отправка оригиналов', 'Передача автомобиля покупателю'])->values();
        $by = User::withRole(Role::Admin)->orderBy('id')->first();

        $notifications = Notification::getFacadeRoot();
        Notification::fake();
        try {
            DB::transaction(function () use ($workflow, $stages, $pickup, $invoice, $check, $signing, $closed, $doomed, $by) {
                $block = fn (string $name) => DB::table('workflow_blocks')->where('workflow_id', $workflow->id)->where('name', $name)->value('id');
                $pickupBlock = $pickup->block_id;
                DB::table('workflow_blocks')->where('id', $pickupBlock)->update(['name' => 'Получение автомобиля', 'text' => 'Заберите автомобиль, платить до получения не нужно']);
                DB::table('workflow_blocks')->where('id', $invoice->block_id)->update(['name' => 'Оплата', 'text' => 'Укажите покупателя: счёт ПРАЙМ и договор купли-продажи придут вместе. Оплатите счёт']);
                DB::table('workflow_blocks')->where('id', $signing->block_id)->update(['name' => 'Договор', 'text' => 'Приложите договор купли-продажи, подписанный покупателем']);
                $supplierBlock = $block('Расчёт с поставщиком') ?? DB::table('workflow_blocks')->insertGetId([
                    'workflow_id' => $workflow->id, 'name' => 'Расчёт с поставщиком', 'text' => 'ПРАЙМ оплачивает автомобиль поставщику', 'position' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('workflow_blocks')->where('workflow_id', $workflow->id)->where('name', 'Оформляем на нас')
                    ->update(['name' => 'Взяли под себя', 'text' => 'Автомобиль взяли себе: оплачиваем его поставщику и оформляем документы на ПРАЙМ']);
                // Порядок блоков — порядок пути: лестница менеджера не возвращается к блоку, что стоит раньше текущего.
                $order = ['Приём подтверждений', 'Получение автомобиля', 'Оплата', 'Договор', 'Расчёт с поставщиком', 'Взяли под себя', 'Сделка закрыта', 'Подтверждений не поступило', 'Покупатель от поставщика'];
                foreach ($order as $i => $name) {
                    DB::table('workflow_blocks')->where('workflow_id', $workflow->id)->where('name', $name)->update(['position' => $i]);
                }

                $supplier = Stage::create([
                    'workflow_id' => $workflow->id, 'block_id' => $supplierBlock, 'position' => 0, 'name' => 'Оплата поставщику',
                    'waits_for' => 'us', 'limit_minutes' => 2 * 1440, 'deadline_source' => 'own', 'asks' => 'nothing',
                    'fields' => [], 'staff_fields' => Stage::keyFields([['label' => 'Дата оплаты'], ['label' => 'Номер платёжки']]),
                    'template_id' => $check->template_id,
                ]);
                $supplier->exits()->create(['label' => 'Поставщику оплачено', 'actor' => 'staff', 'to_stage_id' => $closed->id, 'position' => 0]);

                // Без срока: забрать — его интерес; часы и «Ваш ход» — у оплаты.
                $pickup->update([
                    'position' => 0, 'waits_for' => 'manager', 'limit_minutes' => null, 'offer_state' => 'sold',
                    'ask_title' => 'Заберите автомобиль', 'ask_text' => 'Свяжитесь с владельцем, договоритесь о времени и заберите автомобиль. Платить до получения не нужно',
                ]);
                $pickup->exits()->update(['to_stage_id' => $invoice->id, 'branch' => null]);

                $invoice->update([
                    'name' => 'Оплата по счёту ПРАЙМ', 'position' => 0, 'offer_state' => null,
                    'ask_title' => 'Оплатите счёт', 'ask_text' => 'Оплатите счёт ПРАЙМ по реквизитам и приложите платёжное поручение',
                ]);
                // Деньги приходят ПРАЙМ: проверяем сами, поставщику писать не о чем.
                $check->update(['name' => 'Проверка оплаты', 'position' => 1, 'waits_for' => 'us', 'limit_minutes' => 1440, 'template_id' => null]);
                foreach ($check->exits as $exit) {
                    $exit->update(['to_stage_id' => str_contains($exit->label, 'не поступила') ? $invoice->id : $signing->id, 'branch' => null]);
                }

                $signing->update([
                    'name' => 'Подписанный договор', 'position' => 0, 'waits_for' => 'manager', 'limit_minutes' => 5 * 1440, 'asks' => 'document',
                    'ask_title' => 'Приложите подписанный договор', 'ask_text' => 'Приложите договор купли-продажи, подписанный покупателем',
                ]);
                $signing->exits()->delete();
                $signing->exits()->create(['label' => 'Договор приложен', 'actor' => 'manager', 'to_stage_id' => $supplier->id, 'position' => 0]);
                $signing->exits()->create(['label' => 'Договор получен', 'actor' => 'staff', 'to_stage_id' => $supplier->id, 'position' => 1]);

                // Принятие покупательской ветки — к «Заберите автомобиль».
                DB::table('workflow_exits')->where('to_stage_id', $invoice->id)->where('branch', Outcome::BUYER)
                    ->whereIn('stage_id', $stages->only(['Приём подтверждений', 'Выбор подтверждения'])->pluck('id'))
                    ->update(['to_stage_id' => $pickup->id]);
                // Входы в убранные этапы из оставшихся — в договор; их просьбы — туда же.
                $ids = $doomed->pluck('id');
                DB::table('workflow_exits')->whereIn('to_stage_id', $ids)->whereNotIn('stage_id', $ids)->update(['to_stage_id' => $signing->id]);
                DB::table('requirements')->whereIn('stage_id', $ids)->update(['stage_id' => $signing->id]);

                $this->moveDeals($pickup, $invoice, $by);

                Stage::whereIn('id', $ids)->delete();
                DB::table('workflow_blocks')->where('workflow_id', $workflow->id)
                    ->whereNotExists(fn ($q) => $q->from('workflow_stages')->whereColumn('workflow_stages.block_id', 'workflow_blocks.id'))
                    ->delete();
                app(RevalidateWorkflow::class)($workflow);
            });
        } finally {
            Notification::swap($notifications);
        }
    }

    private function moveDeals(Stage $pickup, Stage $invoice, ?User $by): void
    {
        $void = app(VoidInvoice::class);
        $enter = app(EnterStage::class);
        $route = Stage::where('workflow_id', $pickup->workflow_id)->pluck('id');
        $deals = Deal::where('state', DealState::Active)
            ->whereHas('offer.positions', fn ($p) => $p->where('track', Track::Sale)->whereIn('stage_id', $route))
            ->with('offer')->orderBy('id')->get();
        foreach ($deals as $deal) {
            $offer = $deal->offer;
            // «Взяли под себя» — без менеджера, путь прежний.
            if ($deal->garage_payer === GaragePayer::Us && ! $deal->buyer_id) {
                continue;
            }
            $notes = [];
            if ($deal->garage_payer === GaragePayer::Us) {
                $deal->forceFill(['garage_payer' => GaragePayer::Manager])->save();
                $notes[] = 'гараж «платит менеджер»';
            }
            $open = Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('state', InvoiceState::Issued)->with(['payments', 'claims'])->get()
                ->reject(fn (Invoice $i) => $i->claims->isNotEmpty() || $i->payments->contains(fn ($p) => ! $p->source->isSoft()));
            foreach ($open as $i) {
                $void($i, $by, 'Счёт и договор — после выбора покупателя');
                $notes[] = 'счёт '.$i->label().' аннулирован';
            }
            $deal->forceFill(['agreed_at' => null])->save();
            if ($deal->isGarage()) {
                DealContract::where('deal_id', $deal->id)->update(['buyer_user_id' => null, 'payer' => 'buyer', 'ready_at' => null]);
            }

            // Машина уже у менеджера (вывоз «Стоит у менеджера») — сразу оплата, иначе «Заберите автомобиль».
            $service = $offer->position(Track::Service);
            $picked = $service?->stage->car_place === CarPlace::Keeper && $offer->keeper()?->id === $deal->buyer_id;
            $to = $picked ? $invoice : $pickup;
            Event::fakeFor(fn () => $enter($offer->fresh(), $to, $by), [StageEntered::class]);
            $offer->log(OfferEventType::Note, $by, ['text' => 'Путь сделки Совкомбанка изменён: «'.$to->name.'»'.($notes ? ', '.implode(', ', $notes) : '')]);
        }
    }

    public function down(): void {}
};
