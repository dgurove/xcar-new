{{-- Месяц: принято и выдано ТС, выставлено, оплачено, перечислено вендорам; долги на сегодня. Месяц — в адресе. --}}
@php use App\Support\Money; @endphp
<x-ui.shell :title="mb_convert_case($month->translatedFormat('F Y'), MB_CASE_TITLE, 'UTF-8')" :back="['Деньги', '/money']" narrow>
    <div class="mb-4 flex items-center gap-2">
        <a href="/money/summary?month={{ $month->copy()->subMonth()->format('Y-m') }}" class="btn btn-quiet btn-round btn-s" aria-label="Раньше" data-turbo-action="replace"><x-ui.icon name="chevron-left" class="size-5"/></a>
        <a href="/money/summary?month={{ $month->copy()->addMonth()->format('Y-m') }}" class="btn btn-quiet btn-round btn-s" aria-label="Позже" data-turbo-action="replace"><x-ui.icon name="chevron-right" class="size-5"/></a>
    </div>
    <div class="grid grid-cols-2 gap-2">
        <x-ui.stat :value="$stats['accepted']" label="Принято"/>
        <x-ui.stat :value="$stats['released']" label="Выдано"/>
        <x-ui.stat :value="Money::rub($stats['issued'])" label="Выставлено"/>
        <x-ui.stat :value="Money::rub($stats['paid'])" label="Оплачено"/>
        <x-ui.stat :value="Money::rub($stats['owed'])" label="Должны вендорам"/>
        <x-ui.stat :value="Money::rub($stats['transferred'])" label="Перечислено"/>
    </div>
    <div class="mt-6 grid grid-cols-2 gap-2">
        <x-ui.stat :value="Money::rub($debts->sum('owed_to_us'))" label="Нам должны сейчас" href="/money/debts"/>
        <x-ui.stat :value="Money::rub($debts->sum('unbilled'))" label="Не выставлено" href="/money/debts"/>
    </div>
</x-ui.shell>
