{{-- «Ответственные» (18.11.2026, владелец выбрал вариант 1): вкладки «Предложений» строками по разделам колеса, справа аватары
     ответственных; нажатие — шторка с сотрудниками галками (только кому вкладка открыта). По ним «Только мои» в колесе.
     С 26.11.2026 (`Responsibility`): у отмеченного под именем — «Вся вкладка | Только горящее» (красным счётчиком — всё её дело или
     только просроченное и горящий обязывающий) и «Пуш и Telegram» (уведомления по её строкам; лента — всем); первой строкой —
     горящий срок обязывающего во всех вкладках (красным, без уведомлений и без «Только мои»). Самой первой — «Уведомлять обо всём»
     (27.11.2026, `Responsibility::EVERYTHING`): пуш и Telegram обо всём, как у ответственного за все вкладки. --}}
@php($groups = ['' => [\App\Offers\Responsibility::EVERYTHING => 'Уведомлять обо всём', \App\Offers\Responsibility::BINDING => 'Горящий срок обязывающего во всех вкладках'] + ($groups[''] ?? [])] + $groups)
<x-ui.cabinet title="Ответственные">
    @foreach ($groups as $head => $tabs)
        <div>
        @if ($head !== '')<div class="list-head">{{ $head }}</div>@endif
        <div class="list">
            @foreach ($tabs as $key => $label)
                @php($people = $owners[$key] ?? collect())
                {{-- «Уведомлять обо всём» (27.11.2026) — как горящий срок: не вкладка, только люди галками, без «за что». --}}
                @php($binding = in_array($key, [\App\Offers\Responsibility::BINDING, \App\Offers\Responsibility::EVERYTHING], true))
                <div data-controller="sheet" class="contents">
                    <x-ui.sheet-row>
                        <span class="min-w-0 flex-1 truncate">{{ $label }}</span>
                        @if ($people->isEmpty())
                            {{-- Без ответственного вкладку с «Только мои» не видит никто, кроме главного администратора (24.11.2026); вкладке
                                 ожидания это можно — там нашего дела нет. --}}
                            <span @class(['shrink-0 text-sm', 'text-danger' => ! $binding && ! in_array($key, \App\Offers\DealTabs::WAITING, true), 'text-ink-dim' => $binding || in_array($key, \App\Offers\DealTabs::WAITING, true)])>никто</span>
                        @else
                            <span class="flex shrink-0 gap-1" title="{{ $people->pluck('name')->implode(', ') }}">
                                @foreach ($people->take(3) as $u)<x-ui.avatar :user="$u" :size="26"/>@endforeach
                                @if ($people->count() > 3)<span class="nums grid size-[26px] place-items-center rounded-full bg-surface-2 text-xs text-ink-muted">+{{ $people->count() - 3 }}</span>@endif
                            </span>
                        @endif
                        <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
                    </x-ui.sheet-row>
                    <x-ui.sheet id="tab-owners-{{ $key }}" :title="$label">
                        <form method="post" action="/settings/responsibles/{{ $key }}" class="flex flex-col gap-4">
                            @csrf @method('put')
                            <input type="hidden" name="users[]" value="">
                            <div class="list">
                                @forelse ($staff($key) as $u)
                                    @php($row = $rows[$key][$u->id] ?? null)
                                    <div class="owner-row">
                                        <label class="row row-check">
                                            <x-ui.avatar :user="$u" :size="32"/>
                                            <span class="min-w-0 flex-1 truncate">{{ $u->name }}</span>
                                            <span class="check"><input type="checkbox" name="users[]" value="{{ $u->id }}" @checked($row)></span>
                                        </label>
                                        @unless ($binding)
                                            <div class="owner-opts">
                                                <x-ui.segment :name="'scope['.$u->id.']'" :options="['all' => 'Вся вкладка', 'urgent' => 'Только горящее']" :value="$row?->scope ?? 'all'"/>
                                                <label class="owner-notify">
                                                    <span>Пуш и Telegram</span>
                                                    <input type="checkbox" switch class="switch" name="notify[{{ $u->id }}]" value="1" @checked($row ? $row->notify : true)>
                                                </label>
                                            </div>
                                        @endunless
                                    </div>
                                @empty
                                    <div class="row text-ink-muted">Назначить некого</div>
                                @endforelse
                            </div>
                            <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
                        </form>
                    </x-ui.sheet>
                </div>
            @endforeach
        </div>
        </div>
    @endforeach
</x-ui.cabinet>
