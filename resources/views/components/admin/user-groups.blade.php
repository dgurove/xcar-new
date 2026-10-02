{{-- Группы над списком людей в «Пользователях» (вместо раздела «Кому показывать», 02.10.2026): в «Менеджерах» — группы
     менеджеров для волн показа, в «Сотрудниках» — группы модераторов, что видят и правят предложения друг друга.
     Строка — название, кто в ней и сколько; нажатие — шторка: название, участники чипами, «Удалить». Первая — «Новая группа». --}}
@props(['groups', 'kind'])
@php
    use App\Users\{User, UserGroup};
    $people = User::where('role', UserGroup::roleOf($kind))->orderBy('name')->get();
    $who = $kind === UserGroup::MODERATORS ? 'Модераторы' : 'Менеджеры';
@endphp
<div class="list-head mt-6">Группы @if ($groups->isNotEmpty())<span class="nums">{{ $groups->count() }}</span>@endif</div>
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
            <x-ui.sheet :id="'group-'.($group?->id ?? 'new-'.$kind)" :title="$group?->name ?? 'Новая группа'">
                <form method="post" action="/settings/users/groups{{ $group ? '/'.$group->id : '' }}" class="flex flex-col gap-4">
                    @csrf @if ($group) @method('put') @else <input type="hidden" name="kind" value="{{ $kind }}"> @endif
                    <x-ui.field name="name" label="Название" :value="$group?->name" required maxlength="60"/>
                    <div class="field">
                        <span class="field-label">{{ $who }}</span>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($people as $p)
                                <label class="choice"><input type="checkbox" name="members[]" value="{{ $p->id }}" @checked($group?->members->contains($p))><span class="gap-1.5"><x-ui.avatar :user="$p" :size="20"/>{{ $p->shortName() }}</span></label>
                            @endforeach
                        </div>
                    </div>
                    <x-ui.button block>{{ $group ? 'Сохранить' : 'Добавить' }}</x-ui.button>
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
<div class="list-head mt-6">{{ $kind === UserGroup::MODERATORS ? 'Сотрудники' : 'Менеджеры' }}</div>
