{{-- Активность человека — админу (30.10.2026, владелец: «когда заходил, что делал и с какого устройства — показать
     начальнику»; `Users\Activity`). Сверху — сводка: в сети или сколько не заходил, откуда был последний заход и последнее
     действие, три числа за период и столбики по дням (высота — действия, серый — заходил без действий). Ниже — лента по
     дням: заходы и действия по времени, пустые дни — промежутком «Не заходил N дней». --}}
@php
    use App\Support\Ago;
    use App\Support\Plural;
    $where = ['site' => 'Сайт', 'crm' => 'CRM', 'park' => 'Парковка', 'logistics' => 'Логистика'];
    $icons = ['site' => 'eye', 'crm' => 'cat-car', 'park' => 'park', 'logistics' => 'cat-truck'];
    $last = $a->lastSeen();
    $absent = $a->absentDays();
    $visit = $a->lastVisit();
    $action = $a->lastAction();
    $days = $a->perDay();
    $max = max(1, $days->max('actions'));
    $online = $user->seen_at?->gt(now()->subMinutes(2));
    $status = match (true) {
        $online => 'В сети',
        $absent === null => 'Ни разу не заходил',
        $absent === 0 => 'Был сегодня в '.$last->format('H:i'),
        $absent === 1 => 'Был вчера в '.$last->format('H:i'),
        default => 'Не заходил '.$absent.' '.Plural::of($absent, ['день', 'дня', 'дней']),
    };
    $away = $absent === null || $absent >= 7;
    $dayName = fn ($d) => $d->isToday() ? 'Сегодня' : ($d->isYesterday() ? 'Вчера' : mb_convert_case($d->locale('ru')->isoFormat('dd'), MB_CASE_TITLE).', '.$d->format('d.m'));
    $span = fn ($from, $to) => $from->isSameDay($to) ? $dayName($from) : $from->format('d.m').' – '.($to->isToday() ? 'сегодня' : $to->format('d.m'));
    $seconds = $a->seconds();
