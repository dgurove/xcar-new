{{-- Бар поля чата и меню пузыря — одни на чаты сайта и переписку бота (`chat_controller`): чип ответа или правки,
     превью, скрепка, капсула ввода, круглая «отправить» (бледная, пока нечего слать); меню — один popover на ленту.
     draft — ключ черновика, accept — что можно приложить (у бота — любой файл, пусто). --}}
@props(['draft', 'placeholder' => 'Сообщение', 'accept' => 'image/*,.pdf,.heic'])
<div class="chat-bar">
    <div class="chat-state" data-chat-target="state" hidden>
        <x-ui.icon name="reply" class="size-4 shrink-0 text-accent-text" data-chat-target="stateIcon"/>
        <div class="min-w-0 flex-1"><div class="truncate text-xs text-accent-text" data-chat-target="stateName"></div><div class="truncate text-sm" data-chat-target="stateText"></div></div>
        <button type="button" class="btn btn-ghost btn-s px-2" data-action="chat#cancel" aria-label="Отменить"><x-ui.icon name="x" class="size-4"/></button>
    </div>
    <div class="chat-previews" data-chat-target="previews" hidden></div>
    <form method="post" class="chat-form" data-chat-target="form" data-action="submit->chat#send" data-controller="draft" data-draft-key-value="{{ $draft }}">
        <input type="file" @if ($accept) accept="{{ $accept }}" @endif multiple class="sr-only" tabindex="-1" data-chat-target="files" data-action="change->chat#filesPicked">
        <button type="button" class="btn btn-ghost btn-round" data-action="chat#pick" aria-label="Приложить"><x-ui.icon name="clip" class="size-5"/></button>
        <div class="chat-input"><textarea name="text" rows="1" placeholder="{{ $placeholder }}" data-chat-target="input" data-action="keydown->chat#keydown input->chat#typed change->chat#typed paste->chat#paste" enterkeyhint="enter"></textarea></div>
        <button class="chat-send is-empty" aria-label="Отправить" data-chat-target="submit"><x-ui.icon name="send" class="size-5"/></button>
    </form>
</div>
<div class="menu" popover data-chat-target="menu" role="menu">
    <button type="button" class="menu-item w-full" role="menuitem" data-action="chat#reply"><x-ui.icon name="reply" class="size-5"/>Ответить</button>
    <button type="button" class="menu-item w-full" role="menuitem" data-action="chat#copy" data-chat-target="copyItem"><x-ui.icon name="copy" class="size-5"/>Скопировать</button>
    <button type="button" class="menu-item w-full" role="menuitem" data-action="chat#startEdit" data-chat-target="ownItem editItem"><x-ui.icon name="edit" class="size-5"/>Изменить</button>
    <button type="button" class="menu-item w-full text-danger" role="menuitem" data-action="chat#remove" data-chat-target="ownItem removeItem"><x-ui.icon name="trash" class="size-5"/>Удалить</button>
</div>
