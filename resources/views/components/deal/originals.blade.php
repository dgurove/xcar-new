{{-- Оригиналы документов — шаг «Оригиналы» (26.11.2026, `Offers\Originals`): Совкомбанк (договор комиссии с актом или
     доверенность — к нам, ДКП — прежнему владельцу) и сделки через Р.О.С. или ПРАЙМ (ДКП и акт с поставщиком и с покупателем,
     счёт-фактура — к нам, со сканом). Вид — вариант «Г» (владелец выбрал из макетов 26.11.2026: «сразу действие», без шторки):
     карточка на документ — значок состояния, название, где он сейчас и с какого числа справа; под ней сканы строками файлов и
     кнопки следующего шага (`Original::actions`: «У нас», «Едет СДЭКом» и «Отправили» с полем трека прямо в строке, «Вручено»),
     «Скан», пока его нет. Редкое — меню «⋯» документа: вернуть назад, «Не нужен», у Совкома «Это доверенность», «Напомнить
     менеджеру», «Убрать». Нажатие на документ раскрывает историю: что отметили, кто и когда (`history`). «+ Документ» — доп.
     соглашение и прочее. Всё на месте — шаг проходит сам. --}}
@props(['deal'])
@php
    $items = \App\Offers\Originals::of($deal);
    $base = '/work/deals/'.$deal->id.'/originals';
    $names = \App\Users\User::whereIn('id', $items->flatMap(fn ($o) => array_column($o->history ?? [], 'by'))->filter()->unique()->values())->pluck('name', 'id');
    $failed = (string) old('_original');
