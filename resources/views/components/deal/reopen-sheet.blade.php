{{-- «Вернуть сделку в работу…» (14.11.2026, `ReopenDeal`): шаг из тех, что сделка прошла (отмечен шаг перед закрытием), и что
     снимется — «Поставщику оплачено», если оплата поставщику снова впереди (строкой у шага), и всё, что сделала «Уже продана»
     (галкой). `POST /work/deals/{id}/reopen`. Открывает событие reopen:open (пункт «⋯» окна и страницы). --}}
@props(['deal'])
@php
    use App\Offers\Actions\ReopenDeal;
    $steps = ReopenDeal::stages($deal);
    $snap = ReopenDeal::snapshot($deal);
    $last = $steps->last()['stage'] ?? null;
    $paidSum = $snap ? \App\Billing\Payment::whereIn('id', $snap['payments'] ?? [])->where('state', 'confirmed')->sum('amount') : 0;
    $undo = $snap ? array_filter([
        $paidSum ? 'оплаты '.\App\Support\Money::rub((int) $paidSum) : null,
        ! empty($snap['contract']) ? 'отметка «договор подписан»' : null,
        ! empty($snap['transport']) ? 'вывоз снова идёт' : null,
        ! empty($snap['vehicle']) ? 'выдача на парковке' : null,
    ]) : [];
@endphp
<div data-controller="sheet" data-action="reopen:open@window->sheet#open" class="contents">
    <x-ui.sheet id="reopen-{{ $deal->id }}" title="Вернуть сделку в работу">
        <form method="post" action="/work/deals/{{ $deal->id }}/reopen" class="flex flex-col gap-4">
            @csrf
            <div>
                <h3 class="list-head">На какой шаг</h3>
                <div class="list">
                    @foreach ($steps as $row)
                        @php $s = $row['stage']; $unpay = $deal->supplier_paid_at && ReopenDeal::unpaysSupplier($deal, $s); @endphp
                        <label class="row row-check">
                            <span class="min-w-0 flex-1">
                                <span class="block">{{ $s->name }}</span>
                                <span class="row-sub">@if ($row['at']){{ \App\Support\Ago::html($row['at']) }}@endif @if ($unpay)<span class="text-urgent">снимется «поставщику оплачено {{ $deal->supplier_paid_at->format('d.m') }}»</span>@endif</span>
                            </span>
                            <span class="check"><input type="radio" name="stage_id" value="{{ $s->id }}" @checked($last?->is($s))></span>
                        </label>
                    @endforeach
                </div>
            </div>
            @if ($undo)
                <input type="hidden" name="undo_sold" value="0">
                <x-ui.check name="undo_sold" :checked="true">Откатить «Уже продана»: {{ implode(', ', $undo) }}</x-ui.check>
            @endif
            @error('deal')<p class="field-error">{{ $message }}</p>@enderror
            @error('stage')<p class="field-error">{{ $message }}</p>@enderror
            <x-ui.sheet-foot type="submit">Вернуть в работу</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
