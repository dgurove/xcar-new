{{-- «Приложить чек» на оплате «страховой напрямую» / ДКП — одна кнопка под строками карточки «Оплата» (16.11.2026, владелец, вариант Б;
     2611134025: две одинаковые «Приложить платёжку» — менеджер нажал у ПРАЙМ и приложил чек поставщику на 2,7 млн к подбору). Чек:
     куда можно одно — сразу туда, два — шторка «За что этот чек?» (`receipt_controller`, `DealController::receipt`). Поставщику —
     пока шаг ждёт платёжку и после неё (ещё лист к ушедшей), ООО «ПРАЙМ» — пока остаток счёта не заявлен. Вход: $deal, $requirement,
     $selectionPay (счёт ПРАЙМ). --}}
@php
    use App\Support\Money;
    $supplierOpen = $requirement && $deal->paysSupplierOn($requirement->stage)
        || (! $requirement && $deal->sentPaymentProof());
    $prime = $selectionPay ?? null;
    $primeLeft = $prime ? round($prime->remaining() - $prime->claimed(), 2) : 0;
    // Ждёт чека хоть одна оплата — лаймовая «Приложить чек»; всё уже приложено — тихая «Приложить ещё чек» (лист к ушедшей).
    $waiting = ($requirement && $deal->paysSupplierOn($requirement->stage)) || ($prime && $primeLeft > 0 && $deal->buyer_id === auth()->id());
    $choices = array_filter([
        'supplier' => $supplierOpen ? [$deal->schemeOf() === \App\Offers\DealScheme::Insurer ? 'Поставщику' : 'Собственнику', $deal->supplierPayment()] : null,
        'invoice:'.$prime?->id => $prime && $primeLeft > 0 && $deal->buyer_id === auth()->id() ? [$prime->seller->config()['name'], $primeLeft] : null,
    ]);
@endphp
@if ($choices)
    <form method="post" action="/deals/{{ $deal->id }}/receipt" enctype="multipart/form-data" class="mt-4" data-controller="sheet receipt" data-receipt-single-value="{{ count($choices) === 1 ? 'true' : 'false' }}" data-action="receipt:ask->sheet#open">
        @csrf
        @if (count($choices) === 1)<input type="hidden" name="for" value="{{ array_key_first($choices) }}">@endif
        <label @class(['btn btn-block cursor-pointer', 'btn-accent' => $waiting, 'btn-quiet' => ! $waiting])><x-ui.icon name="clip" class="size-5"/> {{ $waiting ? 'Приложить чек' : 'Приложить ещё чек' }}
            <input type="file" name="file" accept="image/*,.pdf,.heic" class="sr-only" tabindex="-1" data-receipt-target="input" data-action="click->receipt#reset change->receipt#picked">
        </label>
        @error('file')<p class="field-error mt-1">{{ $message }}</p>@enderror
        @error('for')<p class="field-error mt-1">{{ $message }}</p>@enderror
        @if (count($choices) > 1)
            <x-ui.sheet id="receipt-{{ $deal->id }}" title="За что этот чек?">
                <img hidden alt="" class="receipt-shot" data-receipt-target="preview">
                <p hidden class="mb-3 truncate text-sm text-ink-muted" data-receipt-target="name"></p>
                <div class="list">
                    @foreach ($choices as $key => [$name, $sum])
                        <label class="row row-check">
                            <span class="min-w-0 flex-1">{{ $name }}</span>
                            <span class="nums shrink-0 font-semibold">{{ $sum ? Money::rub($sum) : '' }}</span>
                            <span class="check"><input type="radio" name="for" value="{{ $key }}" data-action="receipt#chose"></span>
                        </label>
                    @endforeach
                </div>
                <x-ui.sheet-foot type="submit" data-receipt-target="submit" disabled>Приложить</x-ui.sheet-foot>
            </x-ui.sheet>
        @endif
    </form>
@endif
