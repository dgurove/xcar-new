{{-- Подпись блока документа (19.11.2026, владелец: «как у покупателя — над строкой подпись и справа состояние»): «Счёт — отправлен
     19.11», «Договор — не отправлен». Тон — `Billing\DocWord`: ok зелёным, bad красным, due оранжевым, muted серым. Под ней — своя
     плашка `.list` документа. --}}
@props(['label', 'state' => null, 'tone' => 'muted'])
<div {{ $attributes->class('list-cap !px-0 flex justify-between gap-3') }}>
    <span>{{ $label }}</span>
    @if ($state)<span @class(['text-right', 'text-open' => $tone === 'ok', 'text-danger' => $tone === 'bad', 'text-urgent' => $tone === 'due', 'text-ink-muted' => $tone === 'muted'])>{{ $state }}</span>@endif
</div>
