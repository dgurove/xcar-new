<?php

namespace App\Mail\Jobs;

use App\Mail\Candidate;
use App\Mail\Chains\ChainBuilder;
use App\Mail\Extraction\Code;
use App\Mail\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Письмо → цепочка «Из писем» (`ChainBuilder::attach`); из почты руками — `force`: цепочка заводится как есть. */
final class ExtractCandidate implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId, public bool $quiet = false)
    {
        $this->onConnection('database-long')->onQueue('mail');
    }

    public function handle(ChainBuilder $chains): void
    {
        $message = Message::with(['thread', 'account', 'attachments'])->find($this->messageId);
        if ($message) {
            $chains->attach($message, quiet: $this->quiet);
        }
    }

    public static function run(Message $message, bool $force = false, bool $quiet = false): ?Candidate
    {
        return app(ChainBuilder::class)->attach($message, $force, $quiet);
    }

    public static function key(string $code): string
    {
        return (string) Code::key($code);
    }
}
