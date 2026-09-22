{{-- Заявка из писем строкой: вся строка — ссылка на разбор письма, кнопок нет. Название — марка с моделью
     (без марки так и написано), чипы — что просят, кто просит и чем ТС опознана; справа дата, число писем и
     слово действия. Письмо своими словами не пересказывается: что просят, говорит тег, детали — на разборе. --}}
@props(['c', 'base'])
@php
    use App\Mail\CandidateStage;
    use App\Mail\CandidateState;
    $v = fn (string $f) => $c->value($f);
    $stage = $c->stage ?? CandidateStage::Intake;
    $promoted = $c->state === CandidateState::Promoted;
    $href = $promoted && $c->vehicle_id ? '/cars/'.$c->vehicle_id : '/requests/new?candidate='.$c->id;
    $unread = $c->messages->contains(fn ($m) => ! $m->is_seen && ! $m->isOurs());
    $phone = $v('insured_phone') ?? ((array) $v('phones'))[0] ?? null;
    $who = trim(($v('insured_name') ?? '').' '.($phone ?? ''));
@endphp
<article id="candidate-{{ $c->id }}" class="card rise group">
    <a href="{{ $href }}" class="card-link" aria-hidden="true" tabindex="-1"></a>
    <div class="card-body">
        <div class="card-title">
            <a href="{{ $href }}" class="block min-w-0 flex-1 leading-snug hover:text-accent-text">
                <span class="line-clamp-1 {{ $unread ? 'font-medium' : '' }} {{ $c->hasCar() ? '' : 'text-ink-muted' }}">@if ($unread)<span class="mr-1.5 inline-block size-2 rounded-full bg-urgent align-[1px]"></span>@endif{{ $c->title() }}@if ($c->hasCar() && $v('year')) <span class="nums font-normal text-ink-muted">{{ $v('year') }}</span>@endif</span>
            </a>
        </div>
    </div>
    <div class="card-extra">
        @if ($stage === CandidateStage::Intake)
            <span class="tag">{{ $c->requestTag() }}</span>
        @else
            <span class="tag {{ $stage === CandidateStage::Sold ? 'tag-urgent' : '' }}">{{ $c->stageLabel() }}</span>
        @endif
        @if ($c->vendor?->name ?? $v('vendor') ?? $v('sender'))<span class="tag">{{ $c->vendor?->name ?? $v('vendor') ?? $v('sender') }}</span>@endif
        @if ($c->code)<x-ui.copy-code class="tag" :value="$c->code"/>@endif
        @if ($v('plate'))<x-ui.copy-code class="tag" :value="$v('plate')" done="Госномер в буфере" title="Скопировать госномер"/>@endif
        <x-ui.vin-code :vin="$v('vin')" class="tag"/>
        @if ($phone)
            <a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}" class="tag nums"><x-ui.icon name="phone" class="size-3.5 shrink-0"/>{{ $who }}</a>
        @elseif ($v('insured_name'))
            <span class="tag">{{ $v('insured_name') }}</span>
        @endif
    </div>
    <div class="card-aside">
        {{-- На телефоне дата и слово действия стоят рядом с названием: число писем там лишнее, название важнее. --}}
        <span class="nums text-xs text-ink-dim">{{ ($c->last_message_at ?? $c->created_at)->translatedFormat('j M') }}@if ($c->messages_count > 1)<span class="hidden sm:inline">, {{ $c->messages_count }} {{ \App\Support\Plural::of($c->messages_count, ['письмо', 'письма', 'писем']) }}</span>@endif</span>
        @if ($promoted)
            @if ($c->vehicle_id)<a href="{{ $href }}" class="text-sm text-ink-muted">ТС ›</a>@else<span class="text-sm text-ink-dim">ТС нет</span>@endif
        @elseif ($c->state === CandidateState::Closed)
            <span class="text-sm text-ink-dim">Закрыта</span>
        @elseif ($c->state === CandidateState::Rejected)
            <form method="post" action="{{ $base }}/{{ $c->id }}/decline" class="contents">@csrf<button class="text-sm text-ink-muted">Вернуть ›</button></form>
        @else
            <a href="{{ $href }}" class="text-sm text-accent-text">Завести ›</a>
        @endif
    </div>
</article>
