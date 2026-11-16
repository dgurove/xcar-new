{{-- Плательщик сделки — одна строка над счётом и ссылкой (16.11.2026, владелец: «плательщика выбирать и для счёта, и для ссылки,
     причём в одном месте — это два способа оплаты одной суммы»; «у счёта тоже необязательно менеджер»). Одна на CRM («Продажа»,
     `x-deal.money`) и страницу сделки менеджера (квитанция `cabinet/deals/payment`). Нажатие — шторка «Кто платит»: менеджер
     («Я» у него самого), покупатель по договору и другие его покупатели, сторонние плательщики этой сделки — строками с
     карандашом; «Другой плательщик» — физлицо, ИП или организация. Выбор сразу перевыставляет неоплаченный счёт и ссылку
     (`SetDealPayer`); по счёту уже платили — строка без выбора. --}}
@props(['deal', 'action', 'id' => null, 'editable' => true])
@php
    use App\Offers\Actions\SetDealPayer;
    use App\Support\People;
    $id ??= 'deal-payer-'.$deal->id;
    $me = auth()->user();
    $mine = $me && $me->id === $deal->buyer_id;
    // Имя без заведения стороны (`payerUser`): страница стороны не создаёт.
    $user = $deal->payerUser(sale: $deal->isPrime());
    $isManager = $user ? $user->id === $deal->buyer_id : ($deal->buyer && $deal->payer_party_id === $deal->buyer->party_id);
    $value = $isManager ? ($mine ? 'Вы' : $deal->buyer->name) : ($user?->name ?? $deal->payer?->name ?? 'не выбран');
    $locked = SetDealPayer::locked($deal);
    $can = $editable && ! $locked && $deal->buyer_id;
    $options = $can ? SetDealPayer::options($deal) : [];
    $current = old('_sheet') === $id ? (string) old('payer') : SetDealPayer::current($deal);
@endphp
@if (! $can)
    <div class="row sale-row">
        <span class="sale-row-label">Плательщик</span>
        <span class="sale-row-value">{{ $value }}</span>
    </div>
@else
<div class="contents" data-controller="sheet">
    <x-deal.sale-row label="Плательщик" :value="$value" data-action="sheet#open"/>
    <x-ui.sheet :id="$id" title="Кто платит">
        <form method="post" action="{{ $action }}" class="flex flex-col gap-4" data-controller="reveal autosubmit">
            @csrf @method('put')
            <p class="-mt-2 text-sm text-ink-muted">На него счёт и ссылка на оплату</p>
            <div class="list max-h-80 overflow-y-auto">
                @foreach ($options as $o)
                    @php
                        $who = $o['user']
                            ? People::row($o['user'], $o['sub']) + ($o['value'] === 'manager' && $mine ? ['name' => 'Я'] : [])
                            : ['name' => $o['party']->name, 'sub' => $o['sub'], 'user' => null];
                    @endphp
                    <x-people.option :picker="false" :person="['id' => $o['value']] + $who" name="payer" :checked="$current === $o['value']"
                        action="reveal#pick change->autosubmit#submit" :edit="$o['user'] ? People::editUrl($o['user']) : null"/>
                @endforeach
                <label class="row row-check">
                    <x-ui.row-icon name="plus" size="s" tone="accent"/>
                    <span class="min-w-0 flex-1 font-medium text-accent-text">Другой плательщик</span>
                    <span class="check"><input type="radio" name="payer" value="new" @checked($current === 'new') data-action="reveal#pick"></span>
                </label>
            </div>
            @error('payer')<p class="field-error">{{ $message }}</p>@enderror
            <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="new" @if ($current !== 'new') hidden @endif>
                <x-ui.segment name="kind" :options="['person' => 'Физлицо', 'ip' => 'ИП', 'company' => 'Организация']" :value="old('kind', 'person')"/>
                <div class="fields fields-stack">
                    <x-ui.field name="name" :id="$id.'-name'" label="ФИО или название"/>
                    <x-ui.field name="inn" :id="$id.'-inn'" label="ИНН" inputmode="numeric"/>
                    <x-ui.field name="phone" :id="$id.'-phone'" label="Телефон" type="tel"/>
                </div>
                <x-ui.button block>Сохранить</x-ui.button>
            </div>
        </form>
    </x-ui.sheet>
</div>
@endif
