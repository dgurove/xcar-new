{{-- Спорные поля машины (07.11.2026, владелец: «по этому полю в Мигторге так, в этом письме так, в этом документе так —
     выберите»; `Cars\Sources\Variants`). Сверху блока — строка на поле: подпись, что сейчас, оранжевое «ещё N»; нажатие —
     шторка поля: варианты строками, у каждого откуда (Мигторг — ссылкой на лот, письмо и документ — в шторке документов),
     «Выбрать» пишет ровно этот вариант, «Оставить» — отклоняет остальные. Запрос — fetch (`car_variants_controller`): блок
     стоит в форме карточки, вложенной формы быть нельзя. Показывается тому, кто правит машину: продажу — дверь
     `offer.edit`, ТС — `canManagePark`. --}}
@props(['lane', 'fields'])
@php
    use App\Cars\Sources\Variants;
    use App\Offers\Offer;
    use App\Support\Docs;
    $user = auth()->user();
    $can = $lane?->exists && $user && ($lane instanceof Offer ? $lane->isEditableBy($user) : $user->canManagePark());
    $variants = $can ? array_intersect_key(Variants::for($lane->carRecord(), $lane), array_flip($fields)) : [];
    $url = $lane instanceof Offer ? '/offers/'.$lane->number.'/fields' : '/cars/'.$lane?->id.'/fields';
    $mail = $lane instanceof Offer ? '/work/mail' : '/mail';
    // Файлы источников — одним запросом на блок: вложения писем, документы машины, письма.
    $ids = collect($variants)->flatMap(fn ($v) => collect([...$v['options'], $v['current'] ?? ['sources' => []]])->flatMap(fn ($o) => $o['sources']));
    $attachments = $ids->where('kind', 'doc')->pluck('id')->filter(fn ($i) => ctype_digit((string) $i))->whenNotEmpty(fn ($c) => \App\Mail\Attachment::whereIn('id', $c)->get()->keyBy('id'), fn () => collect());
    $media = $ids->where('kind', 'doc')->pluck('id')->filter(fn ($i) => str_starts_with((string) $i, 'm'))->map(fn ($i) => (int) substr($i, 1))->whenNotEmpty(fn ($c) => \Spatie\MediaLibrary\MediaCollections\Models\Media::whereIn('id', $c)->get()->keyBy('id'), fn () => collect());
    $letters = $ids->where('kind', 'letter')->pluck('id')->whenNotEmpty(fn ($c) => \App\Mail\Message::whereIn('id', $c)->get()->keyBy('id'), fn () => collect());
    $doc = function (array $s) use ($attachments, $media, $letters, $mail) {
        return match ($s['kind']) {
            'doc' => str_starts_with($s['id'], 'm') ? (($m = $media[(int) substr($s['id'], 1)] ?? null) ? Docs::media($m) : null) : (($a = $attachments[(int) $s['id']] ?? null) ? Docs::attachment($a, $mail) : null),
            'letter' => ($m = $letters[(int) $s['id']] ?? null) ? Docs::letter($m, $mail) : null,
            default => null,
        };
    };
@endphp
@if ($variants)
    <div {{ $attributes->class(['variants']) }} data-controller="car-variants" data-car-variants-url-value="{{ $url }}">
        @foreach ($variants as $field => $v)
            @php $sheet = 'variants-'.$field.'-'.$lane->car_id; @endphp
            <div class="contents" data-controller="sheet">
                <button type="button" class="variant-line" data-action="sheet#open">
                    <span class="variant-label">{{ $v['label'] }}</span>
                    @php
                        // Строкой — что сейчас и с чем спор: «Цвет Зеленый или Белый ›»; у пустого — сами варианты.
                        $labels = array_column($v['options'], 'label');
                        $now = $v['current']['label'] ?? array_shift($labels);
                    @endphp
                    <span class="variant-now">{{ $now }}</span>
                    <span class="variant-more"><span class="truncate">или {{ implode(', ', $labels) }}</span><x-ui.icon name="chevron-right" class="size-3.5 shrink-0"/></span>
                </button>
                <x-ui.sheet :id="$sheet" :title="$v['label']">
                    <div class="list">
                        @foreach (array_filter([$v['current'] ? $v['current'] + ['now' => true] : null, ...$v['options']]) as $o)
                            <div class="row variant-option">
                                <span class="min-w-0 flex-1">
                                    <span class="variant-value">{{ $o['label'] }}</span>
                                    <span class="variant-sources">
                                        @forelse ($o['sources'] as $s)
                                            @if ($s['kind'] === 'migtorg')
                                                <a href="https://www.migtorg.com/lots/{{ $s['id'] }}" target="_blank" rel="noopener" class="variant-source">{{ $s['title'] }}</a>
                                            @elseif ($d = $doc($s))
                                                <x-ui.doc :doc="$d" class="variant-source">{{ $s['title'] }}</x-ui.doc>
                                            @else
                                                <span class="variant-source">{{ $s['title'] }}</span>
                                            @endif
                                        @empty
                                            <span class="variant-source">вписано в карточке</span>
                                        @endforelse
                                    </span>
                                </span>
                                @if (! empty($o['now']))
                                    <span class="variant-current">сейчас</span>
                                @else
                                    <button type="button" class="btn btn-s btn-accent shrink-0" data-action="car-variants#pick"
                                        data-car-variants-field-param="{{ $field }}" data-car-variants-key-param="{{ $o['key'] }}" data-car-variants-seen-param="{{ $v['current']['key'] ?? '' }}">Выбрать</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if ($v['current'])
                        <div class="sheet-foot">
                            <button type="button" class="btn btn-quiet w-full" data-action="car-variants#keep"
                                data-car-variants-field-param="{{ $field }}" data-car-variants-seen-param="{{ $v['current']['key'] }}">Оставить {{ $v['current']['label'] }}</button>
                        </div>
                    @endif
                </x-ui.sheet>
            </div>
        @endforeach
    </div>
@endif
