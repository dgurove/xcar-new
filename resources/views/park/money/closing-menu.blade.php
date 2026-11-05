{{-- «···» документа: «Отменить отправку» — только у отправленного, по которому не платили (05.11.2026, владелец: «нужна,
     только если отправлен», красная кнопка внизу — «расположение неудачное»). Отмена — `VoidInvoice`: номер освобождается,
     документ снова живой; при более позднем отправленном по тем же ТС не даёт. --}}
@php $i = $invoice; @endphp
@if ($i && $i->state === \App\Billing\InvoiceState::Issued && (float) $i->paid === 0.0 && auth()->user()->canManagePark())
    <div class="contents" data-controller="menu">
        <button type="button" class="{{ $button ?? 'bar-btn' }}" data-action="menu#toggle" aria-haspopup="menu" aria-controls="doc-menu-{{ $i->id }}" aria-label="Ещё"><x-ui.icon name="more" class="size-[18px]"/></button>
        <div id="doc-menu-{{ $i->id }}" class="menu" popover data-menu-target="list" data-align="end" role="menu">
            <form method="post" action="/money/invoices/{{ $i->id }}/void" data-turbo-confirm="Отменить отправку {{ $i->label() }}? Документ снова станет неотправленным, номер освободится">
                @csrf<button class="menu-item text-danger" role="menuitem" data-action="menu#close"><x-ui.icon name="x" class="size-5"/>Отменить отправку</button>
            </form>
        </div>
    </div>
@endif
