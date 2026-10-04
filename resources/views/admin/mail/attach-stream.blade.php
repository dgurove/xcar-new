{{-- Ответ attach_controller, пока кадры письма прикрепляются: строка хода, ряд фото с заглушками и документы (из
     архивов они приходят вместе с фото). Предложение — ряд редактора, ТС — карточка «От страховой». --}}
<turbo-stream action="replace" target="attach-line"><template><x-mail.attach-line :model="$model"/></template></turbo-stream>
@if ($model instanceof \App\Offers\Offer)
<turbo-stream action="replace" target="gallery"><template>@include('admin.offers.gallery', ['offer' => $model])</template></turbo-stream>
<turbo-stream action="replace" target="papers"><template>@include('admin.offers.papers', ['offer' => $model])</template></turbo-stream>
@else
<turbo-stream action="replace" target="photos-{{ \App\Park\PhotoStage::Vendor->value }}"><template><x-park.photos :vehicle="$model" :stage="\App\Park\PhotoStage::Vendor"/></template></turbo-stream>
<turbo-stream action="replace" target="papers"><template>@include('park.vehicles.papers', ['vehicle' => $model])</template></turbo-stream>
@endif
