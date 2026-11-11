{{-- Под шагом в окне вывоза логистики (`x-logistics.steps`): трек документов, «Документы получены», отправка документов,
     «Перенести» созвон, отмена последнего шага. --}}
{{-- Трек: копировать и смотреть у СДЭКа; получили — отметить (логист и ведущие — и без трека). --}}
@if ($s['key'] === 'docs' && $t->docs_track)
    <div class="mt-2 flex flex-wrap items-center gap-2">
        <x-ui.copy-code :value="$t->docs_track" class="tag" done="Трек в буфере"/>
        <a href="https://www.cdek.ru/ru/tracking?order_id={{ urlencode($t->docs_track) }}" target="_blank" rel="noopener" class="text-sm text-accent-text">Где документы</a>
    </div>
@endif
@if ($s['key'] === 'docs' && $docsDue && $step !== 'pickup')
    <form method="post" action="/transports/{{ $id }}/docs/received" class="mt-3" data-turbo-confirm="Документы у исполнителя?">@csrf<x-ui.button size="sm">Документы получены</x-ui.button></form>
@endif
@if ($s['key'] === 'docs' && $docsForm)
    <div class="mt-2 text-sm text-ink-muted">
        {{ $t->executor?->name }}@if ($t->executor?->phone), <x-ui.tel :value="$t->executor->phone"/>@endif
        @if ($t->executor?->cdek_point)<span class="block text-ink">СДЭК: {{ $t->executor->cdek_point }}</span>@endif
    </div>
    <form method="post" action="/transports/{{ $id }}/docs" class="mt-3 flex flex-col gap-2">
        @csrf
        <div class="fields"><x-ui.field name="docs_track" id="docs-track-{{ $id }}" label="Трек СДЭК" :value="$t->docs_track"/></div>
        <x-ui.button size="sm" class="self-start">{{ $t->docs_track ? 'Сохранить трек' : 'Документы отправлены' }}</x-ui.button>
    </form>
@endif
@php
    $move = $s['key'] === 'call' && $t->agreed_at && $executes && $open && ! $t->state->picked();
    $back = $undoStep === $s['key'] && $s['status'] === 'done';
@endphp
@if ($move || $back)
    <div class="mt-2 flex flex-wrap gap-2">
        @if ($move)<button type="button" class="btn btn-s btn-quiet" data-controller="emit" data-action="emit#send" data-emit-event-param="call-{{ $id }}:open"><x-ui.icon name="calendar" class="size-4"/>Перенести</button>@endif
        @if ($back)
            <x-ui.step-back :action="'/transports/'.$id.'/back'" :label="$undoLabel" kind="transport" variant="inline"/>
        @endif
    </div>
@endif
