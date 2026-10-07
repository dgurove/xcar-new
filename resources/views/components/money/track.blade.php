{{-- Путь денег сделки точками на линии, как путь сделки и дело ТС (.steps): пройденное галочкой с датой, текущее —
     крупной точкой и словом, что сейчас, будущее — серым. Шаги считает `Billing\DealMoney::track`. --}}
@props(['steps'])
<div {{ $attributes->class('steps') }}>
    @foreach ($steps as $s)
        <div class="step step--{{ $s['state'] }}">
            <span class="step-dot">@if ($s['state'] === 'done')<x-ui.icon name="check" class="size-3"/>@endif</span>
            <div class="step-body">
                <div class="step-head">
                    <span class="step-title flex-1">{{ $s['title'] }}</span>
                    @if ($s['at'])<span class="nums shrink-0 text-sm text-ink-dim">{{ $s['at']->translatedFormat('j M') }}</span>@endif
                </div>
                @if ($s['hint'])<p class="step-hint {{ match ($s['tone']) { 'urgent' => '!text-urgent', 'danger' => '!text-danger', 'accent' => '!text-accent-text', default => '' } }}">{{ $s['hint'] }}</p>@endif
            </div>
        </div>
    @endforeach
</div>
