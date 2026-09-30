<turbo-frame id="offer-chat">
    <x-chat.box :chat="$chat" :messages="$messages" :user="$user" :more="$more" open="/offers/{{ $offer->number }}/chat"/>
</turbo-frame>
