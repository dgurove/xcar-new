{{-- Выбор человека с созданием на месте (18.10.2026, владелец: «в сделке для ДКП нужно выбрать покупателя, а его ещё нет — и
     приходится идти в кабинет в пользователей, это бред; и так во многом»). Одна шторка на всё приложение: покупатель
     менеджера, исполнитель логиста, логист и водитель админа, заказчик логиста.
     В форме — строка-поле (`variant=field`, в `.fields`) или строка списка (`row`): выбранный аватаром и именем, пусто —
     «Выбрать» лаймом. Шторка постоянной высоты: поиск (от 8), строки `x-people.option`, последней — «+ Новый …»; она
     выезжает второй панелью той же шторки (`x-people.new-pane`, «‹» назад): данными или ссылкой, JSON без перезагрузки,
     готово — данные входа или ссылка тут же, новый встаёт выбранным. `autosubmit` — выбор сразу отправляет форму (ДКП).
     `none` — строка «никто» с пустым значением (исполнитель «Пока никто»). Событие `people:picked` {id, name, address} — для
     соседних полей (заказчик заявки подставляет адрес).
     `only` — без своего списка, одна панель «+ Новый» (кнопка «Добавить покупателя» над списком, строка в списке с галками):
     новый отмеченным в `append` (селектор списка, имя галки — `name`) или `reload` — страница перечитывается; `trigger` —
     вид кнопки: row (строка с плюсом), button (лаймовая кнопка). --}}
@props(['name', 'label' => null, 'value' => null, 'people' => collect(), 'kind', 'title' => null, 'newLabel' => null, 'link' => false,
    'deal' => null, 'autosubmit' => false, 'none' => null, 'variant' => 'field', 'placeholder' => 'Выбрать', 'required' => false, 'finish' => null,
    'only' => false, 'append' => null, 'reload' => false, 'trigger' => 'row', 'owner' => null])
@php
    use App\Support\Surface;
    $uid = 'pp'.substr(md5($name.$kind.uniqid('', true)), 0, 8);
    $people = collect($people);
    $chosen = $people->first(fn ($p) => (string) $p['id'] === (string) old($name, $value));
    $create = Surface::current() === Surface::Crm ? '/settings/people' : '/people';
    $canCreate = \App\Http\PeopleController::allows(auth()->user(), $kind);
    $error = $errors->first($name);
    // «Готово» в шторке только «+ Новый» (новый просто в списке); в выборе — «Выбрать»: он встаёт в поле.
    $finish ??= $only ? 'Готово' : 'Выбрать';
    $newLabel ??= match ($kind) { 'buyer' => 'Новый покупатель', 'contractor' => 'Новый исполнитель', 'client' => 'Новый заказчик', 'logist' => 'Новый логист', 'driver' => 'Новый сотрудник', 'depot' => 'Новая стоянка', default => 'Новый' };
