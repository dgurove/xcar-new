<?php

namespace App\Mail;

use App\Mail\Jobs\SendMessage;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Исходящее письмо: заготовка ответа/пересылки и запись в базу с постановкой в очередь. Message-ID выписывается до отправки. */
final class Composer
{
    public function __construct(private Parser $parser, private Threads $threads) {}

    /**
     * Ответ уходит с ящика, через который отвечает ящик родителя (`Account::sender`): на письмо в offer@ — с deal@.
     * **По умолчанию — всем участникам** (16.11.2026, Альфа по Skoda Rapid 0770/046/01145/26: «Почему удаляете участников
     * переписки?» — ответы с парковки уходили только написавшему, копия терялась): кому — написавший (его Reply-To), копия —
     * остальные из «Кому» и «Копия» письма и контакты вендора «всегда в копии» (своей стороны: парковка или «Реализация»), без
     * наших ящиков (всех, не только того, с которого отвечаем) и без того, кто уже в «Кому». `$all = false` — только ему.
     */
    public function reply(Message $parent, bool $all = true): array
    {
        $account = $parent->account->sender();
        // Письмо вендора, пересланное сотрудником со своего ящика: отвечаем вендору, а не сотруднику, темой из цитаты.
        $forwarded = $parent->isForwardedByStaff();
        $to = array_filter([$forwarded ? $parent->field('sender') : $parent->replyToAddress()]);
        $cc = [];
        // Пересланное сотрудником — его адресаты наши, участники переписки с вендором — в цитате; копию не собираем.
        if ($all && ! $forwarded) {
            foreach ([AddressKind::To, AddressKind::Cc] as $kind) {
                foreach ($parent->addressesOfKind($kind) as $address) {
                    $cc[] = mb_strtolower($address->email);
                }
            }
            // Участники всей ветки, а не только этого письма (16.11.2026, Skoda Rapid: наш прошлый ответ ушёл без копии, страховая
            // ответила уже без неё — и копия терялась насовсем). Входящие ветки: кто писал, кому и кого ставили в копию.
            if ($parent->thread_id) {
                $incoming = Message::where('thread_id', $parent->thread_id)->where('direction', Direction::In)->whereKeyNot($parent->id)->pluck('id');
                $cc = [...$cc, ...Address::whereIn('message_id', $incoming)->whereIn('kind', [AddressKind::From, AddressKind::To, AddressKind::Cc])
                    ->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->all()];
            }
            if ($vendor = $parent->thread?->vendor) {
                array_push($cc, ...array_map('mb_strtolower', $vendor->ccEmails(sale: $parent->account->scope !== Scope::Park)));
            }
            $ours = Account::query()->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->all();
            $cc = array_values(array_diff(array_unique(array_filter($cc)), array_map('mb_strtolower', $to), $ours, [mb_strtolower($account->email)]));
        }

        return [
            'to' => implode(', ', $to),
            'cc' => implode(', ', $cc),
            'subject' => $this->prefix(($forwarded ? Extraction\QuotationStripper::forwardedSubject($parent->text_body) : null) ?? $parent->subject, 'Re'),
            'body' => $this->signature($account),
            'quote' => 'reply',
        ];
    }

    public function forward(Message $parent): array
    {
        return [
            'to' => '',
            'cc' => '',
            'subject' => $this->prefix($parent->subject, 'Fwd'),
            'body' => $this->signature($parent->account->sender()),
            'quote' => 'forward',
            'forward' => $parent->files()->pluck('id')->all(),
        ];
    }

    /**
     * Письмо этапа ответом в переписку с вендором (05.10.2026): текст шаблона, подпись и цитата, тема «Re: …». Родитель —
     * его последнее письмо, а пока он не писал — наше прошлое письмо по этой машине (`MailController::vendorLetter`): тогда
     * адресат — из шаблона, не мы сами. Ящик — тот, с которого отвечаем ($account уже `sender()`).
     */
    public function replyWith(Message $parent, Account $account, ?Template $template = null, array $values = []): array
    {
        $reply = $this->reply($parent);
        $rendered = $template?->render($values) ?? ['subject' => '', 'body' => ''];
        $ours = $parent->direction === Direction::Out;

        // Тема — «Re: …» ветки, а не шаблона (28.10.2026, владелец: «Альфа потеряет — не поймут, что это ответ в ветку»;
        // до того, с 08.10.2026, тема шаблона — только номер убытка — перебивала «Re:»). Номер убытка в теме ветки и так есть.
        $to = $ours ? ($values['to'] ?? '') : ($reply['to'] ?: ($values['to'] ?? ''));
        // Копия — из шаблона (контакты «всегда в копии») и участники ветки (16.11.2026: копию не теряем ни в одном ответе).
        $split = fn ($list) => array_filter(array_map('trim', explode(',', (string) $list)));
        $cc = array_values(array_diff(array_unique([...$split($values['cc'] ?? ''), ...$split($reply['cc'])]), $split($to)));

        return [
            'to' => $to,
            'cc' => implode(', ', $cc),
            'subject' => $reply['subject'] ?: trim($rendered['subject']),
            'body' => $this->text($rendered['body']).$this->signature($account),
            'quote' => 'reply',
        ];
    }

