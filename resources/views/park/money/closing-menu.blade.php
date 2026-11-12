{{-- «···» документа: «Отменить отправку» — только у отправленного, по которому не платили (05.11.2026, владелец: «нужна,
     только если отправлен», красная кнопка внизу — «расположение неудачное»). Отмена — `VoidInvoice`: номер освобождается,
     документ снова живой; при более позднем отправленном по тем же ТС не даёт. --}}
@php $i = $invoice; @endphp
@if ($i && $i->state === \App\Billing\InvoiceState::Issued && (float) $i->paid === 0.0 && auth()->user()->canManagePark())
    {{-- В полосе окна — значком полосы, в карточке — пилюлей; меню — шторкой действий (`x-ui.more`, 12.11.2026). --}}
    <x-ui.more :id="'doc-menu-'.$i->id" :bar="! isset($button)">
        <form method="post" action="/money/invoices/{{ $i->id }}/void" data-turbo-confirm="Отменить отправку {{ $i->label() }}? Документ снова станет неотправленным, номер освободится">
                @csrf<button class="menu-item text-danger" role="menuitem"><x-ui.icon name="x" class="size-5"/>Отменить отправку</button>
            </form>
    </x-ui.more>
@endif
