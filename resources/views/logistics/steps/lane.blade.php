{{-- Под шагом в дорожке «Вывоз» CRM (`x-logistics.steps`): исполнителя нового вывоза — «Выбрать» (окно «Назначить вывоз»). --}}
@if ($s['key'] === 'executor' && $s['status'] === 'current' && $t->state === \App\Logistics\TransportState::New)
    <x-ui.button type="button" size="sm" class="mt-2" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}:open">Выбрать</x-ui.button>
@endif
