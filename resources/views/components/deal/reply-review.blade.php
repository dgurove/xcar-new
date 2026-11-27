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
        {{-- Одна строка «что видит менеджер» (27.11.2026, владелец выбрал В2 из макетов: «три пункта об одном и том же»): глаз,
             «Менеджер видит» / «ничего не видит», под ним что видит и что нового — письма на разбор (документы, контакт) и контакт
             для получения, если он нужен (`ContactDue`); справа «+N» лаймом. Нажатие: есть письмо — окно из письма, нужен контакт —
             окно с отмеченным контактом, иначе — окно целиком. Строк «Письмо от …» и «Показать контакты менеджеру» больше нет. --}}
        @php
            $summary = ManagerView::summary($deal);
            $contactDue = ! $deal->isGarage() && \App\Offers\ContactDue::due($deal);
            $files = $letters->sum(fn ($m) => \App\Offers\LetterFiles::of($m)->count());
            $letterContact = $letters->contains(fn ($m) => \App\Offers\InsurerReplies::hasContact($m));
            $contactNew = $letterContact || $contactDue;
            $parts = array_filter([
                $files ? $files.' '.\App\Support\Plural::of($files, ['документ', 'документа', 'документов']) : null,
                $contactNew ? ($letters->isNotEmpty() ? 'контакт' : 'контакт для получения') : null,
            ]);
            $inside = implode(' и ', $parts);
            $kind = $what === 'Счёт' ? 'счёт' : 'письмо';
            $news = match (true) {
                $letters->count() > 1 => $letters->count().' '.\App\Support\Plural::of($letters->count(), ['письмо', 'письма', 'писем']).', последнее '.Ago::at($letters->first()->date_at, true, words: false).($inside ? ': '.$inside : ''),
                $letters->isNotEmpty() => $kind.' '.Ago::at($letters->first()->date_at, true, words: false).($inside ? ': '.$inside : ''),
                $contactDue => 'контакт для получения',
                default => null,
            };
            $count = $files + ($contactNew ? 1 : 0) ?: ($letters->isNotEmpty() ? 1 : 0);
        @endphp
        <x-deal.manager-view :deal="$deal" :id="$letters->isNotEmpty() ? $id : $view" :letters="$letters->isNotEmpty() ? $letters : null" :reviews="$letters->isNotEmpty()"
            :focus="$letters->isEmpty() && $contactDue ? ManagerView::CONTACT : null">
            <x-route.task-row :icon="$summary !== '' ? 'eye' : 'eye-off'" :count="$news ? '+'.$count : null">
                {{ $summary !== '' ? 'Менеджер видит' : 'Менеджер ничего не видит' }}
                @if ($summary !== '' || $news)
                    <x-slot:sub>@if ($summary !== '')<span class="block">{{ $summary }}</span>@endif @if ($news)<span class="block">новое: {{ $news }}</span>@endif</x-slot:sub>
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
