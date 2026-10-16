{{-- Группы над списком людей в «Пользователях» (вместо раздела «Кому показывать», 02.10.2026): в «Менеджерах» — группы
     менеджеров для волн показа, в «Сотрудниках» — группы модераторов, что видят и правят предложения друг друга.
     Строка — название, кто в ней и сколько; нажатие — шторка: название, участники чипами, «Удалить». Первая — «Новая группа».
     07.10.2026: поля строками (.fields), кнопка .sheet-foot. --}}
@props(['groups', 'kind'])
@php
    use App\Users\{User, UserGroup};
    $people = User::withRole(UserGroup::roleOf($kind))->orderBy('name')->get();
    $who = $kind === UserGroup::MODERATORS ? 'Модераторы' : 'Менеджеры';
@endphp
{{-- Заголовок и плашка — один блок: между блоками экрана отступ ставит сам экран (gap), своих отступов тут нет. --}}
<section>
<div class="list-head pt-0">Группы @if ($groups->isNotEmpty())<span class="nums">{{ $groups->count() }}</span>@endif</div>
<div class="list">
    @foreach ([null, ...$groups] as $group)
        <div data-controller="sheet" class="contents">
            <x-ui.sheet-row>
                @if ($group)
                    @php $names = [...($group->with_super ? [\App\Offers\AudienceRules::SUPER_NAME] : []), ...$group->members->map->shortName()]; @endphp
                    <span class="min-w-0 flex-1"><span class="block font-medium">{{ $group->name }}</span>
                        @if ($names)<span class="row-sub">{{ implode(', ', $names) }}</span>@endif</span>
                    <span class="nums text-ink-dim">{{ count($names) }}</span>
                @else
                    <x-ui.row-icon name="plus" tone="accent"/>
                    <span class="min-w-0 flex-1 font-medium">Новая группа</span>
                @endif
            </x-ui.sheet-row>
            <x-ui.sheet :id="'group-'.($group?->id ?? 'new-'.$kind)" :title="$group?->name ?? 'Новая группа'">
                <form method="post" action="/settings/users/groups{{ $group ? '/'.$group->id : '' }}" class="flex flex-col gap-3">
                    @csrf @if ($group) @method('put') @else <input type="hidden" name="kind" value="{{ $kind }}"> @endif
                    <div class="fields">
                        <x-ui.field name="name" label="Название" :value="$group?->name" required maxlength="60"/>
                    </div>
                    <div class="field">
                        <span class="field-label">{{ $who }}</span>
                        <div class="flex flex-wrap gap-1.5">
                            {{-- Супер-покупатели — один участник на всех (AudienceRules::SUPER). --}}
                            @if ($kind === UserGroup::MANAGERS)
                                <label class="choice"><input type="checkbox" name="members[]" value="{{ \App\Offers\AudienceRules::SUPER }}" @checked($group?->with_super)><span class="gap-1.5"><x-ui.icon name="users" class="size-4"/>{{ \App\Offers\AudienceRules::SUPER_NAME }}</span></label>
                            @endif
                            @foreach ($people as $p)
                                <label class="choice"><input type="checkbox" name="members[]" value="{{ $p->id }}" @checked($group?->members->contains($p))><span class="gap-1.5"><x-ui.avatar :user="$p" :size="20"/>{{ $p->shortName() }}</span></label>
                            @endforeach
                        </div>
                    </div>
                    <x-ui.sheet-foot>{{ $group ? 'Сохранить' : 'Добавить' }}</x-ui.sheet-foot>
                </form>
                @if ($group)
                    <form method="post" action="/settings/users/groups/{{ $group->id }}" class="mt-2" data-turbo-confirm="Удалить группу «{{ $group->name }}»?">
                        @csrf @method('delete')
                        <x-ui.button block variant="danger">Удалить</x-ui.button>
                    </form>
                @endif
            </x-ui.sheet>
        </div>
    @endforeach
</div>
</section>
