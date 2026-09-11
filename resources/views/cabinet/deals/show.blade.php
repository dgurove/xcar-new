@php
    use App\Offers\DealState;
    use App\Workflow\Asks;
    $currentBlock = $position?->stage->block_id;
    $passed = true;
@endphp
<x-ui.shell :title="'№ '.$offer->number.' · '.$offer->title()" back="/lk/sdelki">
    <div class="flex flex-col gap-4">
        <a href="/offers/{{ $offer->number }}" class="row">
            <div class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="64px"/></div>
            <div class="min-w-0 flex-1">
                <div class="font-medium">{{ $offer->titleWithYear() }}</div>
                <div class="text-sm text-ink-muted">{{ implode(' · ', array_slice($offer->facts(), 1, 3)) }}</div>
            </div>
            <span class="font-semibold tabular-nums">{{ number_format($deal->amount, 0, '', ' ') }} ₽</span>
        </a>

        @if ($deal->state !== DealState::Active)
            <x-ui.card>
                <span class="chip {{ $deal->state === DealState::Done ? 'bg-open-soft text-open' : 'bg-danger-soft text-danger' }}">{{ $deal->state->label() }}</span>
                @if ($deal->closed_at)<span class="ml-2 text-sm text-ink-muted">{{ $deal->closed_at->translatedFormat('j M Y, H:i') }}</span>@endif
            </x-ui.card>
        @elseif ($requirement)
            <x-ui.card :title="$requirement->title" class="bg-accent-soft">
                @if ($requirement->text)<p class="mb-4 whitespace-pre-line">{{ $requirement->text }}</p>@endif
                @if ($requirement->due_at)
                    <div class="mb-4 flex items-center gap-2 text-sm {{ $requirement->due_at->isPast() ? 'text-danger' : 'text-ink-muted' }}">
                        <x-ui.icon name="clock" class="size-4"/>
                        <span>до {{ $requirement->due_at->translatedFormat('j M, H:i') }} ·</span>
                        <span class="tabular-nums" data-controller="timer" data-timer-until-value="{{ $requirement->due_at->toIso8601String() }}" data-timer-done-value="срок вышел"></span>
                    </div>
                @endif
                @if ($position?->payload)
                    <div class="card-nested mb-4 text-sm">
                        @foreach ($position->payload as $k => $v)<div><span class="text-ink-muted">{{ collect($position->stage->staff_fields)->firstWhere('key', $k)['label'] ?? $k }}:</span> {{ $v }}</div>@endforeach
                    </div>
                @endif

                @if ($requirement->asks === Asks::Document)
                    <div class="mb-4" data-controller="photos" data-photos-url-value="/lk/sdelki/{{ $deal->id }}/fayly">
                        <input type="file" accept="image/*,.pdf,.heic" multiple hidden data-photos-target="input" data-action="change->photos#upload">
                        @include('cabinet.deals.files', ['requirement' => $requirement])
                        <div hidden data-photos-target="progress" class="my-2">
                            <div class="mb-1 text-sm text-ink-muted" data-label></div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
                        </div>
                        <x-ui.button type="button" variant="secondary" size="sm" data-action="photos#pick"><x-ui.icon name="camera" class="size-4"/> Приложить</x-ui.button>
                        @if ($errors->has('files'))<p class="field-error mt-2">{{ $errors->first('files') }}</p>@endif
                    </div>
                @endif

                <form method="post" action="/lk/sdelki/{{ $deal->id }}/otvet" class="flex flex-col gap-4">
                    @csrf
                    @if ($requirement->asks === Asks::Fields)
                        @foreach ($requirement->fields as $field)
                            <x-route.field :field="$field" :name="'fields['.$field['key'].']'"/>
                        @endforeach
                    @endif
                    @if ($errors->has('exit'))<p class="field-error">{{ $errors->first('exit') }}</p>@endif
                    <div class="flex flex-col gap-2">
                        @foreach ($exits as $exit)
                            <x-ui.button name="exit" :value="$exit->id" block :variant="$loop->first ? 'primary' : 'secondary'" :data-turbo-confirm="$exit->confirm">{{ $exit->label }}</x-ui.button>
                        @endforeach
                    </div>
                </form>
            </x-ui.card>
        @elseif ($position)
            <x-ui.card :title="$position->stage->managerTitle()">
                @if ($position->stage->managerText())<p class="whitespace-pre-line">{{ $position->stage->managerText() }}</p>@endif
                <div class="mt-3 flex items-center gap-2 text-sm text-ink-muted">
                    <x-ui.icon name="clock" class="size-4"/>
                    <span>с {{ $position->block_entered_at->translatedFormat('j M, H:i') }} ·</span>
                    <span class="tabular-nums" data-controller="timer" data-timer-since-value="{{ $position->block_entered_at->toIso8601String() }}"></span>
                </div>
            </x-ui.card>
        @endif

        @if ($blocks->isNotEmpty())
            <x-ui.card title="Путь">
                <ol class="flex flex-col">
                    @foreach ($blocks as $block)
                        @php
                            $isCurrent = $block->id === $currentBlock;
                            $done = $passed && !$isCurrent;
                            if ($isCurrent) $passed = false;
                            $step = $steps->last(fn ($s) => $s['block'] === $block->name);
                        @endphp
                        <li class="flex gap-3 {{ $loop->last ? '' : 'pb-4' }}">
                            <div class="flex flex-col items-center">
                                <span class="flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $isCurrent ? 'bg-accent text-white' : ($done ? 'bg-accent-soft text-accent-text' : 'bg-surface-3 text-ink-dim') }}">
                                    @if ($done)<x-ui.icon name="check" class="size-3.5"/>@else{{ $loop->iteration }}@endif
                                </span>
                                @unless ($loop->last)<span class="mt-1 w-px flex-1 {{ $done ? 'bg-accent/50' : 'bg-line' }}"></span>@endunless
                            </div>
                            <div class="min-w-0 flex-1 pt-0.5">
                                <div class="{{ $isCurrent ? 'font-medium' : ($done ? '' : 'text-ink-muted') }}">{{ $block->name }}</div>
                                @if ($step && ($done || $isCurrent))<div class="text-sm text-ink-muted">{{ $step['at']->translatedFormat('j M, H:i') }}</div>@endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>
        @endif

        @if ($deal->requirements->where('done_at', '!=', null)->isNotEmpty())
            <x-ui.card title="Ваши ответы">
                <div class="flex flex-col gap-2 text-sm">
                    @foreach ($deal->requirements->whereNotNull('done_at') as $req)
                        @if (!empty($req->answer['exit']))
                            <div class="flex gap-3">
                                <span class="shrink-0 tabular-nums text-ink-dim">{{ $req->done_at->translatedFormat('j M H:i') }}</span>
                                <span>{{ $req->title }} — «{{ $req->answer['exit'] }}»@if (!empty($req->answer['fields'])): {{ implode(', ', $req->answer['fields']) }}@endif</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </x-ui.card>
        @endif
    </div>
</x-ui.shell>
