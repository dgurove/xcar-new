{{-- Ход вывоза лесенкой (`Transport::steps`) — один на дорожку «Вывоз» в CRM, окно вывоза в логистике и страницу рейса
     исполнителя (11.11.2026: было три копии, время шага — по-разному). who=false — без строк «логист» и «исполнитель» (рейс:
     исполнитель знает себя); иначе они только пока никого не назначили или менеджер забирает сам — дальше они строками выше.
     ask — текущий шаг нового вывоза вопросом («Выбрать», CRM). extras — вид с тем, что под шагом у этого места (трек, формы,
     «Перенести»), получает `s` и `with`. --}}
@props(['t', 'who' => true, 'ask' => false, 'extras' => null, 'with' => []])
@php $new = $t->state === \App\Logistics\TransportState::New; @endphp
<div {{ $attributes->merge(['class' => 'steps']) }}>
    @foreach ($t->steps() as $s)
        @continue(in_array($s['key'], ['logist', 'executor'], true) && (! $who || (! $new && ! $t->isSelfPickup())))
        <div class="step step--{{ $ask && $s['status'] === 'current' && $new ? 'ask' : $s['status'] }}">
            <span class="step-dot">@if ($s['status'] === 'done')<x-ui.icon name="check" class="size-3"/>@endif</span>
            <div class="step-body">
                <div class="step-head">
                    <span class="step-title flex-1">{{ $s['title'] }}</span>
                    @if ($s['status'] === 'done' && $s['at'])<span class="nums shrink-0 text-sm text-ink-dim">{{ \App\Support\Ago::html($s['at'], time: true, short: true) }}</span>@endif
                </div>
                @if ($s['hint'])<p class="step-hint">{{ $s['hint'] }}</p>@endif
                @if ($extras)@include($extras, ['s' => $s] + $with)@endif
            </div>
        </div>
    @endforeach
</div>
