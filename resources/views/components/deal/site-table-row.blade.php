{{-- Строка таблицы «Сделок» сайта (18.11.2026, владелец: «сделки и гараж — по умолчанию вид табличный», как в CRM). Одна линия:
     значок типа ТС и название; «Что сейчас» — один факт (правило строк CRM, 17.11.2026): дело менеджера его словами зелёным,
     просрочено — оно же красным, без слова «просрочено»; чужой ход — этап серым (кого ждём, говорит раздел); срок — числом
     своим столбцом (сколько осталось, просрочено или ждём); справа сумма и кнопка дела (`x-deal.ask-action`). На телефоне:
     название с суммой концом строки, под ним факт со сроком, кнопка дела — своей строкой (строка без кнопки справа).
     Админу — чья сделка, строка ведёт в CRM: там он её ведёт. Гаражная — словом «гараж» у названия, ведёт в машину гаража.
     Столбцы — `Cabinet\DealController::table`; `task` — `Deal::managerTask()`, посчитан контроллером один раз. --}}
@props(['deal', 'table', 'task' => null, 'staff' => false, 'inGarage' => false])
@php
    use App\Support\Ago; use App\Support\Money; use App\Users\Wording;
    $offer = $deal->offer;
    $position = $offer->position();
    $garage = $deal->isGarage();
    $href = $staff ? $deal->crmUrl() : ($garage ? $deal->href() : '/deals/'.$deal->id);
    $amount = Money::rub($garage ? $deal->cost : $deal->amount);
    $req = $deal->openRequirement;
    $paidUp = $req && $position?->stage->isPayStep() && $deal->fullyPaid();
    $late = $task ? (bool) $task['due']?->isPast() : false;
    // Что сейчас — словом и цветом.
    [$fact, $tone] = match (true) {
        $deal->declinedBySupplier() => ['Поставщик отказал в продаже', 'text-danger'],
        $task !== null => [Wording::mine($task['title']), $late ? 'text-danger' : 'text-accent-text'],
        $paidUp => ['Оплата получена', 'text-ink-muted'],
        $position !== null => [Wording::mine($position->stage->block?->name ?? $position->stage->name), 'text-ink-muted'],
        default => ['Идёт работа', 'text-ink-muted'],
    };
    // Срок числом: у дела — сколько осталось (вышел — сколько просрочено, красным), у чужого хода — сколько ждём. Числа —
    // готовым текстом, без таймера на строку: шаг у них — час, а два контроллера на каждую из сотни строк возврат в раздел
    // из снимка делали вдвое дольше (сторож `nav-audit`: 474 мс); список и так перечитывается при возврате и по событию.
    $term = match (true) {
        $task !== null && $late => '<span class="nums whitespace-nowrap text-danger" title="Просрочено на">'.e(Ago::since($task['due'], hours: true)).'</span>',
        $task !== null && $task['due'] !== null => '<span class="nums whitespace-nowrap text-ink-muted" title="Осталось">'.e(Ago::left($task['due'], '')).'</span>',
        $task === null && ! $paidUp && $position?->block_entered_at !== null => '<span class="nums whitespace-nowrap text-ink-muted" title="На этапе">'.e(Ago::since($position->block_entered_at, hours: true)).'</span>',
        default => '',
    };
    // «Ваш ход» — с кнопкой ответа прямо в строке (10.11.2026): «Беру в работу», «Автомобиль забрал» — сразу, остальное — ссылкой на шаг.
    $ask = $task && ! $staff ? \App\Offers\ManagerAsk::for($deal) : null;
@endphp
<tr id="deal-{{ $deal->id }}" data-detail-key="{{ $deal->id }}" data-search-row>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <a href="{{ $href }}" class="row-link" @if ($staff) data-turbo="false" @endif><span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/><span class="cell-name">{{ $offer->title() }}@if ($year = $offer->carRecord()->year)<span class="cell-year"> {{ $year }}</span>@endif</span>@if ($garage && ! $inGarage)<span class="ms-1.5 whitespace-nowrap text-sm text-ink-muted">гараж</span>@endif<span class="line-end sm:hidden">{{ $amount }}</span></span></a>
        <span class="cell-sub cell-sub--wrap sm:hidden"><span class="{{ $tone }}">{{ $fact }}</span>@if ($term !== '')<span class="line-end">{!! $term !!}</span>@endif{{ "" }}@if ($staff && $deal->buyer)<span class="md:hidden">{{ $deal->buyer->shortName() }}</span>@endif</span>
        @if ($ask)<x-deal.ask-action :ask="$ask" variant="row" class="row-ask row-ask--cell sm:!hidden"/>@endif
    </td>
        @break
        @case('state')
    <td class="{{ $col->classes() }}"><span class="{{ $tone }} whitespace-normal">{{ $fact }}</span></td>
        @break
        @case('manager')
    <td class="{{ $col->classes() }}"><x-cell.person :user="$deal->buyer" from="lg"/></td>
        @break
        @case('amount')
    <td class="{{ $col->classes() }}">{{ $amount }}</td>
        @break
        @case('term')
    <td class="{{ $col->classes() }}">{!! $term !!}</td>
        @break
        @case('action')
    <td class="{{ $col->classes() }}">@if ($ask)<x-deal.ask-action :ask="$ask" variant="row" class="row-ask row-ask--cell"/>@endif</td>
        @break
        @endswitch
    @endforeach
</tr>
