<turbo-frame id="offer-chat">
    <x-chat.box :chat="$chat" :messages="$messages" :user="$user" open="/offers/{{ $offer->number }}/chat"/>
</turbo-frame>
