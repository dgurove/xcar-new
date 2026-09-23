{{-- Список дел: секция — машина, цепочка «Из писем» или предложение; письма дела стоят все, даже если под
     пилюлю подошло одно из них — действие требуется от дела. Заголовок говорит, что с делом: не заведено —
     лаймовая «Завести», заведено — состояние ТС и «Дело ›». У дела, которое требует нас, полоска слева.
     Этим же куском отвечает живой поиск (X-List) — он подменяет содержимое #threads целиком; data-search-row
     и data-search-group — то, что live_search прячет на первом же знаке, пока не пришёл ответ сервера. --}}
@php use App\Mail\CandidateState; @endphp
@if ($threads->isEmpty())
    <x-ui.empty class="py-6">{{ $q !== '' || $filter ? 'Ничего не нашлось' : match ($box) {
        'attention' => 'Дел нет', 'other' => 'Прочего нет', 'sent' => 'Отправленных нет', 'archive' => 'Архив пуст', default => 'Писем нет' } }}</x-ui.empty>
@else
    <div class="flex flex-col gap-4">
        @foreach ($sections as $section)
            @php $plain = ! $section['vehicle'] && ! $section['offer'] && ! $section['candidate']; @endphp
            <section class="case {{ $section['attention'] ? 'case--'.$section['attention'] : '' }}" data-search-group>
                @if ($section['vehicle'])
                    @php $v = $section['vehicle']; @endphp
                    <div class="mb-1.5 flex flex-wrap items-center gap-1.5">
                        <a href="{{ \App\Support\Surface::Park->url('/cars/'.$v->id) }}" class="font-medium hover:text-accent-text" @if ($crm) data-turbo="false" @endif>{{ $v->titleWithYear() }}</a>
                        <x-park.state :vehicle="$v" only/>
                        @if ($v->vendor)<span class="tag">{{ $v->vendor->name }}</span>@endif
                        <a href="{{ \App\Support\Surface::Park->url('/cars/'.$v->id) }}" class="ml-auto shrink-0 text-sm text-ink-muted hover:text-accent-text" @if ($crm) data-turbo="false" @endif>Дело ›</a>
                    </div>
                @elseif ($section['offer'])
                    @php $o = $section['offer']; @endphp
                    <div class="mb-1.5 flex flex-wrap items-center gap-1.5">
                        <a href="/offers/{{ $o->number }}" class="font-medium hover:text-accent-text">{{ $o->title() }} <span class="nums font-normal text-ink-muted">№ {{ $o->number }}</span></a>
                        <span class="tag">{{ $o->state->label() }}</span>
                        <a href="/offers/{{ $o->number }}" class="ml-auto shrink-0 text-sm text-ink-muted hover:text-accent-text">Предложение ›</a>
                    </div>
                @elseif ($section['candidate'])
                    @php $c = $section['candidate']; @endphp
                    <div class="mb-1.5 flex flex-wrap items-center gap-1.5">
                        <span class="font-medium {{ $c->hasCar() ? '' : 'text-ink-muted' }}">{{ $c->title() }}</span>
                        <span class="tag {{ $c->stage === \App\Mail\CandidateStage::Sold ? 'tag-urgent' : '' }}">{{ $c->stage === \App\Mail\CandidateStage::Intake ? $c->requestTag() : $c->stageLabel() }}</span>
                        @if ($c->vendor?->name)<span class="tag">{{ $c->vendor->name }}</span>@endif
                        <span class="ml-auto shrink-0">
                            @if ($c->state === CandidateState::New)
                                @if ($park)
                                    <a href="/requests/new?candidate={{ $c->id }}" class="btn btn-s btn-accent rounded-full">Завести</a>
                                @else
                                    <form method="post" action="/offers/from-mail/{{ $c->id }}/create">@csrf<button class="btn btn-s btn-accent rounded-full">Завести</button></form>
                                @endif
                            @elseif ($c->state === CandidateState::Promoted)
                                <a href="{{ $park ? '/cars/'.$c->vehicle_id : '/offers/'.$c->offer?->number }}" class="text-sm text-ink-muted hover:text-accent-text">{{ $park ? 'Дело' : 'Предложение' }} ›</a>
                            @else
                                <span class="text-sm text-ink-dim">{{ $c->state->label() }}</span>
                            @endif
                        </span>
                    </div>
                @elseif ($section['attention'] === 'register')
                    {{-- Заявка, которой парсер не нашёл машину: завести цепочку руками. Вендор — в строке письма.
                         У прочих писем без машины заголовка нет: писать в него нечего. --}}
                    <div class="mb-1.5 flex flex-wrap items-center gap-1.5">
                        <span class="font-medium text-ink-muted">Машину в письме не нашли</span>
                        <form method="post" action="{{ $base }}/{{ $section['threads']->first()->id }}/candidate" class="ml-auto shrink-0">@csrf<button class="btn btn-s btn-accent rounded-full">Завести цепочку</button></form>
                    </div>
                @endif
                <div class="flex flex-col gap-1.5">
                    @foreach ($section['threads'] as $thread)
                        <x-mail.thread-row :thread="$thread" :base="$base" :park="$park" :linked="$plain"/>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endif
{{-- Страниц у почты нет: хвост с адресом следующей порции, его ловит endless_controller. --}}
@if ($threads->hasMorePages())
    <div class="py-6 text-center text-sm text-ink-dim" data-endless-next="{{ $threads->appends(request()->query())->nextPageUrl() }}">Ещё письма…</div>
@endif
