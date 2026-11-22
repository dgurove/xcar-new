{{-- Договор, который ещё не готов, — строкой, как будто готов (18.11.2026, владелец: «договор так же», как счёт, только без
     «Сформировать без прописки»): без второй строки (с 22.11.2026), нажатие — шторка «Договор невозможно
     сформировать»: данные покупателя полями тут же (паспорт, скан — `x-billing.buyer-fields`), остальное — строками в свои шторки
     (покупатель, собственник, сумма) или на страницу (ПТС). Под строкой — «Приложить подписанный» (подписали вне системы). --}}
@props(['deal', 'attach' => false])
@php
    $contract = \App\Offers\DealContract::for($deal)->loadMissing(['buyer.party']);
    $missing = $contract->missing();
    // Паспорт приложен, а договору в нём не распознали (18.11.2026): «паспорт приложен, не распознано: дата рождения» вместо «данные
    // покупателя» — как у счёта.
    $unread = $contract->buyer ? \App\Billing\Party::forUser($contract->buyer)->unreadFields() : [];
    $words = $unread
        ? 'паспорт приложен, не распознано: '.implode(', ', [...array_values($unread), ...array_diff($missing, ['данные покупателя'])])
        : 'не указано: '.implode(', ', $missing);
    $id = 'deal-contract-gaps-'.$deal->id;
    $page = '/offers/'.$deal->offer->number;
    $buyer = $contract->buyer;
    $buyerData = $buyer && $deal->isLive() && array_intersect($missing, ['данные покупателя', 'скан паспорта покупателя']);
    $party = $buyerData ? ($buyer->party ?? \App\Billing\Party::forUser($buyer)) : null;
    // Шаблон с прочерками — скачать и заполнить руками (23.11.2026, владелец: «мало ли что бывает»): Word / PDF у строки, «Заполнить…»
    // под ней — та же шторка, что у готового (`x-deal.contract-fill-sheet`). «Отправить как есть» здесь нет: с прочерками не шлём.
    $base = '/work/deals/'.$deal->id;
    $name = preg_replace('/\.pdf$/', '', (new \App\Offers\DkpDocument($contract))->fileName());
    $canFill = $attach && $deal->buyer_id && auth()->user()?->canEditDeals() && \App\Offers\Actions\EditContract::allows($deal);
    $canAttach = $attach && \App\Offers\Actions\AttachSignedContract::allows($deal);
    $fill = 'contract-edit-'.$deal->id;
    // Строки — в свои шторки: событием (`emit`), ПТС — на страницу машины.
    $rows = array_filter([
        in_array('покупатель', $missing, true) ? ['Покупатель', 'не выбран', 'deal-buyer-pick-'.$deal->id.':open', null] : null,
        array_intersect($missing, ['данные продавца', 'данные собственника']) && $contract->form() !== 'ros'
            ? [$deal->isDkp() ? 'Продавец' : 'Собственник', 'нет данных', 'dkp-'.$deal->id.':open', null] : null,
        in_array('данные продавца', $missing, true) && $contract->form() === 'ros' ? ['Реквизиты Р.О.С.', 'нет', null, null] : null,
        in_array('(Э)ПТС', $missing, true) ? ['ПТС', 'не внесён', null, $page.'#car-docs'] : null,
        in_array('цена', $missing, true) ? ['Цена продажи', 'не указана', 'deal-money-'.$deal->id.':open', null] : null,
    ]);
@endphp
<div data-controller="sheet" class="contents">
    <div class="row">
        <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" data-action="sheet#open">
            <x-ui.file-icon name="dkp.pdf"/>
            {{-- Чего нет — не подписью (22.11.2026, владелец: «покупатель, продавец и ПТС уже рядом написаны — подпись не нужна»):
                 строками «Владелец», «ПТС», «Покупатель» рядом, словом «нужны данные» над блоком и списком в шторке. --}}
            <span class="min-w-0 flex-1 truncate">Договор купли-продажи</span>
        </button>
        @if ($attach)<x-deal.dkp-download :deal="$deal" :base="$base" :name="$name"/>@endif
    </div>
    {{-- Подписали вне системы — кнопкой словами под строкой, как у готового договора (`x-deal.contract-doc`; 22.11.2026, владелец:
         «где прикрепление подписанного договора» — тут стояла скрепка справа в строке). --}}
    {{-- Как у готового: «Заполнить…» строкой, под ней «Приложить подписанный» во всю ширину (парой на телефоне не влезали). --}}
    @if ($canFill)
        <div class="send-under"><button type="button" class="btn btn-quiet btn-s w-full gap-2" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $fill }}:open"><x-ui.icon name="edit" class="size-4"/>Заполнить…</button></div>
    @endif
    @if ($canAttach)
        <div class="send-under"><x-deal.attach-signed :deal="$deal" :base="$base" wide/></div>
    @endif
    @if ($canFill)<x-deal.contract-fill-sheet :id="$fill" :base="$base" :name="$name"/>@endif
    <x-ui.sheet :id="$id" title="Договор невозможно сформировать" :open="$errors->hasAny(['buyer.*']) && old('_sheet') === $id" tall>
        <p class="mb-3 text-ink-muted">{{ \Illuminate\Support\Str::ucfirst($words) }}</p>
        @if ($rows)
            <div class="list mb-4">
                @foreach ($rows as [$label, $value, $event, $href])
                    @if ($href)
                        <x-deal.sale-row :label="$label" :value="$value" tone="danger" :href="$href"/>
                    @elseif ($event)
                        <x-deal.sale-row :label="$label" :value="$value" tone="danger" class="w-full text-left" data-controller="emit" data-action="sheet#close emit#send" :data-emit-event-param="$event"/>
                    @else
                        <div class="row sale-row"><span class="sale-row-label">{{ $label }}</span><span class="sale-row-value text-danger">{{ $value }}</span></div>
                    @endif
                @endforeach
            </div>
        @endif
        @if ($buyerData)
            <form method="post" action="/work/deals/{{ $deal->id }}/contract" class="flex flex-col gap-3" id="{{ $id }}-form">
                @csrf @method('put')
                <div class="list-cap !px-0">Покупатель</div>
                <x-billing.buyer-fields :party="$party" :key="'gaps-buyer-'.$deal->id" :scan="$id.'-form'" :fallback="$buyer->name"/>
                <div class="sheet-foot flex flex-col gap-2">
                    <x-ui.button block>Сохранить</x-ui.button>
                    <button type="button" class="btn btn-ghost w-full" data-action="sheet#close">Отмена</button>
                </div>
            </form>
        @else
            <div class="sheet-foot"><button type="button" class="btn btn-ghost w-full" data-action="sheet#close">Отмена</button></div>
        @endif
    </x-ui.sheet>
</div>
