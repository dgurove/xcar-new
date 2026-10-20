{{-- Карточка блока машины — одна на CRM и парковку (19.10.2026): заголовок подписью раздела, «Изменить / Готово», как в
     Контактах iOS (`edit_card_controller`): текстом — слот `view`, полями — сам слот; поля всё время в форме, скрытые, так
     что сохранение соседнего блока шлёт их как есть. fold: null — без «Изменить», поля всегда (черновик, ожидаемая ТС,
     окошко строки); true — свёрнут текстом; false — раскрыт полями с «Готово» (ошибка, расхождение). as=section —
     разделом окошка строки CRM. Пусто — «Добавить». --}}
@props(['title', 'fold' => null, 'empty' => false, 'as' => 'card', 'form' => null, 'top' => null, 'view' => null])
@php $toggle = $fold !== null && $as === 'card'; @endphp
{{-- Пустой свёрнутый блок помечен `data-empty-block`: такие блоки собирает в одну строку «Добавить: …» `empty_blocks_controller`
     (20.10.2026, владелец: «компактнее»), нажатие раскрывает свой блок полями. --}}
<section {{ $attributes->class(['box' => $as === 'card']) }} @if ($toggle && $fold && $empty) data-empty-block="{{ $title }}" @endif
    @if ($toggle) data-controller="edit-card" @if (! $fold) data-edit-card-editing-value="true" @endif @if ($form) data-edit-card-form-value="{{ $form }}" @endif @endif>
    @if ($as === 'card')
        <div class="mb-3 flex items-center gap-3">
            <h2 class="box-title">{{ $title }}</h2>
            @if ($toggle)
                <div class="-my-1 ml-auto flex items-center">
                    <button type="button" class="edit-card-toggle" data-edit-card-target="button" data-action="edit-card#toggle" @if ($empty) data-idle="Добавить" @endif>{{ $fold ? ($empty ? 'Добавить' : 'Изменить') : 'Готово' }}</button>
                </div>
            @endif
        </div>
    @else
        <h2 class="detail-section">{{ $title }}</h2>
    @endif
    {{ $top }}
    @if ($toggle)
        <div data-edit-card-target="view" @unless ($fold) hidden @endunless>{{ $view }}</div>
    @endif
    <div @if ($toggle) data-edit-card-target="edit" @if ($fold) hidden @endif @endif>{{ $slot }}</div>
</section>
