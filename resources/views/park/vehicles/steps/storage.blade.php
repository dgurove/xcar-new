@php use App\Park\{RequestState, ReleasedTo, Inspection}; use App\Support\Money; @endphp
{{-- Шаг «На хранении»: исходы — «Покупатель» (шторка: кто заберёт, обычно приходит письмом страховой) и «Выдать» (заявка на
     выдачу, форма spawn-form). Дни, «На выдачу» и кто заберёт — в шапке дела, ставка — в «Оплатах»: тут не повторяются;
     своё у шага — только с какого дня платит покупатель. --}}
@if ($buyerFrom)<div class="mt-2 flex flex-wrap items-center gap-1.5"><span class="chip nums {{ $buyerFrom->isPast() ? 'bg-danger-soft text-danger' : '' }}">покупатель с {{ $buyerFrom->translatedFormat('j M') }}, {{ Money::rub($buyerRate) }}/сут</span></div>@endif
@if ($canManage)
    <div class="mt-3 flex flex-wrap gap-2">
        <x-ui.button type="button" variant="secondary" data-controller="emit" data-action="emit#send" data-emit-event-param="sold:open">{{ $vehicle->sold_at ? 'Изменить покупателя' : 'Покупатель' }}</x-ui.button>
        <x-ui.button form="spawn-form">Выдать</x-ui.button>
    </div>
@endif
