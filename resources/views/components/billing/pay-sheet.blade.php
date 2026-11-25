{{-- «Оплатить» у менеджера, как перевод в банковском приложении: способ сегментом (ссылкой, по счёту, наличными), сумма
     крупно по центру. У ссылки плательщика нет (18.11.2026, владелец: «плательщика ваще вырезаем… по ссылке просто почта»):
     «Получить ссылку», и она тут же, с «Скопировать» и «Отправить»; почту для чека вводит тот, кто платит, на `/pay`.
     По счёту — PDF с QR строкой документа, дата, номер и платёжка (у комиссии без счёта этого способа нет); наличными — дата.
     Поля в скрытых панелях reveal выключает; обязательность проверяет сервер, `required` на них заблокировал бы отправку.
     07.10.2026: поля строками (.fields), кнопка .sheet-foot. --}}
@props(['id' => 'pay', 'invoices', 'action', 'pdf', 'buyers' => collect(), 'open' => false, 'otherName' => null, 'offline' => false, 'pick' => null, 'only' => false])
@php
    use App\Support\Money; use App\Billing\Acquiring\PayLink;
    $first = $invoices->first();
    $one = $invoices->count() === 1;
    // Без договора эквайринга способа «По ссылке» нет вовсе. Ссылка у счёта уже есть (заводится вместе с ним на менеджера) —
    // «По ссылке» всё равно здесь: выбрать, кто платит (05.10.2026, владелец), новая заменит прежнюю.
    // У счёта ПРАЙМ по сделке ссылки нет вовсе (Совкомбанк: «ссылка не используется», `PayLink::eligible`).
    // offline — ссылка уже стоит рядом (расчёт сделки): здесь только «по счёту» и «наличными».
    $online = ! $offline && app(\App\Billing\Acquiring\Gateway::class)->configured() && $invoices->every(fn ($i) => PayLink::eligible($i));
    $current = $one ? $first->openLink() : null;
    $left = $one ? max(0, round($first->remaining() - $first->claimed(), 2)) : null;
    $max = (float) config('xcar.yookassa.max_amount');
    $fmt = fn ($v) => Money::nums($v, fmod($v, 1) ? 2 : 0);
    // Поле не зовётся `method`: оно перекрыло бы form.method, и Turbo с обработчиками отправки ломались бы.
    // pick + only (15.11.2026, квитанция платежа `cabinet.deals.payment`): способ выбран строкой «Как оплатить» — шторка сразу на
    // нём, без сегмента и без панелей других способов.
    // Комиссия без счёта (18.11.2026) — только ссылкой или наличными: «По счёту» нет, пока счёт не запросили.
    $transfer = $invoices->every(fn ($i) => $i->isDocument());
    $way = $only && $pick ? $pick : old('way', $pick ?? ($online ? 'link' : ($transfer ? 'transfer' : 'cash')));
    $ways = array_filter(['link' => $online ? ['По ссылке', 'Получить ссылку'] : null, 'transfer' => $transfer ? ['По счёту', 'Я оплатил'] : null, 'cash' => ['Наличными', 'Отдал наличными']]);
    $me = auth()->user();
@endphp
<x-ui.sheet :id="$id" :title="$only ? (['link' => 'Оплата онлайн', 'transfer' => 'Перевод по счёту', 'cash' => 'Наличными'][$way] ?? 'Оплатить') : 'Оплатить'" :open="$open">
    <form method="post" action="{{ $action }}" enctype="multipart/form-data" class="flex flex-col gap-4" data-controller="reveal pay-amount autosubmit">
        @csrf
        @if ($only)
            <input type="hidden" name="way" value="{{ $way }}">
        @else
        <div class="segment">
            @foreach ($ways as $k => [$label, $verb])
                <label><input type="radio" name="way" value="{{ $k }}" @checked($way === $k) data-action="reveal#pick"><span>{{ $label }}</span></label>
            @endforeach
        </div>
        @endif

        @if ($one)
            <input type="hidden" name="invoice" value="{{ $first->id }}">
        @else
            <div class="fields">
                <x-ui.field name="invoice" label="Счёт" :value="old('invoice', $first->id)" :options="$invoices->mapWithKeys(fn ($i) => [$i->id => $i->label().', к оплате '.Money::rub($i->remaining() - $i->claimed())])->all()"/>
            </div>
        @endif

        <div class="flex flex-col items-center gap-2">
            <label class="flex w-full items-baseline justify-center gap-2">
                <x-billing.amount :value="old('amount', $one ? $fmt($online && $way === 'link' ? PayLink::defaultAmount($first) : $left) : '')" data-pay-amount-target="input"/>
                <span class="text-2xl text-ink-muted">₽</span>
            </label>
            @if ($one && $max > 0 && $left > $max)
                <div class="flex gap-2">
                    <button type="button" class="chip nums" data-action="pay-amount#set" data-pay-amount-value-param="{{ $fmt($max) }}">{{ $fmt($max) }}</button>
                    <button type="button" class="chip" data-action="pay-amount#set" data-pay-amount-value-param="{{ $fmt($left) }}">весь остаток</button>
                </div>
            @endif
            @error('amount')<div class="text-sm text-danger">{{ $message }}</div>@enderror
        </div>

        @if ($online && (! $only || $way === 'link'))
            <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="link" @if ($way !== 'link') hidden @endif>
                @if ($current)
                    @php [$state] = $current->stateLine(); @endphp
                    <div>
                        <div class="list-cap">Ссылка на оплату, {{ preg_replace('/^ждём оплату, /u', '', $state) }}</div>
                        <x-ui.share-link :url="$current->url()" :title="$current->title()" :text="$current->message()" editable :rows="5" show-url/>
                    </div>
                @else
                    <x-ui.sheet-foot>Получить ссылку</x-ui.sheet-foot>
                @endif
            </div>
        @endif

        @if ($transfer && (! $only || $way === 'transfer'))
        <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="transfer" @if ($way !== 'transfer') hidden @endif>
            @if ($pdf && $one)
                <div class="list">
                    <x-ui.doc :doc="['url' => str_replace('{id}', $first->id, $pdf), 'type' => 'pdf', 'name' => 'schet-'.$first->number.'.pdf', 'label' => 'Счёт '.$first->label()]" class="row">
                        {{-- Тем же видом, что способ «Счёт» в квитанции платежа (15.11.2026): значок PDF с весом и номер. --}}
                        <x-ui.file-icon name="schet.pdf"/>
                        <span class="min-w-0 flex-1">Счёт {{ $first->label() }}</span>
                        <x-ui.chevron/>
                    </x-ui.doc>
                </div>
            @endif
            <div class="fields">
                <x-ui.field name="paid_at" id="{{ $id }}-date-transfer" label="Дата оплаты" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"/>
                <x-ui.field name="ref" id="{{ $id }}-ref" label="№ документа об оплате"/>
                <x-ui.file-field name="slip" label="Документ об оплате" accept=".pdf,.jpg,.jpeg,.png,.heic,image/*"/>
            </div>
            <x-ui.sheet-foot>Я оплатил</x-ui.sheet-foot>
        </div>
        @endif

        @if (! $only || $way === 'cash')
        <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="cash" @if ($way !== 'cash') hidden @endif>
            <div class="fields">
                <x-ui.field name="paid_at" id="{{ $id }}-date-cash" label="Когда отдали" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"/>
            </div>
            <x-ui.sheet-foot>Отдал наличными</x-ui.sheet-foot>
        </div>
        @endif
    </form>
</x-ui.sheet>
