{{-- Окошко строки таблицы машин стоянки (фрейм peek) — работа с ТС без страницы:
     лента фото, метки, дней на стоянке; действия по состоянию — «Принять на стоянку»
     (ожидаемая), переставить (стоянка выбором, отправка сразу), выдать (дата, с
     подтверждением), акты; ниже заявки строками и заметка с последними событиями.
     Формы отвечают в окошко (PeekBack), строка — свежей из row. --}}
@php
    use App\Park\VehicleState;
    $state = $vehicle->state;
    $href = '/cars/'.$vehicle->id;
@endphp
<turbo-frame id="peek" target="_top">
    <x-ui.peek :href="$href" :title="$vehicle->titleWithYear()" :photos="$vehicle->visiblePhotos()">
        <x-slot:marks>
            <x-ui.pill :tone="$state->tone()" class="!min-h-0 !py-1 text-xs">{{ $state->label() }}</x-ui.pill>
            @if ($vehicle->plate)<span class="tag nums">{{ $vehicle->plate }}</span>@endif
            @if ($vehicle->ref)<span class="tag">{{ $vehicle->ref }}</span>@endif
            <x-ui.vin-code :vin="$vehicle->vin" class="tag"/>
            @if ($vehicle->vendor)<span class="tag">{{ $vehicle->vendor->name }}</span>@endif
            @if ($vehicle->yard)<x-ui.place class="tag">{{ $vehicle->yard->name }}</x-ui.place>@endif
            @if ($vehicle->accepted_at)<span class="tag nums">принята {{ $vehicle->accepted_at->translatedFormat('j M Y') }}</span>@endif
            @if ($state === VehicleState::Released && $vehicle->released_at)<span class="tag nums">выдана {{ $vehicle->released_at->translatedFormat('j M Y') }}</span>@endif
        </x-slot:marks>
        <x-slot:aside>
            @if ($state === VehicleState::Stored && $vehicle->daysStored() !== null)<span class="nums whitespace-nowrap text-[17px] font-bold">{{ $vehicle->daysStored() }} {{ \App\Support\Plural::of($vehicle->daysStored(), ['день', 'дня', 'дней']) }}</span>@endif
        </x-slot:aside>
        <x-slot:actions>
            @if ($state === VehicleState::Expected)
                <a href="/requests/new?tip=intake&mashina={{ $vehicle->id }}" class="btn btn-s btn-accent">Принять на стоянку</a>
            @endif
            @if ($state === VehicleState::Stored)
                <form method="post" action="{{ $href }}/move" class="contents" data-controller="autosubmit">@csrf
                    <select name="yard_id" class="field-input field-s w-auto" aria-label="Стоянка" data-action="change->autosubmit#submit">
                        @foreach ($yards as $id => $name)<option value="{{ $id }}" @selected($id == $vehicle->yard_id)>{{ $name }}</option>@endforeach
                    </select>
                </form>
                <form method="post" action="{{ $href }}/release" class="contents" data-turbo-confirm="Выдать ТС?">@csrf
                    <input type="datetime-local" name="released_at" value="{{ now()->format('Y-m-d\TH:i') }}" class="field-input field-s w-auto" aria-label="Выдача">
                    <button class="btn btn-s btn-accent">Выдать</button>
                </form>
                <a href="/acts/{{ $vehicle->id }}/intake" class="pill pill-plain" data-turbo="false" target="_blank">Акт приёма</a>
            @endif
            @if ($state === VehicleState::Released)
                <a href="/acts/{{ $vehicle->id }}/intake" class="pill pill-plain" data-turbo="false" target="_blank">Акт приёма</a>
                <a href="/acts/{{ $vehicle->id }}/release" class="pill pill-plain" data-turbo="false" target="_blank">Акт выдачи</a>
            @endif
            <a href="/requests/new?tip=inspection&mashina={{ $vehicle->id }}" class="pill pill-plain">Новая заявка</a>
        </x-slot:actions>
        @if ($vehicle->requests->isNotEmpty())
            <div class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($vehicle->requests as $r)
                    <a href="/requests/{{ $r->id }}" class="chip {{ $r->isOpen() ? 'bg-accent-soft text-accent-text' : 'bg-closed-soft text-closed' }}">{{ $r->type->label() }} <span class="nums font-normal">{{ $r->isOpen() ? ($r->planned_at?->translatedFormat('j M, H:i') ?? 'ждёт') : $r->state->label() }}</span></a>
                @endforeach
            </div>
        @endif
        <form method="post" action="{{ $href }}/note" class="mt-3 flex gap-2">@csrf
            <input name="text" class="field-input field-s min-w-0 flex-1" placeholder="Заметка" required>
            <button class="btn btn-s btn-quiet shrink-0">Записать</button>
        </form>
        @if ($vehicle->events->isNotEmpty())
            <div class="mt-3 flex flex-col gap-1.5 text-sm">
                @foreach ($vehicle->events->take(5) as $event)
                    <div class="flex gap-3">
                        <span class="shrink-0 text-ink-dim nums">{{ $event->created_at->translatedFormat('j M H:i') }}</span>
                        <span class="min-w-0">{{ $event->text() }}</span>
                        @if ($event->user)<span class="ml-auto shrink-0 text-ink-muted">{{ $event->user->shortName() }}</span>@endif
                    </div>
                @endforeach
            </div>
        @endif
        <x-slot:row><x-park.table-row :vehicle="$vehicle"/></x-slot:row>
    </x-ui.peek>
</turbo-frame>
