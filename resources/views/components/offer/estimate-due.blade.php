{{-- Полоса 48 часов оценки — сколько осталось (за 6 ч оранжевая); вышло — полосы нет, «просрочено» красным по центру.
     Кусок листа `x-offer.estimate-form`: берёт его $estimate, $due, $total, $left, $tone. --}}
<div class="estimate-due estimate-due--{{ $tone }}">
    @unless ($due->isPast())<span class="estimate-bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $left }}"><span style="width: {{ max(3, round($left / $total * 100)) }}%"></span></span>@endunless
    <x-offer.estimate-left :estimate="$estimate" class="!text-current"/>
</div>
