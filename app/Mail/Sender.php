<?php

namespace App\Mail;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address as MimeAddress;
use Symfony\Component\Mime\Email;
use Throwable;

/** Отправка по SMTP ящика и копия в «Отправленные» через APPEND: mail.ru сам её не кладёт. */
final class Sender
{
    public function __construct(private Threads $threads) {}

    public static function transport(Account $account): TransportInterface
    {
        return Mail::build($account->smtpConfig())->getSymfonyTransport();
    }

    public function send(Message $message): void
    {
        $account = $message->account;
        $email = $this->build($message);
        $message->forceFill(['send_state' => SendState::Sending, 'send_attempts' => $message->send_attempts + 1])->save();

        self::transport($account)->send($email);

        $message->forceFill(['send_state' => SendState::Sent, 'send_error' => null, 'sent_at' => now(), 'size' => strlen($email->toString())])->save();
        $this->appendToSent($message, $email);
        if ($message->thread) {
            $this->threads->refresh($message->thread);
        }
    }

    public function build(Message $message): Email
    {
        $account = $message->account;
        $email = (new Email)->from(new MimeAddress($account->email, $account->fromName()))->subject((string) $message->subject);
        foreach ([AddressKind::To, AddressKind::Cc, AddressKind::Bcc] as $kind) {
            $list = $message->addressesOfKind($kind)->map(fn ($a) => new MimeAddress($a->email, (string) $a->name))->all();
            if ($list) {
                match ($kind) {
                    AddressKind::To => $email->to(...$list),
                    AddressKind::Cc => $email->cc(...$list),
                    default => $email->bcc(...$list),
                };
            }
        }
        if ($message->text_body) {
            $email->text($message->text_body);
        }
        if ($message->html_body) {
            $email->html($message->html_body);
        }
        $headers = $email->getHeaders();
        if ($message->message_id) {
            $headers->remove('Message-ID');
            $headers->addIdHeader('Message-ID', $message->message_id);
        }
        if ($message->in_reply_to) {
            $headers->addIdHeader('In-Reply-To', $message->in_reply_to);
        }
        if ($message->references_header) {
            $headers->addIdHeader('References', preg_split('/\s+/', $message->references_header, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }
        foreach ($message->attachments as $attachment) {
            $contents = $attachment->contents();
            if ($contents === null) {
                Log::warning('Почта: вложения нет ни у нас, ни в ящике', ['message' => $message->id, 'attachment' => $attachment->id]);

                continue;
            }
            $email->attach($contents, $attachment->filename, $attachment->mime ?: 'application/octet-stream');
        }

        return $email;
    }

    private function appendToSent(Message $message, Email $email): void
    {
        $account = $message->account;
        $folder = $account->folderOf(FolderKind::Sent);
        if (! $folder) {
            Log::warning('Почта: папка «Отправленные» неизвестна, копия не сохранена', ['account' => $account->email]);

            return;
        }
        $imap = new Imap($account);
        try {
            $uid = $imap->append($folder->path, $email->toString(), ['\\Seen'], now());
            $message->forceFill(['folder_id' => $folder->id, 'imap_uid' => $uid, 'uid_validity' => $uid ? $folder->uid_validity : null, 'appended_to_sent_at' => now(), 'is_seen' => true])->save();
        } catch (Throwable $e) {
            Log::error('Почта: копия в «Отправленные» не сохранилась', ['account' => $account->email, 'message' => $message->id, 'error' => $e->getMessage()]);
        } finally {
            $imap->disconnect();
        }
    }
}
