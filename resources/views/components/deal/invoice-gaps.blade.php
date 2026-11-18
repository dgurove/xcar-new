{{-- Счёт за ТС, которого ещё нет, — строкой, как будто он сформирован (18.11.2026, владелец по жалобе начальника «где счёт на тачку,
     они не прикладывают данные»: «в CRM будет отображаться счёт как если бы всё было заполнено, просто при нажатии — окно, что счёт
     невозможно сформировать, пока не указаны: ФИО, адрес прописки; там же можно сразу ввести»). Второй строкой — чего нет; нажатие —
     шторка: недостающие поля покупателя (`Party::invoiceGaps`, у ИП и организации — свои), «Сформировать счёт», «Сформировать без
     прописки» (не хватает только её — `invoice_without_reg_at`), «Отмена». Не поля покупателя (покупатель не выбран, цена,
     вознаграждение, реквизиты Р.О.С.) — словами, покупатель — кнопкой выбора (`x-deal.buyer`, событие `deal-buyer-pick-{id}`). --}}
@props(['deal'])
@php
    use App\Billing\PartyKind;
    $blockers = $deal->invoiceBlockers();
    $buyer = $deal->invoiceBuyer();
    $party = $buyer ? \App\Billing\Party::forUser($buyer) : null;
    $fields = $party ? array_intersect_key($blockers, $party->invoiceGaps()) : [];
    $canEdit = auth()->user()?->canEditDeals() && $deal->isLive();
    $id = 'deal-invoice-gaps-'.$deal->id;
    $onlyReg = $party?->invoiceLacksOnlyReg() && array_keys($fields) === ['reg_address'];
    $company = $party?->kind === PartyKind::Company;
    $words = mb_strtolower(implode(', ', $blockers));
@endphp
<div data-controller="sheet" class="contents">
    <button type="button" class="row w-full text-left" data-action="sheet#open">
        <x-ui.file-icon name="schet.pdf"/>
        <span class="min-w-0 flex-1">
            <span class="block truncate">Счёт за ТС</span>
            <span class="row-sub">@if ($blockers)<span class="text-danger">не указано: {{ $words }}</span>@else<span class="text-ink-muted">не сформирован</span>@endif</span>
        </span>
        <x-ui.chevron/>
    </button>
    <x-ui.sheet :id="$id" title="Счёт невозможно сформировать" :open="$errors->hasAny(['buyer.*']) && old('_sheet') === $id">
        @if ($blockers)
            <p class="mb-3 text-ink-muted">Пока не указано: {{ $words }}</p>
        @else
            <p class="mb-3 text-ink-muted">Счёт не встал сам — выставьте его в «Деньгах»</p>
        @endif
        @if ($fields && $canEdit)
            <form method="post" action="/work/deals/{{ $deal->id }}/invoice-data" class="flex flex-col gap-3" id="{{ $id }}-form">
                @csrf @method('put')
                <div class="fields fields-stack">
                    @foreach ($fields as $k => $label)
                        @php $name = $company && $k === 'name' ? 'org' : $k; @endphp
                        <x-ui.field :name="'buyer['.$name.']'" :id="$id.'-'.$name" :label="$label" :type="str_contains($k, 'address') ? 'textarea' : 'text'" :rows="str_contains($k, 'address') ? 1 : null" required/>
                    @endforeach
                </div>
                <div class="sheet-foot flex flex-col gap-2">
                    <x-ui.button block>Сформировать счёт</x-ui.button>
                    @if ($onlyReg)
                        <button type="submit" name="without_reg" value="1" class="btn btn-quiet w-full" formnovalidate>Сформировать без прописки</button>
                    @endif
                    <button type="button" class="btn btn-ghost w-full" data-action="sheet#close">Отмена</button>
                </div>
            </form>
        @else
            <div class="sheet-foot flex flex-col gap-2">
                @if (isset($blockers['buyer']) && $canEdit)
                    <button type="button" class="btn btn-accent w-full" data-controller="emit" data-action="sheet#close emit#send" data-emit-event-param="deal-buyer-pick-{{ $deal->id }}:open">Выбрать покупателя</button>
                @endif
                <button type="button" class="btn btn-ghost w-full" data-action="sheet#close">Отмена</button>
            </div>
        @endif
    </x-ui.sheet>
</div>
