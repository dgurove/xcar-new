@php use App\Park\{RequestState, ReleasedTo, Inspection}; use App\Support\Money; @endphp
{{-- Шаг «На хранении»: исходы — «Покупатель» (шторка: кто заберёт, обычно приходит письмом страховой) и «Выдать» (окно
     действия). Дни, «На выдачу» и кто заберёт — в шапке дела, ставка — в «Оплатах»: тут не повторяются;
     своё у шага — только с какого дня платит покупатель. --}}
@if ($buyerFrom && ($money ?? true))<div class="mt-2 flex flex-wrap items-center gap-1.5"><span class="chip nums {{ $buyerFrom->isPast() ? 'bg-danger-soft text-danger' : '' }}">покупатель с {{ $buyerFrom->translatedFormat('j M') }}, {{ Money::rub($buyerRate) }}/сут</span></div>@endif
{{-- «Выдать» и «Покупатель» — и приёмщику (`$handles`, 27.10.2026). --}}
@if ($handles ?? $canManage)
    <div class="mt-2 flex gap-2">
        <x-park.act-button :vehicle="$vehicle" step="release" label="Выдать" class="btn btn-s btn-accent min-w-0 flex-1"/>
        <button type="button" class="btn btn-s btn-quiet shrink-0" data-controller="emit" data-action="emit#send" data-emit-event-param="sold:open">{{ $vehicle->sold_at ? 'Изменить покупателя' : 'Покупатель' }}</button>
    </div>
@endif
