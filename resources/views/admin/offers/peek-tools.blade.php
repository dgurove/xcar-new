{{-- Полоса окошка: «✨» (письма у предложения есть — окно «Распознать»), «Поделиться». Её же перерисовывает загрузка
     кадров (gallery-stream) — там числа писем нет, спрашиваем базу. --}}
@if (auth()->user()->canCrmMail() && ($letters ?? \App\Mail\Thread::where('offer_id', $offer->id)->exists()))
    <x-mail.scan-button :url="'/offers/'.$offer->number.'/scan'" look="icon"/>
@endif
{{-- Модератор черновики не делит. --}}
@if (auth()->user()->canManageCrm())@include('admin.offers.share-button', ['class' => 'peek-close'])@endif
