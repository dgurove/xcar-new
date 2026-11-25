{{-- Окно «Что видит менеджер» (25.11.2026, владелец выбрал вариант Б: «„в сделку“ и „менеджеру“ должно быть одно и то же; что из письма
     парсится — встаёт само, а что у менеджера отображаем — сотрудникам видеть и решать; и после „показать“ — окошко, где видно, что он
     видит, а что нет, с возможностью убирать, добавлять»). Пункты — `Offers\ManagerView::items` (контакт для получения из карточки,
     документы машины, наш счёт и договор) двумя списками «Видит» / «Не видит» с «Скрыть» / «Показать»; нажатия копятся, «Сохранить» с
     глаголом по выбранному — одним запросом (`PUT /work/deals/{id}/manager-view` → `SetManagerView`, одно сообщение менеджеру в чат).
     Ниже — «Добавить» (контакт карандашом — шторка `{id}-contact`, документ — загрузкой в документы машины), своё сообщение и
     «Сообщения менеджеру» (что ушло, `admin.deals.disclosure-edit`).
     Из письма (`letters` — ждущие разбора, `reviews`; или одно письмо окна писем — `message`): сверху «Показать из письма» — его документы,
     контакт и строки подписания, отмечены сразу; текст письма — в сообщение (`Disclosures::draft`), галка шага (`Disclose::stepExit`),
     «Не показывать» — письмо разобрано. Открывается событием `{id}:open`. --}}
@props(['deal', 'id' => null, 'letters' => null, 'letter' => null, 'reviews' => false, 'open' => false, 'focus' => null, 'top' => false])
@php
    use App\Offers\Actions\Disclose;
    use App\Offers\Disclosures;
    use App\Offers\ManagerView;
    use App\Offers\OwnerDocs;
    use App\Support\Ago;
    $id ??= 'manager-view-'.$deal->id;
    $offer = $deal->offer;
    $items = collect(ManagerView::items($deal));
    $letters = collect($letters ?? ($letter ? [$letter] : []))->filter()->sortByDesc(fn ($m) => $m->date_at)->values();
    $fromLetter = $letters->isNotEmpty();
    $letterIds = $letters->pluck('id')->all();
    // Из письма: его документы (картинки — не отмечены: обычно фото машины), контакт, если в письме он есть, строки подписания.
    $proposals = $fromLetter ? Disclosures::proposals($deal, $letters, $letter?->id) : [];
    $letterItems = $fromLetter ? $items->filter(fn ($i) => $i['media'] && in_array($i['letter'], $letterIds, true))->values() : collect();
    $hasContact = collect($proposals)->contains(fn ($p) => in_array($p['kind'], ['phone', 'name'], true) && str_starts_with((string) $p['group'], 'letter:'));
    $contactItem = $items->firstWhere('key', ManagerView::CONTACT);
    if ($fromLetter && $hasContact && $contactItem['can']) {
        $letterItems->prepend($contactItem);
    }
    $lines = collect($proposals)->filter(fn ($p, $k) => str_starts_with($k, 'signing:'));
    // Отмечено всё из письма: его файлы в документах — документы (фото ПТС, паспорта), кадры машины лежат в фото, а не здесь.
    $pre = fn ($i) => ! $i['visible'] && $i['can'];
    $draft = $fromLetter ? Disclosures::draft($letters, $letter?->id ?? $letters->first()->id) : '';
    $step = $fromLetter ? Disclose::stepExit($deal, $letters) : null;
    $stepOn = $step && Disclose::stepByDefault($step, $letters, $deal);
    $seen = $items->where('visible', true)->values();
    $unseen = $items->where('visible', false)->values();
    $shown = Disclosures::shown($deal);
    $form = $id.'-form';
    $vendor = $offer->vendor?->name ?? 'поставщика';
    $title = $fromLetter ? 'Письмо от '.$vendor : 'Что видит менеджер';
    // Карточка пуста — правку контакта заполняет телефон и имя из писем поставщика (`Disclosures::contactLetters`, «Готовы к передаче – 8-…»).
    $letterContacts = [];
    if (! $fromLetter && ! \App\Cars\OwnerContacts::of($offer)) {
        $found = collect(Disclosures::proposals($deal, Disclosures::contactLetters($deal), contact: true));
        $name = $found->firstWhere('kind', 'name')['value'] ?? null;
        $letterContacts = $found->where('kind', 'phone')->values()->map(fn ($p, $n) => ['name' => $n === 0 ? $name : null, 'phone' => $p['value']])->all();
    }
