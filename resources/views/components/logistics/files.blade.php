{{-- Фото и документы машины в задаче вывоза (19.10.2026, админы и Татьяна: «не хватает документов и фоток из писем»).
     Кто что видит — `Logistics\FileAccess`: ведущие — всё и глазом решают за логиста (без логиста — за исполнителя), логист —
     открытое ему и глазом решает за исполнителя, исполнитель — открытое ему, без глаз. Глаз кадра — тот же, что в ряду кадров
     (`x-ui.photos` с `hideBy`, `photos#hide` → `/transports/{id}/files/{media}/hide`), у документа — круглая кнопка в строке.
     Ответ на глаз — этот блок целиком (`files-stream`). Пусто — блока нет.
     22.10.2026 (владелец со слов Татьяны: «зачем фотки два раза», «логист не может прикреплять» — исполнитель шлёт ей фото
     почтой и мессенджерами): ленты сверху карточки больше нет, кадры машины — здесь всем, кто их видит; ниже «Фото при
     вывозе» (`x-logistics.photos`, `pickup` — добавляют `pickup`: ведущие и логист всегда, исполнитель до «Забрал»; пока
     открыта шторка «Забрал» — копия `view`, снимают в ней) и «+ Документ» ведущим и логисту (`TransportController::paper`). --}}
@props(['t'])
@php
    use App\Logistics\FileAccess;
    $me = auth()->user();
    $files = FileAccess::files($t);
    $mine = fn ($m) => FileAccess::visible($t, $me, $m);
    // За кого решает глаз этого человека; кто без глаза — видит открытое ему, без глаз.
    $who = FileAccess::toggleFor($t, $me);
    $photos = $files['photos']->filter($mine)->values();
    // Ведёт задачу за исполнителя (ведущие и логист): кадры и документы добавляет и после «Забрал» — их присылают потом.
    $keeps = ($me->leadsLogistics() && ! $t->isOrder()) || $t->hasLogist($me);
    $pickupEdit = $t->isPickup() && $t->isHandledBy($me) && $t->executor_id && ($keeps ? $t->state !== \App\Logistics\TransportState::Cancelled : $t->isOpen() && ! $t->state->picked());
    // Шторка «Забрал» открыта к нажатию — снимают в ней, здесь копия для просмотра (свой id, поток загрузки меняет обе).
    $pickupView = $t->state === \App\Logistics\TransportState::Agreed && ($t->isExecutedBy($me) || $keeps) && ! $t->pickupBlocker($me);
    $pickup = $t->isPickup() && ($pickupEdit || $t->photos('pickup')->isNotEmpty());
    $addsPapers = $t->car && $keeps;
    $papers = $files['papers']->filter($mine)->values();
    $eye = $who === 'logist' ? 'Видит логист' : 'Видит исполнитель';
@endphp
<section id="transport-files-{{ $t->id }}" @if ($photos->isEmpty() && $papers->isEmpty() && ! $pickup && ! $addsPapers) hidden @endif class="mt-5">
    @if ($photos->isNotEmpty())
        <div data-controller="photos" data-photos-url-value="/transports/{{ $t->id }}/files" data-photos-readonly-value="true">
            <h3 class="list-head">Фото <span class="nums font-normal text-ink-dim">{{ $photos->count() }}</span></h3>
            <x-ui.photos :photos="$photos" readonly :hide="(bool) $who" :hide-by="$who ? fn ($m) => ! FileAccess::sees($m, $who) : null" :eye-title="$eye" :main="false" :id="'transport-files-photos-'.$t->id"/>
        </div>
    @endif
    @if ($pickup)
        <x-logistics.photos :t="$t" at="pickup" :edit="$pickupEdit" :view="$pickupView" class="{{ $photos->isNotEmpty() ? '!mt-4' : '!mt-0' }}"/>
    @endif
    @if ($papers->isNotEmpty() || $addsPapers)
        <div class="list-head mt-4 flex items-center justify-between gap-3">
            <span>Документы@if ($papers->isNotEmpty()) <span class="nums font-normal text-ink-dim">{{ $papers->count() }}</span>@endif</span>
            @if ($addsPapers)
                <form method="post" action="/transports/{{ $t->id }}/papers" enctype="multipart/form-data" data-controller="autosubmit" class="contents">@csrf
                    <label class="cursor-pointer text-sm font-normal text-accent-text">+ Документ<input type="file" name="files[]" multiple class="sr-only" tabindex="-1" data-action="change->autosubmit#submit"></label>
                </form>
            @endif
        </div>
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
