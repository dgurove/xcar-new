{{-- Сам список писем: секции по ТС и кандидатам во «Входящих», плоские строки в остальных пилюлях и в поиске.
     Этим же куском отвечает живой поиск (заголовок X-List) — он подменяет содержимое #threads целиком,
     поэтому здесь нет ничего, кроме списка и страниц. data-search-row и data-search-group — то, что
     live_search_controller прячет на первом же знаке, пока не пришёл ответ. --}}
@if ($threads->isEmpty())
    <x-ui.empty class="py-6">{{ $q !== '' || $filter ? 'Ничего не нашлось' : match ($box) { 'waiting' => 'Все письма отвечены', 'other' => 'Прочего нет', default => 'Писем нет' } }}</x-ui.empty>
@elseif ($sections !== null)
    <div class="flex flex-col gap-4">
        @foreach ($sections as $section)
            <section data-search-group>
                @if ($section['vehicle'])
                    @php $v = $section['vehicle']; @endphp
                    <div class="mb-1.5 flex flex-wrap items-center gap-1.5">
                        <a href="{{ \App\Support\Surface::Park->url('/cars/'.$v->id) }}" class="font-medium hover:text-accent-text" @if ($crm) data-turbo="false" @endif>{{ $v->titleWithYear() }}@if ($v->plate) <span class="nums font-normal text-ink-muted">{{ $v->plate }}</span>@endif</a>
                        <x-park.state :vehicle="$v"/>
                    </div>
                @elseif ($section['offer'])
                    @php $o = $section['offer']; @endphp
                    <div class="mb-1.5 flex flex-wrap items-center gap-1.5">
                        <a href="/offers/{{ $o->number }}" class="font-medium hover:text-accent-text">{{ $o->title() }} <span class="nums font-normal text-ink-muted">№ {{ $o->number }}</span></a>
                        <span class="chip">{{ $o->state->label() }}</span>
                    </div>
                @elseif ($section['candidate'])
                    @php $c = $section['candidate']; $cv = fn ($f) => $c->extracted[$f]['value'] ?? null; @endphp
                    <div class="mb-1.5 flex items-start gap-2">
                        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                            <span class="font-medium">{{ $c->title() }}</span>
                            @if ($c->code)<x-ui.copy-code class="tag" :value="$c->code"/>@endif
                            @if ($cv('plate'))<span class="tag nums">{{ $cv('plate') }}</span>@endif
                            @if ($c->vendor?->name ?? $cv('vendor'))<span class="tag">{{ $c->vendor?->name ?? $cv('vendor') }}</span>@endif
                        </div>
                        @if ($c->state === \App\Mail\CandidateState::Rejected || $c->state === \App\Mail\CandidateState::Closed)
                            <span class="shrink-0 text-sm text-ink-dim">{{ $c->state->label() }}</span>
                        @elseif ($c->state === \App\Mail\CandidateState::Promoted)
                            <a href="{{ $park ? '/cars/'.$c->vehicle_id : '/offers/'.$c->offer?->number }}" class="shrink-0 text-sm text-ink-muted hover:text-accent-text">{{ $park ? 'ТС' : 'Предложение' }} ›</a>
                        @elseif ($park)
                            <a href="/requests/new?candidate={{ $c->id }}" class="shrink-0 text-sm text-accent-text">Завести ›</a>
                        @else
                            <form method="post" action="/offers/from-mail/{{ $c->id }}/create" class="shrink-0">@csrf<button class="text-sm text-accent-text">Завести ›</button></form>
                        @endif
                    </div>
                @else
                    <div class="mb-1.5 text-sm text-ink-muted">Без тождества</div>
                @endif
                <div class="flex flex-col gap-1.5">
                    @foreach ($section['threads'] as $thread)
                        <x-mail.thread-row :thread="$thread" :base="$base" :park="$park"/>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@else
    <div class="flex flex-col gap-1.5">
        @foreach ($threads as $thread)
            <x-mail.thread-row :thread="$thread" :base="$base" :park="$park" linked/>
        @endforeach
    </div>
@endif
@if ($threads->hasPages())<div class="mt-6"><x-ui.pager :of="$threads"/></div>@endif
