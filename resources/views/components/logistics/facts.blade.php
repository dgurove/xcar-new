{{-- Кто и куда — строками группы, как Настройки iOS (18.10.2026, владелец: «сырая вёрстка»): логист (если вывоз через
     него), исполнитель, куда везти с дальностью, комментарий. Подпись мелко сверху, значение под ней; строка, которую можно
     поменять, — нажатием открывает окно (`who` — событие окна «Кто вывозит» / исполнителя, `where` — «Куда везти»; null —
     только показ). Без обёртки `.list` — встаёт в группу карточки рядом с владельцем и «Где забрать». `brief` — исполнителю
     (владелец 18.10.2026: «упростить ему интерфейс»): только куда везти и комментарий, кто он и его логист — не нужно. --}}
@props(['t', 'me' => null, 'logist' => null, 'who' => null, 'where' => null, 'brief' => false])
@php
    $row = function (string $label, string $value, ?string $sub, ?string $event, string $tone = '') {
        $body = '<span class="min-w-0 flex-1"><span class="block text-sm text-ink-muted">'.e($label).'</span>'
            .'<span class="block break-words '.$tone.'">'.e($value).'</span>'
            .($sub ? '<span class="block text-sm text-ink-muted">'.e($sub).'</span>' : '').'</span>';

        return $event
            ? '<button type="button" class="row w-full items-center gap-4 text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="'.e($event).'">'.$body.'<svg viewBox="0 0 24 24" class="size-5 shrink-0 text-ink-dim" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>'
            : '<div class="row items-center gap-4">'.$body.'</div>';
    };
    $mine = fn (?\App\Users\User $u) => $u && $me && $u->is($me) ? 'вы' : $u?->shortName();
    $manager = $t->channel === \App\Logistics\Channel::Manager;
    $dest = $t->routeLabel();
@endphp
@if ($t->logist && ! $brief)
    {!! $row('Логист', $mine($t->logist), null, $logist) !!}
@endif
@if (! $manager && ! $brief)
    {!! $row('Исполнитель', $mine($t->executor) ?? ($t->logist && ! ($me && $t->hasLogist($me)) ? 'выбирает логист' : 'не выбран'), null, $who, $t->executor ? '' : 'text-urgent') !!}
@endif
@if (($t->isPickup() || $t->from_depot_id) && ! $t->isOrder())
    {{-- На стоянку логиста — вторым этажом, куда дальше (следующий отрезок). --}}
    @php $then = $t->destination === \App\Logistics\Point::Depot ? 'дальше: '.($t->nextLabel() ?? 'пока неизвестно') : null; @endphp
    {!! $row('Куда везти', $dest ?? 'пока неизвестно', implode(', ', array_filter([$then, $t->reach ? mb_lcfirst($t->reach->label()) : null])) ?: null, $where, $dest ? '' : 'text-urgent') !!}
@endif
@if ($t->comment)
    <div class="row"><span class="min-w-0"><span class="block text-sm text-ink-muted">Комментарий</span><span class="block whitespace-pre-line break-words">{{ $t->comment }}</span></span></div>
@endif
