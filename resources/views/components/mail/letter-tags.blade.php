{{-- Теги разбора письма — те же, что в строке почты: что просят, кто просит, чем ТС опознана, к какому сроку.
     Оранжевым — только то, что требует человека: ждёт ответа, на выдачу, срок ответа, чего в письме не хватает. --}}
@props(['candidate', 'letter' => null, 'waits' => false])
@php
    use App\Mail\CandidateStage;
    use App\Mail\Extraction\Intent;
    $c = $candidate;
    $v = fn (string $f) => $c->value($f);
    $intent = $letter ? Intent::tryFrom((string) $letter->intent) : null;
    $phone = $v('insured_phone') ?? ((array) $v('phones'))[0] ?? null;
    $by = $v('answer_by') ? \Illuminate\Support\Carbon::parse($v('answer_by'))->timezone('Europe/Moscow') : null;
    $stage = $c->stage ?? CandidateStage::Intake;
@endphp
{{-- 10.10.2026, владелец: «одно к машине относится, другое к фактам — что-то чипами, что-то текстом». Первым рядом —
     что с письмом словами (ждёт ответа, что просят, чего не хватает) и коды чипами (убыток с логотипом вендора, госномер,
     VIN); строкой ниже — к какому сроку и кто страхователь (телефон ссылкой). --}}
<div class="flex flex-wrap items-center gap-1.5">
    @if ($waits)<x-ui.state tone="urgent">Ждёт ответа</x-ui.state>@endif
    {{-- Пока цепочка на заявке — что просят (вывоз или привоз, то же слово, что в «Из писем»); дальше говорит этап. --}}
    @if ($stage !== CandidateStage::Intake)<x-ui.state :tone="$stage === CandidateStage::Sold ? 'urgent' : 'plain'">{{ $c->stageLabel() }}</x-ui.state>
    @elseif ($intent === null || $intent === Intent::Intake)<x-ui.state>{{ $c->requestTag() }}</x-ui.state>
    @elseif ($intent->short())<span class="tag {{ $intent->tone() }}">{{ $intent->short() }}</span>@endif
    @if (! $v('brand') && ! $v('vin'))<x-ui.state tone="urgent">Марки и VIN в письме нет</x-ui.state>@elseif (! $v('vin'))<x-ui.state tone="urgent">VIN в письме нет</x-ui.state>@endif
    @if (! $c->vendor)<x-mail.vendor-pick :pick="\App\Mail\VendorPick::of(auth()->user(), $c->scope, $c->scope === \App\Mail\Scope::Park ? '/mail' : '/work/mail', $c->thread, $c->relationLoaded('messages') ? $c->messages : [], $c)"/>@endif
    @if ($c->code)<x-vendor.ref :vendor="$c->vendor" :ref="$c->code" copy class="tag nums"/>@elseif ($c->vendor)<x-vendor.name :vendor="$c->vendor" class="tag"/>@elseif ($v('vendor'))<span class="tag">{{ $v('vendor') }}</span>@endif
    @if ($v('plate'))<x-ui.copy-code class="tag" :value="$v('plate')" done="Госномер в буфере" title="Скопировать госномер"/>@endif
    <x-ui.vin-code :vin="$v('vin')" class="tag"/>
    @if ($by || $phone || $v('insured_name'))
        <div class="detail-line basis-full !mt-0.5 text-sm">
            @if ($by)<span class="nums {{ $by->isPast() ? 'text-danger' : 'text-urgent' }}">ответить до {{ $by->translatedFormat('j M, H:i') }}</span>@endif
            @if ($v('insured_name'))<span>{{ $v('insured_name') }}</span>@endif
            @if ($phone)<a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}" class="nums whitespace-nowrap text-accent-text">{{ $phone }}</a>@endif
        </div>
    @endif
</div>
