{{-- «Оценить» у неоценённого черновика (админу): чип-кнопка там, где должна стоять цена продажи — в таблице, плитках и
     строках. Ведёт в карточку таблицы на этой строке (цена → «В продажу», и сразу следующий неоценённый); список, пресет
     и сортировка остаются те же, вид — таблица, страницы нет: таблица целиком на одной. Предзагрузка ни к чему.
     label «Заполнить» — то же у закупочной во вкладке «Без закупочной цены».
     Отдано менеджерам на оценку (11.10.2026): оценил — вместо «Оценить» лаймовая пилюля с аватаром и суммой (лучшая, «+1»
     у второй), ждём — аватары и срок серым (вышел — красным «просрочено»); склонять имя не нужно. --}}
@props(['offer', 'label' => 'Оценить'])
@php
    $href = '/?'.http_build_query(array_filter(array_merge(request()->query(), ['vid' => 'table', 'peek' => $offer->number, 'page' => null]), fn ($v) => is_string($v) || is_int($v) ? $v !== '' : false));
    $estimates = $offer->relationLoaded('openEstimates') ? $offer->openEstimates : collect();
    $given = $estimates->filter->isGiven()->sortByDesc('amount')->values();
    $waiting = $estimates->reject->isGiven()->sortBy('due_at')->values();
@endphp
@if ($given->isNotEmpty())
    @php $best = $given->first(); @endphp
    <a href="{{ $href }}" {{ $attributes->class(['pill pill-accent nums !min-h-6 !py-0 !pl-1 !pr-3 gap-1.5 text-sm']) }} data-turbo-action="replace" data-turbo-prefetch="false" title="{{ $given->map(fn ($e) => $e->user->shortName().' '.\App\Support\Money::rub($e->amount))->implode(', ') }}"><x-ui.avatar :user="$best->user" :size="18"/>{{ \App\Support\Money::nums($best->amount) }}@if ($given->count() > 1)<span class="opacity-70">+{{ $given->count() - 1 }}</span>@endif</a>
@elseif ($waiting->isNotEmpty())
    @php $first = $waiting->first(); @endphp
    <a href="{{ $href }}" {{ $attributes->class(['inline-flex items-center gap-1.5 text-sm']) }} data-turbo-action="replace" data-turbo-prefetch="false" title="Оценивает: {{ $waiting->map(fn ($e) => $e->user->shortName())->implode(', ') }}">
        <span class="flex -space-x-1.5">@foreach ($waiting->take(3) as $e)<x-ui.avatar :user="$e->user" :size="18" class="ring-2 ring-surface"/>@endforeach</span>
        <x-offer.estimate-left :estimate="$first" word="" short/>
    </a>
@else
<a href="{{ $href }}" {{ $attributes->class(['pill pill-accent !min-h-6 !py-0 !px-3 text-sm']) }} data-turbo-action="replace" data-turbo-prefetch="false">{{ $label }}</a>
@endif
