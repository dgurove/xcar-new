{{-- Кнопки шага (и поля, если шаг их просит). Просит документ — кнопки нет, пока файл не приложен (06.10.2026, владелец:
     «почему кнопка "Договор приложен", если договор ещё не приложен»): «Приложить» и удаление файла отвечают потоком,
     который подменяет и этот блок (`files-stream`). --}}
@php
    use App\Workflow\Asks;
    $waiting = $requirement->asks === Asks::Document && $requirement->getMedia('files')->isEmpty();
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
            {{-- Один исход — во всю ширину, два — в ряд одной ширины, как ответы в диалоге приложения; больше — переносом. --}}
            <div @class(['gap-3', 'grid' => $exits->count() <= 2, 'grid-cols-2' => $exits->count() === 2, 'flex flex-wrap' => $exits->count() > 2])>
                @foreach ($exits as $exit)
                    <x-ui.button name="exit" :value="$exit->id" :variant="$loop->first ? 'primary' : 'secondary'" :data-turbo-confirm="$exit->confirm" class="min-w-0 px-4">{{ $exit->label }}</x-ui.button>
                @endforeach
            </div>
        </form>
    @endunless
</div>
