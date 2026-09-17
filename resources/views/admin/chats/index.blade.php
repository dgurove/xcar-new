{{-- Чаты в CRM: пресеты и поиск сверху, ниже панель «список | переписка». Чат площадки — с полем
     ответа; переписка покупателя с менеджером — только читается (readonly: поля нет, счётчики не
     трогаются), в баре — покупатель и чип его менеджера. --}}
@php
    $current ??= null;
    $chat ??= null;
    $me = auth()->user();
    $qs = request()->getQueryString() ? '?'.request()->getQueryString() : '';
@endphp
<x-ui.shell :title="$chat ? $chat->displayName() : 'Чаты'" :heading="false" :back="$chat ? ['Чаты', '/work/chats'.$qs] : null" :back-row="false">
    <div class="chat-crm-top">
        <x-admin.work-titles current="chats" :count="$chats->total()"/>
        <x-ui.toolbar class="mt-5" :pills="\App\Http\Admin\ChatController::PRESETS" :pill="$preset" pill-param="preset" :counts="['unread' => $unread]" name="chats">
            <x-slot:filters>
                <input name="q" value="{{ $q }}" placeholder="Имя, телефон, менеджер, номер предложения" class="field-input field-s">
            </x-slot:filters>
        </x-ui.toolbar>
    </div>
    <div class="chat-split chat-split-crm mt-6 {{ $current ? 'has-current' : '' }}" data-controller="split">
        <div class="chat-rows">
            @if ($chats->isEmpty())
                <x-ui.empty>Чатов нет</x-ui.empty>
            @else
                <div class="chat-rows-list">
                    @foreach ($chats as $c)
                        <x-chat.row :chat="$c" :me="$me" :href="'/work/chats/'.$c->id.$qs" :current="$c->id === $current" staff/>
                    @endforeach
                </div>
                {{ $chats->links() }}
            @endif
        </div>
        <turbo-frame id="chat-screen" class="chat-pane" target="_top">
            @if ($chat)
                <x-chat.screen :chat="$chat" :messages="$messages" :user="$user" :name="$chat->displayName()" :other="$chat->user" :back="'/work/chats'.$qs" :first-unread="$firstUnread" :more="$more" :readonly="$chat->isBuyerChat()" :link="$chat->user_id ? '/settings/users/'.$chat->user_id.'?chat='.$chat->id : null"/>
            @else
                <div class="chat-none"><x-ui.icon name="chat" class="size-10 text-ink-dim"/>Выберите чат</div>
            @endif
        </turbo-frame>
    </div>
</x-ui.shell>
