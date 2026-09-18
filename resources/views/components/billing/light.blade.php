{{-- Светофор счёта пилюлей: оплачен, ждём, срок близко, просрочен, аннулирован. --}}
@props(['invoice'])
@php $tone = $invoice->light(); $text = match (true) { $invoice->state === \App\Billing\InvoiceState::Void => 'Аннулирован', $invoice->state === \App\Billing\InvoiceState::Paid => 'Оплачен', $invoice->isOverdue() => 'Просрочен '.$invoice->due_at->diffInDays(now()).' дн', $invoice->isPartial() => 'Частично', default => 'До '.$invoice->due_at->translatedFormat('j M') }; @endphp
<x-ui.pill :tone="$tone ?? 'plain'" {{ $attributes->merge(['class' => '!min-h-0 !py-1 text-xs']) }}>{{ $text }}</x-ui.pill>
