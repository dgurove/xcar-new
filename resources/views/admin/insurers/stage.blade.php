@php
    use App\Workflow\{Track, WaitsFor, DeadlineSource, Asks, Actor};
    use App\Offers\{OfferState, CarPlace};
    $w = $workflow;
    $back = "/nastroyki/strahovye/{$w->insurer_id}?vetka={$w->track->value}";
    $m = $stage->limit_minutes;
    [$limitValue, $limitUnit] = $m === null ? [null, 'hours'] : ($m % 1440 === 0 ? [$m / 1440, 'days'] : ($m % 60 === 0 ? [$m / 60, 'hours'] : [$m, 'minutes']));
    $types = ['text' => 'Строка', 'number' => 'Число', 'date' => 'Дата', 'textarea' => 'Текст'];
@endphp
<x-ui.shell :title="$stage->exists ? $stage->name : 'Новый этап'" :trail="[['Главная', '/'], ['Настройки', '/nastroyki'], ['Страховые', '/nastroyki/strahovye'], ['Маршрут', $back], [$stage->exists ? $stage->name : 'Новый этап']]" narrow>
    <form method="post" action="{{ $stage->exists ? '/nastroyki/marshruty/etapy/'.$stage->id : '/nastroyki/marshruty/'.$w->id.'/etapy' }}" id="stage-form" class="flex flex-col gap-4">
        @csrf @if ($stage->exists) @method('put') @endif

        <x-ui.card title="Этап">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field name="name" label="Название" :value="$stage->name" required class="sm:col-span-2"/>
                <x-ui.field name="block_id" label="Блок" :options="$w->blocks->pluck('name', 'id')" :value="$stage->block_id"/>
                <x-ui.field name="waits_for" label="Чей ход" :options="WaitsFor::options()" :value="$stage->waits_for?->value"/>
                @if ($w->track === Track::Sale)
                    <x-ui.field name="offer_state" label="Что становится с предложением" :options="OfferState::options()" placeholder="Не меняется" :value="$stage->offer_state?->value"/>
                @else
                    <x-ui.field name="car_place" label="Где машина" :options="CarPlace::options()" placeholder="Не меняется" :value="$stage->car_place?->value"/>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card title="Срок">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field name="deadline_source" label="Откуда срок" :options="DeadlineSource::options()" :value="$stage->deadline_source?->value ?? 'own'"/>
                <div class="field">
                    <label class="field-label" for="f-limit_value">Сколько ждём</label>
                    <div class="flex gap-2">
                        <input id="f-limit_value" name="limit_value" inputmode="numeric" value="{{ old('limit_value', $limitValue) }}" class="field-input" placeholder="—">
                        <select name="limit_unit" class="field-input !w-auto">
                            @foreach (['minutes' => 'мин', 'hours' => 'ч', 'days' => 'дн'] as $k => $l)<option value="{{ $k }}" @selected(old('limit_unit', $limitUnit) === $k)>{{ $l }}</option>@endforeach
                        </select>
                    </div>
                    @error('limit_value')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-ui.card>

        @if ($w->track === Track::Sale)
        <x-ui.card title="Просьба к менеджеру" data-controller="repeater">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field name="ask_title" label="Заголовок" :value="$stage->ask_title" placeholder="Подтвердите сделку" class="sm:col-span-2"/>
                <x-ui.field name="ask_text" label="Текст" type="textarea" :value="$stage->ask_text" class="sm:col-span-2"/>
                <x-ui.field name="asks" label="Что приложить" :options="Asks::options()" :value="$stage->asks?->value ?? 'nothing'"/>
            </div>
            <div class="mt-4 flex flex-col gap-2" data-repeater-target="list">
                @foreach ($fields as $i => $field)
                    <div class="flex gap-2" data-row>
                        <input type="hidden" name="fields[{{ $i }}][key]" value="{{ $field['key'] ?? '' }}">
                        <input name="fields[{{ $i }}][label]" value="{{ $field['label'] ?? '' }}" class="field-input flex-1" placeholder="Подпись поля">
                        <select name="fields[{{ $i }}][type]" class="field-input !w-auto">@foreach ($types as $k => $l)<option value="{{ $k }}" @selected(($field['type'] ?? 'text') === $k)>{{ $l }}</option>@endforeach</select>
                        <button type="button" class="btn btn-ghost px-2 text-ink-muted" data-action="repeater#remove" aria-label="Убрать"><x-ui.icon name="x" class="size-5"/></button>
                    </div>
                @endforeach
            </div>
            <template data-repeater-target="template">
                <div class="flex gap-2" data-row>
                    <input name="fields[__i__][label]" class="field-input flex-1" placeholder="Подпись поля">
                    <select name="fields[__i__][type]" class="field-input !w-auto">@foreach ($types as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                    <button type="button" class="btn btn-ghost px-2 text-ink-muted" data-action="repeater#remove" aria-label="Убрать"><x-ui.icon name="x" class="size-5"/></button>
                </div>
            </template>
            <x-ui.button type="button" variant="ghost" size="sm" class="mt-2" data-action="repeater#add"><x-ui.icon name="plus" class="size-4"/> Поле менеджера</x-ui.button>
        </x-ui.card>

        @if ($templates->isNotEmpty())
        <x-ui.card title="Письмо страховой">
            <x-ui.field name="template_id" label="Шаблон" :options="$templates" placeholder="Не пишем" :value="$stage->template_id"/>
        </x-ui.card>
        @endif

        <x-ui.card title="Что вписываем мы на входе" data-controller="repeater">
            <div class="flex flex-col gap-2" data-repeater-target="list">
                @foreach ($staffFields as $i => $field)
                    <div class="flex gap-2" data-row>
                        <input type="hidden" name="staff_fields[{{ $i }}][key]" value="{{ $field['key'] ?? '' }}">
                        <input name="staff_fields[{{ $i }}][label]" value="{{ $field['label'] ?? '' }}" class="field-input flex-1" placeholder="Подпись поля">
                        <select name="staff_fields[{{ $i }}][type]" class="field-input !w-auto">@foreach ($types as $k => $l)<option value="{{ $k }}" @selected(($field['type'] ?? 'text') === $k)>{{ $l }}</option>@endforeach</select>
                        <button type="button" class="btn btn-ghost px-2 text-ink-muted" data-action="repeater#remove" aria-label="Убрать"><x-ui.icon name="x" class="size-5"/></button>
                    </div>
                @endforeach
            </div>
            <template data-repeater-target="template">
                <div class="flex gap-2" data-row>
                    <input name="staff_fields[__i__][label]" class="field-input flex-1" placeholder="Подпись поля">
                    <select name="staff_fields[__i__][type]" class="field-input !w-auto">@foreach ($types as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                    <button type="button" class="btn btn-ghost px-2 text-ink-muted" data-action="repeater#remove" aria-label="Убрать"><x-ui.icon name="x" class="size-5"/></button>
                </div>
            </template>
            <x-ui.button type="button" variant="ghost" size="sm" class="mt-2" data-action="repeater#add"><x-ui.icon name="plus" class="size-4"/> Поле</x-ui.button>
        </x-ui.card>
        @endif

        <x-ui.card title="Исходы" data-controller="repeater">
            <div class="flex flex-col gap-3" data-repeater-target="list">
                @foreach ($exits as $i => $exit)
                    <div class="box-nested grid gap-2 sm:grid-cols-[1fr_auto]" data-row>
                        @if (!empty($exit['id']))<input type="hidden" name="exits[{{ $i }}][id]" value="{{ $exit['id'] }}">@endif
                        <input name="exits[{{ $i }}][label]" value="{{ $exit['label'] ?? '' }}" class="field-input" placeholder="Надпись на кнопке">
                        <div class="flex gap-2">
                            <select name="exits[{{ $i }}][actor]" class="field-input !w-auto">@foreach (Actor::options() as $k => $l)<option value="{{ $k }}" @selected(($exit['actor'] ?? 'staff') === $k)>{{ $l }}</option>@endforeach</select>
                            <button type="button" class="btn btn-ghost px-2 text-ink-muted" data-action="repeater#remove" aria-label="Убрать"><x-ui.icon name="x" class="size-5"/></button>
                        </div>
                        <select name="exits[{{ $i }}][to_stage_id]" class="field-input sm:col-span-2">
                            <option value="">Куда ведёт</option>
                            @foreach ($targets as $id => $label)<option value="{{ $id }}" @selected((string) ($exit['to_stage_id'] ?? '') === (string) $id)>{{ $label }}</option>@endforeach
                        </select>
                        <input name="exits[{{ $i }}][confirm]" value="{{ $exit['confirm'] ?? '' }}" class="field-input sm:col-span-2" placeholder="Вопрос перед нажатием, если нужен">
                    </div>
                @endforeach
            </div>
            <template data-repeater-target="template">
                <div class="box-nested grid gap-2 sm:grid-cols-[1fr_auto]" data-row>
                    <input name="exits[__i__][label]" class="field-input" placeholder="Надпись на кнопке">
                    <div class="flex gap-2">
                        <select name="exits[__i__][actor]" class="field-input !w-auto">@foreach (Actor::options() as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                        <button type="button" class="btn btn-ghost px-2 text-ink-muted" data-action="repeater#remove" aria-label="Убрать"><x-ui.icon name="x" class="size-5"/></button>
                    </div>
                    <select name="exits[__i__][to_stage_id]" class="field-input sm:col-span-2">
                        <option value="">Куда ведёт</option>
                        @foreach ($targets as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                    </select>
                    <input name="exits[__i__][confirm]" class="field-input sm:col-span-2" placeholder="Вопрос перед нажатием, если нужен">
                </div>
            </template>
            <x-ui.button type="button" variant="ghost" size="sm" class="mt-3" data-action="repeater#add"><x-ui.icon name="plus" class="size-4"/> Исход</x-ui.button>
        </x-ui.card>
    </form>

    <x-ui.action-bar>
        <x-ui.button form="stage-form" class="min-w-0 flex-1">Сохранить</x-ui.button>
        @if ($stage->exists)
            <form method="post" action="/nastroyki/marshruty/etapy/{{ $stage->id }}" data-turbo-confirm="Удалить этап «{{ $stage->name }}»?">@csrf @method('delete')<x-ui.button variant="danger"><x-ui.icon name="trash" class="size-5"/></x-ui.button></form>
        @endif
    </x-ui.action-bar>
</x-ui.shell>
