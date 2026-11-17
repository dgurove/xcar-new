{{-- Счёт файлом — строкой списка под строкой счёта на странице сделки менеджера (15.11.2026, владелец: «счёт должен быть не
     страницей, а кнопкой „Скачать PDF“»; выбрал вид «файлом, как документ», тот же, что у счёта в «Деньгах» CRM): значок PDF с
     весом, «Счёт № 184 от 13.11», нажатие — PDF в шторке документов, справа — «Скачать» (на ПК файлом, на телефоне — системный
     лист, `file_controller`). Нет файла или счёт аннулирован — не рисуется. --}}
@props(['invoice', 'url'])
{{-- PDF рисуется при открытии из текущих данных (17.11.2026, `InvoicePdf`): веса до открытия нет. --}}
@if ($invoice->hasPdf() && $invoice->state !== \App\Billing\InvoiceState::Void)
    <div {{ $attributes->class('row') }}>
        <a href="{{ $url }}" class="flex min-w-0 flex-1 items-center gap-3" data-doc="pdf" data-doc-name="Счёт {{ $invoice->label() }}" target="_blank">
            <x-ui.file-icon name="schet.pdf"/>
            <span class="min-w-0 flex-1 truncate">Счёт {{ $invoice->label() }} <span class="text-ink-muted">от {{ \App\Support\Ago::day($invoice->issued_at) }}</span></span>
        </a>
        <a href="{{ $url }}" download class="btn btn-ghost btn-s shrink-0 px-1.5 text-ink-muted" aria-label="Скачать PDF" title="Скачать PDF" data-controller="file" data-action="file#share" data-file-name="Счёт {{ $invoice->label() }}.pdf"><x-ui.icon name="download" class="size-5"/></a>
    </div>
@endif
