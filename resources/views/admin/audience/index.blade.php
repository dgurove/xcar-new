{{-- Кому показывать: группы менеджеров и шаблоны волн. Строка — нажатие открывает шторку правки, первая строка
     каждого списка — «новая». Шаблон собирается той же шторкой волн, что у предложения. --}}
<x-ui.cabinet title="Кому показывать">
    <div class="list-head">Группы @if ($groups->isNotEmpty())<span class="nums">{{ $groups->count() }}</span>@endif</div>
    <div class="list">
        @foreach ([null, ...$groups] as $group)
            <div data-controller="sheet" class="contents">
                <button type="button" class="row w-full text-left" data-action="sheet#open">
                    @if ($group)
                        <span class="min-w-0 flex-1"><span class="block font-medium">{{ $group->name }}</span>
                            @if ($group->members->isNotEmpty())<span class="row-sub">{{ $group->members->map->shortName()->implode(', ') }}</span>@endif</span>
                        <span class="nums text-ink-dim">{{ $group->members->count() }}</span>
                    @else
                        <x-ui.row-icon name="plus" tone="accent"/>
                        <span class="min-w-0 flex-1 font-medium">Новая группа</span>
                    @endif
                </button>
                <x-ui.sheet :id="'group-'.($group?->id ?? 'new')" :title="$group ? 'Группа' : 'Новая группа'">
                    <form method="post" action="/settings/audience/groups{{ $group ? '/'.$group->id : '' }}" class="flex flex-col gap-4">
                        @csrf @if ($group) @method('put') @endif
                        <x-ui.field name="name" label="Название" :value="$group?->name" required maxlength="60"/>
                        <div class="field">
                            <span class="field-label">Менеджеры</span>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($managers as $m)
                                    <label class="choice"><input type="checkbox" name="members[]" value="{{ $m->id }}" @checked($group?->members->contains($m))><span class="gap-1.5"><x-ui.avatar :user="$m" :size="20"/>{{ $m->shortName() }}</span></label>
                                @endforeach
                            </div>
                        </div>
                        <x-ui.button block>{{ $group ? 'Сохранить' : 'Добавить' }}</x-ui.button>
                    </form>
                    @if ($group)
                        <form method="post" action="/settings/audience/groups/{{ $group->id }}" class="mt-2" data-turbo-confirm="Удалить группу «{{ $group->name }}»?">
                            @csrf @method('delete')
                            <x-ui.button block variant="danger">Удалить</x-ui.button>
                        </form>
                    @endif
                </x-ui.sheet>
            </div>
        @endforeach
    </div>

    <div class="list-head mt-4">Шаблоны @if ($presets->isNotEmpty())<span class="nums">{{ $presets->count() }}</span>@endif</div>
    <div class="list">
        @foreach ([null, ...$presets] as $preset)
            @php $rules = $preset?->rules ?? \App\Offers\AudienceRules::everyone(); @endphp
            <div data-controller="sheet" class="contents">
                <button type="button" class="row w-full text-left" data-action="sheet#open">
                    @if ($preset)
                        <span class="min-w-0 flex-1"><span class="block font-medium">{{ $preset->name }}</span>@if ($preset->summary() !== $preset->name)<span class="row-sub">{{ $preset->summary() }}</span>@endif</span>
                    @else
                        <x-ui.row-icon name="plus" tone="accent"/>
                        <span class="min-w-0 flex-1 font-medium">Новый шаблон</span>
                    @endif
                </button>
                <x-ui.sheet :id="'preset-'.($preset?->id ?? 'new')" :title="$preset ? 'Шаблон' : 'Новый шаблон'">
                    <form method="post" action="/settings/audience/presets{{ $preset ? '/'.$preset->id : '' }}" class="flex flex-col gap-4"
                        data-controller="audience" data-audience-options-value="{{ json_encode($audienceOptions) }}" data-audience-effective-value="{{ json_encode($rules) }}">
                        @csrf @if ($preset) @method('put') @endif
                        <x-ui.field name="name" label="Название" :value="$preset?->name" required maxlength="60"/>
                        <input type="hidden" name="rules" value="{{ json_encode($rules) }}" data-audience-target="rules">
                        <div>@include('admin.audience.editor')</div>
                        @error('rules')<p class="field-error">{{ $message }}</p>@enderror
                        <x-ui.button block>{{ $preset ? 'Сохранить' : 'Добавить' }}</x-ui.button>
                    </form>
                    @if ($preset)
                        <form method="post" action="/settings/audience/presets/{{ $preset->id }}" class="mt-2" data-turbo-confirm="Удалить шаблон «{{ $preset->name }}»?">
                            @csrf @method('delete')
                            <x-ui.button block variant="danger">Удалить</x-ui.button>
                        </form>
                    @endif
                </x-ui.sheet>
            </div>
        @endforeach
    </div>
</x-ui.cabinet>
