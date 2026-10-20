{{-- Текущий шаг дела — плашкой «что сейчас» сверху страницы (20.10.2026, владелец: «как в CRM установка цен — красиво и
     удобно»): название шага, подсказка и его действие компактными кнопками; в ленте шагов ниже он остаётся строкой. Тем же
     видом, что плашки окошка CRM (`admin/offers/now-block`) и «Ждут от нас» карточки (`park/vehicles/now`). --}}
@php
    $step = $cur;
    $partial = match (true) { $step->key === 'report-release' => 'report', in_array($step->key, ['intake', 'report', 'release'], true) => 'act', str_starts_with($step->key, 'reply') => 'reply', default => $step->key };
@endphp
<section class="flex flex-col gap-1 rounded-(--radius-l) bg-surface-2 p-3 step-now">
    <h2 class="text-base font-medium">{{ $step->title }}</h2>
    @if ($step->hint)<p class="text-sm text-ink-muted">{{ $step->hint }}</p>@endif
    @include('park.vehicles.steps.'.$partial, ['req' => $step->request, 'step' => $step])
    {{-- Висящие дела позади текущего шага (отчёт вендору не отправлен) — строками под ним, кнопкой справа. --}}
    @foreach ($steps as $todo)
        @continue($todo === $step || $todo->state !== \App\Park\Step::TODO || ! $todo->plate || ! in_array($todo->plate['kind'], ['window', 'act'], true))
        <div class="mt-2 flex items-center gap-3 border-t border-line/40 pt-2">
            <span class="min-w-0 flex-1 text-sm">{{ $todo->title }}</span>
            @if ($todo->plate['kind'] === 'window')<x-mail.window-button :url="$todo->plate['url']" :label="$todo->plate['label']" icon="mail" class="btn-s shrink-0"/>
            @else<x-park.act-button :vehicle="$vehicle" :step="$todo->plate['step']" :label="$todo->plate['label']" class="btn btn-s btn-quiet shrink-0"/>@endif
        </div>
    @endforeach
</section>
