{{-- Ставки хранения вендора по категориям — плашкой строк, последней строкой переход к редактору прайса. Одно
     место для обзора вендора в CRM, шторки «Изменить» и шторки вендора на парковке. --}}
@props(['vendor', 'edit' => null])
<div {{ $attributes->merge(['class' => 'list']) }}>
    @foreach ($vendor->storageRates() as $category => $rate)
        <div class="pass-row"><span>{{ $category }}</span><span class="nums text-right {{ $rate ? 'text-ink-muted' : 'text-urgent' }}">{{ $rate ?? 'нет ставки' }}</span></div>
    @endforeach
    @if ($edit)
        <a href="{{ $edit }}" class="pass-row" data-turbo="{{ str_starts_with($edit, 'http') ? 'false' : 'true' }}"><span class="text-accent-text">Изменить ставки</span><x-ui.icon name="chevron-right" class="size-4 text-accent-text"/></a>
    @endif
</div>
