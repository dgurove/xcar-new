{{-- Полоса окошка: «✨» (письма у предложения есть — окно «Распознать»), «Поделиться». Её же перерисовывает загрузка
     кадров (gallery-stream) — там числа писем нет, спрашиваем базу. --}}
@if ($letters ?? \App\Mail\Thread::where('offer_id', $offer->id)->exists())
    <x-mail.scan-button :url="'/offers/'.$offer->number.'/scan'" look="icon"/>
@endif
@include('admin.offers.share-button', ['class' => 'peek-close'])
