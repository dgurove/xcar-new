{{-- «для других менеджеров до 15.11 17:00» — статус публикации сделки (`PublishDeal`) одними словами везде: строка «Показ»,
     факт в шапке окна, вопрос переоценки. cap — с большой буквы; без срока — «без срока». --}}
@props(['offer', 'cap' => false])
@php $until = $offer->deal_show_until; @endphp
{{ $cap ? 'Для' : 'для' }} других менеджеров {!! $until ? 'до <span class="nums">'.\App\Support\Ago::html($until, true, words: false).'</span>' : 'без срока' !!}
