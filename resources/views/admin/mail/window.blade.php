{{-- Окно ветки: тема, участники, ТС или предложение, все письма целиком с «Ответить» под каждым;
     привязка к ТС / предложению и «Не прочитано» — тут же. Страницы ветки на стоянке нет; в CRM — ссылка «Ветка». --}}
@php $park = $base === '/mail'; $who = collect($thread->counterparts())->map(fn ($p) => $p['name'] ?: $p['email'])->take(3); $linked = $park ? $thread->vehicle : $thread->offer; @endphp
<turbo-frame id="letters-frame" target="_top">
    <div class="flex flex-col gap-4">
        <div>
            <h3 class="text-base font-medium">{{ $thread->subject ?: '(без темы)' }}</h3>
            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                @foreach ($who as $name)<span class="tag">{{ $name }}</span>@endforeach
                @if ($thread->vehicle)<a href="{{ \App\Support\Surface::Park->url('/cars/'.$thread->vehicle->id) }}" class="tag" @unless ($park) data-turbo="false" @endunless>{{ $thread->vehicle->titleWithYear() }}@if ($thread->vehicle->ref && $thread->vehicle->brand_id) <span class="nums text-ink-muted ml-1">{{ $thread->vehicle->ref }}</span>@endif</a>@endif
                @if ($thread->offer)<a href="{{ \App\Support\Surface::Crm->url('/offers/'.$thread->offer->number) }}" class="tag" @if ($park) data-turbo="false" @endif>{{ $thread->offer->title() }} <span class="nums text-ink-muted">№ {{ $thread->offer->number }}</span></a>@endif
                <span class="tag">{{ $thread->account->title }}</span>
                <details class="contents">
                    <summary class="chip cursor-pointer list-none">{{ $linked ? 'Перепривязать' : ($park ? 'Привязать к ТС' : 'Привязать к предложению') }}</summary>
                    <form method="post" action="{{ $base }}/{{ $thread->id }}/link" class="mt-2 flex w-full items-end gap-2" data-turbo-frame="letters-frame">
                        @csrf
                        @if ($park)
                            <div class="min-w-0 flex-1"><x-ui.combobox name="vehicle_id" label="Транспортное средство" url="/reference/cars" :value="$thread->vehicle_id" :text="$thread->vehicle?->titleWithYear()"/></div>
                        @else
                            <x-ui.field name="number" label="Номер предложения" inputmode="numeric" :value="$thread->offer?->number" span="flex-1"/>
                        @endif
                        <x-ui.button size="sm">Привязать</x-ui.button>
                        @if ($linked)<x-ui.button variant="ghost" size="sm" name="{{ $park ? 'vehicle_id' : 'number' }}" value="">Отвязать</x-ui.button>@endif
                    </form>
                </details>
                <form method="post" action="{{ $base }}/{{ $thread->id }}/unread" data-turbo-frame="_top" class="contents">@csrf<button class="chip text-ink-muted">Не прочитано</button></form>
                @unless ($park)<a href="{{ $base }}/{{ $thread->id }}" class="chip">Ветка</a>@endunless
            </div>
            @if ($errors->any())<p class="field-error mt-2">{{ $errors->first() }}</p>@endif
        </div>
        <x-mail.panel :messages="$thread->messages" :base="$base" reply/>
    </div>
</turbo-frame>
