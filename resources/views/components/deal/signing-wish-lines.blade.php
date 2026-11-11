{{-- Названное время по дням строками (11.11.2026, данные элементами, а не фразой): «чт 12.11» тусклым, часы — рядом. --}}
@props(['deal', 'title' => 'Покупатель может'])
<span {{ $attributes->class('min-w-0 flex-1') }}>
    <span class="block">{{ $title }}</span>
    @foreach (\App\Offers\Signing::byDay($deal) as $d)
        <span class="block nums"><span class="text-ink-muted">{{ $d['day']->locale('ru')->isoFormat('dd') }} {{ $d['day']->format('d.m') }}</span>&nbsp; {{ implode(', ', $d['times']) }}</span>
    @endforeach
</span>
