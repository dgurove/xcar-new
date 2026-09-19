{{-- «Из писем» — один экран на CRM и стоянку: кандидат — одна ТС со всеми письмами о ней. Пресеты по состоянию,
     сортировка, поиск и вендор в фильтрах, три вида (плитки/строки — x-mail.candidate-card, таблица —
     x-mail.candidate-row с окошком). Что куда ведёт — из контроллера ($base, $mail, $park). --}}
@php use App\Support\ListView; $view = ListView::pick(request(), $candidates->total()); @endphp
<x-ui.shell title="Из писем" :count="$candidates->total()">
    <x-ui.toolbar :sorts="\App\Http\Admin\CandidateController::SORTS" :sort="$sort" :pills="$presets" :pill="$preset" pill-param="preset" :counts="$counts" :hidden="array_filter(['vendor' => $vendor, ListView::PARAM => request(ListView::PARAM)])" name="candidates">
        <x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra>
        <x-slot:filters>
            <input type="search" name="q" value="{{ $q }}" class="field-input" placeholder="Убыток, VIN, госномер, тема" enterkeyhint="search">
            @if ($vendors->isNotEmpty())<select name="vendor" class="field-input"><option value="">Все вендоры</option>@foreach ($vendors as $id => $name)<option value="{{ $id }}" @selected((string) $vendor === (string) $id)>{{ $name }}</option>@endforeach</select>@endif
        </x-slot:filters>
    </x-ui.toolbar>
    @if ($candidates->isEmpty())
        <x-ui.empty class="mt-6">{{ $q !== '' || $vendor ? 'Ничего не нашлось' : ($park ? 'Писем о хранении нет' : 'Писем с предложениями нет') }}</x-ui.empty>
    @elseif ($view === ListView::TABLE)
        <x-ui.table id="candidates" class="mt-6">
            <x-slot:head>
                <tr>
                    <th>№ убытка</th><th class="grow">Марка, модель</th><th class="hidden sm:table-cell">VIN</th><th class="hidden sm:table-cell">Вендор</th>
                    <th class="num">Писем</th><th class="num">Последнее</th><th class="num hidden sm:table-cell">Ответ до</th>
                </tr>
            </x-slot:head>
            @foreach ($candidates as $c)<x-mail.candidate-row :c="$c" :base="$base"/>@endforeach
        </x-ui.table>
    @else
        <div class="mt-6 {{ ListView::containerClass($view) }}">
            @foreach ($candidates as $c)<x-mail.candidate-card :c="$c" :base="$base" :mail="$mail" :park="$park"/>@endforeach
        </div>
    @endif
    <div class="mt-8"><x-ui.pager :of="$candidates" :sizes="ListView::perSizes($view)"/></div>
</x-ui.shell>
