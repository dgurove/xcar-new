{{-- «Ответственные» (18.11.2026, владелец выбрал вариант 1): вкладки «Предложений» строками по разделам колеса, справа аватары
     ответственных; нажатие — шторка с сотрудниками галками (только кому вкладка открыта). По ним «Только мои» в колесе. --}}
<x-ui.cabinet title="Ответственные">
    @foreach ($groups as $head => $tabs)
        <div>
        @if ($head !== '')<div class="list-head">{{ $head }}</div>@endif
        <div class="list">
            @foreach ($tabs as $key => $label)
                @php($people = $owners[$key] ?? collect())
                <div data-controller="sheet" class="contents">
                    <x-ui.sheet-row>
                        <span class="min-w-0 flex-1 truncate">{{ $label }}</span>
                        @if ($people->isEmpty())
                            {{-- Без ответственного вкладку с «Только мои» не видит никто, кроме главного администратора (24.11.2026). --}}
                            <span class="shrink-0 text-sm text-danger">никто</span>
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
                                    <label class="row row-check">
                                        <x-ui.avatar :user="$u" :size="32"/>
                                        <span class="min-w-0 flex-1 truncate">{{ $u->name }}</span>
                                        <span class="check"><input type="checkbox" name="users[]" value="{{ $u->id }}" @checked($people->contains('id', $u->id))></span>
                                    </label>
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