    public function fresh(Account $account, ?Template $template = null, array $values = []): array
    {
        $rendered = $template?->render($values) ?? ['subject' => '', 'body' => ''];

        return ['to' => $values['to'] ?? '', 'cc' => $values['cc'] ?? '', 'subject' => $rendered['subject'], 'body' => $this->text($rendered['body']).$this->signature($account)];
    }

    /** Текст шаблона без разметки — абзацами и строками (`Template::html`): редактор письма склеивал его в одну строку. */
    private function text(string $body): string
    {
        return $body === '' || preg_match('/<\/?[a-z][^>]*>/i', $body) ? $body : Template::html($body);
    }

    /**
     * Цитата исходного письма — не в редакторе, а под ответом при отправке (28.10.2026, владелец: в поле ответа стоял текст
     * исходного письма, курсор оказывался в цитате, и Trix ломал её разметку). Как у Gmail: пишешь только ответ, переписка
     * приклеивается сама. `quote` — 'reply' или 'forward', пусто — без цитаты.
     */
    public function withQuote(string $html, Message $parent, string $mode = 'reply'): string
    {
        return $html.$this->quote($parent, $mode === 'forward' ? 'Пересылаемое письмо' : 'Исходное письмо');
    }

    /**
     * inline — картинки в теле письма (QR пропуска): путь в outbox => Content-ID, на который ссылается `<img src="cid:…">`.
     *
     * @param  array{to: string, cc?: string, bcc?: string, subject: string, body: string, quote?: string, files?: list<string>, forward?: list<int>, inline?: array<string, string>}  $data
     */
    /** @param  bool  $dispatch  false — в очередь отправки письмо ставит вызвавший (`dispatch`), когда запишет его у себя */
    public function create(Account $account, array $data, ?Message $parent, ?User $by, ?Thread $thread = null, bool $dispatch = true): Message
    {
        $messageId = Str::uuid().'@'.(Str::after($account->email, '@') ?: 'xcar.ru');
        $html = trim((string) ($data['body'] ?? ''));
        if ($parent && ! empty($data['quote'])) {
            $html = $this->withQuote($html, $parent, (string) $data['quote']);
        }
        $text = $this->parser->htmlToText($html);
        $to = $this->emails($data['to'] ?? '');

        $message = DB::transaction(function () use ($account, $data, $parent, $by, $thread, $messageId, $html, $text, $to) {
            $subject = trim((string) ($data['subject'] ?? '')) ?: '(без темы)';
            // Ветка родителя — только своего ящика. Ответ с другого ящика (на письмо в offer@ — с deal@) — новая ветка
            // ящика отправки за тем же предложением: ответ вендора на неё придёт туда и найдёт её по In-Reply-To.
            $own = $parent?->account_id === $account->id ? $parent->thread : null;
            $thread ??= $own ?? Thread::create([
                'account_id' => $account->id, 'root_message_id' => $messageId, 'subject' => $subject,
                'subject_normalized' => $this->parser->normalizeSubject($subject), 'last_message_at' => now(),
                'offer_id' => $parent?->thread?->offer_id, 'vendor_id' => $parent?->thread?->vendor_id,
            ]);
            $message = Message::create([
                'account_id' => $account->id,
                'thread_id' => $thread->id,
                'direction' => Direction::Out,
                'message_id' => $messageId,
                'in_reply_to' => $parent?->message_id,
                'references_header' => $parent?->referencesForReply(),
                'subject' => $subject,
                'subject_normalized' => $this->parser->normalizeSubject($subject),
                'from_email' => mb_strtolower($account->email),
                'from_name' => $account->fromName(),
                'to_preview' => mb_substr(implode(', ', $to), 0, 255),
                'date_at' => now(),
                'internal_at' => now(),
                'html_body' => $html ?: null,
                'text_body' => $text ?: null,
                'preview' => $text ? mb_substr($text, 0, 300) : null,
                'is_seen' => true,
                'parse_state' => ParseState::Parsed,
                'send_state' => SendState::Queued,
                'created_by' => $by?->id,
            ]);
            $message->addresses()->create(['kind' => 'from', 'email' => mb_strtolower($account->email), 'name' => $account->fromName(), 'position' => 0]);
            foreach (['to' => $to, 'cc' => $this->emails($data['cc'] ?? ''), 'bcc' => $this->emails($data['bcc'] ?? '')] as $kind => $list) {
                foreach ($list as $i => $email) {
                    $message->addresses()->create(['kind' => $kind, 'email' => $email, 'position' => $i]);
                }
            }
            $this->attach($message, $data, $parent);
            $this->threads->refresh($thread);

            return $message;
        });
        if ($dispatch) {
            $this->dispatch($message);
        }

        return $message;
    }

