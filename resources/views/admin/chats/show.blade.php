{{-- Чат в CRM: с площадкой — сотрудник отвечает; покупателя с менеджером — только читается
     (readonly: поля нет, счётчики не трогаются), в полосе — покупатель и его менеджер. --}}
@php $readonly = $chat->isBuyerChat(); @endphp
<x-ui.shell :title="$chat->displayName()" :heading="false" :back="['Чаты', '/work/chats']" narrow>
    <x-chat.screen :chat="$chat" :messages="$messages" :user="$user" :name="$chat->displayName()" :other="$chat->user" :first-unread="$firstUnread" :more="$more" :readonly="$readonly"/>
</x-ui.shell>
