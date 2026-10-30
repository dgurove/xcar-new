{{-- Таймлайн дела: точки на линии слева, как трекинг посылки. Сделанное — строкой с раскрытием, текущее — строкой (его
     действие — плашкой сверху дела, `now-step`), будущее — серым. --}}
@php use App\Park\Step; @endphp
<div class="steps">
    @foreach ($steps as $step)
        @php $partial = match (true) { $step->key === 'report-release' => 'report', in_array($step->key, ['intake', 'report', 'release'], true) => 'act', str_starts_with($step->key, 'reply') => 'reply', default => $step->key }; $r = $step->request; @endphp
        <div class="step step--{{ $step->state }}{{ $step->danger ? ' step--danger' : '' }}{{ $step->key === 'reply' || ($step->key === 'buyer' && $step->state === Step::TODO && $step->at) ? ' step--ask' : '' }}">
            <span class="step-dot">@if ($step->isDone() && ! $step->danger)<x-ui.icon name="check" class="size-3"/>@endif</span>
            <div class="step-body">
                @if ($step->key === 'refused')
                    {{-- Отказ покупателя: раскрывать нечего, причина и кто записал стоят серым текстом прямо в строке. --}}
                    <div class="step-head">
                        <span class="step-title">{{ $step->title }}</span>
                        @if ($step->at)<span class="fact nums">{{ \App\Support\Ago::html($step->at, true, words: false) }}</span>@endif
                        @foreach ($step->chips as $chip)<span class="fact max-w-[14rem]"><span class="truncate">{{ $chip }}</span></span>@endforeach
                    </div>
                @elseif ($step->isDone())
                    <details class="step-details">
                        <summary class="step-head">
                            <span class="step-title">{{ $step->title }}</span>
                            {{-- Дата приёма и выдачи — сама выбор даты и времени (`x-park.date-edit`), только администратору парковки. --}}
                            @if ($step->at && in_array($step->key, ['intake', 'release'], true) && auth()->user()->isParkAdmin())<x-park.date-edit :vehicle="$vehicle" :field="$step->key === 'intake' ? 'accepted_at' : 'released_at'" class="fact nums underline decoration-dotted underline-offset-2"/>
                            @elseif ($step->at)<span class="fact nums">{{ \App\Support\Ago::html($step->at, $step->key !== 'letter', words: false) }}</span>@endif
                            @foreach ($step->chips as $chip)<span class="fact max-w-[14rem]"><span class="truncate">{{ $chip }}</span></span>@endforeach
                            @if ($step->hint)<span class="min-w-0 truncate text-sm text-ink-muted">{{ $step->hint }}</span>@endif
                            <x-ui.icon name="chevron-down" class="step-chevron ml-auto size-4 shrink-0 self-center text-ink-dim"/>
                        </summary>
                        @include('park.vehicles.steps.done')
                    </details>
                @elseif ($step->isCurrent())
                    {{-- Действие текущего шага — плашкой сверху дела (`now-step`, 20.10.2026), здесь — строкой. --}}
                    <div class="step-head">
                        <span class="step-title">{{ $step->title }}</span>
                    </div>
                @elseif ($step->key === 'buyer' && $step->state === Step::TODO)
                    {{-- Анкета покупателя (выдача по QR): не текущий шаг, но с делом — ссылка или «Страховая подтвердила». --}}
                    <div class="step-head">
                        <span class="step-title">{{ $step->title }}</span>
                        @if ($step->at)<span class="fact nums">{{ \App\Support\Ago::html($step->at, true, words: false) }}</span>@endif
                        @foreach ($step->chips as $chip)<span class="fact">{{ $chip }}</span>@endforeach
                    </div>
                    @include('park.vehicles.steps.buyer')
                @elseif ($step->key === 'reply')
                    {{-- Письмо, которое ждёт ответа: строкой; ответить — плашкой «Ждут от нас» сверху дела (`park/vehicles/now`). --}}
                    <div class="step-head"><span class="step-title">{{ $step->title }}</span>@if ($step->at)<span class="fact nums">{{ \App\Support\Ago::html($step->at, true, words: false) }}</span>@endif</div>
                @else
                    <div class="step-head">
                        <span class="step-title">{{ $step->title }}</span>
                    </div>
                    @if ($step->state === Step::TODO && $step->hint)<p class="step-hint">{{ $step->hint }}</p>@endif
                @endif
            </div>
        </div>
    @endforeach
</div>
