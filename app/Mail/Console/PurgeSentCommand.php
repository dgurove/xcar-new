<?php

namespace App\Mail\Console;

use App\Mail\Actions\PurgeSentMessage;
use App\Mail\Message;
use Illuminate\Console\Command;

/** Убрать ушедшее наше письмо из ящика и из CRM, шаг сделки — снова «не отправлено» (`PurgeSentMessage`). */
final class PurgeSentCommand extends Command
{
    protected $signature = 'mail:purge-sent {message : id письма} {--dry : только показать}';

    protected $description = 'Удалить ушедшее письмо из ящика и CRM, вернуть шаг «не отправлено»';

    public function handle(PurgeSentMessage $purge): int
    {
        $message = Message::with(['account', 'folder', 'thread', 'attachments'])->findOrFail((int) $this->argument('message'));
        $this->line('Письмо '.$message->id.': «'.$message->subject.'» → '.$message->to_preview.', '.$message->sent_at);
        foreach ($purge($message, (bool) $this->option('dry')) as $line) {
            $this->line('  '.$line);
        }

        return self::SUCCESS;
    }
}
