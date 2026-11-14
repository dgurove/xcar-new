{{-- «Уже продана…» (14.11.2026, владелец: «кнопка в меню, которая отметит все шаги выполненными и отправит в завершённые,
     спрашивая, оплачено ли всё»): договор («Ещё нет» — сделка встаёт на шаге договора и ждёт его обычным ходом), деньги
     менеджера (наши счета: оплачено или висит долг), поставщику (если впереди наша оплата ему), когда продали. Строка «что
     будет» — по ответам (`MarkSoldDeal::run`, панели `reveal`). `POST /work/deals/{id}/sold` → `MarkSoldDeal`. Открывает
     событие sold:open (пункт «⋯» окна и страницы). --}}
@props(['deal'])
@php
    use App\Offers\Actions\MarkSoldDeal;
    use App\Support\Money;
    $offer = $deal->offer;
    $bills = MarkSoldDeal::unpaid($deal);
    $owed = (int) round($bills->sum(fn ($i) => $i->remaining()));
    $asksMoney = $bills->isNotEmpty() || ! $deal->agreed();
    $asksSupplier = MarkSoldDeal::asksSupplier($deal);
    $line = function (bool $signed, bool $paid) use ($deal) {
        $run = MarkSoldDeal::run($deal, $signed, $paid);

        return match (true) {
            ! $run?->stop => 'Маршрут дальше не идёт — шаг придётся провести вручную',
            $run->closes() => 'Все шаги отметятся, сделка закроется, ТС — у покупателя',
            $run->passed === [] => 'Сделка остаётся на «'.$run->stop->name.'»',
            default => 'Шаги до «'.$run->stop->name.'» отметятся, сделка встанет на нём',
        };
    };
    // Не оплатили поставщику — сделка встанет на шаге нашей оплаты: туда (договор подписан) и пишем.
    $payStop = $asksSupplier ? MarkSoldDeal::run($deal, true, false)?->stop : null;
@endphp
<div data-controller="sheet" data-action="sold:open@window->sheet#open" class="contents">
    <x-ui.sheet id="sold-{{ $deal->id }}" title="Уже продана">
        <form method="post" action="/work/deals/{{ $deal->id }}/sold" class="flex flex-col gap-4">
            @csrf
            <div class="rounded-(--radius-m) bg-surface-2 px-3 py-2.5">
                <div class="font-medium">{{ $offer?->titleWithYear() }}</div>
                <div class="text-sm text-ink-muted">{{ $deal->buyer?->shortName() ?? 'Без менеджера' }}@if ($deal->amount), <span class="text-ink">{{ Money::rub($deal->amount) }}</span>@endif</div>
            </div>
            @if ($asksMoney)
                <div>
                    <h3 class="list-head">Менеджер нам заплатил{{ $owed ? ', '.Money::rub($owed) : '' }}</h3>
                    <x-ui.segment name="manager_paid" :options="['yes' => 'Да', 'no' => 'Нет, пусть висит долг']" :value="old('manager_paid', 'yes')"/>
                </div>
            @endif
            <div data-controller="reveal" class="flex flex-col gap-3">
                <div>
                    <h3 class="list-head">Договор с покупателем</h3>
                    <x-ui.segment name="signed" :options="['yes' => 'Подписан', 'no' => 'Ещё нет']" :value="old('signed', 'yes')" pick/>
                </div>
                <p class="text-sm text-ink-muted" data-reveal-target="pane" data-reveal-key="yes">{{ $line(true, true) }}</p>
                <p class="text-sm text-ink-muted" data-reveal-target="pane" data-reveal-key="no" hidden>{{ $line(false, true) }}</p>
            </div>
            @if ($asksSupplier)
                <div data-controller="reveal" class="flex flex-col gap-3">
                    <div>
                        <h3 class="list-head">Поставщику оплачено</h3>
                        <x-ui.segment name="supplier_paid" :options="['yes' => 'Да', 'no' => 'Ещё нет']" :value="old('supplier_paid', 'yes')" pick/>
                    </div>
                    @if ($payStop)<p class="text-sm text-ink-muted" data-reveal-target="pane" data-reveal-key="no" hidden>Сделка встанет на «{{ $payStop->name }}» — оплату отметите там</p>@endif
                </div>
            @endif
            <div class="fields">
                <x-ui.field type="date" name="sold_on" :id="'sold-on-'.$deal->id" label="Когда продали" :value="old('sold_on', now()->toDateString())" :max="now()->toDateString()" required/>
            </div>
            @error('deal')<p class="field-error">{{ $message }}</p>@enderror
            <x-ui.sheet-foot type="submit">Отметить проданной</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
