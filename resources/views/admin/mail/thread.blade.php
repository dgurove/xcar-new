@php $park = $base === '/mail'; @endphp
<x-ui.shell :title="$thread->subject ?: '(без темы)'" :back="['Почта', $base]">
    <div class="mb-4 flex flex-wrap items-center gap-2" data-controller="sheet">
        {{-- Ящик — текстом, ТС или предложение — ссылкой, привязка — кнопкой справа (10.10.2026: «что-то чипами, что-то текстом»). --}}
        <span class="text-sm text-ink-muted">{{ $thread->account->title }}</span>
        @if ($park && $thread->vehicle)
            <a href="/cars/{{ $thread->vehicle->id }}" class="text-sm text-accent-text">{{ $thread->vehicle->titleWithYear() }}@if ($thread->vehicle->ref && $thread->vehicle->brand_id) <span class="nums opacity-70">{{ $thread->vehicle->ref }}</span>@endif</a>
        @elseif (!$park && $thread->offer)
            <a href="/offers/{{ $thread->offer->number }}" class="text-sm text-accent-text">{{ $thread->offer->title() }} <span class="nums opacity-70">№ {{ $thread->offer->number }}</span></a>
        @endif
        <button type="button" class="btn btn-s btn-quiet ml-auto" data-action="sheet#open">{{ ($park ? $thread->vehicle : $thread->offer) ? 'Перепривязать' : ($park ? 'Привязать к ТС' : 'Привязать к предложению') }}</button>
        <x-ui.sheet id="link" :title="$park ? 'Транспортное средство' : 'Предложение'" :open="$errors->has('number') || $errors->has('vehicle_id')">
            <form method="post" action="{{ $base }}/{{ $thread->id }}/link" class="flex flex-col gap-3">
                @csrf
                @if ($park)
                    <x-ui.combobox name="vehicle_id" label="Транспортное средство" url="/reference/cars" :value="$thread->vehicle_id" :text="$thread->vehicle?->titleWithYear()"/>
                @else
                    <x-ui.field name="number" label="Номер предложения" :value="$thread->offer?->number"/>
                @endif
                <div class="sheet-foot flex gap-2">
                    <x-ui.button class="flex-1">Привязать</x-ui.button>
                    @if ($park ? $thread->vehicle : $thread->offer)<x-ui.button variant="ghost" name="{{ $park ? 'vehicle_id' : 'number' }}" value="">Отвязать</x-ui.button>@endif
                </div>
            </form>
        </x-ui.sheet>
        <form method="post" action="{{ $base }}/{{ $thread->id }}/unread" class="ml-auto">@csrf<x-ui.button variant="ghost" size="sm">Не прочитано</x-ui.button></form>
    </div>

    <div class="flex flex-col gap-4">
        @foreach ($messages as $message)
            <x-mail.message :message="$message" :base="$base" :document="$documents($message)"/>
        @endforeach
    </div>
</x-ui.shell>
