{{-- Деньги по сделкам: пресеты, сортировка и поиск в тулбаре, «Взаиморасчёты по менеджерам» строкой над списком,
     сами счета и вознаграждения — только таблицей с окошком; действия в окошке, в строках их нет. --}}
@php use App\Support\ListView; @endphp
<x-ui.shell title="Деньги" :heading="false">
    <x-admin.work-titles current="money" :count="$invoices->total()"/>
    <x-ui.toolbar class="mt-5" :sorts="\App\Http\Admin\MoneyController::SORTS" :sort="$sort" :pills="\App\Http\Admin\MoneyController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" :tones="['claims' => !empty($counts['claims']) ? 'pill-urgent' : '']" name="money">
        <x-slot:filters><input type="search" name="q" value="{{ $q }}" class="field-input" placeholder="Менеджер, № предложения, ТС" enterkeyhint="search"></x-slot:filters>
    </x-ui.toolbar>
    <div class="mt-4 flex flex-col gap-2">
        <a href="/work/money/managers" class="row !py-3"><span class="min-w-0 flex-1 font-medium">Взаиморасчёты по менеджерам</span><x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/></a>
    </div>
    @if ($invoices->isEmpty())
        <x-ui.empty class="mt-6">{{ $q !== '' ? 'Ничего не нашлось' : match ($preset) { 'claims' => 'Никто об оплате не сообщал', 'payouts' => 'Выплачивать нечего', default => 'Счетов нет' } }}</x-ui.empty>
    @else
        <x-ui.table id="invoices" class="mt-6" :open="request('peek')">
            <x-slot:head><tr><th>№</th><th class="grow">Менеджер</th><th class="hidden sm:table-cell">Срок</th><th class="num">Сумма</th><th class="num hidden sm:table-cell">Остаток</th></tr></x-slot:head>
            @foreach ($invoices as $i)<x-money.table-row :invoice="$i"/>@endforeach
        </x-ui.table>
    @endif
    @if ($invoices->hasPages())<div class="mt-8"><x-ui.pager :of="$invoices" :sizes="ListView::PER_ROWS"/></div>@endif
</x-ui.shell>
