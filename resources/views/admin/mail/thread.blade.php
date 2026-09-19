@php $park = $base === '/mail'; @endphp
<x-ui.shell :title="$thread->subject ?: '(без темы)'" :back="['Почта', '/work/mail']">
    <div class="mb-4 flex flex-wrap items-center gap-2" data-controller="sheet">
        <span class="chip">{{ $thread->account->title }}</span>
        @if ($park && $thread->vehicle)
            <a href="/cars/{{ $thread->vehicle->id }}" class="chip bg-accent-soft text-accent-text">{{ $thread->vehicle->titleWithYear() }}@if ($thread->vehicle->ref) <span class="nums opacity-70">{{ $thread->vehicle->ref }}</span>@endif</a>
        @elseif (!$park && $thread->offer)
            <a href="/offers/{{ $thread->offer->number }}" class="chip bg-accent-soft text-accent-text">{{ $thread->offer->title() }} <span class="nums opacity-70">№ {{ $thread->offer->number }}</span></a>
        @endif
        <button type="button" class="chip" data-action="sheet#open">{{ ($park ? $thread->vehicle : $thread->offer) ? 'Перепривязать' : ($park ? 'Привязать к ТС' : 'Привязать к предложению') }}</button>
        <x-ui.sheet id="link" :title="$park ? 'Транспортное средство' : 'Предложение'" :open="$errors->has('number') || $errors->has('vehicle_id')">
            <form method="post" action="{{ $base }}/{{ $thread->id }}/link" class="flex flex-col gap-3">
                @csrf
                @if ($park)
                    <x-ui.combobox name="vehicle_id" label="Транспортное средство" url="/reference/cars" :value="$thread->vehicle_id" :text="$thread->vehicle?->titleWithYear()"/>
                @else
                    <x-ui.field name="number" label="Номер предложения" inputmode="numeric" :value="$thread->offer?->number" autofocus/>
                @endif
                <div class="flex gap-2">
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
