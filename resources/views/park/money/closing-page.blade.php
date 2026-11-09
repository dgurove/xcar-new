{{-- Документ «Счетов и актов» на всю страницу: в строке заголовка — логотип, плательщик, состояние словом, справа сумма и
     действия (`closing-actions`); плашки — только когда нужен наш ход (`closing-alerts`); под ними ТС документа тем же
     списком, что «Наличие → Выданы» (06.11.2026, владелец: «ровно точно такую же»): `park.vehicles.list` — чипы, сортировка,
     лупа, виды, окошко ТС, плюс «Акт» и «Сумма» документа (`VehicleController::listing`). --}}
@php use App\Support\Money; $d = $doc; @endphp
<x-ui.shell :title="$d->name.', '.$d->what.' за '.mb_strtolower($d->month->translatedFormat($d->month->isSameYear(now()) ? 'F' : 'F Y'))" :back="['Счета и акты', '/money/closing#m'.$d->month->format('Y-m')]" cache="no-cache" :detail="$detail">
    <x-slot:lead>@if ($d->vendor)<x-vendor.logo :vendor="$d->vendor"/>@endif</x-slot:lead>
    <x-slot:badge><span class="title-state {{ $d->tone() }}">{{ $d->state === 'send' ? 'не отправлен' : $d->word() }}</span></x-slot:badge>
    <x-slot:actions class="doc-acts">@include('park.money.closing-actions')</x-slot:actions>
    @include('park.money.closing-alerts')
    {{-- Переписка по документу (09.11.2026, владелец: «удобно видеть переписку по этой цепочке, писать и отвечать на их
         вопросы»): письма веток с этим документом (`mail_threads.closing_key`) строками, нажатие — окно на письме, «Ответить» —
         редактор в ветке. Нет писем — блока нет. --}}
    @if ($letters->isNotEmpty())
        <section class="mt-4 max-w-3xl">
            <h2 class="detail-section">Переписка <span class="nums font-normal text-ink-dim">{{ \App\Mail\Chains\NodeTitle::count($letters) }}</span></h2>
            <x-mail.letters :messages="$letters" :url="'/money/closing/'.$d->key.'/letters'"/>
        </section>
    @endif
    <x-ui.toolbar :sort="$sort" :facets="$facets" name="closing-cars" search="Номер, VIN, госномер, СТС, марка" search-target="#cars" :q="$q" class="mt-4">
        <x-slot:extra><x-ui.view-switch :current="$view" :views="\App\Support\ListView::ALL"/></x-slot:extra>
    </x-ui.toolbar>
    <div class="mt-4" id="cars">@include('park.vehicles.list')</div>
    <x-mail.window/>
    <x-park.act-window/>
</x-ui.shell>
