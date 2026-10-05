{{-- Светофор счёта словом: оплачен (наша выплата — «выплачено»), ждём, срок близко, просрочен, аннулирован. --}}
@props(['invoice'])
@php $tone = $invoice->light(); $text = match (true) { $invoice->state === \App\Billing\InvoiceState::Void => 'Аннулирован', $invoice->state === \App\Billing\InvoiceState::Paid => $invoice->isOwed() ? ($invoice->isAgentFee() ? 'Выплачено' : 'Перечислено') : 'Оплачен', $invoice->isOverdue() => 'Просрочен на '.$invoice->overdueDays().' дн', $invoice->isPartial() => 'Частично', ! $invoice->due_at => 'После договора', default => 'До '.$invoice->due_at->translatedFormat('j M') }; @endphp
<x-ui.state :tone="$tone ?? 'plain'" {{ $attributes }}>{{ $text }}</x-ui.state>
