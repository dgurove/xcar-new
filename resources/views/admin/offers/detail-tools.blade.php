{{-- Полоса карточки: «✨» (документ, который окно прочтёт, или письма — тем, кому открыта почта), «Поделиться». Её же
     перерисовывает загрузка кадров и документов (gallery-stream) — там числа писем нет, спрашиваем базу. --}}
@if (\App\Mail\Scan\OfferSubject::for($offer, auth()->user())->papers()->isNotEmpty()
    || (auth()->user()->canCrmMail() && ($letters ?? \App\Mail\Thread::where('offer_id', $offer->id)->exists())))
    <x-mail.scan-button :url="'/offers/'.$offer->number.'/scan'" look="icon" :papers="true"/>
@endif
{{-- Модератор черновики не делит. --}}
@if (auth()->user()->canManageCrm())@include('admin.offers.share-button', ['class' => 'bar-btn'])@endif
