{{-- Кнопки шага (и поля, если шаг их просит). Просит документ — кнопки нет, пока файл не приложен (06.10.2026, владелец:
     «почему кнопка "Договор приложен", если договор ещё не приложен»): «Приложить» и удаление файла отвечают потоком,
     который подменяет и этот блок (`files-stream`). --}}
@php
    use App\Workflow\Asks;
    $waiting = $requirement->asks === Asks::Document && $requirement->getMedia('files')->isEmpty();
    // «Отказываюсь» — серой кнопкой в ряд с «Напомнить через…» под главной (19.11.2026; с 10.10.2026 была тихой строкой).
    [$retreats, $moves] = $exits->partition(fn ($e) => $e->isRetreat());
    // Вопрос о новой закупочной с «Отказываюсь» над шагом (`x-offer.reprice-ask`) — второй отмены сделки рядом не даём (19.11.2026,
    // владелец: «не должно быть двух плашек действий»). Он назвал цену или ждёт нашего контрпредложения — отмены там нет, строка остаётся.
    $askRefuses = ($me = auth()->user()) && \App\Offers\RepriceAsk::open()->where('user_id', $me->id)->where('deal_id', $deal->id)->latest('id')->get()
        ->contains(fn ($a) => $a->alive() && ! $a->isOffered() && ! $a->isCountered());
    $retreats = $askRefuses ? collect() : $retreats;
    // Закрыть сделку, пока автомобиль не забрали, нельзя (18.10.2026): вместо кнопки — что сделать сначала.
    $blocker = $moves->contains(fn ($e) => \App\Offers\Deal::closes($e)) ? $deal->closeBlocker() : null;
    if ($blocker) {
        $moves = $moves->reject(fn ($e) => \App\Offers\Deal::closes($e));
    }
@endphp
<div id="requirement-exits">
    @unless ($waiting)
        <form method="post" action="/deals/{{ $deal->id }}/reply" id="reply-{{ $requirement->id }}" class="mt-5 flex flex-col gap-4">
            @csrf
            @if ($requirement->asks === Asks::Fields)
                @foreach ($requirement->fields as $field)
                    <x-route.field :field="$field" :name="'fields['.$field['key'].']'"/>
                @endforeach
            @endif
            @if ($errors->has('exit'))<p class="field-error">{{ $errors->first('exit') }}</p>@endif
            @if ($blocker)<p class="font-medium text-urgent">{{ $blocker }}</p>@endif
            {{-- Один исход — во всю ширину, два — в ряд одной ширины, как ответы в диалоге приложения; больше — переносом. --}}
            <div @class(['gap-3', 'grid' => $moves->count() <= 2, 'grid-cols-2' => $moves->count() === 2, 'flex flex-wrap' => $moves->count() > 2])>
                @foreach ($moves as $exit)
                    @if (\App\Offers\Warnings::takes($exit))
                        {{-- «Беру в работу» — окно с тремя кнопками (`x-deal.take-sheet`), а не `confirm.js` с двумя. --}}
                        <x-ui.button type="button" :variant="$loop->first ? 'primary' : 'secondary'" data-controller="emit" data-action="emit#send" :data-emit-event-param="'take:take-'.$requirement->id.'-'.$exit->id" class="min-w-0 px-4">{{ \App\Support\Opens::label(\App\Users\Wording::mine($exit->label), true) }}</x-ui.button>
                    @else
                        <x-ui.button name="exit" :value="$exit->id" :variant="$loop->first ? 'primary' : 'secondary'" :data-turbo-confirm="\App\Users\Wording::mine($exit->confirm) ?: null" class="min-w-0 px-4">{{ \App\Support\Opens::label(\App\Users\Wording::mine($exit->label), (bool) $exit->confirm) }}</x-ui.button>
                    @endif
                @endforeach
            </div>
        </form>
        @foreach ($moves->filter(fn ($e) => \App\Offers\Warnings::takes($e)) as $exit)
            <x-deal.take-sheet :id="'take-'.$requirement->id.'-'.$exit->id" :form="'reply-'.$requirement->id" :take="['exit', $exit->id]" :ok="$exit->takes() ? 'Да, беру в работу' : 'Да, берём'"
                :drop="($r = $retreats->first()) ? ['exit', $r->id] : null" :deal="$deal" :ask="$requirement"/>
        @endforeach
        {{-- «Отказываюсь» и «Напомнить через…» — двумя кнопками в ряд под главной (19.11.2026, владелец: тихая ссылка по центру —
             «в нормальных приложениях так не делают»). Шторка напоминания — со своими формами, поэтому ряд — за формой ответа,
             а «Отказываюсь» шлёт её атрибутом `form`. --}}
        @if ($retreats->isNotEmpty())
            <div class="mt-3 flex gap-3">
                @foreach ($retreats as $exit)
                    <button form="reply-{{ $requirement->id }}" name="exit" value="{{ $exit->id }}" class="btn btn-quiet shrink-0 px-5" {{ \App\Offers\Warnings::html(\App\Offers\Warnings::DEAL_OFF, 'Да, отказываюсь', danger: true) }}><span class="truncate">{{ \App\Support\Opens::label(\App\Users\Wording::mine($exit->label)) }}</span></button>
                @endforeach
                <x-deal.remind :deal="$deal" :ask="$requirement"/>
            </div>
        @endif
    @endunless
</div>
