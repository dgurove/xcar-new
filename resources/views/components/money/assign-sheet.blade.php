{{-- «Назначить менеджера» ручной ссылке (22.11.2026, владелец: «в „Без менеджера“ — менеджеров указывать и к сделке существующей
     привязывать»): кнопкой в строке «Оплат» и строкой «Менеджер» в карточке ссылки. ТС — поле с подсказкой машин системы
     (`x-billing.car-field`): выбрали машину со сделкой — менеджер встаёт её покупателем, оплата засчитывается в её счета
     (`AssignServiceManager`), подпись кнопки — исход («Засчитать 53 000 ₽», `DealCredit::outcome`). row — строкой «Менеджер» карточки. --}}
@props(['invoice', 'label' => 'Назначить менеджера', 'row' => false])
@php
    $managers = once(fn () => \App\Users\User::withRole(\App\Users\Role::Manager)->orderBy('name')->pluck('name', 'id'));
    $link = $invoice->payLinks->sortByDesc('id')->first();
    $id = 'assign-'.$invoice->id;
@endphp
<span class="contents" data-controller="sheet">
    @if ($row)
        <button type="button" class="row w-full text-left" data-action="sheet#open" aria-controls="{{ $id }}">
            <span class="shrink-0 text-ink-muted">Менеджер</span>
            <span @class(['min-w-0 flex-1 truncate text-right', 'text-accent-text' => ! $invoice->assignedManager])>{{ $invoice->assignedManager?->shortName() ?? 'Назначить' }}</span>
            <x-ui.chevron/>
        </button>
    @else
        <button type="button" class="btn btn-quiet btn-xs" data-action="sheet#open" aria-controls="{{ $id }}">{{ $label }}</button>
    @endif
    <x-ui.sheet :id="$id" title="Чья ссылка" class="sheet-steady">
        <form method="post" action="/work/money/invoices/{{ $invoice->id }}/manager" class="flex flex-1 flex-col gap-3" data-controller="pay-existing" data-action="car-field:chosen->pay-existing#chosen">
            @csrf
            <x-billing.car-field group :offer-id="$invoice->offer_id" :text="$invoice->offer ? trim($invoice->offer->title().' '.$invoice->offer->year) : $invoice->car_title" :preview="$link ? '/work/money/links/'.$link->id.'/attach' : null">
                <p class="px-1 text-sm text-accent-text" data-car-field-target="note" hidden></p>
                <div class="fields">
                    <x-ui.field name="manager_id" label="Менеджер" :options="$managers" :value="$invoice->manager_id" placeholder="Без менеджера"/>
                </div>
                <x-ui.sheet-foot foot="mt-auto" data-car-field-target="submit">Сохранить</x-ui.sheet-foot>
            </x-billing.car-field>
        </form>
    </x-ui.sheet>
</span>
