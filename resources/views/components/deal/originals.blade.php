{{-- Оригиналы документов — шаг «Оригиналы» (26.11.2026, `Offers\Originals`): Совкомбанк (договор комиссии с актом или
     доверенность — к нам, ДКП — прежнему владельцу) и сделки через Р.О.С. или ПРАЙМ (ДКП и акт с поставщиком и с покупателем,
     счёт-фактура — к нам, со сканом). Документ строкой, под ним где он сейчас («у менеджера», «едет к нам СДЭКом, трек …») и
     «нет скана»; нажатие — шторка: у Совкома что подписали, где документ (строки с отметкой), трек СДЭК, «Где документ» у
     СДЭКа, скан «Приложить» (у ДКП с покупателем — подписанный договор сделки), «Напомнить менеджеру», если документ у него.
     «+ Документ» — доп. соглашение и прочее. Всё на месте — шаг проходит сам. --}}
@props(['deal'])
@php
    $items = \App\Offers\Originals::of($deal);
    $base = '/work/deals/'.$deal->id.'/originals';
@endphp
<div {{ $attributes->class(['list']) }}>
    {{-- Сделка встала на шаг раньше, чем появились документы шага, — завести их. --}}
    @if ($items->isEmpty() && \App\Offers\Originals::kit($deal) !== [])
        <form method="post" action="{{ $base }}/open" class="contents">@csrf<button class="row w-full text-left text-accent-text"><x-ui.icon name="file" class="size-5 shrink-0"/><span class="min-w-0 flex-1">Завести документы</span></button></form>
    @endif
    @foreach ($items as $o)
        @php
            $sheet = 'original-'.$o->id;
            $scans = $o->scans();
            $noScan = $o->needs_scan && $o->state !== 'skip' && $scans->isEmpty();
            $sub = implode(', ', array_filter([$o->place(), $o->track && $o->state === 'sent' ? 'трек '.$o->track : null, $noScan ? 'нет скана' : null]));
        @endphp
        <div class="contents" data-controller="sheet">
            <button type="button" class="row w-full text-left" data-action="sheet#open" aria-controls="{{ $sheet }}">
                <x-ui.icon :name="$o->settled() ? 'check-circle' : 'file'" @class(['size-5 shrink-0', 'text-open' => $o->settled(), 'text-ink-dim' => ! $o->settled()])/>
                <span class="min-w-0 flex-1">
                    <span class="block">{{ $o->title }}</span>
                    <span @class(['row-sub', 'text-accent-text' => $o->todo() !== null])>{{ $sub }}</span>
                </span>
                <x-ui.chevron/>
            </button>
            <x-ui.sheet :id="$sheet" :title="$o->title" :open="old('_sheet') === $sheet">
                <form method="post" action="{{ $base }}/{{ $o->id }}" class="flex flex-col gap-4">
                    @csrf @method('put')
                    <input type="hidden" name="_sheet" value="{{ $sheet }}">
                    {{-- Совком: редко у собственника уже соглашения и акты — тогда к нам едет только доверенность. --}}
                    @if (isset(\App\Offers\Originals::SIGNED[$o->kind]))
                        <x-ui.segment name="kind" :options="['commission' => 'Договор комиссии', 'proxy' => 'Доверенность']" :value="old('_sheet') === $sheet ? old('kind', $o->kind) : $o->kind"/>
                    @endif
                    <div class="list">
                        @foreach ($o->choices() as $value => $label)
                            <label class="row row-check">
                                <span class="min-w-0 flex-1">{{ $label }}</span>
                                <span class="check"><input type="radio" name="state" value="{{ $value }}" @checked(old('_sheet') === $sheet ? old('state') === $value : $o->state === $value)></span>
                            </label>
                        @endforeach
                    </div>
                    @error('state')<p class="field-error">{{ $message }}</p>@enderror
                    <div class="fields"><x-ui.field name="track" :id="$sheet.'-track'" label="Трек СДЭК" :value="old('_sheet') === $sheet ? old('track') : $o->track"/></div>
                    @if ($o->track)
                        <a href="https://www.cdek.ru/ru/tracking?order_id={{ urlencode($o->track) }}" target="_blank" rel="noopener" class="text-sm text-accent-text">Где документ</a>
                    @endif
                    <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
                </form>
                @if ($o->needs_scan || $scans->isNotEmpty())
                    <div class="mt-4 flex flex-col gap-2">
                        <p class="px-1 text-sm text-ink-dim">Скан</p>
                        @foreach ($scans as $media)
                            <x-ui.file :name="\App\Media\DocName::of($media)" :mime="$media->mime_type" :bytes="$media->size" href="/files/{{ $media->id }}">
                                @if ($media->model_type === \App\Offers\Original::class)
                                    <form method="post" action="{{ $base }}/{{ $o->id }}/scan/{{ $media->id }}" data-turbo-confirm="Убрать скан?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Убрать"><x-ui.icon name="x" class="size-5"/></button></form>
                                @endif
                            </x-ui.file>
                        @endforeach
                        @if ($o->kind === 'buyer_dkp')
                            {{-- Скан ДКП с покупателем — подписанный договор сделки: одно место, его же видит «Ждём договор». --}}
                            <x-deal.attach-signed :deal="$deal" :base="'/work/deals/'.$deal->id" label="Приложить скан" wide/>
                        @else
                            <x-ui.photo-pdf :id="'original-scan-'.$o->id" :url="$base.'/'.$o->id.'/scan'" :title="$o->title" :name="$o->title.' '.$deal->offer->title()"
                                icon="clip" :label="$scans->isEmpty() ? 'Приложить скан' : 'Приложить ещё'" always word button="btn btn-s btn-quiet w-full gap-2"/>
                        @endif
                    </div>
                @endif
                @if ($o->waitsManager() && $deal->buyer_id)
                    <form method="post" action="{{ $base }}/nudge" class="mt-3">@csrf<button class="btn btn-quiet btn-s w-full gap-2"><x-ui.icon name="bell" class="size-4"/>Напомнить менеджеру</button></form>
                @endif
                @if ($o->kind === 'extra')
                    <form method="post" action="{{ $base }}/{{ $o->id }}" class="mt-3" data-turbo-confirm="Убрать «{{ $o->title }}»?">@csrf @method('delete')<button class="btn btn-quiet btn-s w-full text-danger">Убрать</button></form>
                @endif
            </x-ui.sheet>
        </div>
    @endforeach
    <div class="contents" data-controller="sheet">
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
