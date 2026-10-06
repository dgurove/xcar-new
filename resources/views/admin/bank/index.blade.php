{{-- Поступления из выписки Сбера: что легло в счёт само, что ждёт руки, что «не наше». Только таблица с карточкам,
     действия (привязать к счёту, «не наше») — в карточке. --}}
@php use App\Support\ListView; $view = ListView::fromRequest(request()) === ListView::WIDE ? ListView::WIDE : ListView::TABLE; @endphp
<x-ui.shell title="Выписка" :back="['Банк', '/settings/bank']" :detail="$detail">
    <x-ui.toolbar :sort="$sort" :pills="\App\Http\Admin\BankController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" :tones="['unmatched' => !empty($counts['unmatched']) ? 'pill-urgent' : '']" name="bank" search="Плательщик, ИНН, назначение">
        <x-slot:extra><x-ui.view-switch :views="[ListView::TABLE, ListView::WIDE]" :current="$view"/></x-slot:extra>
    </x-ui.toolbar>
    @if (! $connection->connected())
        <div class="list mt-4"><a href="/settings/bank" class="row"><span class="min-w-0 flex-1">СберБизнес не подключён</span><x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/></a></div>
    @endif
    <div id="list">
    @if ($transactions->isEmpty())
        <x-ui.empty class="mt-6">{{ $q !== '' ? 'Ничего не нашлось' : ($preset === 'unmatched' ? 'Все поступления разобраны' : 'Поступлений нет') }}</x-ui.empty>
    @else
        <x-ui.table id="bank" class="mt-6" :view="$view">
            <x-slot:head><tr><x-ui.th :sort="$sort" key="payer" class="grow">Плательщик</x-ui.th><th class="cell-dim col-detail-hide hidden lg:table-cell">Назначение</th><x-ui.th :sort="$sort" key="booked" class="hidden sm:table-cell">Дата</x-ui.th><x-ui.th :sort="$sort" key="amount" class="num">Сумма</x-ui.th></tr></x-slot:head>
            @foreach ($transactions as $tx)<x-bank.table-row :tx="$tx"/>@endforeach
        </x-ui.table>
    @endif
    @if ($transactions->hasPages())<div class="mt-8"><x-ui.pager :of="$transactions" :sizes="ListView::PER_ROWS"/></div>@endif
    </div>
</x-ui.shell>
