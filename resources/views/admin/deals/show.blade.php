@php
    use App\Offers\DealState;
    use App\Workflow\Asks;
    $position = $offer->position();
    $open = $deal->requirements->whereNull('done_at');
    $answered = $deal->requirements->whereNotNull('done_at')->sortByDesc('done_at');
@endphp
<x-ui.shell :title="$offer->titleWithYear()" :back="['Сделки', '/work/deals']">
    <div class="-mt-3 mb-4 flex flex-wrap items-center gap-1.5">
        @if ($deal->state !== DealState::Active)
            <x-ui.pill :tone="$deal->state === DealState::Done ? 'open' : 'danger'">{{ $deal->state->label() }}</x-ui.pill>
            @if ($deal->closed_at)<x-ui.pill tone="plain"><span>{{ $deal->closed_at->translatedFormat('j M Y, H:i') }}</span></x-ui.pill>@endif
        @elseif ($position)
            <x-route.status :position="$position"/>
        @else
            <x-ui.pill :tone="$offer->state->tone()">{{ $offer->state->label() }}</x-ui.pill>
        @endif
        <x-ui.pill tone="plain" href="/offers/{{ $offer->number }}"><x-ui.icon name="car" class="size-4"/> Предложение № {{ $offer->number }}</x-ui.pill>
        @if ($errors->has('exit'))<x-ui.flash tone="danger" class="w-full">{{ $errors->first('exit') }}</x-ui.flash>@endif
    </div>

    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="contents lg:col-start-1 lg:row-start-1 lg:flex lg:flex-col lg:gap-4">
            @if ($offer->positions->isNotEmpty())
                @include('admin.offers.route')
            @endif

            @if ($open->isNotEmpty())
            <x-ui.card title="Ждём от менеджера" class="order-2 box-urgent">
                <div class="flex flex-col gap-2">
                    @foreach ($open as $r)
                        <div class="box-nested">
                            <div class="font-medium">{{ $r->title }}</div>
                            @if ($r->text)<div class="mt-1 whitespace-pre-line text-sm text-ink-muted">{{ $r->text }}</div>@endif
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                @if ($r->stage?->block?->name)<span class="tag">{{ $r->stage->block->name }}</span>@endif
                                @if ($r->due_at)<span class="tag nums" @if ($r->due_at->isPast()) style="--tag-bg:#fef3c7;--tag-text:#92400e;--tag-bg-d:#3f2606;--tag-text-d:#fcd34d" @endif>до {{ $r->due_at->translatedFormat('j M, H:i') }}</span>@endif
                            </div>
                            @if ($r->media->isNotEmpty())
                                <div class="mt-2 flex flex-col">
                                    @foreach ($r->media as $f)<x-ui.file :name="$f->file_name" :mime="$f->mime_type" :size="$f->humanReadableSize" href="/files/{{ $f->id }}" class="py-1"/>@endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
            @endif

            @if ($answered->isNotEmpty())
            <x-ui.card title="Ответы менеджера" class="order-3">
                <div class="flex flex-col gap-2">
                    @foreach ($answered as $r)
                        <div class="box-nested">
                            <div class="flex flex-wrap items-baseline gap-x-2">
                                <span class="font-medium">{{ $r->title }}</span>
                                <span class="text-sm text-ink-muted">{{ $r->done_at->translatedFormat('j M, H:i') }}</span>
                            </div>
                            @if ($r->answer['exit'] ?? null)<div class="mt-1 text-sm">«{{ $r->answer['exit'] }}»</div>@endif
                            @if (!empty($r->answer['fields']))
                                <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-3">
                                    @foreach ($r->answer['fields'] as $k => $v)
                                        <div class="min-w-0"><dt class="text-ink-dim">{{ collect($r->fields)->firstWhere('key', $k)['label'] ?? $k }}</dt><dd class="break-words">{{ is_array($v) ? implode(', ', $v) : $v }}</dd></div>
                                    @endforeach
                                </dl>
                            @endif
                            @if ($r->media->isNotEmpty())
                                <div class="mt-2 flex flex-col">
                                    @foreach ($r->media as $f)<x-ui.file :name="$f->file_name" :mime="$f->mime_type" :size="$f->humanReadableSize" href="/files/{{ $f->id }}" class="py-1"/>@endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
            @endif

            <x-ui.card title="Заметка" class="order-4" id="deal-note">
                <form method="post" action="/work/deals/{{ $deal->id }}/note" class="flex flex-col gap-2">
                    @csrf
                    <textarea name="notes" class="field-input" placeholder="Что важно помнить по этой сделке">{{ old('notes', $deal->notes) }}</textarea>
                    <x-ui.button size="sm" variant="secondary" class="self-end">Сохранить</x-ui.button>
                </form>
            </x-ui.card>
        </div>

        <div class="contents lg:col-start-2 lg:row-start-1 lg:flex lg:flex-col lg:gap-4">
            <x-ui.card class="order-1 overflow-hidden !p-0">
                <a href="/offers/{{ $offer->number }}" class="block aspect-[4/3] bg-surface-3"><x-offer.photo :media="$offer->mainPhoto()" sizes="(min-width: 1024px) 352px, 100vw" class="size-full object-cover"/></a>
                <div class="p-4">
                    <div class="nums text-2xl font-bold leading-none">{{ \App\Support\Money::rub($deal->amount) }}</div>
                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                        @if ($offer->asking_price)<span class="tag nums">продажа {{ \App\Support\Money::rub($offer->asking_price) }}</span>@endif
                        <span class="tag nums">{{ $deal->created_at->translatedFormat('j M Y') }}</span>
                    </div>
                    @if ($deal->buyer)<a href="/settings/users/{{ $deal->buyer_id }}" class="mt-3 block font-medium">{{ $deal->buyer->name }}</a>@endif
                    @if ($deal->buyer?->phone)<a href="tel:+{{ $deal->buyer->phone }}" class="text-accent-text">{{ $deal->buyer->phoneFormatted() }}</a>@endif
                    @if ($deal->buyer?->email)<div class="text-sm text-ink-muted">{{ $deal->buyer->email }}</div>@endif
                    @if ($deal->bid?->comment)<div class="mt-2 text-sm">{{ $deal->bid->comment }}</div>@endif
                </div>
            </x-ui.card>

            <x-ui.card title="История" class="order-5">
                <div class="flex flex-col gap-1.5 text-sm">
                    @forelse ($events->take(40) as $event)
                        <div class="flex gap-3">
                            <span class="shrink-0 text-ink-dim">{{ $event->created_at->translatedFormat('j M H:i') }}</span>
                            <span class="min-w-0">{{ $event->text() }}</span>
                            @if ($event->user)<span class="ml-auto shrink-0 text-ink-muted">{{ $event->user->shortName() }}</span>@endif
                        </div>
                    @empty
                        <div class="text-ink-muted">Пока ничего.</div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
</x-ui.shell>
