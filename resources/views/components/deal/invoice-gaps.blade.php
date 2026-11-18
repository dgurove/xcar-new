{{-- Счёт за ТС, которого ещё нет, — строкой, как будто он сформирован (18.11.2026, владелец по жалобе начальника «где счёт на тачку,
     они не прикладывают данные»: «в CRM будет отображаться счёт как если бы всё было заполнено, просто при нажатии — окно, что счёт
     невозможно сформировать, пока не указаны: ФИО, адрес прописки; там же можно сразу ввести»). Второй строкой — чего нет; нажатие —
     шторка: недостающие поля покупателя (`Party::invoiceGaps`, у ИП и организации — свои), «Сформировать счёт», «Сформировать без
     прописки» (не хватает только её — `invoice_without_reg_at`), «Отмена». Не поля покупателя (покупатель не выбран, цена,
     вознаграждение, реквизиты Р.О.С.) — словами, покупатель — кнопкой выбора (`x-deal.buyer`, событие `deal-buyer-pick-{id}`). --}}
@props(['deal'])
@php
    use App\Billing\PartyKind;
    $blockers = $deal->invoiceBlockers();
    $buyer = $deal->invoiceBuyer();
    $party = $buyer ? \App\Billing\Party::forUser($buyer) : null;
    $fields = $party ? array_intersect_key($blockers, $party->invoiceGaps()) : [];
    $canEdit = auth()->user()?->canEditDeals() && $deal->isLive();
    $id = 'deal-invoice-gaps-'.$deal->id;
    $onlyReg = $party?->invoiceLacksOnlyReg() && array_keys($fields) === ['reg_address'];
    $company = $party?->kind === PartyKind::Company;
    $words = mb_strtolower(implode(', ', $blockers));
    // Паспорт приложен — так и сказать, и показать скан, чтобы вписать по нему (18.11.2026, владелец: «если паспорт приложен, надо
    // писать, что он приложен»; «не „нет прописки“, а „прописка не распозналась“»).
    $unread = $deal->invoiceGap() === 'unread';
    $reading = ! $unread && $party?->scanState() === 'reading';
    $lead = $unread ? 'паспорт приложен, не распознано: ' : ($reading ? 'паспорт распознаётся, пока нет: ' : 'не указано: ');
    // Цены продажи или вознаграждения нет — форма цены (`x-deal.price-money`); данных договору не хватает — строка в шторку данных.
    $money = (bool) array_intersect_key($blockers, ['price' => 1, 'commission' => 1]);
    $contractData = $buyer && $deal->isLive() && $deal->hasContract() && array_intersect(\App\Offers\DealContract::for($deal)->missing(), ['данные покупателя', 'скан паспорта покупателя']);
    $scanPdf = ($unread || $reading) && $party->getMedia('passport')->isNotEmpty()
        ? ['url' => '/parties/'.$party->id.'/passport.pdf', 'type' => 'pdf', 'name' => \App\Billing\Documents\PassportPdf::fileName($party), 'label' => 'Паспорт'] : null;
