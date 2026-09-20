{{-- Почта: Входящие · Отправленные · Архив (ветка там, где её последнее письмо, как в Gmail). Во «Входящих» секции:
     «Не разобрано» (письма без ТС и кандидата, справа «Заявка ›»), кандидаты «Из писем» (справа «Завести ›»), ТС
     (CRM — предложения). Поиск — по всем письмам сразу, список плоский. Любая строка открывает окно писем ветки
     (x-mail.window); ?window=id — открыть окно сразу (ссылки из уведомлений). --}}
@php $crm = ! $park; @endphp
<x-ui.shell title="Почта" :heading="false">
    @if ($crm)
        <x-admin.work-titles current="mail" :count="$threads->total()"/>
    @else
        <x-ui.section-title level="h1" :count="$threads->total()">Почта</x-ui.section-title>
    @endif

    <x-ui.toolbar class="mt-5" :pills="\App\Http\Admin\MailController::BOXES" :pill="$box" pill-param="box" :counts="['inbox' => $unread]" :pill-default="$q === ''" name="mail">
        <x-slot:extra>
            <a href="{{ $base }}/new" class="btn btn-s btn-accent shrink-0 rounded-full"><x-ui.icon name="edit" class="size-4"/><span class="hidden sm:inline">Написать</span></a>
        </x-slot:extra>
        <x-slot:filters>
            <input type="search" name="q" value="{{ $q }}" placeholder="Тема, текст, адрес, файл, номер, VIN, госномер" class="field-input field-s" enterkeyhint="search">
        </x-slot:filters>
    </x-ui.toolbar>

    @if ($accounts->isEmpty())
        @if ($crm)<x-ui.empty class="mt-6" href="/settings/mailboxes/new" link="Завести ящик">Ящиков ещё нет</x-ui.empty>@else<x-ui.empty class="mt-6">Ящиков ещё нет</x-ui.empty>@endif
    @elseif ($threads->isEmpty())
        <x-ui.empty class="mt-6">{{ $q !== '' ? 'Ничего не нашлось' : 'Писем нет' }}</x-ui.empty>
    @elseif ($sections !== null)
        <div class="mt-6 flex flex-col gap-6" id="threads">
            @foreach ($sections as $section)
                <section>
                    @if ($section['vehicle'])
                        @php $v = $section['vehicle']; @endphp
                        <div class="mb-2 flex flex-wrap items-center gap-1.5">
                            <a href="{{ \App\Support\Surface::Park->url('/cars/'.$v->id) }}" class="font-medium hover:text-accent-text" @if ($crm) data-turbo="false" @endif>{{ $v->titleWithYear() }}@if ($v->plate) <span class="nums font-normal text-ink-muted">{{ $v->plate }}</span>@endif</a>
                            <x-park.state :vehicle="$v"/>
                        </div>
                    @elseif ($section['offer'])
                        @php $o = $section['offer']; @endphp
                        <div class="mb-2 flex flex-wrap items-center gap-1.5">
                            <a href="/offers/{{ $o->number }}" class="font-medium hover:text-accent-text">{{ $o->title() }} <span class="nums font-normal text-ink-muted">№ {{ $o->number }}</span></a>
                            <span class="chip">{{ $o->state->label() }}</span>
                        </div>
                    @elseif ($section['candidate'])
                        @php $c = $section['candidate']; $cv = fn ($f) => $c->extracted[$f]['value'] ?? null; @endphp
                        <div class="mb-2 flex items-start gap-3">
                            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                                <span class="font-medium">{{ $c->title() }}</span>
                                @if ($c->code && $c->hasCar())<span class="tag nums">{{ $c->code }}</span>@endif
                                @if ($cv('plate'))<span class="tag nums">{{ $cv('plate') }}</span>@endif
                                @if ($c->vendor?->name ?? $cv('vendor'))<span class="tag">{{ $c->vendor?->name ?? $cv('vendor') }}</span>@endif
                            </div>
                            @if ($c->state === \App\Mail\CandidateState::Rejected)
                                <span class="shrink-0 text-sm text-ink-dim">В архиве</span>
                            @elseif ($c->state === \App\Mail\CandidateState::Promoted)
                                <a href="{{ $park ? '/cars/'.$c->vehicle_id : '/offers/'.$c->offer?->number }}" class="shrink-0 text-sm text-ink-muted hover:text-accent-text">{{ $park ? 'ТС' : 'Предложение' }} ›</a>
                            @elseif ($park)
                                <a href="/requests/new?candidate={{ $c->id }}" class="shrink-0 text-sm text-accent-text">Завести ›</a>
                            @else
                                <form method="post" action="/offers/from-mail/{{ $c->id }}/create" class="shrink-0">@csrf<button class="text-sm text-accent-text">Завести ›</button></form>
                            @endif
                        </div>
                    @else
                        <div class="mb-2 text-sm text-ink-muted">Не разобрано</div>
                    @endif
                    <div class="flex flex-col gap-2">
                        @foreach ($section['threads'] as $thread)
                            <x-mail.thread-row :thread="$thread" :base="$base" :park="$park" :action="$section['key'] === 'none'"/>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
        @if ($threads->hasPages())<div class="mt-8"><x-ui.pager :of="$threads"/></div>@endif
    @else
        <div class="mt-6 flex flex-col gap-2" id="threads">
            @foreach ($threads as $thread)
                <x-mail.thread-row :thread="$thread" :base="$base" :park="$park" linked/>
            @endforeach
        </div>
        @if ($threads->hasPages())<div class="mt-8"><x-ui.pager :of="$threads"/></div>@endif
    @endif
    <x-mail.window :url="$window ?? null"/>
</x-ui.shell>
