{{-- «Оплата за подбор» — вкладка колеса «Предложений» (18.11.2026, `SelectionController::tab`): счета подбора по машинам, как в
     «Оплатах → Комиссии», двумя группами — «Не выставлены» (сделка на шаге оплаты, ссылку не отправили — наш ход, «Выставить»), «Подтвердить оплату» (менеджер сообщил, что оплатил, — «Пришли») и
     «Выставлены» (ждём оплату). Строка — `x-money.person-row` со столбцом менеджера, нажатие — окно машины. --}}
@php
    use App\Support\Money;
    $columns = \App\Http\Admin\SelectionController::table();
    $sum = fn ($bills) => '<span class="table-group-aside"><span class="nums">'.e(Money::rub($bills->sum(fn ($i) => $i->remaining()))).'</span></span>';
@endphp
<x-ui.shell :title="$presets['selection'] ?? 'Оплата за подбор'" :heading="false" :detail="$detail">
    <x-admin.tab-head :presets="$presets" preset="selection" :counts="$counts" :sections="$sections" name="selection" :facets="$facets" search="Номер, марка, VIN, убыток"/>

    <div id="list">
    @if ($total === 0 && $facets->activeCount() && ! $facets->searching())
        <x-ui.empty class="mt-6" :href="$facets->resetUrl()" link="Сбросить фильтр">По фильтру ничего</x-ui.empty>
    @elseif ($total === 0)
        <x-ui.empty class="mt-6">{{ $facets->searching() ? 'Ничего не нашлось' : 'Неоплаченных подборов нет' }}</x-ui.empty>
    @else
        <x-ui.table id="selection-table" class="mt-4" :table="$columns">
            <x-slot:head><x-table.head :table="$columns"/></x-slot:head>
            @if ($unsent->isNotEmpty())
                <x-table.group :table="$columns" key="unsent" title="Не выставлены" :count="$unsent->count()" danger>{!! $sum($unsent) !!}</x-table.group>
                @foreach ($unsent as $i)<x-money.person-row :table="$columns" :invoice="$i"/>@endforeach
            @endif
            @if ($claimed->isNotEmpty())
                <x-table.group :table="$columns" key="claimed" title="Подтвердить оплату" :count="$claimed->count()" ours>{!! $sum($claimed) !!}</x-table.group>
                @foreach ($claimed as $i)<x-money.person-row :table="$columns" :invoice="$i"/>@endforeach
            @endif
            @if ($issued->isNotEmpty())
                <x-table.group :table="$columns" key="issued" title="Выставлены" :count="$issued->count()">{!! $sum($issued) !!}</x-table.group>
                @foreach ($issued as $i)<x-money.person-row :table="$columns" :invoice="$i"/>@endforeach
            @endif
        </x-ui.table>
    @endif
    </div>
    <x-mail.window/>
</x-ui.shell>
