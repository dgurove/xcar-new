{{-- Платёжное поручение за менеджера на шаге оплаты (10.11.2026, владелец: «чтобы админы за него могли прикрепить, если он как-то
     по-другому админам отправил»; с 24.11.2026 — «Приложить» одним документом, `x-ui.photo-pdf`): кнопка — выбор фото и файлов
     телефоном, шторка — что выбрали и название «Платёжное поручение {марка модель}», «Сохранить» — один документ в просьбу шага
     и его ответ за него (`PaymentProofController::storeDoc`, `pass`). Вид кнопки — слот `trigger` или `label`. --}}
@props(['id', 'deal', 'requirement', 'label' => 'Приложить документ об оплате за менеджера', 'button' => 'btn btn-s btn-quiet'])
<x-ui.photo-pdf :id="$id" :url="'/work/deals/'.$deal->id.'/payment-proof/doc'" :fields="['pass' => '1']" title="Документ об оплате"
    :name="'Документ об оплате '.$deal->offer->title()" icon="clip" :label="$label" always :button="$button" {{ $attributes }}>
    @isset($trigger)<x-slot:trigger>{{ $trigger }}</x-slot:trigger>@endisset
</x-ui.photo-pdf>
