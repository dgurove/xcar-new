{{-- «Настройки → Таймеры» (19.01.2027, `Admin\TimerController`): сроки этапов всех вендоров одной таблицей. ПК — строка этапа,
     столбцы вендоров; ячейка — срок, «7 сейчас, 5 просрочено», «обычно 6 д» (`StageStats`), нажатие — шторка срока. Телефон —
     строки этапов списком, нажатие — шторка этапа со всеми вендорами. «Всем вендорам» — один срок строке целиком. Новый срок
     доходит до сделок на этапе (галка, по умолчанию да). --}}
@php
    use App\Workflow\DeadlineSource;
    use App\Workflow\Stage;
    use App\Workflow\StageStats;
    $own = fn (Stage $s) => $s->deadline_source === DeadlineSource::Own;
    $now = fn (Stage $s) => (int) ($counts[$s->id]->n ?? 0);
    $late = fn (Stage $s) => (int) ($counts[$s->id]->late ?? 0);
    // В таблице чужой источник — коротко: «срок приёма», «срок страховой».
    $short = fn (Stage $s) => match ($s->deadline_source) { DeadlineSource::BidsClose => 'срок приёма', DeadlineSource::InsurerDeadline => 'срок страховой', default => $s->limitLabel() ?? 'без срока' };
    $usual = fn (Stage $s) => StageStats::usual($stats[$s->workflow->vendor_id][$s->name] ?? null);
@endphp
<x-ui.cabinet title="Таймеры">
    @if ($rows === [])
        <x-ui.empty>Маршрутов со сроками нет</x-ui.empty>
    @else
        {{-- ПК: таблица. --}}
        <div class="hidden overflow-x-auto md:block">
            <x-ui.table id="timers">
                <x-slot:head>
                    <tr>
                        <th class="min-w-56">Этап</th>
                        @foreach ($workflows as $w)<th class="w-32 whitespace-normal"><x-vendor.name :vendor="$w->vendor"/></th>@endforeach
                    </tr>
                </x-slot:head>
                @foreach ($rows as $r)
                    <tr>
                        <td class="min-w-56 align-top whitespace-normal">
                            <span class="block">{{ $r['name'] }}</span>
                            <span class="block text-sm text-ink-muted">{{ $r['block'] }}@if (count($r['cells']) > 1 && collect($r['cells'])->contains($own)), <a href="?row={{ urlencode($r['name']) }}" class="text-accent-text">всем вендорам</a>@endif</span>
                        </td>
                        @foreach ($workflows as $w)
                            @php $s = $r['cells'][$w->id] ?? null; @endphp
                            <td class="w-32 align-top whitespace-normal">
                                @if ($s)
                                    @if ($own($s))<a href="?edit={{ $s->id }}" class="block">@endif
                                        <span class="block nums {{ $own($s) ? 'underline decoration-dotted underline-offset-4' : 'text-ink-muted' }}">{{ $short($s) }}</span>
                                        @if ($now($s))<span class="block text-sm text-ink-muted">{{ $now($s) }} сейчас@if ($late($s)), <span class="text-danger">{{ $late($s) }} просрочено</span>@endif</span>@endif
                                        @if ($u = $usual($s))<span class="block text-sm text-ink-dim">{{ $u }}</span>@endif
                                    @if ($own($s))</a>@endif
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </x-ui.table>
        </div>

        {{-- Телефон: этап строкой, сроки вендоров — строкой текста; нажатие — шторка этапа. --}}
        <div class="list md:hidden">
            @foreach ($rows as $r)
                @php $lateAll = collect($r['cells'])->sum($late); @endphp
                <a href="?row={{ urlencode($r['name']) }}" class="row">
                    <span class="min-w-0 flex-1">
                        <span class="block">{{ $r['name'] }}</span>
                        <span class="row-sub">{{ collect($r['cells'])->map($short)->unique()->implode(', ') }}@if ($lateAll), <span class="text-danger">{{ $lateAll }} просрочено</span>@endif</span>
                    </span>
                    <x-ui.chevron/>
                </a>
            @endforeach
        </div>
    @endif

    {{-- Срок одного этапа одного вендора. --}}
    @if ($edit && $own($edit))
        <div class="contents" data-controller="sheet">
            <x-ui.sheet id="timer-edit" :title="$edit->name" :open="true">
                <p class="-mt-2 mb-4 text-sm text-ink-muted"><x-vendor.name :vendor="$edit->workflow->vendor"/></p>
                <form method="post" action="/settings/timers/{{ $edit->id }}" class="flex flex-col gap-4">
                    @csrf @method('put')
                    @include('admin.timers.limit-fields', ['stage' => $edit, 'occupied' => (int) ($counts[$edit->id]->n ?? 0)])
                    <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </div>
    @endif

    {{-- Строка целиком: вендоры строками (нажатие — свой срок), ниже один срок всем. --}}
    @if ($row)
        <div class="contents" data-controller="sheet">
            <x-ui.sheet id="timer-row" :title="$row['name']" :open="true" tall>
                <div class="list">
                    @foreach ($row['cells'] as $s)
                        @if ($own($s))<a href="?edit={{ $s->id }}" class="row">@else<div class="row">@endif
                            <span class="min-w-0 flex-1"><x-vendor.name :vendor="$s->workflow->vendor"/>
                                <span class="row-sub">@if ($now($s)){{ $now($s) }} сейчас@if ($late($s)), <span class="text-danger">{{ $late($s) }} просрочено</span>@endif @endif @if ($u = $usual($s)){{ $now($s) ? ', ' : '' }}{{ $u }}@endif</span>
                            </span>
                            <span class="shrink-0 nums">{{ $s->limitLabel() ?? 'без срока' }}</span>
                            @if ($own($s))<x-ui.chevron/>@endif
                        @if ($own($s))</a>@else</div>@endif
                    @endforeach
                </div>
                @if (collect($row['cells'])->filter($own)->count() > 1)
                    <form method="post" action="/settings/timers/row" class="mt-6 flex flex-col gap-4">
                        @csrf @method('put')
                        <input type="hidden" name="name" value="{{ $row['name'] }}">
                        <div class="list-cap">Всем вендорам</div>
                        @include('admin.timers.limit-fields', ['stage' => collect($row['cells'])->first($own), 'occupied' => collect($row['cells'])->filter($own)->sum($now)])
                        <x-ui.sheet-foot>Всем вендорам</x-ui.sheet-foot>
                    </form>
                @endif
            </x-ui.sheet>
        </div>
    @endif
</x-ui.cabinet>