    public function dispatch(Message $message): void
    {
        SendMessage::dispatch($message->id);
    }

    /** @return list<string> */
    public function emails(string $line): array
    {
        preg_match_all('/[^\s<>,;"]+@[^\s<>,;"]+/u', $line, $m);

        return array_values(array_unique(array_map(fn ($e) => mb_strtolower(trim($e, '.')), $m[0])));
    }

    private function attach(Message $message, array $data, ?Message $parent): void
    {
        $disk = Storage::disk(Parts::CACHE_DISK);
        $position = 0;
        $inline = (array) ($data['inline'] ?? []);
        foreach ([...(array) ($data['files'] ?? []), ...array_keys($inline)] as $path) {
            if (! str_starts_with((string) $path, 'outbox/')) {
                continue;
            }
            // Файла нет — письмо не заводится (29.10.2026: «Письмо пришло без вложения» — молча уходило без него).
            if (! $disk->exists($path) || ! $disk->size($path)) {
                throw ValidationException::withMessages(['files' => 'Файл «'.substr(basename((string) $path), 37).'» не загрузился, приложите его ещё раз']);
            }
            // WebP, AVIF и HEIC страховые не открывают — уходят JPEG (`OutboxImage`, 09.11.2026). Картинки в теле — как есть.
            if (! isset($inline[$path])) {
                $path = OutboxImage::normalize($path);
            }
            $message->attachments()->create([
                'filename' => substr(basename($path), 37), // после uuid и дефиса
                'mime' => $disk->mimeType($path) ?: null,
                'size' => $disk->size($path),
                'path' => $path,
                'content_id' => $inline[$path] ?? null,
                'is_inline' => isset($inline[$path]),
                'position' => $position++,
            ]);
        }
        if ($parent && ! empty($data['forward'])) {
            // Пересылаемый файл не копируется: тот же blob, что у исходного письма.
            foreach ($parent->attachments()->whereIn('id', (array) $data['forward'])->get() as $original) {
                if (($contents = $original->contents()) === null) {
                    throw ValidationException::withMessages(['files' => 'Файл «'.$original->filename.'» не достать из ящика, приложите его заново']);
                }
                $message->attachments()->create(['filename' => $original->filename, 'mime' => $original->mime, 'size' => strlen($contents), 'blob_sha' => Blobs::put($contents), 'pinned_at' => now(), 'position' => $position++]);
            }
        }
        $message->forceFill(['has_attachments' => $position > 0, 'attachments_count' => $position])->save();
    }

    private function prefix(?string $subject, string $prefix): string
    {
        $clean = trim((string) $subject);
        if ($clean === '') {
            return "{$prefix}: (без темы)";
        }

        return preg_match('/^\s*'.$prefix.'\s*:/iu', $clean) ? $clean : "{$prefix}: {$clean}";
    }

    private function signature(Account $account): string
    {
        // Пустая строка над подписью — `<div><br></div>`: пустой `<p>` Trix выбрасывает, и курсор вставал в подпись.
        return '<div><br></div>'.($account->signature ? '<div>'.$account->signature.'</div>' : '');
    }

    public function quote(Message $parent, string $title = 'Исходное письмо'): string
    {
        $source = $parent->text_body ?: $this->parser->htmlToText((string) $parent->html_body);
        $from = $parent->from_name ? "{$parent->from_name} <{$parent->from_email}>" : (string) $parent->from_email;
        // Письмо вендора, пересланное сотрудником с личного ящика: цитируем само письмо вендора, без пересылки.
        if ($parent->isForwardedByStaff() && ($forwarded = Extraction\QuotationStripper::forwardedBody($source)) !== null) {
            $source = $forwarded;
            $from = (string) $parent->field('sender');
        }
        $header = sprintf('%s, %s, %s:', $title, $parent->date_at?->format('d.m.Y H:i') ?? '', $from);
        // Абзацы по пустым строкам, строки внутри — через <br>; заглушки картинок «[cid:image001.png@…]» — долой.
        $lines = array_slice(preg_split('/\R/u', trim($source)) ?: [], 0, 200);
        $lines = array_filter($lines, fn ($l) => ! preg_match('/^\s*\[cid:[^\]]*\]\S*\s*$/u', $l));
        $paragraphs = preg_split('/\n\s*\n/u', implode("\n", $lines)) ?: [];
        $body = implode('', array_map(fn ($p) => '<p style="margin:0 0 .8em">'.implode('<br>', array_map(fn ($l) => e($l), explode("\n", trim($p, "\n")))).'</p>',
            array_filter($paragraphs, fn ($p) => trim($p) !== '')));

        return '<br><blockquote style="border-left:2px solid #ccc;margin:0 0 0 .8ex;padding-left:1ex">'
            .'<p style="margin:0 0 .8em">'.e($header).'</p>'.$body.'</blockquote>';
    }
}
