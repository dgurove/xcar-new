@php
    use App\Workflow\{Track, WaitsFor, Actor};
    $w = $workflow;
    $base = "/admin/strahovye/{$insurer->id}";
@endphp
<x-ui.shell :title="$insurer->name" back="/admin/strahovye" :wide="true">
    <div class="mb-4 flex flex-wrap items-center gap-2" data-controller="sheet">
        @unless ($insurer->is_active)<span class="chip bg-closed-soft text-closed">выключена</span>@endunless
        <x-ui.button type="button" variant="secondary" size="sm" data-action="sheet#open">Реквизиты</x-ui.button>
        <x-ui.sheet id="insurer-form" title="Страховая" :open="$errors->hasAny(['name', 'email', 'phone'])">
            <form method="post" action="{{ $base }}" class="flex flex-col gap-4">
                @csrf @method('put')
                <x-ui.field name="name" label="Название" :value="$insurer->name" required/>
                <x-ui.field name="contact_name" label="Контакт" :value="$insurer->contact_name"/>
                <x-ui.field name="phone" label="Телефон" type="tel" :value="$insurer->phone"/>
                <x-ui.field name="email" label="Почта" type="email" :value="$insurer->email"/>
                <x-ui.field name="notes" label="Заметки" type="textarea" :value="$insurer->notes"/>
                <x-ui.check name="is_active" :checked="$insurer->is_active">Работаем с ней</x-ui.check>
                <x-ui.button block>Сохранить</x-ui.button>
            </form>
            @if (!$insurer->offers()->exists())
                <form method="post" action="{{ $base }}" class="mt-3" data-turbo-confirm="Удалить страховую?">@csrf @method('delete')<x-ui.button variant="danger" block>Удалить</x-ui.button></form>
            @endif
        </x-ui.sheet>
        <div class="presets ml-auto !mx-0 !px-0">
            @foreach (Track::cases() as $t)
                <a href="{{ $base }}?vetka={{ $t->value }}" class="preset" @if ($track === $t) aria-current="true" @endif>{{ $t->label() }}</a>
            @endforeach
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        @if ($w->is_active)
            <span class="chip bg-open-soft text-open">Маршрут включён</span>
            <form method="post" action="/admin/marshruty/{{ $w->id }}/vklyuchit">@csrf<input type="hidden" name="active" value="0"><x-ui.button variant="ghost" size="sm">Выключить</x-ui.button></form>
        @elseif (!$problems)
            <span class="chip bg-closed-soft text-closed">Маршрут выключен</span>
            <form method="post" action="/admin/marshruty/{{ $w->id }}/vklyuchit">@csrf<input type="hidden" name="active" value="1"><x-ui.button size="sm">Включить</x-ui.button></form>
        @endif
        @if ($errors->has('workflow'))<span class="field-error w-full">{{ $errors->first('workflow') }}</span>@endif
        @if ($errors->has('stage'))<span class="field-error w-full">{{ $errors->first('stage') }}</span>@endif
        @if ($errors->has('block'))<span class="field-error w-full">{{ $errors->first('block') }}</span>@endif
    </div>

    @if ($problems && $w->blocks->isNotEmpty())
        <div class="card mb-4 border-l-4 border-danger">
            <div class="mb-2 font-medium text-danger">Маршрут не включится</div>
            <ul class="flex flex-col gap-1 text-sm">@foreach ($problems as $p)<li>{{ $p }}</li>@endforeach</ul>
        </div>
    @endif

    <div data-controller="sortable" data-sortable-url-value="/admin/marshruty/{{ $w->id }}/poryadok">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" data-sortable-target="blocks">
            @foreach ($w->blocks as $block)
                <x-ui.card data-block-id="{{ $block->id }}" class="flex flex-col" data-controller="sheet">
                    <div class="mb-3 flex items-center gap-2">
                        <span class="cursor-grab text-ink-dim md:touch-none" data-handle><x-ui.icon name="grip" class="size-5"/></span>
                        <h2 class="flex-1 text-lg">{{ $block->name }}</h2>
                        <button type="button" class="btn btn-ghost btn-sm px-2" data-action="sheet#open" aria-label="Блок"><x-ui.icon name="edit" class="size-5"/></button>
                    </div>
                    <x-ui.sheet id="block-{{ $block->id }}" title="Блок">
                        <form method="post" action="/admin/marshruty/bloki/{{ $block->id }}" class="flex flex-col gap-4">
                            @csrf @method('put')
                            <x-ui.field name="name" label="Название для менеджера" :value="$block->name" required/>
                            <x-ui.field name="text" label="Что менеджер читает на этом шаге" type="textarea" :value="$block->text"/>
                            <x-ui.button block>Сохранить</x-ui.button>
                        </form>
                        @if ($block->stages->isEmpty())
                            <form method="post" action="/admin/marshruty/bloki/{{ $block->id }}" class="mt-3">@csrf @method('delete')<x-ui.button variant="danger" block>Удалить блок</x-ui.button></form>
                        @endif
                    </x-ui.sheet>
                    <div class="flex min-h-12 flex-col gap-2" data-sortable-target="stages">
                        @foreach ($block->stages as $stage)
                            <a href="/admin/marshruty/etapy/{{ $stage->id }}" class="card-nested block" data-stage-id="{{ $stage->id }}">
                                <div class="flex items-start gap-2">
                                    <span class="flex-1 font-medium">{{ $stage->name }}</span>
                                    @if ($occupied[$stage->id] ?? 0)<span class="badge">{{ $occupied[$stage->id] }}</span>@endif
                                </div>
                                <div class="mt-1.5 flex flex-wrap gap-1 text-sm">
                                    <span class="chip">{{ $stage->waits_for->label() }}</span>
                                    @if ($stage->deadline_source !== \App\Workflow\DeadlineSource::Own)
                                        <span class="chip">{{ $stage->deadline_source->label() }}</span>
                                    @elseif ($stage->limit_minutes)
                                        <span class="chip">{{ $stage->limit_minutes >= 1440 ? intdiv($stage->limit_minutes, 1440).' д' : ($stage->limit_minutes >= 60 ? intdiv($stage->limit_minutes, 60).' ч' : $stage->limit_minutes.' мин') }}</span>
                                    @endif
                                    @if ($stage->offer_state)<x-offer.state :state="$stage->offer_state"/>@endif
                                    @if ($stage->car_place)<span class="chip bg-open-soft text-open">{{ $stage->car_place->label() }}</span>@endif
                                    @if ($stage->asks !== \App\Workflow\Asks::Nothing)<span class="chip">{{ $stage->asks->label() }}</span>@endif
                                </div>
                                @if ($stage->exits->isNotEmpty())
                                    <div class="mt-2 flex flex-col gap-0.5 text-sm text-ink-muted">
                                        @foreach ($stage->exits as $exit)
                                            <div class="flex gap-1.5"><span class="{{ $exit->actor === Actor::Manager ? 'text-accent-text' : ($exit->actor === Actor::Timer ? 'text-urgent' : 'text-ink') }}">{{ $exit->label }}</span><span>→ {{ $exit->to?->name ?? '…' }}</span></div>
                                        @endforeach
                                    </div>
                                @endif
                            </a>
                        @endforeach
                    </div>
                    <a href="/admin/marshruty/{{ $w->id }}/etapy/novyy?blok={{ $block->id }}" class="btn btn-ghost btn-sm mt-2 self-start"><x-ui.icon name="plus" class="size-4"/> Этап</a>
                </x-ui.card>
            @endforeach
        </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2" data-controller="sheet">
        <x-ui.button type="button" variant="secondary" data-action="sheet#open"><x-ui.icon name="plus" class="size-5"/> Блок</x-ui.button>
        @if ($w->blocks->isEmpty())
            <form method="post" action="/admin/marshruty/{{ $w->id }}/tipovoy">@csrf<x-ui.button>Заполнить типовым маршрутом</x-ui.button></form>
        @endif
        <x-ui.sheet id="block-new" title="Новый блок">
            <form method="post" action="/admin/marshruty/{{ $w->id }}/bloki" class="flex flex-col gap-4">
                @csrf
                <x-ui.field name="name" label="Название для менеджера" required autofocus/>
                <x-ui.field name="text" label="Что менеджер читает на этом шаге" type="textarea"/>
                <x-ui.button block>Добавить</x-ui.button>
            </form>
        </x-ui.sheet>
    </div>
</x-ui.shell>
