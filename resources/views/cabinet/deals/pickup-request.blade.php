{{-- Кто везёт или «Нужен вывоз» (17.10.2026, владелец): забирает сам — «Автомобиль забрал» кнопкой шага, логистов — только
     если попросит; везёт логистика — кто и когда словом, довезут — шаг пройдёт сам. В чек-листе договора — под «Заберите
     автомобиль», иначе — под кнопками шага. --}}
@if ($handover?->carrier)
    <p class="mt-4 text-sm text-ink-muted">{{ $handover->carrier }}</p>
@elseif ($handover?->canRequest)
    <form method="post" action="/deals/{{ $deal->id }}/pickup" class="{{ ($inline ?? false) ? 'mt-1' : 'mt-3 text-center' }}" data-turbo-confirm="Попросить логистов забрать ТС?" data-turbo-confirm-label="Попросить">
        @csrf<button @class(["retreat-link", "!mx-0 !px-0" => $inline ?? false])>Нужен вывоз</button>
    </form>
@endif