@endphp
<div data-controller="sheet" class="contents">
    <button type="button" class="row w-full text-left" data-action="sheet#open">
        <x-ui.file-icon name="schet.pdf"/>
        <span class="min-w-0 flex-1">
            <span class="block truncate">Счёт за ТС</span>
            <span class="row-sub">@if ($blockers)<span class="text-danger">{{ $lead }}{{ $words }}</span>@else<span class="text-ink-muted">не сформирован</span>@endif</span>
        </span>
        <x-ui.chevron/>
    </button>
    <x-ui.sheet :id="$id" title="Счёт невозможно сформировать" :open="$errors->hasAny(['buyer.*']) && old('_sheet') === $id">
        @if ($blockers)
            <p class="mb-3 text-ink-muted">{{ \Illuminate\Support\Str::ucfirst($lead) }}{{ $words }}</p>
            @if ($scanPdf)
                <div class="list mb-3">
                    <x-ui.doc :doc="$scanPdf" class="row"><x-ui.row-icon name="file" size="s" tone="open"/><span class="min-w-0 flex-1">Скан паспорта, PDF</span><x-ui.chevron/></x-ui.doc>
                </div>
            @endif
        @else
            <p class="mb-3 text-ink-muted">Счёт не встал сам — выставьте его в «Деньгах»</p>
        @endif
        @if ($fields && $canEdit)
            {{-- Вид покупателя — сегментом, поля счёта своего вида (18.11.2026, владелец: «тут же должно быть указание типа покупателя,
                 если его нет, и ввод реквизитов»): физлицо — ФИО и прописка, ИП — ФИО, ИНН, ОГРНИП, адрес, организация — название,
                 ИНН, КПП, юридический адрес. У ИП и организации — поле «Реквизиты целиком»: вставили карточку из Word — разложилось по
                 полям (`requisites_paste_controller`). Скрытые группы выключены (`reveal`) и в форму не уходят. --}}
            @php
                $kind = old('buyer.kind', match ($party->kind) { PartyKind::Company => 'company', PartyKind::Entrepreneur => 'ip', default => 'person' });
                $ip = $party->kind === PartyKind::Entrepreneur;
                $own = fn (string $f, bool $mine) => $mine ? $party->{$f} : null;
                $f = fn (string $g, string $k) => $id.'-'.$g.'-'.$k;
            @endphp
            <form method="post" action="/work/deals/{{ $deal->id }}/invoice-data" class="flex flex-col gap-3" id="{{ $id }}-form" data-controller="reveal requisites-paste" data-requisites-paste-prefix-value="buyer">
                @csrf @method('put')
                <x-ui.segment name="buyer[kind]" :options="['person' => 'Физ. лицо', 'ip' => 'ИП', 'company' => 'Юр. лицо']" :value="$kind" pick/>
                <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="person" @if ($kind !== 'person') hidden @endif>
                    <x-ui.field name="buyer[name]" :id="$f('p', 'name')" label="ФИО" :value="$company ? null : $party->name" required/>
                    <x-ui.field name="buyer[reg_address]" :id="$f('p', 'reg')" label="Адрес прописки" type="textarea" rows="1" :value="$party->reg_address" :required="! $onlyReg"/>
                </div>
                <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="ip" data-requisites-paste-target="group" @if ($kind !== 'ip') hidden @endif>
                    <x-ui.field name="_paste" :id="$f('ip', 'paste')" label="Реквизиты целиком" type="textarea" rows="2" placeholder="Скопируйте карточку ИП из Word и вставьте сюда"/>
                    <x-ui.field name="buyer[name]" :id="$f('ip', 'name')" label="ФИО" :value="$company ? null : $party->name" required/>
                    <x-ui.field name="buyer[inn]" :id="$f('ip', 'inn')" label="ИНН" :value="$own('inn', $ip)" placeholder="12 цифр" required/>
                    <x-ui.field name="buyer[ogrn]" :id="$f('ip', 'ogrn')" label="ОГРНИП" :value="$own('ogrn', $ip)" placeholder="15 цифр" required/>
                    <x-ui.field name="buyer[legal_address]" :id="$f('ip', 'addr')" label="Адрес" type="textarea" rows="1" :value="$ip ? ($party->legal_address ?: $party->reg_address) : $party->reg_address" required/>
                </div>
                <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="company" data-requisites-paste-target="group" @if ($kind !== 'company') hidden @endif>
                    <x-ui.field name="_paste" :id="$f('org', 'paste')" label="Реквизиты целиком" type="textarea" rows="2" placeholder="Скопируйте карточку организации из Word и вставьте сюда"/>
                    <x-ui.field name="buyer[org]" :id="$f('org', 'name')" label="Название" :value="$own('name', $company)" required/>
                    <x-ui.field name="buyer[inn]" :id="$f('org', 'inn')" label="ИНН" :value="$own('inn', $company)" required/>
                    <x-ui.field name="buyer[kpp]" :id="$f('org', 'kpp')" label="КПП" :value="$own('kpp', $company)" required/>
                    <x-ui.field name="buyer[legal_address]" :id="$f('org', 'addr')" label="Юридический адрес" type="textarea" rows="1" :value="$own('legal_address', $company)" required/>
                </div>
                <div class="sheet-foot flex flex-col gap-2">
                    <x-ui.button block>Сформировать счёт</x-ui.button>
                    @if ($onlyReg)
                        {{-- Без прописки — только физлицу: у ИП и организации кнопки нет (панель `reveal`). --}}
                        <div data-reveal-target="pane" data-reveal-key="person" @if ($kind !== 'person') hidden @endif>
                            <button type="submit" name="without_reg" value="1" class="btn btn-quiet w-full" formnovalidate>Сформировать без прописки</button>
                        </div>
                    @endif
                    <button type="button" class="btn btn-ghost w-full" data-action="sheet#close">Отмена</button>
                </div>
            </form>
        @elseif ($money && $canEdit)
            {{-- Цены продажи или вознаграждения нет — вписать тут же (18.11.2026, владелец по сделке Алины: «почему не указана цена
                 продажи? вон же 882к»). Сумма договора, вписанная руками, — подсказкой: счёт пойдёт по ней. --}}
            <x-deal.price-money :deal="$deal" submit="Сформировать счёт"/>
        @else
            <div class="sheet-foot flex flex-col gap-2">
                @if (isset($blockers['buyer']) && $canEdit)
                    <button type="button" class="btn btn-accent w-full" data-controller="emit" data-action="sheet#close emit#send" data-emit-event-param="deal-buyer-pick-{{ $deal->id }}:open">Выбрать покупателя</button>
                @endif
                <button type="button" class="btn btn-ghost w-full" data-action="sheet#close">Отмена</button>
            </div>
        @endif
        @if ($contractData && $canEdit)
            <div class="list mt-3">
                <button type="button" class="row w-full text-left" data-controller="emit" data-action="sheet#close emit#send" data-emit-event-param="deal-buyer-data-{{ $deal->id }}:open">
                    <span class="min-w-0 flex-1">Все данные покупателя</span><x-ui.chevron/>
                </button>
            </div>
        @endif
    </x-ui.sheet>
</div>
