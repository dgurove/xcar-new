<?php

namespace App\Chats\Actions;

use App\Chats\Chat;
use App\Chats\File;
use App\Live\Publisher;
use App\Live\Topics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Удалить чат площадки целиком (06.10.2026, владелец: «иногда спам, рекламу присылают»): сообщения и файлы уходят
 * каскадом базы, файлы — и с диска, строки уведомлений о нём — у всех, бейджи перечитываются. Только чат площадки:
 * переписку покупателя с его менеджером сотрудник только читает.
 */
final class DeleteChat
{
    public function __construct(private Publisher $publish) {}

    public function __invoke(Chat $chat): void
    {
        abort_if($chat->isBuyerChat(), 404);
        $paths = File::whereIn('message_id', $chat->messages()->select('id'))->pluck('path');
        $users = DB::transaction(function () use ($chat) {
            $subject = '/account/chats/'.$chat->id;
            $users = DB::table('notifications')->where('data->subject', $subject)->pluck('notifiable_id')->unique()->values()->all();
            DB::table('notifications')->where('data->subject', $subject)->delete();
            $chat->delete();

            return $users;
        });
        Storage::disk('private')->delete($paths->all());
        if ($users) {
            $this->publish->badges(array_map(Topics::user(...), $users));
        }
    }
}
