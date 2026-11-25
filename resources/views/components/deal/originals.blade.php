{{-- Оригиналы документов — шаг «Оригиналы» сделки Совкомбанка (26.11.2026, `Offers\Originals`): документ строкой, под ним где он
     сейчас («у исполнителя вывоза», «едет СДЭКом, трек …», «у поставщика»); нажатие — шторка: где документ (строки с отметкой),
     трек СДЭК, «Где документ» у СДЭКа; у подписанного ДКП — «Попросить менеджера прислать» (в чат). «+ Документ» — доп. соглашение
     и прочее. Всё на месте — шаг проходит сам. --}}
@props(['deal'])
@php
    $items = \App\Offers\Originals::of($deal);
    $base = '/work/deals/'.$deal->id.'/originals';
@endphp
<div {{ $attributes->class(['list']) }}>
    {{-- Сделка встала на шаг раньше, чем появились документы шага, — завести три по умолчанию. --}}
    @if ($items->isEmpty())
        <form method="post" action="{{ $base }}/open" class="contents">@csrf<button class="row w-full text-left text-accent-text"><x-ui.icon name="file" class="size-5 shrink-0"/><span class="min-w-0 flex-1">Завести документы</span></button></form>
    @endif
    @foreach ($items as $o)
        @php $sheet = 'original-'.$o->id; @endphp
        <div class="contents" data-controller="sheet">
            <button type="button" class="row w-full text-left" data-action="sheet#open" aria-controls="{{ $sheet }}">
                <x-ui.icon :name="$o->settled() ? 'check-circle' : 'file'" @class(['size-5 shrink-0', 'text-open' => $o->settled(), 'text-ink-dim' => ! $o->settled()])/>
                <span class="min-w-0 flex-1">
                    <span class="block">{{ $o->title }}</span>
                    <span @class(['row-sub', 'text-accent-text' => $o->ours()])>{{ $o->place() }}@if ($o->track && $o->state === 'sent'), трек {{ $o->track }}@endif</span>
                </span>
                <x-ui.chevron/>
            </button>
            <x-ui.sheet :id="$sheet" :title="$o->title" :open="old('_sheet') === $sheet">
                <form method="post" action="{{ $base }}/{{ $o->id }}" class="flex flex-col gap-4">
                    @csrf @method('put')
                    <input type="hidden" name="_sheet" value="{{ $sheet }}">
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
                @if ($o->kind === 'dkp_back' && $o->state === 'waiting' && $deal->buyer_id)
                    <form method="post" action="{{ $base }}/nudge" class="mt-3">@csrf<button class="btn btn-quiet btn-s w-full gap-2"><x-ui.icon name="bell" class="size-4"/>Попросить менеджера прислать</button></form>
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
                <div class="fields"><x-ui.field name="title" :id="'original-new-title-'.$deal->id" label="Что за документ" placeholder="Доп. соглашение к договору комиссии" required/></div>
                <x-ui.segment name="to" :options="\App\Offers\Originals::DESTINATIONS" value="supplier"/>
                <x-ui.sheet-foot>Добавить</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
</div>
