{{-- «Приём подтверждений до» — одно поле на всё, где машину показывают менеджерам (12.11.2026, владелец: где-то срок был, где-то
     нет): «Показ» предложения, «Опубликовать для других менеджеров», «Вернуть в продажу», «Без подтверждений», переоценка.
     Чипы «3 дня», «Неделя» и «До обязывающего» — одним нажатием; по умолчанию — через 3 дня в час закрытия, не позже срока
     обязывающего (`Binding::clampClose`). Прежде то же было «Сколько показывать» (1 час — 3 дня), «Срок показа 3 дня | Неделя |
     До даты» и «3 дня | Неделя». --}}
@props(['offer', 'name' => 'bids_close_at', 'value' => null, 'form' => null, 'id' => null, 'label' => 'Приём подтверждений до', 'required' => true, 'span' => null])
@php
    use App\Offers\{Binding, Slots};
    $limit = Binding::closeLimit($offer);
    $value ??= Binding::clampClose($offer, Slots::closeFor(now()));
    $quick = [
        ['label' => '3 дня', 'at' => Binding::clampClose($offer, Slots::closeFor(now(), 3))],
        ['label' => 'Неделя', 'at' => Binding::clampClose($offer, Slots::closeFor(now(), 7))],
    ];
    if ($limit) {
        $quick[] = ['label' => 'До обязывающего', 'at' => $limit];
    }
    // Одинаковые дни (обязывающее раньше недели) — одним чипом.
    $quick = collect($quick)->unique(fn ($q) => $q['at']->format('Y-m-d H:i'))->values()->all();
@endphp
<x-ui.field :name="$name" :id="$id ?? 'close-'.$offer->number.'-'.\Illuminate\Support\Str::random(4)" :label="$label" type="datetime-local" default-time="17:30"
    :value="$value instanceof \DateTimeInterface ? $value->format('Y-m-d\TH:i') : $value" :limit="$limit" :quick="$quick" inline :required="$required" :form="$form" :span="trim('close-field '.$span)" {{ $attributes }}/>
