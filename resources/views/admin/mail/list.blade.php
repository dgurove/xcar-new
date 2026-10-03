{{-- Список дел: секция — машина, цепочка «Из писем», предложение или письмо без них; каждая — карточка беседы
     (.chain-card): шапка, под ней письма дела лентой на линии (x-mail.letter-row), даже если под пилюлю подошло одно
     из них — действие требуется от дела. Шапка: незаведённая цепочка — x-mail.chain-head с «Завести»; заведённое —
     имя дела ссылкой, под ним логотип вендора с номером и состояние словом, справа шеврон в дело. У дела, которое
     требует нас, полоска слева. Писем — все, и веток в архиве тоже (`letters` секции).
     Этим же куском отвечает живой поиск (X-List) — он подменяет содержимое #threads целиком; data-search-row
     и data-search-group — то, что live_search прячет на первом же знаке, пока не пришёл ответ сервера. --}}
@php use App\Mail\CandidateState; @endphp
@if ($threads->isEmpty())
    <x-ui.empty class="py-6">{{ $q !== '' || $filtered ? 'Ничего не нашлось' : match ($box) {
        'attention' => 'Дел нет', 'register' => 'Заводить нечего', 'other' => 'Прочего нет', 'sent' => 'Отправленных нет', 'archive' => 'Архив пуст', default => 'Писем нет' } }}</x-ui.empty>
@else
    <div class="flex flex-col gap-2">
        @foreach ($sections as $section)
            @php
                $mark = $forced ? null : $section['attention'];
                $c = $section['candidate'];
                $v = $section['vehicle'];
                $o = $section['offer'];
                $t = $section['threads']->first();
                // Незаведённая цепочка — шапка с «Завести»; письмо-заявка без машины — с «Завести» руками.
                $fresh = $c?->state === CandidateState::New;
                $orphan = $section['kind'] === 't' && $section['register'];
                // Шапка заведённого дела: имя, вендор с номером, состояние словом, переход.
                $head = match (true) {
                    (bool) $v => ['title' => $v->titleWithYear(), 'href' => \App\Support\Surface::Park->url('/cars/'.$v->id), 'vendor' => $v->vendor, 'ref' => $v->ref,
                        'state' => $v->state->label(), 'urgent' => $v->state->tone() === 'urgent'],
                    (bool) $o => ['title' => $o->title().($o->published_at ? ' № '.$o->number : ''), 'href' => '/offers/'.$o->number, 'vendor' => $o->vendor, 'ref' => $o->claim_ref,
                        'state' => $o->state->label(), 'urgent' => false],
                    $c && ! $fresh => ['title' => $c->title(), 'href' => $c->state === CandidateState::Promoted ? ($park ? '/cars/'.$c->vehicle_id : '/offers/'.$c->offer?->number) : null,
                        'vendor' => $c->vendor, 'ref' => $c->code, 'state' => $c->state->label(), 'urgent' => false],
                    ! $orphan && ! $c => ['title' => \App\Mail\Extraction\Patterns::cleanSubject($t->subject) ?: '(без темы)', 'href' => null, 'vendor' => $t->vendor, 'ref' => null, 'state' => null, 'urgent' => false],
                    default => null,
                };
                // Окно писем — всё дело на этом письме.
                $url = fn ($m) => match (true) {
                    (bool) $v => '/cars/'.$v->id.'/letters?at='.$m->id,
                    (bool) $o => '/offers/'.$o->number.'/letters?at='.$m->id,
                    (bool) $c => $queue.'/'.$c->id.'/letters?at='.$m->id,
                    default => $base.'/'.$m->thread_id.'/window?at='.$m->id,
                };
                $letters = $section['letters']->sortBy(fn ($m) => $m->date_at?->getTimestamp() ?? 0)->values();
                $continued = \App\Mail\Chains\NodeTitle::continued($letters);
                $files = $letters->groupBy(fn ($m) => $continued[$m->id] ?? $m->id)->map(fn ($g) => $g->sum(fn ($m) => $m->files()->count()));
                // Ждёт ответа — последнее входящее ветки с `needs_reply_at`, как в ленте.
                $waiting = $section['threads']->whereNotNull('needs_reply_at')->pluck('id')->all();
                $asks = $letters->filter(fn ($m) => in_array($m->thread_id, $waiting, true) && ! $m->isOurs())->groupBy('thread_id')->map->last()->pluck('id')->all();
                $staged = collect($c?->stages ?? [])->pluck('message_id')->all();
                $prev = null;
            @endphp
            {{-- Смахивается дело целиком: архивировать одно письмо из цепочки смысла нет. --}}
            <x-ui.swipe id="case-{{ $section['kind'] }}-{{ $section['id'] }}" data-search-group>
            <section class="chain-card {{ $mark ? 'case--'.$mark : '' }}">
                @if ($fresh)
                    <x-mail.chain-head :candidate="$c" :queue="$queue" :park="$park"/>
                @elseif ($orphan)
                    {{-- Заявка, которой парсер не нашёл машину: заводится руками. --}}
                    <div class="chain-head">
                        <div class="min-w-0 flex-1">
                            <div class="chain-head-title text-ink-muted">Машину в письме не нашли</div>
                            {{-- Имя вендора — подписью логотипа (ref): у одного логотипа нет базовой линии, и строка садилась ниже имени дела. --}}
                            @if ($t->vendor)<div class="chain-head-sub"><x-vendor.ref :vendor="$t->vendor" :ref="$t->vendor->name"/></div>@endif
                        </div>
                        {{-- «Не заявка» здесь — просто архив письма: цепочки у него ещё нет, отклонять нечего. --}}
                        <form method="post" action="{{ $base }}/case/t/{{ $section['id'] }}/archive" class="hidden md:contents" data-turbo-confirm="Не заявка? Письмо уйдёт в архив">@csrf<button class="btn btn-s btn-quiet case-do"><span class="opacity-70">Не заявка</span></button></form>
                        <form method="post" action="{{ $base }}/{{ $t->id }}/candidate" class="contents">@csrf<button class="btn btn-s btn-accent case-do">Завести</button></form>
                    </div>
                @elseif ($head)
                    <div class="chain-head">
                        <div class="min-w-0 flex-1">
                            @if ($head['href'])
                                <a href="{{ $head['href'] }}" class="chain-head-title block hover:text-accent-text" @if ($v && $crm) data-turbo="false" @endif>{{ $head['title'] }}</a>
                            @else
                                <div class="chain-head-title">{{ $head['title'] }}</div>
                            @endif
                            @if ($head['vendor'] || $head['ref'] || $head['state'])
                                <div class="chain-head-sub">
                                    <x-vendor.ref :vendor="$head['vendor']" :ref="$head['ref']"/>
                                    @if ($head['state'])<span class="{{ $head['urgent'] ? 'text-urgent' : '' }}">{{ $head['state'] }}</span>@endif
                                </div>
                            @endif
                        </div>
                        @if ($head['href'])<a href="{{ $head['href'] }}" class="-mr-1.5 shrink-0 p-1.5" aria-label="Открыть" @if ($v && $crm) data-turbo="false" @endif><x-ui.chevron/></a>@endif
                    </div>
                @endif
                <div class="chain rail">
                    @foreach ($letters as $m)
                        @continue(isset($continued[$m->id]))
                        <x-mail.letter-row :message="$m" :url="$url($m)" :waits="in_array($m->id, $asks, true)"
                            :stage="in_array($m->id, $staged, true)" :repeat="$prev?->from_email === $m->from_email" :files="$files[$m->id] ?? 0"/>
                        @php $prev = $m; @endphp
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
