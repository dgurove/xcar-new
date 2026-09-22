{{-- Теги разбора письма — те же, что в строке почты: что просят, кто просит, чем ТС опознана, к какому сроку.
     Оранжевым — только то, что требует человека: ждёт ответа, продана, срок ответа, чего в письме не хватает. --}}
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
<div class="flex flex-wrap items-center gap-1.5">
    @if ($waits)<span class="tag tag-urgent">Ждёт ответа</span>@endif
    {{-- Пока цепочка на заявке — что просят (вывоз или привоз, то же слово, что в «Из писем»); дальше говорит этап. --}}
    @if ($stage !== CandidateStage::Intake)<span class="tag {{ $stage === CandidateStage::Sold ? 'tag-urgent' : '' }}">{{ $c->stageLabel() }}</span>
    @elseif ($intent === null || $intent === Intent::Intake)<span class="tag">{{ $c->requestTag() }}</span>
    @elseif ($intent->short())<span class="tag {{ $intent->tone() }}">{{ $intent->short() }}</span>@endif
    @if ($by)<span class="tag nums {{ $by->isPast() ? 'tag-danger' : 'tag-urgent' }}">до {{ $by->translatedFormat('j M H:i') }}</span>@endif
    @if ($c->vendor?->name ?? $v('vendor'))<span class="tag">{{ $c->vendor?->name ?? $v('vendor') }}</span>@endif
    @if ($c->code)<x-ui.copy-code class="tag" :value="$c->code"/>@endif
    @if ($v('plate'))<x-ui.copy-code class="tag" :value="$v('plate')" done="Госномер в буфере" title="Скопировать госномер"/>@endif
    <x-ui.vin-code :vin="$v('vin')" class="tag"/>
    @if ($phone)<a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}" class="tag nums">{{ $phone }}</a>@endif
    @if (! $v('brand') && ! $v('vin'))<span class="tag tag-urgent">Марки и VIN в письме нет</span>@elseif (! $v('vin'))<span class="tag tag-urgent">VIN в письме нет</span>@endif
</div>
