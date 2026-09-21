{{-- Окно ветки: тема, участники, ТС или предложение, лента писем (x-mail.chain) с одним «Ответить» внизу.
     Привязка, архив, «Не прочитано» и в CRM ссылка на страницу ветки — в меню «···». Страницы ветки на стоянке нет. --}}
@php $park = $base === '/mail'; $who = collect($thread->counterparts())->map(fn ($p) => $p['name'] ?: $p['email'])->take(3); $linked = $park ? $thread->vehicle : $thread->offer; $thread->loadMissing('candidate'); $menu = 'thread-menu-'.$thread->id; @endphp
<turbo-frame id="letters-frame" target="_top">
    <div class="flex flex-col gap-4" data-controller="unhide">
        <div>
            <div class="flex items-center gap-2">
                <h3 class="min-w-0 flex-1 text-base font-medium">{{ $thread->subject ?: '(без темы)' }}</h3>
                <div class="contents" data-controller="menu">
                    <button type="button" class="btn btn-s btn-quiet btn-round -my-1 -mr-2 shrink-0" data-action="menu#toggle" aria-label="Ещё" aria-haspopup="menu" aria-controls="{{ $menu }}"><x-ui.icon name="more" class="size-5"/></button>
                    <div id="{{ $menu }}" class="menu" popover data-menu-target="list" role="menu">
                        <button type="button" class="menu-item w-full" role="menuitem" data-action="menu#close unhide#show">{{ $linked ? 'Перепривязать' : ($park ? 'Привязать к ТС' : 'Привязать к предложению') }}</button>
                        <form method="post" action="{{ $base }}/{{ $thread->id }}/archive" data-turbo-frame="letters-frame" class="contents">@csrf<button class="menu-item w-full" role="menuitem">{{ $thread->archived_at ? 'Вернуть из архива' : 'В архив' }}</button></form>
                        <form method="post" action="{{ $base }}/{{ $thread->id }}/unread" data-turbo-frame="_top" class="contents">@csrf<button class="menu-item w-full" role="menuitem">Не прочитано</button></form>
                        @unless ($park)<a href="{{ $base }}/{{ $thread->id }}" class="menu-item" role="menuitem">Страница ветки</a>@endunless
                    </div>
                </div>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                @foreach ($who as $name)<span class="tag">{{ $name }}</span>@endforeach
                @if ($thread->vehicle)<a href="{{ \App\Support\Surface::Park->url('/cars/'.$thread->vehicle->id) }}" class="tag" @unless ($park) data-turbo="false" @endunless>{{ $thread->vehicle->titleWithYear() }}@if ($thread->vehicle->ref && $thread->vehicle->brand_id) <span class="nums text-ink-muted ml-1">{{ $thread->vehicle->ref }}</span>@endif</a>@endif
                @if ($thread->offer)<a href="{{ \App\Support\Surface::Crm->url('/offers/'.$thread->offer->number) }}" class="tag" @if ($park) data-turbo="false" @endif>{{ $thread->offer->title() }} <span class="nums text-ink-muted">№ {{ $thread->offer->number }}</span></a>@endif
                <span class="tag">{{ $thread->account->title }}</span>
                @if ($thread->candidate && ! $linked)
                    @if ($thread->candidate->state === \App\Mail\CandidateState::Rejected)<span class="chip text-ink-muted">Кандидат в архиве</span>
                    @elseif ($park)<a href="/requests/new?candidate={{ $thread->candidate->id }}" class="chip" data-turbo-frame="_top">Завести ›</a>
                    @else<form method="post" action="/offers/from-mail/{{ $thread->candidate->id }}/create" class="contents" data-turbo-frame="_top">@csrf<button class="chip">Завести ›</button></form>@endif
                @elseif (! $linked)
                    <form method="post" action="{{ $base }}/{{ $thread->id }}/candidate" class="contents" data-turbo-frame="_top">@csrf<button class="chip">{{ $park ? 'Заявка' : 'Предложение' }} ›</button></form>
                @endif
            </div>
            <form method="post" action="{{ $base }}/{{ $thread->id }}/link" class="mt-3 flex items-end gap-2" data-turbo-frame="letters-frame" data-unhide-target="block" @unless ($errors->any()) hidden @endunless>
                @csrf
                @if ($park)
                    <div class="min-w-0 flex-1"><x-ui.combobox name="vehicle_id" label="Транспортное средство" url="/reference/cars" :value="$thread->vehicle_id" :text="$thread->vehicle?->titleWithYear()"/></div>
                @else
                    <x-ui.field name="number" label="Номер предложения" inputmode="numeric" :value="$thread->offer?->number" span="flex-1"/>
                @endif
                <x-ui.button size="sm">Привязать</x-ui.button>
                @if ($linked)<x-ui.button variant="ghost" size="sm" name="{{ $park ? 'vehicle_id' : 'number' }}" value="">Отвязать</x-ui.button>@endif
            </form>
            @if ($errors->any())<p class="field-error mt-2">{{ $errors->first() }}</p>@endif
        </div>
        <x-mail.chain :messages="$thread->messages" :base="$base" :candidate="$thread->candidate" :vehicle="$thread->vehicle" :subjects="false" reply/>
    </div>
</turbo-frame>