@endphp
<div {{ $attributes->class(['flex flex-col gap-2']) }}>
    {{-- Сделка встала на шаг раньше, чем появились документы шага, — завести их. --}}
    @if ($items->isEmpty() && \App\Offers\Originals::kit($deal) !== [])
        <div class="list"><form method="post" action="{{ $base }}/open" class="contents">@csrf<button class="row w-full text-left text-accent-text"><x-ui.icon name="file" class="size-5 shrink-0"/><span class="min-w-0 flex-1">Завести документы</span></button></form></div>
    @endif
    @foreach ($items as $o)
        @php
            [$icon, $tone] = $o->icon();
            $scans = $o->scans();
            $history = array_reverse($o->history ?? []);
            $sub = implode(', ', array_filter([$o->place(), $o->state === 'sent' ? $o->track : null]));
            $mine = $failed === (string) $o->id;
            $actions = $o->actions();
            $scanAsk = $o->needs_scan && $o->state !== 'skip';
        @endphp
        {{-- Одним ребёнком плашки: `.list` рисует линию между детьми, а шапка и кнопки документа — одно целое. --}}
        <div class="list" id="original-{{ $o->id }}"><div>
            <div class="flex items-start gap-3 p-3 sm:p-4">
                <x-ui.icon :name="$icon" class="mt-0.5 size-5 shrink-0 {{ $tone }}"/>
                @if ($history)
                    <details class="group min-w-0 flex-1">
                        <summary class="block cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                            <span class="block">{{ $o->title }}</span>
                            <span class="row-sub flex-nowrap items-baseline"><span class="min-w-0 flex-1">{{ $sub }}</span><span class="shrink-0 text-ink-dim">{{ \App\Support\Ago::html($o->since()) }}</span></span>
                        </summary>
                        <ol class="mt-2 flex flex-col gap-1.5 border-l border-line pl-3 text-sm">
                            @foreach ($history as $h)
                                <li class="flex items-baseline gap-2">
                                    <span class="min-w-0 flex-1">{{ $o->said($h) }}@if (! empty($h['by']) && isset($names[$h['by']]))<span class="text-ink-dim"> {{ $names[$h['by']] }}</span>@endif</span>
                                    <span class="shrink-0 text-ink-dim">{{ \App\Support\Ago::html(\Illuminate\Support\Carbon::parse($h['at']), time: true) }}</span>
                                </li>
                            @endforeach
                        </ol>
                    </details>
                @else
                    <div class="min-w-0 flex-1">
                        <span class="block">{{ $o->title }}</span>
                        <span class="row-sub flex-nowrap items-baseline"><span class="min-w-0 flex-1">{{ $sub }}</span><span class="shrink-0 text-ink-dim">{{ \App\Support\Ago::html($o->since()) }}</span></span>
                    </div>
                @endif
                <x-ui.more :id="'original-more-'.$o->id" label="Ещё по документу" light>
                    @foreach ($o->rare() as $state => $label)
                        <form method="post" action="{{ $base }}/{{ $o->id }}" class="contents">@csrf @method('put')<input type="hidden" name="state" value="{{ $state }}"><button class="menu-item w-full" role="menuitem">{{ $label }}</button></form>
                    @endforeach
                    {{-- Совком: редко у собственника уже соглашения и акты — тогда к нам едет только доверенность. --}}
                    @if (isset(\App\Offers\Originals::SIGNED[$o->kind]))
                        <form method="post" action="{{ $base }}/{{ $o->id }}" class="contents">@csrf @method('put')<input type="hidden" name="state" value="{{ $o->state }}"><input type="hidden" name="kind" value="{{ $o->kind === 'commission' ? 'proxy' : 'commission' }}"><button class="menu-item w-full" role="menuitem">Это {{ mb_strtolower(\App\Offers\Originals::SIGNED[$o->kind === 'commission' ? 'proxy' : 'commission']) }}</button></form>
                    @endif
                    @if ($o->track)
                        <a href="https://www.cdek.ru/ru/tracking?order_id={{ urlencode($o->track) }}" target="_blank" rel="noopener" class="menu-item w-full" role="menuitem">Где посылка</a>
                    @endif
                    @if ($o->waitsManager() && $deal->buyer_id)
                        <form method="post" action="{{ $base }}/nudge" class="contents">@csrf<button class="menu-item w-full" role="menuitem">Напомнить менеджеру</button></form>
                    @endif
                    @if ($o->kind === 'extra')
                        <form method="post" action="{{ $base }}/{{ $o->id }}" class="contents" data-turbo-confirm="Убрать «{{ $o->title }}»?">@csrf @method('delete')<button class="menu-item w-full text-danger" role="menuitem">Убрать</button></form>
                    @endif
                </x-ui.more>
            </div>
            @if ($scans->isNotEmpty())
                <div class="flex flex-col gap-2 px-3 pb-3 sm:px-4">
                    @foreach ($scans as $media)
                        <x-ui.file :name="\App\Media\DocName::of($media)" :mime="$media->mime_type" :bytes="$media->size" href="/files/{{ $media->id }}">
                            @if ($media->model_type === \App\Offers\Original::class)
                                <form method="post" action="{{ $base }}/{{ $o->id }}/scan/{{ $media->id }}" data-turbo-confirm="Убрать скан?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Убрать"><x-ui.icon name="x" class="size-5"/></button></form>
                            @endif
                        </x-ui.file>
                    @endforeach
                </div>
            @endif
            @if ($actions || $scanAsk)
                <div class="flex flex-wrap gap-2 px-3 pb-3 sm:px-4 sm:pb-4">
                    @foreach ($actions as $a)
                        @if (! empty($a['track']))
                            @php
                                $form = 'original-track-'.$o->id;
                                $fields = '<input type="hidden" name="_original" value="'.$o->id.'"><input type="hidden" name="state" value="sent">'
                                    .'<input name="track" form="'.$form.'" class="field-input min-w-0 flex-1" placeholder="Трек СДЭК" autocomplete="off" autocapitalize="characters" value="'.e($mine ? old('track') : '').'" aria-label="Трек СДЭК">';
                            @endphp
                            @if ($a['main'])
                                {{-- Документ от нас: трек — прямо в строке, «Отправили» рядом. --}}
                                <form method="post" action="{{ $base }}/{{ $o->id }}" id="{{ $form }}" class="flex w-full gap-2">@csrf @method('put'){!! $fields !!}<button class="btn btn-s btn-accent shrink-0">{{ $a['label'] }}</button></form>
                                @if ($mine && $errors->has('track'))<p class="field-error w-full px-1">{{ $errors->first('track') }}</p>@endif
                            @else
                                {{-- Документ нам: «Едет СДЭКом» раскрывает поле трека под кнопками. --}}
                                <details class="flex-1 open:basis-full" @if ($mine) open @endif>
                                    <summary class="btn btn-s btn-quiet w-full list-none gap-2 [&::-webkit-details-marker]:hidden"><x-ui.icon name="truck" class="size-4"/>{{ $a['label'] }}</summary>
                                    <form method="post" action="{{ $base }}/{{ $o->id }}" id="{{ $form }}" class="mt-2 flex gap-2">@csrf @method('put'){!! $fields !!}<button class="btn btn-s btn-accent shrink-0">Сохранить</button></form>
                                    @if ($mine && $errors->has('track'))<p class="field-error mt-2 px-1">{{ $errors->first('track') }}</p>@endif
                                </details>
                            @endif
                        @else
                            <form method="post" action="{{ $base }}/{{ $o->id }}" class="flex flex-1">@csrf @method('put')<input type="hidden" name="state" value="{{ $a['state'] }}"><button @class(['btn btn-s w-full', 'btn-accent' => $a['main'], 'btn-quiet' => ! $a['main']])>{{ $a['label'] }}</button></form>
                        @endif
                    @endforeach
                    @if ($scanAsk && $o->kind === 'buyer_dkp')
                        {{-- Скан ДКП с покупателем — подписанный договор сделки: одно место, его же видит «Ждём договор». --}}
                        <x-deal.attach-signed :deal="$deal" :base="'/work/deals/'.$deal->id" label="Скан" :button="'btn btn-s btn-quiet flex-1 gap-2'"/>
                    @elseif ($scanAsk)
                        <x-ui.photo-pdf :id="'original-scan-'.$o->id" :url="$base.'/'.$o->id.'/scan'" :title="$o->title" :name="$o->title.' '.$deal->offer->title()"
                            icon="clip" :label="$scans->isEmpty() ? 'Скан' : 'Ещё скан'" always button="btn btn-s btn-quiet flex-1 gap-2"/>
                    @endif
                </div>
            @endif
        </div></div>
    @endforeach
    <div class="list" data-controller="sheet">
        <button type="button" class="row w-full text-left text-accent-text" data-action="sheet#open" aria-controls="original-new-{{ $deal->id }}"><x-ui.icon name="plus" class="size-5 shrink-0"/><span class="min-w-0 flex-1">Документ</span></button>
        <x-ui.sheet :id="'original-new-'.$deal->id" title="Ещё документ" :open="old('_sheet') === 'original-new-'.$deal->id">
            <form method="post" action="{{ $base }}" class="flex flex-col gap-4">
                @csrf
                <input type="hidden" name="_sheet" value="original-new-{{ $deal->id }}">
                <div class="fields"><x-ui.field name="title" :id="'original-new-title-'.$deal->id" label="Что за документ" placeholder="Доп. соглашение" required/></div>
                <x-ui.segment name="to" :options="\App\Offers\Originals::DESTINATIONS" value="us"/>
                <x-ui.sheet-foot>Добавить</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
</div>
