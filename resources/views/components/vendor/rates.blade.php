{{-- Ставки хранения вендора по категориям — плашкой строк, последней строкой переход к редактору прайса. Одно
     место для обзора вендора в CRM, шторки «Изменить» и шторки вендора на парковке. --}}
@props(['vendor', 'edit' => null])
<div {{ $attributes->merge(['class' => 'list']) }}>
    @foreach ($vendor->storageRates() as $category => $rate)
        <div class="row justify-between"><span>{{ $category }}</span><span class="nums text-right {{ $rate ? 'text-ink-muted' : 'text-urgent' }}">{{ $rate ?? 'нет ставки' }}</span></div>
    @endforeach
    @if ($edit)
        <a href="{{ $edit }}" class="row justify-between" data-turbo="{{ str_starts_with($edit, 'http') ? 'false' : 'true' }}"><span class="text-accent-text">Изменить ставки</span><x-ui.icon name="chevron-right" class="size-4 text-accent-text"/></a>
    @endif
</div>