@endphp
<section class="activity">
    <div class="box activity-hero">
        <div @class(['activity-status', 'is-away' => $away, 'is-online' => $online])>{{ $status }}</div>
        <div class="detail-line mt-1 text-sm text-ink-muted">
            @if ($last && ! $online)<span>последний заход {!! Ago::html($last, time: true, words: false) !!}</span>@endif
            @if ($visit?->user_agent)<span>{{ $visit->device() }}</span>@endif
            @if ($visit?->surface)<span>{{ $where[$visit->surface->value] }}</span>@endif
        </div>
        @if ($action)
            <div class="mt-1 text-sm text-ink-muted">
                Последнее действие {!! Ago::html($action['at'], time: true) !!}:
                @if ($action['href'])<a href="{{ $action['href'] }}" class="text-ink">{{ $action['title'] }}</a>@else<span class="text-ink">{{ $action['title'] }}</span>@endif
                — {{ mb_lcfirst($action['text']) }}
            </div>
        @endif

        <div @class(['activity-stats', 'sm:grid-cols-3' => $seconds])>
            <div class="activity-stat">
                <b @class(['text-danger' => $a->activeDays() <= $a->days / 5])>{{ $a->activeDays() }}<small> из {{ $a->days }}</small></b>
                <span>{{ Plural::of($a->activeDays(), ['день', 'дня', 'дней']) }} с заходом</span>
            </div>
            <div class="activity-stat">
                <b>{{ $a->actions->count() }}</b>
                <span>{{ Plural::of($a->actions->count(), ['действие', 'действия', 'действий']) }}</span>
            </div>
            {{-- Время — только по журналу посещений (с 30.10.2026): пока его нет, плашки нет. --}}
            @if ($seconds)
                <div class="activity-stat activity-stat-wide">
                    <b>{{ Ago::duration($seconds) }}</b>
                    <span>в системе</span>
                </div>
            @endif
        </div>

        {{-- Столбики по дням: высота — число действий, серый — заходил без действий, черта — пустой день. --}}
        <div class="activity-chart" role="img" aria-label="Действия по дням за {{ $a->days }} дней">
            @foreach ($days as $d)
                @php $label = $dayName($d['day']).': '.($d['actions'] ? $d['actions'].' '.Plural::of($d['actions'], ['действие', 'действия', 'действий']) : ($d['visited'] ? 'заходил' : 'не было')).($d['seconds'] ? ', '.Ago::duration($d['seconds']) : ''); @endphp
                <span @class(['activity-day', 'is-act' => $d['actions'], 'is-visit' => ! $d['actions'] && $d['visited']])
                      style="--h: {{ $d['actions'] ? max(10, round($d['actions'] / $max * 100)) : ($d['visited'] ? 6 : 0) }}%" title="{{ $label }}"><i></i></span>
            @endforeach
        </div>
        <div class="activity-axis">
            <span>{{ $a->from->format('d.m') }}</span>
            <span class="activity-legend"><i class="is-act"></i>действия <i class="is-visit"></i>только заходил</span>
            <span>сегодня</span>
        </div>
    </div>

    <div class="mt-5 mb-1 flex flex-wrap gap-1.5">
        @foreach (\App\Users\Activity::PERIODS as $p)
            <x-ui.pill :href="'?pill=activity&days='.$p" :current="$a->days === $p" data-turbo-action="replace">{{ $p }} {{ Plural::of($p, ['день', 'дня', 'дней']) }}</x-ui.pill>
        @endforeach
    </div>

    @foreach ($a->timeline() as $t)
        @if ($t['kind'] === 'gap')
            <div @class(['activity-gap', 'is-long' => $t['absent'] && $t['days'] >= 3])>
                <span>{{ $t['absent'] ? ($t['days'] === 1 && $t['to']->isToday() ? 'Сегодня не заходил' : 'Не заходил '.$t['days'].' '.Plural::of($t['days'], ['день', 'дня', 'дней'])) : 'Без действий '.$t['days'].' '.Plural::of($t['days'], ['день', 'дня', 'дней']) }}</span>
                @unless ($t['days'] === 1 && $t['to']->isToday())<span class="text-ink-muted">{{ $span($t['from'], $t['to']) }}</span>@endunless
            </div>
        @else
            @php
                $dv = $t['items']->where('kind', 'visit');
                $da = $t['items']->where('kind', 'action')->sum(fn ($i) => count($i['texts']));
                $ds = $dv->sum(fn ($i) => $i['visit']->seconds());
            @endphp
            <h2 class="list-head mt-5">
                <span>{{ $dayName($t['day']) }}</span>
                <span class="ml-auto text-sm font-normal text-ink-muted">{{ collect([$dv->count() ? $dv->count().' '.Plural::of($dv->count(), ['заход', 'захода', 'заходов']) : null, $ds ? Ago::duration($ds) : null, $da ? $da.' '.Plural::of($da, ['действие', 'действия', 'действий']) : null])->filter()->implode(' · ') }}</span>
            </h2>
            <div class="list">
                @foreach ($t['items'] as $i)
                    @if ($i['kind'] === 'visit')
                        @php $v = $i['visit']; @endphp
                        <div class="row items-center gap-3">
                            <x-ui.row-icon :name="$v->login ? 'login' : (str_contains($v->device(), 'iPhone') || str_contains($v->device(), 'Android') ? 'smartphone' : 'eye')" size="s" :tone="$v->login ? 'soft' : null"/>
                            <div class="min-w-0 flex-1">
                                <div class="truncate">{{ $v->login ? 'Вход' : 'Заходил' }}@if ($v->surface) · {{ $where[$v->surface->value] }}@endif</div>
                                <div class="row-sub"><span>{{ $v->device() }}</span>@if ($v->ip)<span class="nums">{{ $v->ip }}</span>@endif</div>
                            </div>
                            <span class="nums shrink-0 text-sm text-ink-muted">{{ $v->started_at->format('H:i') }}@if ($v->seconds() > 90)–{{ $v->last_at->format('H:i') }}@endif</span>
                        </div>
                    @else
                        <a @if ($i['href']) href="{{ $i['href'] }}" @endif class="row items-center gap-3">
                            <x-ui.row-icon :name="str_starts_with($i['key'], 'chat:') ? 'chat' : $icons[$i['surface']->value]" size="s"/>
                            <div class="min-w-0 flex-1">
                                <div class="truncate">{{ $i['title'] }}</div>
                                <div class="activity-what">{{ implode(', ', array_map(fn ($x) => mb_lcfirst($x), array_slice($i['texts'], 0, 4))) }}@if (count($i['texts']) > 4) и ещё {{ count($i['texts']) - 4 }}@endif</div>
                            </div>
                            <span class="nums shrink-0 text-sm text-ink-muted">{{ $i['at']->format('H:i') }}</span>
                        </a>
                    @endif
                @endforeach
            </div>
        @endif
    @endforeach
</section>