@endphp
<div {{ $attributes->class([$variant === 'field' && ! $only ? 'field' : 'contents', 'field-invalid' => $error]) }} data-controller="sheet people-pick"
    data-people-pick-kind-value="{{ $kind }}" data-people-pick-url-value="{{ $create }}" data-people-pick-name-value="{{ $name }}"
    @if ($deal) data-people-pick-deal-value="{{ $deal }}" @endif @if ($owner) data-people-pick-owner-value="{{ $owner }}" @endif @if ($autosubmit) data-people-pick-autosubmit-value="true" @endif
    data-people-pick-placeholder-value="{{ $placeholder }}"
    @if ($only) data-people-pick-only-value="true" @endif @if ($append) data-people-pick-append-value="{{ $append }}" @endif @if ($reload) data-people-pick-reload-value="true" @endif>
    @if ($only)
        @if ($trigger === 'button')
            <button type="button" class="btn btn-accent min-w-0 flex-1" data-action="sheet#open people-pick#add"><x-ui.icon name="plus" class="size-5"/> {{ $placeholder }}</button>
        @else
            <button type="button" class="row w-full text-left transition-colors hover:bg-hover" data-action="sheet#open people-pick#add">
                <x-ui.row-icon name="plus" tone="accent"/>
                <span class="min-w-0 flex-1 font-medium">{{ $placeholder }}</span>
            </button>
        @endif
    @elseif ($variant === 'field')
        @if ($label)<span class="field-label">{{ $label }}@if ($required)<x-ui.req/>@endif</span>@endif
        <button type="button" class="field-input people-value" data-action="sheet#open" data-people-pick-target="shown">
            @if ($chosen)<span class="truncate">{{ $chosen['name'] }}</span>@else<span class="text-accent-text">{{ $none && $value === null ? $none : $placeholder }}</span>@endif
            <x-ui.chevron/>
        </button>
    @else
        {{-- Строкой списка — действие («Выбрать покупателя», «Другой покупатель»): кто выбран, пишет строка над ней. --}}
        <button type="button" class="row w-full text-left" data-action="sheet#open">
            <span class="min-w-0 flex-1 font-medium text-accent-text">{{ $placeholder }}</span>
            <x-ui.chevron/>
        </button>
    @endif
    @unless ($only)<input type="hidden" name="{{ $name }}" value="{{ old($name, $value) }}" data-people-pick-target="hidden">@endunless
    @if ($error && $variant === 'field')<p class="field-error">{{ $error }}</p>@endif

    <x-ui.sheet :id="$uid" :title="$only ? $newLabel : ($title ?? $label ?? 'Выбор')" class="sheet-steady people-sheet">
        {{-- Шторка стоит внутри чужой формы (вывоз, договор): её списки и «+ Новый …» — не поля той формы (`formFree` в app.js). --}}
        <div @class(['people-panes', 'people-panes--only' => $only]) data-people-pick-target="panes" data-form-free>
            @unless ($only)
            <div class="people-list-pane">
                @if ($people->count() >= 8)
                    {{-- Не форма (шторка стоит внутри чужих форм) — вид лупы списков. --}}
                    <div class="toolbar-search search-field mb-3">
                        <x-ui.icon name="search" class="size-4 shrink-0 text-ink-muted"/>
                        <input type="search" placeholder="Имя или телефон" autocomplete="off" aria-label="Поиск" data-action="input->people-pick#filter">
                    </div>
                @endif
                <div class="list" data-people-pick-target="list">
                    @if ($none)
                        <label class="row row-check people-option" data-people-option data-search-text="">
                            <x-ui.row-icon name="user" size="s" tone="muted"/>
                            <span class="min-w-0 flex-1 text-ink-muted" data-people-name>{{ $none }}</span>
                            <span class="check"><input type="radio" name="_pp_{{ $uid }}" value="" @checked(! $chosen) data-action="change->people-pick#choose"></span>
                        </label>
                    @endif
                    @foreach ($people as $p)
                        <x-people.option :person="$p" :name="'_pp_'.$uid" :checked="$chosen && (string) $chosen['id'] === (string) $p['id']"/>
                    @endforeach
                    @if ($canCreate)
                        <button type="button" class="row w-full text-left" data-people-pick-target="addRow" data-action="people-pick#add">
                            <x-ui.row-icon name="plus" size="s" tone="accent"/>
                            <span class="min-w-0 flex-1 font-medium text-accent-text">{{ $newLabel }}</span>
                            <x-ui.chevron/>
                        </button>
                    @endif
                </div>
                @if ($people->isEmpty() && ! $none && ! $canCreate)<x-ui.empty class="mt-4">Пока никого нет</x-ui.empty>@endif
            </div>
            @endunless
            @if ($canCreate)
                <div class="people-new-pane" data-new-title="{{ $newLabel }}">
                    <x-people.new-pane :kind="$kind" :link="$link" :uid="$uid"/>
                    <span hidden data-people-pick-target="finishLabel">{{ $finish }}</span>
                </div>
            @endif
        </div>
    </x-ui.sheet>
</div>
