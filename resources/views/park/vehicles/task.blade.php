{{-- Задача сверху карточки строки: все письма ТС строками, как в «Почте» (x-mail.letters; письмо, по которому заявка
     заведена, у выдачи — письмо о продаже, — этапом; строка открывает окно на письме, «Ответить»). Страхователь — строкой
     группы ниже, вместе с продажей и документами (`detail`, 20.10.2026).
     Самого приёма и выдачи в карточке нет (12.10.2026, владелец: «разграничить машину и действие»): кнопка подвала
     открывает окно действия (`x-park.act-window`), после него карточка к следующей заявке. --}}
@php
    use App\Park\{RequestType, VehicleState};
    $t = $task;
    $release = $t->type === RequestType::Release && $vehicle->state === VehicleState::Stored;
@endphp
<section class="mt-3 flex flex-col gap-3">
    <x-park.city-pick :vehicle="$vehicle"/>
    @if ($t->type === RequestType::Release && ! $release)<p class="text-sm text-ink-muted">Выдача после приёма</p>@endif
    @if ($t->type === RequestType::Inspect && $t->isOpen())
        {{-- Осмотр (13.11.2026): кто просит и когда договорились — кнопкой-датой с календарём (`POST /requests/{id}/plan`). --}}
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <x-park.request-type :type="$t->type"/>
            @if ($t->contact_name)<span class="min-w-0 truncate text-ink-muted">{{ $t->contact_name }}</span>@endif
            <x-ui.date chip submit time name="planned_at" :id="'insp-'.$t->id" :value="$t->planned_at" placeholder="договориться о дне осмотра" :form="'insp-plan-'.$t->id"/>
            <form method="post" action="/requests/{{ $t->id }}/plan" id="insp-plan-{{ $t->id }}" hidden>@csrf</form>
        </div>
    @endif
    <x-mail.letters :messages="$messages" :url="'/cars/'.$vehicle->id.'/letters'" :stages="[$taskLetterId]"/>
</section>
