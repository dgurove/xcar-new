{{-- Строка таблицы «Оплаты»: кто (плательщик счёта или менеджер, которому выплачиваем; платит покупатель менеджера —
     менеджер под именем), под ним срок светофором, номер, ТС и «сообщил об оплате»; от 640 номер, ТС и срок встают
     столбцами. Под суммой — кто кому словом (06.10.2026: счета нам и выплаты менеджерам шли одной таблицей без
     направления): «должен нам» / «оплатил», «должны ему» / «выплатили». У частичной — остаток. Нажатие — карточка. --}}
@props(['invoice'])
@php
    use App\Billing\InvoiceState;
    use App\Support\Money;
    $i = $invoice;
    $offer = $i->deal?->offer ?? $i->offer;
    // Разовая оплата — без машины: на месте ТС её услуга.
    $what = $offer?->titleWithYear() ?? ($i->isService() ? $i->charges->first()?->title : null);
    $claim = $i->claims->isNotEmpty();
    $owed = $i->isOwed();
    $due = $i->state === InvoiceState::Issued ? 'до '.$i->due_at->translatedFormat('j M') : ($owed && $i->state === InvoiceState::Paid ? 'выплачено' : mb_strtolower($i->state->label()));
    $tone = match ($i->light()) { 'danger' => 'text-danger', 'urgent' => 'text-urgent', 'open' => 'text-accent-text', default => '' };
    $left = $i->remaining();
    $manager = $i->manager();
    // Платит не сам менеджер (его покупатель) — менеджер под именем плательщика.
    $via = ! $owed && $manager && $manager->party_id !== $i->party_id ? $manager : null;
    $word = match (true) {
        $i->state === InvoiceState::Void => null, // «аннулирован» уже в сроке
        $owed => $i->state === InvoiceState::Paid ? 'выплатили' : 'должны ему',
        default => $i->state === InvoiceState::Paid ? 'оплатил' : 'должен нам',
    };
    // Ссылку открывали, а денег нет — что вышло, цветом прямо в строке (заводится у каждого счёта, сама по себе не новость).
    $link = $i->state === InvoiceState::Issued && ! $owed ? $i->openLink() : null;
    [$linkLine, $linkTone] = $link && ($link->error_at || $link->attempts->isNotEmpty()) ? $link->stateLine() : [null, null];
@endphp
<tr data-detail-key="{{ $i->id }}" data-search-row id="invoice-{{ $i->id }}">
    <td class="grow">
        <x-ui.row-link :key="$i->id"><span class="cell-title"><x-vendor.name :party="$i->party"/></span></x-ui.row-link>
        <span class="cell-sub">
            @if ($via)<x-ui.person :user="$via"/>@endif
            @if ($claim)<span class="text-urgent">сообщил об оплате</span>@endif
            @if ($linkLine)<span class="{{ $linkTone === 'danger' ? 'text-danger' : 'text-urgent' }}">по ссылке {{ $linkLine }}</span>@endif
            <span class="sm:hidden {{ $tone }}">{{ $due }}</span>
            <span class="sm:hidden">{{ $owed ? 'выплата' : $i->label() }}</span>
            @if ($what)<span class="sm:hidden">{{ $what }}</span>@endif
        </span>
    </td>
    <td class="cell-dim hidden sm:table-cell">{{ $owed ? 'выплата' : $i->label() }}</td>
    <td class="cell-dim col-detail-hide hidden lg:table-cell"><span class="block max-w-56 truncate">{{ $what }}</span></td>
    <td class="hidden sm:table-cell {{ $tone }}">{{ $due }}</td>
    <td class="num nums">
        <span class="{{ $owed && $i->state === InvoiceState::Issued ? 'text-accent-text' : '' }}">{{ Money::nums($i->isPartial() ? $left : $i->total) }}</span>
        @if ($word)<span class="cell-sub">{{ $word }}@if ($i->isPartial()) <span class="nums">из {{ Money::nums($i->total) }}</span>@endif</span>@endif
    </td>
    <td class="cell-dim num nums hidden sm:table-cell">@if ($left > 0 && $i->state === InvoiceState::Issued){{ Money::nums($left) }}@endif</td>
</tr>
