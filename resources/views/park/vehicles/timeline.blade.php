{{-- Таймлайн дела: точки на линии слева, как трекинг посылки. Сделанное — строкой с раскрытием, текущее — подсказка и
     форма прямо тут, будущее — серым. Форма act-form оборачивает весь список (внутри сделанных шагов форм нет). --}}
@php use App\Park\Step; @endphp
<div class="steps">
    @foreach ($steps as $step)
        @php $partial = match (true) { $step->key === 'report-release' => 'report', $step->key === 'intake' && ($step->plate['kind'] ?? null) === 'spawn' => 'intake-spawn', str_starts_with($step->key, 'reply') => 'reply', default => $step->key }; $r = $step->request; @endphp
        <div class="step step--{{ $step->state }}{{ $step->danger ? ' step--danger' : '' }}{{ $step->key === 'reply' ? ' step--ask' : '' }}">
            <span class="step-dot">@if ($step->isDone() && ! $step->danger)<x-ui.icon name="check" class="size-3"/>@endif</span>
            <div class="step-body">
                @if ($step->key === 'refused')
                    {{-- Отказ покупателя: раскрывать нечего, причина и кто записал стоят чипами прямо в строке. --}}
                    <div class="step-head">
                        <span class="step-title">{{ $step->title }}</span>
                        @if ($step->at)<span class="tag nums">{{ $step->at->translatedFormat('j M, H:i') }}</span>@endif
                        @foreach ($step->chips as $chip)<span class="tag max-w-[14rem] truncate">{{ $chip }}</span>@endforeach
                    </div>
                @elseif ($step->isDone())
                    <details class="step-details">
                        <summary class="step-head">
                            <span class="step-title">{{ $step->title }}</span>
                            @if ($step->at)<span class="tag nums">{{ $step->at->translatedFormat('j M'.($step->key === 'letter' ? '' : ', H:i')) }}</span>@endif
                            @foreach ($step->chips as $chip)<span class="tag max-w-[14rem] truncate">{{ $chip }}</span>@endforeach
                            @if ($step->hint)<span class="min-w-0 truncate text-sm text-ink-muted">{{ $step->hint }}</span>@endif
                            <x-ui.icon name="chevron-down" class="step-chevron ml-auto size-4 shrink-0 self-center text-ink-dim"/>
                        </summary>
                        @include('park.vehicles.steps.done')
                    </details>
                @elseif ($step->isCurrent())
                    <div class="step-head">
                        <span class="step-title">{{ $step->title }}</span>
                        @if ($r?->planned_at && $step->key !== 'tow')<span class="tag nums {{ $r->isOverdue() ? 'text-danger' : '' }}">{{ $r->planned_at->translatedFormat('j M, H:i') }}</span>@endif
                        @if ($step->key === 'intake' && $r?->delivery && ! $r->isTow())<span class="tag">{{ $r->delivery->label() }}</span>@endif
                    </div>
                    @if ($step->hint)<p class="step-hint">{{ $step->hint }}</p>@endif
                    @include('park.vehicles.steps.'.$partial, ['req' => $r, 'step' => $step])
                @elseif ($step->key === 'reply')
                    {{-- Письмо, которое ждёт ответа: текст и два действия, форма текущего шага при этом на месте. --}}
                    <div class="step-head"><span class="step-title">{{ $step->title }}</span>@if ($step->at)<span class="tag nums">{{ $step->at->translatedFormat('j M, H:i') }}</span>@endif</div>
                    @include('park.vehicles.steps.reply')
                @else
                    <div class="step-head">
                        <span class="step-title">{{ $step->title }}</span>
                        @if ($step->state === Step::TODO && $step->plate && $step->plate['kind'] === 'window')<x-mail.window-button :url="$step->plate['url']" :label="$step->plate['label']" chip/>@endif
                    </div>
                    @if ($step->state === Step::TODO && $step->hint)<p class="step-hint">{{ $step->hint }}</p>@endif
                @endif
            </div>
        </div>
    @endforeach
</div>
