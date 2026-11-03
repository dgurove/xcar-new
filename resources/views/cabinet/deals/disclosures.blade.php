{{-- Из писем страховой менеджер видит только то, что показал админ (`Offers\Disclosure`, 03.11.2026): разами, по порядку.
     Внутри задачи — между тем, что сделать, и кнопками: с кем связаться, видно сразу. --}}
@php
    $disclosures ??= collect();
    // Всё показанное уже стоит в «Получении автомобиля» — блока нет (пустой блок не рисуется).
    $handover ??= null;
    $seen = fn ($d) => collect($d->besides($handover))->where('kind', '!=', 'file')->isNotEmpty() || $d->files()->isNotEmpty();
    $early = ($early ?? collect())->filter($seen)->values();
    $late = ($late ?? $disclosures)->filter($seen)->values();
@endphp
@if ($early->isNotEmpty() || $late->isNotEmpty())
<section @class(['mt-5' => ! ($bare ?? false)])>
    <h3 class="text-sm font-medium text-ink-dim">Информация по сделке</h3>
    <div class="mt-2 flex flex-col gap-2">
        @if ($early->isNotEmpty())
            <details class="reply-more">
                <summary class="chip">Ещё {{ $early->count() }}</summary>
                <div class="mt-2 flex flex-col gap-2">
                    @foreach ($early as $disclosure)@include('cabinet.deals.disclosure')@endforeach
                </div>
            </details>
        @endif
        @foreach ($late as $disclosure)@include('cabinet.deals.disclosure')@endforeach
    </div>
</section>
@endif
