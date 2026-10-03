{{-- Окошко строки таблицы машин стоянки (фрейм peek) — работа с ТС без страницы:
     лента фото, метки, дней на стоянке; действия по состоянию — «Принять на стоянку»
     (ожидаемая), переставить (стоянка выбором, отправка сразу), выдать (дата, с
     подтверждением), акты; ниже расхождения карточки с документами строками «Взять» (FillFromDocs), заявки строками и
     заметка с последними событиями. Под действиями — группа документов: расхождения и «Заполнить из документов»
     (пока нет марки, модели, VIN, года или цвета) или «Сверить с документами»; искра — ещё и в полосе окошка (tools).
     Окно «Из документов» встаёт поверх, окошко остаётся и перечитывается после «Подставить».
     Формы отвечают в окошко (PeekBack), строка — свежей из row. --}}
@php
    use App\Park\VehicleState;
    $state = $vehicle->state;
    $href = '/cars/'.$vehicle->id;
    $scanUrl = $href.'/scan';
    $gaps = ! $vehicle->brand_id || ! $vehicle->model_id || $vehicle->vinProblem() || ! $vehicle->year || ! $vehicle->color;
@endphp
<x-ui.detail>
    <x-ui.peek :href="$href" :title="$vehicle->titleWithYear()" :photos="$vehicle->visiblePhotos()">
        <x-slot:marks>
            <x-ui.state :tone="$state->tone()">{{ $state->label() }}</x-ui.state>
            @if ($vehicle->plate)<x-ui.plate :value="$vehicle->plate"/>@endif
            @if ($vehicle->ref)<x-ui.copy-code class="tag" :value="$vehicle->ref"/>@endif
            <x-ui.vin-code :vin="$vehicle->vin" class="tag"/>
            @if ($vehicle->vendor)<x-vendor.name :vendor="$vehicle->vendor" class="tag"/>@endif
            @if ($vehicle->yard)<x-ui.place class="tag">{{ $vehicle->yard->name }}</x-ui.place>@endif
            @if ($total && $total['rate'])<span class="tag nums">{{ \App\Support\Money::rub($total['rate']) }}/д</span>@endif
            <x-park.alerts :vehicle="$vehicle" :skip="$differences ? [\App\Park\Alerts::DOCS] : []"/>
            @if ($vehicle->accepted_at)<span class="tag nums">принята {{ $vehicle->accepted_at->translatedFormat('j M Y') }}</span>@endif
            @if ($state === VehicleState::Released && $vehicle->released_at)<span class="tag nums">выдана {{ $vehicle->released_at->translatedFormat('j M Y') }}</span>@endif
        </x-slot:marks>
        <x-slot:aside>
            @if ($state === VehicleState::Stored && $vehicle->daysStored() !== null)<span class="nums whitespace-nowrap text-lg font-bold">{{ $vehicle->daysStored() }} {{ \App\Support\Plural::of($vehicle->daysStored(), ['день', 'дня', 'дней']) }}</span>@endif
            @if ($total && $total['amount'] > 0)<span class="nums whitespace-nowrap text-sm text-ink-dim">{{ \App\Support\Money::rub($total['amount']) }}</span>@endif
        </x-slot:aside>
        <x-slot:actions>
            @if ($state === VehicleState::Expected)
                @php $open = $vehicle->openRequest(\App\Park\RequestType::Tow) ?? $vehicle->openRequest(\App\Park\RequestType::Intake); @endphp
                @if ($open)<a href="/cars/{{ $vehicle->id }}" class="btn btn-s btn-accent">{{ $open->verb() ?? 'Открыть' }}</a>
                @else<form method="post" action="/requests" class="contents" data-turbo-frame="_top">@csrf<input type="hidden" name="type" value="intake"><input type="hidden" name="vehicle_id" value="{{ $vehicle->id }}"><button class="btn btn-s btn-accent">Принять</button></form>@endif
            @endif
            @if ($state === VehicleState::InTransit && ($tow = $vehicle->openRequest(\App\Park\RequestType::Tow)))
                <a href="/cars/{{ $vehicle->id }}" class="btn btn-s btn-accent">Принять</a>
            @endif
            @if ($state === VehicleState::Stored)
                <form method="post" action="{{ $href }}/move" class="contents" data-controller="autosubmit">@csrf
                    <select name="yard_id" class="field-input field-s w-auto" aria-label="Парковка" data-action="change->autosubmit#submit">
                        @foreach ($yards as $id => $name)<option value="{{ $id }}" @selected($id == $vehicle->yard_id)>{{ $name }}</option>@endforeach
                    </select>
                </form>
                {{-- Выдача — только через заявку с осмотром, подписью и актом; одна дорога. --}}
                @if ($release = $vehicle->openRequest(\App\Park\RequestType::Release))
                    <a href="/cars/{{ $vehicle->id }}" class="btn btn-s btn-accent">Выдать</a>
                @else
                    <form method="post" action="/requests" class="contents" data-turbo-frame="_top">@csrf<input type="hidden" name="type" value="release"><input type="hidden" name="vehicle_id" value="{{ $vehicle->id }}"><button class="btn btn-s btn-accent">Выдать</button></form>
                @endif
                @if ($debt > 0)<span class="pill pill-danger !min-h-0 !py-1 text-xs nums">долг {{ \App\Support\Money::rub($debt) }}</span>@endif
                <a href="/acts/{{ $vehicle->id }}/intake" class="pill pill-plain" data-turbo="false" target="_blank">Акт приёма</a>
            @endif
            @if ($state === VehicleState::Released)
                <a href="/acts/{{ $vehicle->id }}/intake" class="pill pill-plain" data-turbo="false" target="_blank">Акт приёма</a>
                <a href="/acts/{{ $vehicle->id }}/release" class="pill pill-plain" data-turbo="false" target="_blank">Акт выдачи</a>
            @endif
            @unless ($state->isFinal())<a href="/requests/new?type=inspection&car={{ $vehicle->id }}" class="pill pill-plain">Осмотр</a>@endunless
        </x-slot:actions>
        @if ($scanFiles->isNotEmpty())
            <x-slot:tools><x-mail.scan-button :url="$scanUrl" look="icon"/></x-slot:tools>
        @endif
        @if ($differences || $scanFiles->isNotEmpty())
            <form method="post" action="{{ $href }}/take" class="mt-3">@csrf
                <x-park.doc-differences :vehicle="$vehicle" :differences="$differences">
                    @if ($scanFiles->isNotEmpty())<x-mail.scan-button :url="$scanUrl" :fill="$gaps" :files="$scanFiles"/>@endif
                </x-park.doc-differences>
            </form>
        @endif
        @if ($vehicle->requests->isNotEmpty())
            <div class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($vehicle->requests as $r)
                    <a href="/cars/{{ $vehicle->id }}{{ $r->isOpen() ? '?req='.$r->id : '' }}" class="chip {{ $r->isOpen() ? 'bg-accent-soft text-accent-text' : 'bg-closed-soft text-closed' }}">{{ $r->type->label() }} <span class="nums font-normal">{{ $r->isOpen() ? ($r->planned_at?->translatedFormat('j M, H:i') ?? 'ждёт') : $r->state->label() }}</span></a>
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
        <x-slot:row><x-park.table-row :vehicle="$vehicle" :total="$total"/></x-slot:row>
    </x-ui.peek>
</x-ui.detail>
