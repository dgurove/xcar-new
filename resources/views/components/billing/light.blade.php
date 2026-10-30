{{-- Светофор счёта словом: оплачен (наша выплата — «выплачено»), ждём, срок близко, просрочен, аннулирован. --}}
@props(['invoice'])
@php $tone = $invoice->light(); $text = match (true) { $invoice->state === \App\Billing\InvoiceState::Void => 'Аннулирован', $invoice->state === \App\Billing\InvoiceState::Paid => $invoice->isOwed() ? ($invoice->isAgentFee() ? 'Выплачено' : 'Перечислено') : 'Оплачен', $invoice->isOverdue() => $invoice->overdueLabel(true), $invoice->isPartial() => 'Частично', ! $invoice->due_at => \Illuminate\Support\Str::ucfirst($invoice->laterLabel()), default => 'До '.\App\Support\Ago::day($invoice->due_at) }; @endphp
<x-ui.state :tone="$tone ?? 'plain'" {{ $attributes }}>{{ $text }}</x-ui.state>
