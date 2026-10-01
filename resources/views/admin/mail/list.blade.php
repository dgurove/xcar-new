{{-- Список дел: секция — машина, цепочка «Из писем» или предложение; письма дела стоят все, даже если под
     пилюлю подошло одно из них — действие требуется от дела. Заголовок говорит, что с делом: не заведено —
     лаймовая «Завести», заведено — состояние ТС и «Дело ›». У дела, которое требует нас, полоска слева;
     в «Из писем» её нет — там всё до последней строки надо завести, и красить нечего.
     Этим же куском отвечает живой поиск (X-List) — он подменяет содержимое #threads целиком; data-search-row
     и data-search-group — то, что live_search прячет на первом же знаке, пока не пришёл ответ сервера. --}}
@php use App\Mail\CandidateState; @endphp
@if ($threads->isEmpty())
    <x-ui.empty class="py-6">{{ $q !== '' || $filter ? 'Ничего не нашлось' : match ($box) {
        'attention' => 'Дел нет', 'register' => 'Заводить нечего', 'other' => 'Прочего нет', 'sent' => 'Отправленных нет', 'archive' => 'Архив пуст', default => 'Писем нет' } }}</x-ui.empty>
@else
    <div class="flex flex-col gap-4">
        @foreach ($sections as $section)
            @php
                $plain = ! $section['vehicle'] && ! $section['offer'] && ! $section['candidate'];
                // В «Из писем» полоски нет: там каждая строка — то, что надо завести, и выделять нечего.
                $mark = $forced ? null : $section['attention'];
                // Незаведённая цепочка — карточка: шапка и письма на линии внутри одной плашки, как беседа в почте.
                $card = $section['candidate']?->state === CandidateState::New;
                // Письмо-заявка, которой парсер не нашёл машину, — та же карточка: шапка и письма ветки на линии.
                $orphan = $section['kind'] === 't' && $section['register'];
            @endphp
            {{-- Смахивается дело целиком: архивировать одно письмо из цепочки смысла нет. --}}
            <x-ui.swipe id="case-{{ $section['kind'] }}-{{ $section['id'] }}" data-search-group>
            <section class="case {{ $card || $orphan ? 'chain-card' : '' }} {{ $mark ? 'case--'.$mark : '' }}">
                @if ($section['vehicle'])
                    @php $v = $section['vehicle']; @endphp
                    <div class="case-head">
                        <div class="case-name">
                            <a href="{{ \App\Support\Surface::Park->url('/cars/'.$v->id) }}" class="font-medium hover:text-accent-text" @if ($crm) data-turbo="false" @endif>{{ $v->titleWithYear() }}</a>
                            @if ($v->vendor)<x-vendor.name :vendor="$v->vendor" class="tag"/>@endif
                        </div>
                        <a href="{{ \App\Support\Surface::Park->url('/cars/'.$v->id) }}" class="case-go" @if ($crm) data-turbo="false" @endif><x-park.state :vehicle="$v" only/><span aria-hidden="true">›</span></a>
                    </div>
                @elseif ($section['offer'])
                    @php $o = $section['offer']; @endphp
                    <div class="case-head">
                        <div class="case-name">
                            <a href="/offers/{{ $o->number }}" class="font-medium hover:text-accent-text">{{ $o->title() }}@if ($o->published_at) <span class="nums font-normal text-ink-muted">№ {{ $o->number }}</span>@endif</a>
                            @if ($o->vendor)<x-vendor.name :vendor="$o->vendor" class="tag"/>@endif
                        </div>
                        <a href="/offers/{{ $o->number }}" class="case-go"><span class="tag">{{ $o->state->label() }}</span><span aria-hidden="true">›</span></a>
                    </div>
                @elseif ($card)
                    <x-mail.chain-head :candidate="$section['candidate']" :queue="$queue" :park="$park"/>
                @elseif ($section['candidate'])
                    @php $c = $section['candidate']; @endphp
                    <div class="case-head">
                        <div class="case-name">
                            <span class="font-medium {{ $c->hasCar() ? '' : 'text-ink-muted' }}">{{ $c->title() }}</span>
                            <span class="tag {{ $c->stage === \App\Mail\CandidateStage::Sold ? 'tag-urgent' : '' }}">{{ $c->stage === \App\Mail\CandidateStage::Intake ? $c->requestTag() : $c->stageLabel() }}</span>
                            @if ($c->vendor)<x-vendor.name :vendor="$c->vendor" class="tag"/>@endif
                        </div>
                        <span class="flex shrink-0 items-center gap-1.5">
                            @if ($c->state === CandidateState::New)
                                {{-- На телефоне цепочку отклоняет свайп, на компьютере свайпа нет — там кнопка. --}}
                                <form method="post" action="{{ $queue }}/{{ $c->id }}/decline" class="hidden md:contents" data-turbo-confirm="Не заявка? Цепочка уйдёт в архив">@csrf<button class="btn btn-s btn-quiet case-do"><span class="opacity-70">Не заявка</span></button></form>
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
                @elseif ($orphan)
                    {{-- Заявка, которой парсер не нашёл машину: заводится руками. У прочих писем без машины заголовка нет. --}}
                    @php $t = $section['threads']->first(); @endphp
                    <div class="chain-head">
                        <div class="min-w-0 flex-1">
                            <div class="chain-head-title text-ink-muted">Машину в письме не нашли</div>
                            @if ($t->vendor)<div class="chain-head-sub"><x-vendor.ref :vendor="$t->vendor"/><span>{{ $t->vendor->name }}</span></div>@endif
                        </div>
                        {{-- «Не заявка» здесь — просто архив письма: цепочки у него ещё нет, отклонять нечего. --}}
                        <form method="post" action="{{ $base }}/case/t/{{ $section['id'] }}/archive" class="hidden md:contents" data-turbo-confirm="Не заявка? Письмо уйдёт в архив">@csrf<button class="btn btn-s btn-quiet case-do"><span class="opacity-70">Не заявка</span></button></form>
                        <form method="post" action="{{ $base }}/{{ $t->id }}/candidate" class="contents">@csrf<button class="btn btn-s btn-accent case-do">Завести</button></form>
                    </div>
                @endif
                {{-- Незаведённая цепочка — все её письма лентой на линии: заявка, с которой началось, видна всегда. --}}
                <div class="{{ $card || $orphan ? 'chain rail' : 'flex flex-col gap-1.5' }}">
                    @if ($card || $orphan)
                        @php
                            $letters = ($card ? $section['candidate']->messages : $section['threads']->flatMap->messages)->sortBy(fn ($m) => $m->date_at?->getTimestamp() ?? 0)->values();
                            // Окно — цепочки на этом письме; у письма без машины цепочки нет, окно — его ветки.
                            $url = fn ($m) => $card ? $queue.'/'.$section['candidate']->id.'/letters?at='.$m->id : $base.'/'.$m->thread_id.'/window';
                            $continued = \App\Mail\Chains\NodeTitle::continued($letters);
                            $files = $letters->groupBy(fn ($m) => $continued[$m->id] ?? $m->id)->map(fn ($g) => $g->sum(fn ($m) => $m->files()->count()));
                            // Ждёт ответа — последнее входящее ветки с `needs_reply_at`, как в ленте.
                            $waiting = $section['threads']->whereNotNull('needs_reply_at')->pluck('id')->all();
                            $asks = $letters->filter(fn ($m) => in_array($m->thread_id, $waiting, true) && ! $m->isOurs())->groupBy('thread_id')->map->last()->pluck('id')->all();
                            $staged = collect($section['candidate']?->stages ?? [])->pluck('message_id')->all();
                            $prev = null;
                        @endphp
                        @foreach ($letters as $m)
                            @continue(isset($continued[$m->id]))
                            <x-mail.letter-row :message="$m" :url="$url($m)" :waits="in_array($m->id, $asks, true)"
                                :stage="in_array($m->id, $staged, true)" :repeat="$prev?->from_email === $m->from_email" :files="$files[$m->id] ?? 0"/>
                            @php $prev = $m; @endphp
                        @endforeach
                    @else
                        @foreach ($section['threads'] as $thread)
                            <x-mail.thread-row :thread="$thread" :base="$base" :park="$park" :linked="$plain"/>
                        @endforeach
                    @endif
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
