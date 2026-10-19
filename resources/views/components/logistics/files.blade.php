{{-- Фото и документы машины в задаче вывоза (19.10.2026, админы и Татьяна: «не хватает документов и фоток из писем»).
     Кто что видит — `Logistics\FileAccess`: ведущие — всё и глазом решают за логиста (без логиста — за исполнителя), логист —
     открытое ему и глазом решает за исполнителя, исполнитель — открытое ему, без глаз. Глаз кадра — тот же, что в ряду кадров
     (`x-ui.photos` с `hideBy`, `photos#hide` → `/transports/{id}/files/{media}/hide`), у документа — круглая кнопка в строке.
     Ответ на глаз — этот блок целиком (`files-stream`). Пусто — блока нет. --}}
@props(['t'])
@php
    use App\Logistics\FileAccess;
    $me = auth()->user();
    $files = FileAccess::files($t);
    $mine = fn ($m) => FileAccess::visible($t, $me, $m);
    // За кого решает глаз этого человека. Кадры блоком — только тому, у кого есть глаз: остальным хватает ленты сверху.
    $who = FileAccess::toggleFor($t, $me);
    $photos = $who ? $files['photos']->filter($mine)->values() : collect();
    $papers = $files['papers']->filter($mine)->values();
    $eye = $who === 'logist' ? 'Видит логист' : 'Видит исполнитель';
@endphp
<section id="transport-files-{{ $t->id }}" @if ($photos->isEmpty() && $papers->isEmpty()) hidden @endif class="mt-5">
    @if ($photos->isNotEmpty())
        <div data-controller="photos" data-photos-url-value="/transports/{{ $t->id }}/files" data-photos-readonly-value="true">
            <h3 class="list-head">Фото <span class="nums font-normal text-ink-dim">{{ $photos->count() }}</span></h3>
            <x-ui.photos :photos="$photos" readonly :hide="(bool) $who" :hide-by="$who ? fn ($m) => ! FileAccess::sees($m, $who) : null" :eye-title="$eye" :main="false" :id="'transport-files-photos-'.$t->id"/>
        </div>
    @endif
    @if ($papers->isNotEmpty())
        <h3 class="list-head mt-4">Документы <span class="nums font-normal text-ink-dim">{{ $papers->count() }}</span></h3>
        <div class="flex flex-col">
            @foreach ($papers as $m)
                @php $open = $who ? FileAccess::sees($m, $who) : true; @endphp
                <x-ui.file :name="$m->file_name" :mime="$m->mime_type" :size="$m->humanReadableSize" :href="'/files/'.$m->id">
                    @if ($who)
                        {{-- Глаз как у «Видно менеджеру» в CRM: открыт — лаймовый, закрыт — серый. --}}
                        <form method="post" action="/transports/{{ $t->id }}/files/{{ $m->id }}/eye" class="contents">@csrf
                            <button @class(['btn btn-ghost btn-s px-2', 'text-accent-text' => $open, 'text-ink-dim' => ! $open]) title="{{ $eye }}: {{ $open ? 'да' : 'нет' }}" aria-label="{{ $eye }}: {{ $open ? 'скрыть' : 'показать' }}">
                                <x-ui.icon :name="$open ? 'eye' : 'eye-off'" class="size-5"/>
                            </button>
                        </form>
                    @endif
                </x-ui.file>
            @endforeach
        </div>
    @endif
</section>
