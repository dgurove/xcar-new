{{-- Список дел: секция — машина, цепочка «Из писем» или предложение; письма дела стоят все, даже если под
     пилюлю подошло одно из них — действие требуется от дела. Заголовок говорит, что с делом: не заведено —
     лаймовая «Завести», заведено — состояние ТС и «Дело ›». У дела, которое требует нас, полоска слева.
     Этим же куском отвечает живой поиск (X-List) — он подменяет содержимое #threads целиком; data-search-row
     и data-search-group — то, что live_search прячет на первом же знаке, пока не пришёл ответ сервера. --}}
@php use App\Mail\CandidateState; @endphp
@if ($threads->isEmpty())
    <x-ui.empty class="py-6">{{ $q !== '' || $filter ? 'Ничего не нашлось' : match ($box) {
        'attention' => 'Дел нет', 'register' => 'Заводить нечего', 'other' => 'Прочего нет', 'sent' => 'Отправленных нет', 'archive' => 'Архив пуст', default => 'Писем нет' } }}</x-ui.empty>
@else
    <div class="flex flex-col gap-4">
        @foreach ($sections as $section)
            @php $plain = ! $section['vehicle'] && ! $section['offer'] && ! $section['candidate']; @endphp
            {{-- Смахивается дело целиком: архивировать одно письмо из цепочки смысла нет. --}}
            <x-ui.swipe id="case-{{ $section['kind'] }}-{{ $section['id'] }}" data-search-group>
            <section class="case {{ $section['attention'] ? 'case--'.$section['attention'] : '' }}">
                @if ($section['vehicle'])
                    @php $v = $section['vehicle']; @endphp
                    <div class="case-head">
                        <div class="case-name">
                            <a href="{{ \App\Support\Surface::Park->url('/cars/'.$v->id) }}" class="font-medium hover:text-accent-text" @if ($crm) data-turbo="false" @endif>{{ $v->titleWithYear() }}</a>
                            @if ($v->vendor)<span class="tag">{{ $v->vendor->name }}</span>@endif
                        </div>
                        <a href="{{ \App\Support\Surface::Park->url('/cars/'.$v->id) }}" class="case-go" @if ($crm) data-turbo="false" @endif><x-park.state :vehicle="$v" only/><span aria-hidden="true">›</span></a>
                    </div>
                @elseif ($section['offer'])
                    @php $o = $section['offer']; @endphp
                    <div class="case-head">
                        <div class="case-name">
                            <a href="/offers/{{ $o->number }}" class="font-medium hover:text-accent-text">{{ $o->title() }} <span class="nums font-normal text-ink-muted">№ {{ $o->number }}</span></a>
                        </div>
                        <a href="/offers/{{ $o->number }}" class="case-go"><span class="tag">{{ $o->state->label() }}</span><span aria-hidden="true">›</span></a>
                    </div>
                @elseif ($section['candidate'])
                    @php $c = $section['candidate']; @endphp
                    <div class="case-head">
                        <div class="case-name">
                            <span class="font-medium {{ $c->hasCar() ? '' : 'text-ink-muted' }}">{{ $c->title() }}</span>
                            <span class="tag {{ $c->stage === \App\Mail\CandidateStage::Sold ? 'tag-urgent' : '' }}">{{ $c->stage === \App\Mail\CandidateStage::Intake ? $c->requestTag() : $c->stageLabel() }}</span>
                            @if ($c->vendor?->name)<span class="tag">{{ $c->vendor->name }}</span>@endif
                        </div>
                        <span class="flex shrink-0 items-center gap-1.5">
                            @if ($c->state === CandidateState::New)
                                {{-- На телефоне цепочку отклоняет свайп, на компьютере свайпа нет — там кнопка. --}}
                                <form method="post" action="{{ $queue }}/{{ $c->id }}/decline" class="hidden md:contents" data-turbo-confirm="Не заявка? Цепочка уйдёт в архив">@csrf<button class="btn btn-s btn-quiet case-do">Не заявка</button></form>
                                @if ($park)
                                    <a href="/requests/new?candidate={{ $c->id }}" class="btn btn-s btn-accent case-do">Завести</a>
                                @else
                                    <form method="post" action="{{ $queue }}/{{ $c->id }}/create" class="contents">@csrf<button class="btn btn-s btn-accent case-do">Завести</button></form>
                                @endif
                            @elseif ($c->state === CandidateState::Promoted)
                                <a href="{{ $park ? '/cars/'.$c->vehicle_id : '/offers/'.$c->offer?->number }}" class="case-go"><span class="tag">{{ $c->state->label() }}</span><span aria-hidden="true">›</span></a>
                            @else
                                <span class="text-sm text-ink-dim">{{ $c->state->label() }}</span>
                            @endif
                        </span>
                    </div>
                @elseif ($section['attention'] === 'register')
                    {{-- Заявка, которой парсер не нашёл машину: завести цепочку руками. Вендор — в строке письма.
                         У прочих писем без машины заголовка нет: писать в него нечего. --}}
                    <div class="case-head">
                        <div class="case-name"><span class="font-medium text-ink-muted">Машину в письме не нашли</span></div>
                        <form method="post" action="{{ $base }}/{{ $section['threads']->first()->id }}/candidate" class="shrink-0">@csrf<button class="btn btn-s btn-accent case-do">Завести цепочку</button></form>
                    </div>
                @endif
                <div class="flex flex-col gap-1.5">
                    @foreach ($section['threads'] as $thread)
                        <x-mail.thread-row :thread="$thread" :base="$base" :park="$park" :linked="$plain"/>
                    @endforeach
                </div>
            </section>
            <x-slot:actions>
                <form method="post" action="{{ $base }}/case/{{ $section['kind'] }}/{{ $section['id'] }}/archive" data-queue>@csrf
                    @if ($box === 'archive')<input type="hidden" name="restore" value="1">@endif
                    <button type="submit" class="swipe-btn swipe-btn-accent" aria-label="{{ $box === 'archive' ? 'Вернуть' : 'В архив' }}"><x-ui.icon :name="$box === 'archive' ? 'undo' : 'archive'" class="size-5"/></button>
                </form>
            </x-slot:actions>
            </x-ui.swipe>
        @endforeach
    </div>
@endif
{{-- Страниц у почты нет: хвост с адресом следующей порции, его ловит endless_controller. --}}
@if ($threads->hasMorePages())
    <div class="py-6 text-center text-sm text-ink-dim" data-endless-next="{{ $threads->appends(request()->query())->nextPageUrl() }}">Ещё письма…</div>
@endif
