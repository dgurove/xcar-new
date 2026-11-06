{{-- Счета и акты (06.11.2026, владелец: «как в вендорах: каждый месяц — отдельный блок, месяц подписан над блоком за его
     пределами»): месяц — подпись `.list-head` над своей плашкой `.list` (справа итог месяца; подпись тихая — 0,7), в плашке
     строки `x-billing.closing-row` тем же видом, что «Вендоры»; в месяце сначала где нужен наш ход (`ClosingDoc::window`).
     Фильтров нет. Документы живые, пока не ушли: номер встаёт в момент отправки. --}}
@php use App\Support\Money; use Illuminate\Support\Carbon; use Illuminate\Support\Str; @endphp
<x-ui.shell title="Счета и акты" :back="['Оплаты', '/money']" cache="no-cache">
    @if ($docs->isEmpty())
        <x-ui.empty class="mt-6">Документов нет</x-ui.empty>
    @else
        @foreach ($docs as $ym => $list)
            @php $month = Carbon::createFromFormat('!Y-m', $ym); @endphp
            <section id="m{{ $ym }}" @class(['mt-6' => ! $loop->first, 'mt-2' => $loop->first])>
                <h2 class="list-head opacity-70">
                    <span>{{ Str::ucfirst($month->translatedFormat($month->isSameYear(now()) ? 'F' : 'F Y')) }}@if ($month->isSameMonth(now())), идёт @endif</span>
                    <span class="nums ml-auto text-ink">{{ Money::rub($list->sum(fn ($d) => $d->drift['now'] ?? $d->amount)) }}</span>
                </h2>
                <div class="list">
                    @foreach ($list as $doc)<x-billing.closing-row :doc="$doc"/>@endforeach
                </div>
            </section>
        @endforeach
        @if ($months < 24)
            <div class="mt-6 flex justify-center"><a href="/money/closing?months={{ $months + 6 }}" class="btn btn-s btn-quiet" data-turbo-action="replace">Ещё раньше</a></div>
        @endif
    @endif
    <x-mail.window/>
</x-ui.shell>
