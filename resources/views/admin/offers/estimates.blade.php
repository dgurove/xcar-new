{{-- Оценки менеджеров под полем цены продажи (11.10.2026): оценил — сумма и «Взять» / «Отклонить», думает — срок и «×»;
     ниже «Отдать менеджеру на оценку». Строка — как цены закупки под ней: аватар, имя, справа своё. --}}
@php $n = $offer->number; @endphp
<div class="mt-3 flex flex-col gap-2 text-sm">
    @foreach ($offer->openEstimates as $e)
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
            <span class="flex min-w-0 items-center gap-2"><x-ui.avatar :user="$e->user" :size="20"/><span class="truncate">{{ $e->user->shortName() }}</span></span>
            @if ($e->isGiven())
                <span class="ml-auto flex items-center gap-2">
                    <form method="post" action="/offers/{{ $n }}/estimates/{{ $e->id }}/reject" class="contents" data-turbo-confirm="Отклонить оценку {{ \App\Support\Money::rub($e->amount) }}?" data-turbo-confirm-label="Отклонить" data-turbo-confirm-text="{{ $e->user->shortName() }} оценит ещё раз">
                        @csrf<button type="submit" class="btn btn-s btn-quiet">Отклонить</button>
                    </form>
                    <form method="post" action="/offers/{{ $n }}/estimates/{{ $e->id }}/take" class="contents">
                        @csrf<button type="submit" class="btn btn-s btn-accent nums">Взять {{ \App\Support\Money::rub($e->amount) }}</button>
                    </form>
                </span>
            @else
                <span class="ml-auto flex items-center gap-1">
                    @if ($e->isOverdue())<x-offer.estimate-left :estimate="$e"/>@else<span class="text-ink-muted">оценивает, <x-offer.estimate-left :estimate="$e"/></span>@endif
                    <form method="post" action="/offers/{{ $n }}/estimates/{{ $e->id }}" class="contents" data-turbo-confirm="Снять {{ $e->user->shortName() }} с оценки?" data-turbo-confirm-label="Снять">
                        @csrf @method('delete')
                        <button type="submit" class="btn btn-icon !size-7" aria-label="Снять с оценки"><x-ui.icon name="x" class="size-4"/></button>
                    </form>
                </span>
            @endif
        </div>
    @endforeach
    <x-offer.estimate-ask :offer="$offer" :managers="$managers"/>
</div>
