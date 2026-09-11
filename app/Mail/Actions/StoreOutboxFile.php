<?php

namespace App\Mail\Actions;

use App\Mail\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Файл к будущему письму: лежит в outbox до отправки, имя получателю — исходное. */
final class StoreOutboxFile
{
    public function __invoke(UploadedFile $file): string
    {
        $name = mb_substr(trim(preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $file->getClientOriginalName()) ?: 'file', '_ '), 0, 150) ?: 'file';
        $path = 'mail/outbox/'.Str::uuid().'-'.$name;
        Storage::disk(Message::DISK)->put($path, $file->getContent());

        return $path;
    }
}
