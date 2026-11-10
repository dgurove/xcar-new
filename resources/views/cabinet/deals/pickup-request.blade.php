{{-- Кто везёт или «Попросить вывоз…» (17.10.2026, владелец): забирает сам — «Автомобиль забрал» кнопкой шага, логистов — только
     если попросит; везёт логистика — кто и когда словом, довезут — шаг пройдёт сам. В чек-листе договора — под «Заберите
     автомобиль», иначе — под кнопками шага. --}}
@if ($handover?->carrier)
    <p class="mt-4 text-sm text-ink-muted">{{ $handover->carrier }}</p>
@elseif ($handover?->canRequest)
    @if ($handover->canPick)
        {{-- Шага получения у маршрута нет (Альфа): «Забрал» — здесь, иначе его вывоз так и висел бы открытым. --}}
        <form method="post" action="/deals/{{ $deal->id }}/picked" class="mt-4" data-turbo-confirm="Забрали автомобиль?" data-turbo-confirm-label="Автомобиль забрал">
            @csrf<x-ui.button block variant="secondary">Автомобиль забрал</x-ui.button>
        </form>
    @endif
    {{-- «Попросить вывоз…» (10.11.2026, было «Нужен вывоз» — владелец: «непонятно, что эта кнопка делает»): шторка с тем,
         что будет (`x-deal.pickup-ask-sheet`), просьба — в ней. --}}
    <div data-controller="sheet" class="{{ ($inline ?? false) ? 'mt-1' : 'mt-3 text-center' }}">
        <button type="button" @class(["retreat-link", "!mx-0 !px-0" => $inline ?? false]) data-action="sheet#open">Попросить вывоз…</button>
        <x-deal.pickup-ask-sheet :deal="$deal"/>
    </div>
@endif
