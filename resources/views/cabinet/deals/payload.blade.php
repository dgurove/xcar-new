{{-- Поля, что вписали на шаге сотрудники (дата, адрес площадки…), кроме показанных в получении. --}}
@if ($payload->isNotEmpty())
    {{-- Значками, подпись поля — строкой под значением, не колонкой (11.11.2026). --}}
    <x-ui.info class="mt-4">
        @foreach ($payload as $k => $v)
            @php $label = collect($position->stage->staff_fields)->firstWhere('key', $k)['label'] ?? $k; @endphp
            <x-ui.info-row :icon="preg_match('/дат|срок|когда/iu', $label) ? 'calendar' : (preg_match('/адрес|мест/iu', $label) ? 'map-pin' : 'file')" :sub="$label" class="nums">{{ $v }}</x-ui.info-row>
        @endforeach
    </x-ui.info>
@endif
