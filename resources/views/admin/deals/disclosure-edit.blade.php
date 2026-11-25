{{-- Показанное менеджеру — с «Изменить» (25.11.2026, владелец: «должна быть возможность редактировать: в тексте что-то убрать или
     добавить, документ убрать или добавить; при сохранении в сделке поменялось, то сообщение удалилось и новое отправилось»).
     Карточка как у менеджера (`cabinet.deals.disclosure`), «Изменить» ставит на её место форму (`edit_card_controller`, как «Контакт
     владельца»): сообщение, контакты строками с × и «+ Контакт» (`repeater_controller`), документы машины галками; «Готово» сохраняет,
     «Удалить показ» — своим вопросом. Сервер — `DisclosureController::update|destroy` → `EditDisclosure`. Ждёт $disclosure, $deal. --}}
@php
    $form = 'disclosure-edit-'.$disclosure->id;
    $items = collect($disclosure->items ?? []);
    $text = $items->where('kind', 'text')->pluck('value')->implode("\n\n");
    $contacts = $items->whereIn('kind', ['name', 'phone', 'address'])->values();
    $shownFiles = $items->where('kind', 'file')->pluck('media')->map(fn ($id) => (int) $id)->all();
    $papers = $deal->offer->getMedia('papers');
    $labels = ['name' => 'Имя', 'phone' => 'Телефон', 'address' => 'Адрес'];
@endphp
<div class="flex flex-col gap-1.5" data-controller="edit-card" data-edit-card-form-value="{{ $form }}">
    <div class="flex items-center justify-between gap-3">
        <p class="nums text-sm text-ink-dim">{!! \App\Support\Ago::html($disclosure->created_at, true, words: false) !!}</p>
        <button type="button" class="edit-card-toggle" data-edit-card-target="button" data-action="edit-card#toggle">Изменить</button>
    </div>
    <div data-edit-card-target="view">
        @include('cabinet.deals.disclosure', ['disclosure' => $disclosure, 'bare' => true])
    </div>
    <div data-edit-card-target="edit" hidden>
        <form method="post" action="/work/deals/{{ $deal->id }}/disclosures/{{ $disclosure->id }}" id="{{ $form }}" class="flex flex-col gap-4" data-controller="repeater">
            @csrf @method('put')
            <x-ui.field type="textarea" name="text" label="Сообщение менеджеру" :id="$form.'-text'" :value="$text" rows="5"/>
            <div>
                <p class="field-label mb-1">Контакты</p>
                <div class="flex flex-col gap-2" data-repeater-target="list">
                    @foreach ($contacts as $i => $c)
                        <div class="flex items-end gap-2" data-row>
                            <x-ui.field :name="$c['kind'].'[]'" :label="$labels[$c['kind']]" :id="$form.'-c'.$i" :value="$c['value']" span="min-w-0 flex-1" :type="$c['kind'] === 'phone' ? 'tel' : 'text'"/>
                            <button type="button" class="btn btn-ghost px-2 text-ink-muted" data-action="repeater#remove" aria-label="Убрать"><x-ui.icon name="x" class="size-5"/></button>
                        </div>
                    @endforeach
                </div>
                <template data-repeater-target="template">
                    <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-end gap-2" data-row>
                        <x-ui.field name="name[]" label="Имя" id="{{ $form }}-n__i__"/>
                        <x-ui.field name="phone[]" type="tel" label="Телефон" id="{{ $form }}-p__i__"/>
                        <button type="button" class="btn btn-ghost px-2 text-ink-muted" data-action="repeater#remove" aria-label="Убрать"><x-ui.icon name="x" class="size-5"/></button>
                    </div>
                </template>
                <button type="button" class="mt-2 text-sm text-accent-text" data-action="repeater#add">+ Контакт</button>
            </div>
            @if ($papers->isNotEmpty())
                <div>
                    <p class="field-label mb-1">Документы</p>
                    <div class="list">
                        @foreach ($papers as $m)
                            <label class="row row-check">
                                <span class="min-w-0 flex-1"><x-ui.file-name :name="$m->name ?: $m->file_name"/></span>
                                <span class="check"><input type="checkbox" name="files[]" value="{{ $m->id }}" @checked(in_array($m->id, $shownFiles, true))></span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
        </form>
        <form method="post" action="/work/deals/{{ $deal->id }}/disclosures/{{ $disclosure->id }}" class="mt-3"
            data-turbo-confirm="Удалить показ?" data-turbo-confirm-text="Сообщение у менеджера удалится" data-turbo-confirm-label="Удалить" data-turbo-confirm-danger>
            @csrf @method('delete')
            <button class="btn btn-s btn-ghost text-danger">Удалить показ</button>
        </form>
    </div>
</div>
