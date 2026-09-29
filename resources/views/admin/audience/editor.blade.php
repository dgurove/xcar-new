{{-- Начинка шторки «Кому»: шаблоны (если есть) и волны. Общая для предложения и шаблона в настройках. --}}
@if ($audienceOptions['presets'] ?? [])
    <div class="list mb-4">
        @foreach ($audienceOptions['presets'] as $preset)
            <button type="button" class="row w-full text-left" data-action="audience#preset" data-audience-id-param="{{ $preset['id'] }}">
                <span class="min-w-0 flex-1"><span class="block font-medium">{{ $preset['name'] }}</span>@if ($preset['summary'] !== $preset['name'])<span class="row-sub">{{ $preset['summary'] }}</span>@endif</span>
                <x-ui.icon name="check" class="size-5 shrink-0 text-accent-text" data-audience-mark="{{ $preset['id'] }}" hidden/>
            </button>
        @endforeach
    </div>
@endif
<div class="flex flex-col gap-2" data-audience-target="waves"></div>
<button type="button" class="pill pill-plain mt-3" data-action="audience#add"><x-ui.icon name="plus" class="size-4"/> Волна</button>
