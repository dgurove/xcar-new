{{-- Файлы акта счёта кнопками шторки документов: реестр — прежняя форма страховой (Excel, Word; у РГС акт и приложение),
     счёт на ТС — PDF акта хранения, без файла — страница на печать. Счёт без акта (сделка, вывоз) — ничего. --}}
@php
    use App\Billing\ChargeKind;
    $files = $invoice->getMedia('act')->values();
    $shown = $invoice->kind === ChargeKind::Storage || ($invoice->isRegistry() && $invoice->kind === ChargeKind::Tow);
    $type = fn ($m) => match (true) { str_ends_with($m->file_name, '.xlsx') => 'sheet', str_ends_with($m->file_name, '.docx') => 'word', default => 'pdf' };
@endphp
@if ($shown)
    @forelse ($files as $n => $m)
        <x-ui.doc :doc="['url' => $base.'/act'.($n ? '?n='.$n : ''), 'type' => $type($m), 'name' => $m->file_name, 'label' => pathinfo($m->file_name, PATHINFO_FILENAME)]" :class="$class"/>
    @empty
        <x-ui.doc :doc="['url' => $base.'/act', 'type' => $invoice->isRegistry() ? 'sheet' : 'html', 'name' => $invoice->isRegistry() ? 'Отчёт-акт' : 'Акт хранения']" :class="$class"/>
    @endforelse
@endif
