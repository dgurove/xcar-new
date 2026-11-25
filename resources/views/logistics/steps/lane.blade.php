{{-- Под шагом в дорожке «Вывоз» CRM (`x-logistics.steps`): исполнителя нового вывоза — «Выбрать» (окно «Назначить вывоз»). --}}
@if ($s['key'] === 'executor' && $s['status'] === 'current' && $t->state === \App\Logistics\TransportState::New)
    <x-ui.button type="button" size="sm" class="mt-2" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}:open">Выбрать</x-ui.button>
@endif
{{-- Документы СДЭКом (26.11.2026, владелец: «Лиза готовит документы для вывоза и отправляет их СДЭКом, не знаю, отображаем ли мы это в
     CRM, надо отображать»): трек — копировать и «Где документы» у СДЭКа; не отправлены — кому и куда (пункт СДЭК исполнителя) и поле
     трека, «Документы отправлены» (`Logistics\Actions\Docs`, тот же, что в логистике); дошли — «Документы получены» за исполнителя. --}}
@if ($s['key'] === 'docs')
    @php
        $me = auth()->user();
        $docsForm = $me?->sendsLogisticsDocs() && $t->docs_by_cdek && $t->executor_id && $t->isOpen() && ! $t->state->picked() && ! $t->docs_received_at;
        $docsGot = $me?->leadsLogistics() && $t->docs_by_cdek && $t->docs_sent_at && ! $t->docs_received_at && $t->isOpen() && ! $t->state->picked();
    @endphp
    @if ($t->docs_track)
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <x-ui.copy-code :value="$t->docs_track" class="tag" done="Трек в буфере"/>
            <a href="https://www.cdek.ru/ru/tracking?order_id={{ urlencode($t->docs_track) }}" target="_blank" rel="noopener" class="text-sm text-accent-text">Где документы</a>
        </div>
    @endif
    @if ($docsForm && ! $t->docs_sent_at)
        <div class="mt-2 text-sm text-ink-muted">
            {{ $t->executor?->name }}@if ($t->executor?->phone), <x-ui.tel :value="$t->executor->phone"/>@endif
            @if ($t->executor?->cdek_point)<span class="block text-ink">СДЭК: {{ $t->executor->cdek_point }}</span>@endif
        </div>
        <form method="post" action="/work/transports/{{ $t->id }}/docs" class="mt-3 flex flex-col gap-2">
            @csrf
            <div class="fields"><x-ui.field name="docs_track" id="crm-docs-track-{{ $t->id }}" label="Трек СДЭК" :value="$t->docs_track"/></div>
            <x-ui.button size="sm" class="self-start">Документы отправлены</x-ui.button>
        </form>
    @elseif ($docsGot)
        <form method="post" action="/work/transports/{{ $t->id }}/docs/received" class="mt-3" data-turbo-confirm="Документы у исполнителя?">@csrf<x-ui.button size="sm" variant="secondary">Документы получены</x-ui.button></form>
    @endif
@endif
