<?php

namespace App\Mail\Extraction;

use App\Support\OfficePreview;
use Illuminate\Support\Facades\Process;
use Throwable;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Текст вложения письма для разбора — без OCR (скан парковки читает `DocumentText`): docx и xlsx (тем же разбором, что шторка документов),
 * текстовый PDF (`pdftotext`, poppler в образе), txt, вложенное письмо .eml (тема, текст и его документы —
 * страховая, пересланная сотрудником, прикладывает исходное письмо так). Скан-картинка текста не даёт — пусто.
 */
final class AttachmentText
{
    private const MAX_BYTES = 10_000_000;

    private const MAX_EML_BYTES = 80_000_000;

    public static function of(string $path, string $name, ?string $mime = null, int $depth = 0): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $eml = $ext === 'eml' || $mime === 'message/rfc822';
        // Вложенное письмо тяжёлое из-за своих фото — их не читаем, поэтому ему потолок выше.
        if (! is_file($path) || filesize($path) > ($eml ? self::MAX_EML_BYTES : self::MAX_BYTES)) {
            return null;
        }
        try {
            return match (true) {
                in_array($ext, ['docx', 'xlsx', 'xlsm', 'csv', 'txt'], true) => OfficePreview::plainText($path, $name),
                $ext === 'pdf' || $mime === 'application/pdf' => self::pdf($path),
                $eml && $depth === 0 => self::eml($path),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Текстовый слой можно читать: сканер Альфы кладёт под картинку свой OCR-мусор («=e{ (\l !t EO .': c{»), и
     * такой слой хуже, чем никакого. Читаемый — больше трети слов из трёх и больше букв одного алфавита.
     */
    public static function readable(string $text): bool
    {
        $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < 5) {
            return false;
        }
        $words = count(array_filter($tokens, fn ($t) => preg_match('/^[«("]?(?:\p{Cyrillic}{3,}|[A-Za-z]{3,})[.,:;»)"]?$/u', $t)));
        if ($words / count($tokens) < 0.35) {
            return false;
        }

        return ! self::garbled($tokens);
    }

    /**
     * Слой сканера «почти русский» (18.11.2026, ЭПТС 5991/046/00482/26: «Ндевтифихаrдrrонншй Hor,tep», «2Ез4NJ», «2о2з» —
     * разбор не нашёл ни VIN, ни модели, а наш OCR читает тот же скан чисто): слова, где кириллица смешана с латиницей или
     * сидит внутри числа. У слоя, что сделала программа, таких слов нет (замер прода — меньше 2 %), у слоя сканера — от 4 %.
     *
     * @param  list<string>  $tokens
     */
    private static function garbled(array $tokens): bool
    {
        $lettered = array_filter($tokens, fn ($t) => preg_match('/\p{L}/u', $t));
        $mixed = array_filter($lettered, fn ($t) => (preg_match('/\p{Cyrillic}/u', $t) && preg_match('/[A-Za-z]/', $t)) || preg_match('/\d\p{Cyrillic}{1,2}\d/u', $t));

        return count($lettered) >= 20 && count($mixed) / count($lettered) >= 0.03;
    }

    /** Первые пять страниц: оценка и акт — одна-две, дальше в PDF бывают только сканы. */
    private static function pdf(string $path): ?string
    {
        $result = Process::timeout(20)->run([config('xcar.pdftotext', 'pdftotext'), '-layout', '-l', '5', '-enc', 'UTF-8', $path, '-']);

        return $result->successful() && trim($result->output()) !== '' ? $result->output() : null;
    }

    private static function eml(string $path): ?string
    {
        $message = ImapMessage::fromFile($path);
        $parts = [trim('Тема: '.$message->getSubject()), trim((string) ($message->getTextBody() ?: strip_tags((string) $message->getHTMLBody())))];
        foreach ($message->getAttachments() as $attachment) {
            $name = (string) $attachment->getName();
            if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['pdf', 'docx', 'xlsx', 'xlsm', 'csv', 'txt'], true)) {
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'xcar-eml-');
            try {
                file_put_contents($tmp, $attachment->getContent());
                $parts[] = self::of($tmp, $name, (string) $attachment->getMimeType(), 1);
            } finally {
                @unlink($tmp);
            }
        }
        $text = trim(implode("\n\n", array_filter($parts)));

        return $text !== '' ? $text : null;
    }
}
