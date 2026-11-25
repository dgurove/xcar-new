{{-- Письмо поставщика и что видит менеджер — в ходе сделки и окне машины (25.11.2026, владелец: «„Показать менеджеру“ и заполнение
     данных из письма не дружат — подружить»; вариант Б). Что из письма распознано — уже в сделке (контакт, документы, собственник,
     продавец ДКП, подписание), здесь — только что из этого видит менеджер: одно окно `x-deal.manager-view`.
     - `line` — строками в текущем шаге пути (`x-route.path`): письмо ждёт разбора — «Письмо от … / Показать менеджеру?» (окно из письма:
       его документы и контакт отмечены, текст — в сообщение, галка шага); ниже всегда — «Менеджер видит: контакт, паспорт…» (окно
       целиком: «Видит» / «Не видит», скрыть, показать, добавить, сообщения).
     - `pill` — «Что видит менеджер» пилюлей в ряду кнопок окна машины.
     - иначе (`compact`, плашка окна без пути) — письмо ждёт разбора: «Письмо», «Не показывать», «Показать менеджеру…». --}}
@props(['deal', 'compact' => false, 'pill' => false, 'line' => false, 'manual' => false])
@php
    use App\Offers\ManagerView;
    use App\Support\Ago;
    $live = $deal->buyer_id && $deal->isActive() && auth()->user()?->canEditDeals();
    $letters = $live ? \App\Offers\ReplyReview::pending()->where('deal_id', $deal->id)->with('message.attachments')->orderByDesc('id')->get()->pluck('message')->filter()->values() : collect();
    $offer = $deal->offer;
    $id = 'disclose-'.$deal->id;
    $view = 'manager-view-'.$deal->id;
    $step = $letters->isNotEmpty() ? \App\Offers\Actions\Disclose::stepExit($deal, $letters) : null;
    $what = $step && \App\Offers\SupplierInvoice::needs($deal, $step) ? 'Счёт' : 'Письмо';
@endphp
@if ($live)
    @if ($pill)
        <x-deal.manager-view :deal="$deal" :id="$view.'-pill'">
            <button type="button" {{ $attributes->class('pill pill-plain gap-1.5') }} data-action="sheet#open"><x-ui.icon name="users" class="size-4"/>Что видит менеджер</button>
        </x-deal.manager-view>
    @elseif ($line)
        @if ($letters->isNotEmpty())
            {{-- Что пришло и когда; нажатие — окно из письма (16.11.2026: без логотипа и числа писем). --}}
            <x-deal.manager-view :deal="$deal" :id="$id" :letters="$letters" reviews>
                <x-route.task-row icon="mail" urgent>
                    {{ $what }} от <span class="nums whitespace-nowrap">{!! Ago::html($letters->first()->date_at, true, words: false) !!}</span>
                    <x-slot:sub>Показать менеджеру?</x-slot:sub>
                </x-route.task-row>
            </x-deal.manager-view>
        @endif
        {{-- Что менеджер видит сейчас — всегда строкой: нажатие — окно целиком. --}}
        @php $summary = ManagerView::summary($deal); @endphp
        <x-deal.manager-view :deal="$deal" :id="$view">
            <x-route.task-row :icon="$summary !== '' ? 'eye' : 'eye-off'">
                {{ $summary !== '' ? 'Менеджер видит' : 'Менеджер ничего не видит' }}
                @if ($summary !== '')
                    <x-slot:sub>{{ $summary }}</x-slot:sub>
                @endif
            </x-route.task-row>
        </x-deal.manager-view>
    @elseif ($letters->isNotEmpty())
        <div {{ $attributes }}>
            <x-deal.manager-view :deal="$deal" :id="$id" :letters="$letters" reviews>
                <div class="proposal">
                    <div class="proposal-body">
                        <span class="proposal-mark" aria-hidden="true"><x-ui.icon name="mail" class="size-4"/></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-ink">{{ $what === 'Счёт' ? 'Счёт от поставщика' : 'Письмо от поставщика' }}{{ $letters->count() > 1 ? ', писем: '.$letters->count() : '' }}</p>
                            <p class="text-sm text-ink-muted">{!! Ago::html($letters->first()->date_at, true) !!}, менеджер о нём не знает</p>
                        </div>
                    </div>
                    <div class="proposal-acts flex-wrap">
                        <x-mail.window-button :url="'/offers/'.$offer->number.'/letters?at='.$letters->first()->id" label="Письмо" class="btn-s"/>
                        <button type="button" class="btn btn-s btn-accent" data-action="sheet#open">Показать менеджеру…</button>
                    </div>
                </div>
            </x-deal.manager-view>
        </div>
    @endif
@endif
