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
    @if ($t->type === RequestType::Release && ! $release)<p class="text-sm text-ink-muted">Выдача после приёма</p>@endif
    <x-mail.letters :messages="$messages" :url="'/cars/'.$vehicle->id.'/letters'" :stages="[$taskLetterId]"/>
</section>