@endphp
<div class="contents" data-controller="sheet" data-action="{{ $id }}:open@window->sheet#open">
    {{-- Слот — то, что открывает окно (строка дела, пилюля): внутри контроллера шторки, `data-action="sheet#open"`. --}}
    {{ $slot }}
    <x-ui.sheet :id="$id" :title="$title" :open="$open" tall>
        <form method="post" action="/work/deals/{{ $deal->id }}/manager-view" id="{{ $form }}" class="flex flex-col gap-4" data-controller="manager-view" @if ($top) data-turbo-frame="_top" @endif>
            @csrf @method('put')
            @if ($letter)<input type="hidden" name="message" value="{{ $letter->id }}">@elseif ($fromLetter)<input type="hidden" name="reviews" value="1">@endif
            @if ($errors->has('view'))<x-ui.flash tone="danger">{{ $errors->first('view') }}</x-ui.flash>@endif
            @if ($fromLetter)
                <p class="-mt-2 text-sm text-ink-muted"><span class="nums">{!! Ago::html($letters->first()->date_at, true, words: false) !!}</span>@if ($letters->count() > 1), писем: {{ $letters->count() }}@endif</p>
                @if ($letterItems->isNotEmpty() || $lines->isNotEmpty())
                    <div>
                        <p class="field-label mb-1">Показать из письма</p>
                        <div class="list">
                            @foreach ($letterItems as $i)
                                <label class="row row-check mview-row">
                                    @include('admin.deals.manager-view-icon', ['i' => $i])
                                    <span class="min-w-0 flex-1"><span class="block truncate">{{ $i['label'] }}</span><span class="block truncate text-sm text-ink-muted">{{ $i['visible'] ? 'уже видит' : $i['sub'] }}</span></span>
                                    @unless ($i['visible'])<span class="check"><input type="checkbox" name="show[]" value="{{ $i['key'] }}" data-label="{{ $i['label'] }}" @checked($pre($i)) data-action="manager-view#update"></span>@endunless
                                </label>
                            @endforeach
                            @foreach ($lines as $k => $p)
                                <label class="row row-check mview-row">
                                    <x-ui.row-icon name="map-pin" size="s"/>
                                    <span class="min-w-0 flex-1"><span class="block text-sm text-ink-muted">{{ $p['label'] ?? 'Подписание' }}</span><span class="block">{{ $p['value'] }}</span></span>
                                    <span class="check"><input type="checkbox" name="line[]" value="{{ $k }}" data-label="{{ mb_strtolower($p['label'] ?? 'подписание') }}" checked data-action="manager-view#update"></span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
                <x-ui.field type="textarea" name="text" label="Сообщение менеджеру" :id="$id.'-text'" :value="old('text', $draft)" rows="4" data-action="input->manager-view#update" data-manager-view-target="text"/>
                @if ($step)
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="step" value="{{ $step->id }}" class="switch" @checked($stepOn)>
                        <span>Шаг «{{ $step->label }}»</span>
                    </label>
                @endif
            @else
                <p class="-mt-2 text-sm text-ink-muted">{{ $deal->buyer?->shortName() }}, {{ $offer->title() }}</p>
                @foreach (['Видит' => $seen, 'Не видит' => $unseen] as $cap => $list)
                    @if ($list->isNotEmpty())
                        <div>
                            <p class="field-label mb-1">{{ $cap }}</p>
                            <div class="list">
                                @foreach ($list as $i)
                                    @php $on = ! $i['visible'] && $focus === $i['key'] && $i['can']; @endphp
                                    <div @class(['row mview-row', 'is-pending' => $on]) data-manager-view-target="row">
                                        @include('admin.deals.manager-view-icon', ['i' => $i])
                                        <span class="min-w-0 flex-1"><span class="block truncate">{{ $i['label'] }}</span><span class="block truncate text-sm text-ink-muted">{{ $i['sub'] }}</span></span>
                                        @if ($i['edit'])
                                            <button type="button" class="btn btn-ghost btn-s shrink-0 px-2 text-ink-muted" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $id }}-contact:open" aria-label="Изменить контакт"><x-ui.icon name="edit" class="size-5"/></button>
                                        @endif
                                        @if ($i['can'])
                                            <label class="mview-act">
                                                <input type="checkbox" class="sr-only" name="{{ $i['visible'] ? 'hide[]' : 'show[]' }}" value="{{ $i['key'] }}" data-label="{{ $i['label'] }}" @checked($on) data-action="manager-view#update">
                                                <span data-off>{{ $i['visible'] ? ($i['group'] === 'ours' ? 'Отозвать' : 'Скрыть') : 'Показать' }}</span><span data-on>Отменить</span>
                                            </label>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
                <div>
                    <p class="field-label mb-1">Добавить</p>
                    <div class="list" data-controller="photos" data-photos-url-value="/offers/{{ $offer->number }}/media" data-photos-collection-value="papers">
                        <button type="button" class="row w-full text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $id }}-contact:open"><x-ui.row-icon name="plus" size="s" tone="accent"/><span class="flex-1 font-medium text-accent-text">Контакт</span></button>
                        <label class="row relative w-full cursor-pointer"><x-ui.row-icon name="plus" size="s" tone="accent"/><span class="flex-1 font-medium text-accent-text">Документ</span>
                            <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx,.xls,.xlsx" multiple class="sr-only" data-photos-target="input" data-action="change->photos#upload"></label>
                        <div hidden data-photos-target="progress" class="px-3 pb-3"><div class="mb-1 text-sm text-ink-muted" data-label></div><div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div></div>
                    </div>
                </div>
                <x-ui.field type="textarea" name="text" label="Сообщение менеджеру" :id="$id.'-text'" :value="old('text')" rows="2" data-action="input->manager-view#update" data-manager-view-target="text"/>
            @endif
            {{-- Кнопка — когда есть что сохранить; подпись — что именно («Показать: паспорт, СТС»), считает `manager_view_controller`. --}}
            <div class="sheet-foot" data-manager-view-target="foot" @if (! $fromLetter && ! $focus) hidden @endif>
                <x-ui.button block data-manager-view-target="submit" data-idle="{{ $fromLetter ? 'Показать менеджеру' : 'Сохранить' }}">{{ $fromLetter ? 'Показать менеджеру' : 'Сохранить' }}</x-ui.button>
            </div>
        </form>
        @if ($fromLetter)
            <form method="post" action="/work/deals/{{ $deal->id }}/reviews/done" class="mt-2" @if ($top) data-turbo-frame="_top" @endif>
                @csrf
                <button class="btn btn-quiet btn-block">Не показывать</button>
            </form>
            <button type="button" class="row mt-3 w-full text-left text-sm" data-controller="emit" data-action="sheet#close emit#send" data-emit-event-param="manager-view-{{ $deal->id }}:open">
                <span class="flex-1 text-ink-muted">Менеджер уже видит {{ $seen->count() }}</span><span class="text-accent-text">Что видит</span><x-ui.chevron/>
            </button>
        @elseif ($shown->isNotEmpty())
            <div class="mt-5">
                <p class="field-label mb-1">Сообщения менеджеру</p>
                <div class="flex flex-col gap-4">
                    @foreach ($shown as $disclosure)
                        @include('admin.deals.disclosure-edit', ['disclosure' => $disclosure, 'deal' => $deal])
                    @endforeach
                </div>
            </div>
        @endif
    </x-ui.sheet>
</div>
@unless ($fromLetter)
    {{-- Контакт для получения — правка карточки (`PUT /offers/{n}`, только контакт и адрес): показан — менеджеру уходит «изменён»
         (`ResendContact`). --}}
    <div class="contents" data-controller="sheet" data-action="{{ $id }}-contact:open@window->sheet#open">
        <x-ui.sheet :id="$id.'-contact'" title="Контакт для получения">
            <form method="post" action="/offers/{{ $offer->number }}" class="flex flex-col gap-3">
                @csrf @method('put')
                <input type="hidden" name="_fields[]" value="contacts"><input type="hidden" name="_fields[]" value="inspection_address">
                <x-route.contacts :contacts="\App\Cars\OwnerContacts::of($offer) ?: ($letterContacts ?: [['name' => null, 'phone' => null]])" :labels="['Владелец', 'Телефон']" :id="$id.'-contacts'"/>
                <x-ui.field name="inspection_address" label="Адрес" :value="$offer->inspection_address" :id="$id.'-address'"/>
                <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
@endunless
