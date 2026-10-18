{{-- Кнопки шага (и поля, если шаг их просит). Просит документ — кнопки нет, пока файл не приложен (06.10.2026, владелец:
     «почему кнопка "Договор приложен", если договор ещё не приложен»): «Приложить» и удаление файла отвечают потоком,
     который подменяет и этот блок (`files-stream`). --}}
@php
    use App\Workflow\Asks;
    $waiting = $requirement->asks === Asks::Document && $requirement->getMedia('files')->isEmpty();
    // «Я передумал» — не второй кнопкой рядом, а тихой строкой под главной (10.10.2026, владелец).
    [$retreats, $moves] = $exits->partition(fn ($e) => $e->isRetreat());
    // Закрыть сделку, пока автомобиль не забрали, нельзя (18.10.2026): вместо кнопки — что сделать сначала.
    $blocker = $moves->contains(fn ($e) => \App\Offers\Deal::closes($e)) ? $deal->closeBlocker() : null;
    if ($blocker) {
        $moves = $moves->reject(fn ($e) => \App\Offers\Deal::closes($e));
    }
@endphp
<div id="requirement-exits">
    @unless ($waiting)
        <form method="post" action="/deals/{{ $deal->id }}/reply" class="mt-5 flex flex-col gap-4">
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
                    <x-ui.button name="exit" :value="$exit->id" :variant="$loop->first ? 'primary' : 'secondary'" :data-turbo-confirm="\App\Users\Wording::mine($exit->confirm)" class="min-w-0 px-4">{{ \App\Users\Wording::mine($exit->label) }}</x-ui.button>
                @endforeach
            </div>
            @foreach ($retreats as $exit)
                <button name="exit" value="{{ $exit->id }}" class="retreat-link" data-turbo-confirm="{{ \App\Users\Wording::mine($exit->confirm) ?: 'Отказаться от сделки?' }}">{{ \App\Users\Wording::mine($exit->label) }}</button>
            @endforeach
        </form>
    @endunless
</div>
