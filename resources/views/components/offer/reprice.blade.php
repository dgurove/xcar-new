{{-- Переоценка на решение (28.10.2026, владелец: «по такому-то письму такая-то переоценка, с возможностью принять или
     отменить»; `Offers\Reprice`). Плашка наверху окошка и редактора: было → стало, откуда, «Открыть письмо», «Отклонить» и
     «Применить…». Шторка — новая цена (правится), у открытого цена продажи (заявленная пустая — идёт за закупочной), и решение по каждому, кто сейчас
     подтвердил, и по сделке: «Оставить N | Обновить M», заранее не отмечено ничего, «Применить» — когда решено по всем
     (`reprice_controller`). Вписали закупочную в редакторе при подтвердивших — шторка открывается у автора сама, один раз. --}}
@props(['offer', 'reprice'])
@php
    use App\Support\Money;
    ['bids' => $bids, 'deal' => $deal] = \App\Offers\Reprice::affected($offer);
    $id = 'reprice-'.$reprice->id;
    $diff = $reprice->to_price - (int) $reprice->from_price;
    // Админ вписал закупочную в редакторе — шторка открывается один раз, по метке `DetectReprice::open`.
    $mine = session('reprice-open') == $reprice->id;
    $dealLocked = $deal && ! $deal->moneyEditable();
    $open = $offer->state === \App\Offers\OfferState::Open;
@endphp
<div {{ $attributes->class('proposal reprice') }} data-controller="sheet">
    <div class="proposal-body">
        <span class="proposal-mark" aria-hidden="true"><x-ui.icon :name="$reprice->source === 'letter' ? 'mail' : 'edit'" class="size-4"/></span>
        <div class="min-w-0 flex-1">
            <p class="text-ink">Переоценка {{ $reprice->sourceLabel() }}</p>
            <p class="nums text-sm text-ink-muted"><s>{{ Money::nums((int) $reprice->from_price) }}</s> → <span class="font-semibold text-ink">{{ Money::rub($reprice->to_price) }}</span> ({{ $diff > 0 ? '+' : '−' }}{{ Money::nums(abs($diff)) }})</p>
        </div>
    </div>
    <div class="proposal-acts">
        @if ($reprice->message_id)<x-mail.window-button :url="'/offers/'.$offer->number.'/letters?at='.$reprice->message_id" label="Письмо" class="btn-s"/>@endif
        <form method="post" action="/offers/{{ $offer->number }}/reprices/{{ $reprice->id }}" data-turbo-confirm="Отклонить переоценку? Закупочная останется {{ Money::rub((int) $reprice->from_price) }}" data-turbo-confirm-label="Отклонить">
            @csrf @method('delete')
            <button class="btn btn-s btn-quiet text-danger">Отклонить</button>
        </form>
        <button type="button" class="btn btn-s btn-accent" data-action="sheet#open" aria-controls="{{ $id }}">Применить…</button>
    </div>
    <x-ui.sheet :id="$id" title="Переоценка" tall :open="$mine || old('_reprice') == $reprice->id">
        <form method="post" action="/offers/{{ $offer->number }}/reprices/{{ $reprice->id }}" class="flex flex-col gap-4" data-controller="reprice"
              data-reprice-from-value="{{ (int) $reprice->from_price }}">
            @csrf
            <input type="hidden" name="_reprice" value="{{ $reprice->id }}">
            @error('reprice')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
            <div class="fields">
                <x-ui.field name="price" label="Закупочная, ₽" :value="Money::nums((int) old('price', $reprice->to_price))" :id="$id.'-price'" required
                    data-reprice-target="price" data-action="input->reprice#update"/>
                @if ($open)
                    <x-ui.field name="asking_price" label="Цена продажи, ₽" :value="$offer->asking_price ? Money::nums($offer->asking_price) : null" :id="$id.'-asking'"/>
                @endif
            </div>
            @if ($bids->isNotEmpty() || $deal)
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="field-label">Закупочная у тех, кто уже подтвердил</span>
                    <span class="flex gap-2">
                        <button type="button" class="chip" data-action="reprice#all" data-reprice-choice-param="keep">Всем оставить</button>
                        <button type="button" class="chip" data-action="reprice#all" data-reprice-choice-param="update">Всем обновить</button>
                    </span>
                </div>
                <div class="list">
                    @foreach ($bids as $bid)
                        @php $cost = (int) $bid->frozenCost(); @endphp
                        <div class="row flex-col !items-stretch gap-2" data-reprice-target="row" data-amount="{{ (int) $bid->amount }}">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <x-ui.person :user="$bid->user" full/>
                                <span class="nums text-sm text-ink-muted">{{ $bid->isGarage() ? 'в гараж' : Money::rub($bid->amount) }}, закупочная {{ Money::nums($cost) }}</span>
                            </div>
                            <div class="segment">
                                <label><input type="radio" name="bids[{{ $bid->id }}]" value="keep" @checked(old('bids.'.$bid->id) === 'keep') data-action="reprice#update">Оставить&nbsp;{{ Money::nums($cost) }}</label>
                                <label><input type="radio" name="bids[{{ $bid->id }}]" value="update" @checked(old('bids.'.$bid->id) === 'update') data-action="reprice#update">Обновить&nbsp;<span data-reprice-target="to">{{ Money::nums($reprice->to_price) }}</span></label>
                            </div>
                            <p class="text-sm text-danger" data-reprice-target="loss" hidden></p>
                        </div>
                    @endforeach
                    @if ($deal)
                        <div class="row flex-col !items-stretch gap-2" data-reprice-target="row" data-amount="{{ (int) $deal->amount }}">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="font-medium">Сделка</span>@if ($deal->buyer)<x-ui.person :user="$deal->buyer" full/>@endif
                                <span class="nums text-sm text-ink-muted">закупочная {{ Money::nums((int) $deal->cost) }}</span>
                            </div>
                            <div class="segment">
                                <label><input type="radio" name="deal" value="keep" @checked(old('deal') === 'keep' || $dealLocked) data-action="reprice#update">Оставить&nbsp;{{ Money::nums((int) $deal->cost) }}</label>
                                {{-- По счёту уже платили — обновить нельзя: вместо цены словом, почему. --}}
                                <label @if ($dealLocked) class="opacity-40" @endif><input type="radio" name="deal" value="update" @checked(old('deal') === 'update') @disabled($dealLocked) data-action="reprice#update">
                                    @if ($dealLocked)
                                        Уже платили
                                    @else
                                        Обновить&nbsp;<span data-reprice-target="to">{{ Money::nums($reprice->to_price) }}</span>
                                    @endif
                                </label>
                            </div>
                            <p class="text-sm text-danger" data-reprice-target="loss" hidden></p>
                        </div>
                    @endif
                </div>
            @endif
            <x-ui.sheet-foot type="submit" data-reprice-target="submit">Применить переоценку</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
