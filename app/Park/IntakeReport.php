<?php

namespace App\Park;

use App\Mail\Account;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Vendors\ContactRole;
use App\Vendors\Vendor;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Отчёт о приёме вендору (12.10.2026, владелец: «висит задача отправить акты приёма — а где это делать?»). Как его
 * отправляли руками из mail.ru (25 писем из 25): ответ в ветке заявки тому, кто её прислал, одной фразой «Направляю Вам
 * фотоотчет и скан-копии документов по принятому ТС», 15–38 фото приёма, у Альфы Москва — сканы «Альфа акт 1 / заявка /
 * стс / эптс / дов {модель} {4 знака VIN}». Наш сгенерированный акт не прикладывают. Здесь — всё, что нужно форме
 * (`x-park.report-form`) и отправке (`Actions\SendIntakeReport`).
 */
final class IntakeReport
{
    /** Письмо больше — частями «ч.1, ч.2»: почтовые ящики страховых режут вложения за 20–25 МБ. */
    public static int $partBytes = 18 * 1024 * 1024;

    /** @param Collection<int, Media> $photos @param array<string, Collection<int, Media>> $slots вид → лежащие документы */
    private function __construct(
        public readonly Vehicle $vehicle,
        public readonly ?Message $parent,
        public readonly Account $account,
        public readonly string $to,
        public readonly string $cc,
        public readonly Collection $photos,
        public readonly array $slots,
    ) {}

    /** @param ?Message $parent ответ на это письмо («Дослать» на «Запросили документы»), иначе — на письмо заявки */
    public static function for(Vehicle $vehicle, ?Message $parent = null): ?self
    {
        $vehicle->loadMissing(['vendor', 'media', 'requests']);
        $parent ??= self::parent($vehicle);
        $account = $parent?->account?->sender() ?? Account::where('scope', Scope::Park)->sending()->orderBy('title')->first();
        if (! $account) {
            return null;
        }
        $to = $parent ? ($parent->isForwardedByStaff() ? (string) $parent->field('sender') : (string) $parent->replyToAddress()) : '';
        $to = $to ?: (string) ($vehicle->vendor?->email(ContactRole::Storage, ContactRole::Claims) ?? '');
        // Сначала снятое на парковке, потом приехавшее из писем: вендору нужны наши кадры, а не его же.
        $photos = $vehicle->photos()->filter(fn (Media $m) => PhotoStage::of($m) === PhotoStage::Intake)
            ->sortBy(fn (Media $m) => $m->getCustomProperty('source') === 'mail' ? 1 : 0)->values();
        $papers = $vehicle->papers();
        $slots = [];
        foreach ([...self::kinds($vehicle), ReportDoc::Other] as $kind) {
            $slots[$kind->value] = $papers->filter(fn (Media $m) => ($m->getCustomProperty('report_doc') ?? ReportDoc::ofName($m->file_name)?->value) === $kind->value)->values();
        }

        return new self($vehicle, $parent, $account, $to, '', $photos, $slots);
    }

    /**
     * @return list<ReportDoc> документы, что этот вендор получает сканами. Список пуст у вендора парковки — берётся у его
     *                         вендора CRM (`crm_vendor_id`, 29.10.2026: у «АльфаСтрахование СПб» его не было, и в отчётах не ушло ни одного документа).
     */
    public static function kinds(Vehicle $vehicle): array
    {
        $list = $vehicle->vendor?->intake_report_docs ?: [];
        if (! $list && ($crm = $vehicle->vendor?->crm_vendor_id)) {
            $list = Vendor::find($crm)?->intake_report_docs ?: [];
        }

        return array_values(array_filter(array_map(fn ($k) => ReportDoc::tryFrom((string) $k), $list), fn ($k) => $k !== ReportDoc::Other));
    }

    /** Письмо, на которое отвечаем: письмо заявки на приём, иначе последнее входящее в ветке ТС (не покупателю). */
    private static function parent(Vehicle $vehicle): ?Message
    {
        $letterId = $vehicle->requests->where('type', RequestType::Intake)->sortByDesc('id')->first(fn ($r) => $r->letter_id)?->letter_id;
        $letter = $letterId ? Message::with(['account', 'thread'])->find($letterId) : null;
        if ($letter) {
            return $letter;
        }
        $thread = Thread::where('vehicle_id', $vehicle->id)->park()->where('buyer', false)->orderByDesc('last_message_at')->first();

        return $thread?->messages()->with(['account', 'thread'])->where('direction', Direction::In)->orderByDesc('date_at')->first();
    }

    /** Фраза письма — как писали руками: со сканами — «фотоотчет и скан-копии документов», без — «фотоотчет». */
    public static function text(bool $docs): string
    {
        return $docs ? 'Добрый день. Направляю Вам фотоотчет и скан-копии документов по принятому ТС.' : 'Добрый день. Направляю Вам фотоотчет принятого ТС.';
    }

    /** Имя скана в письме — как называли руками: «Альфа акт 1 чанган алсвин 0367.pdf»; «Другой документ» — своим именем. */
    public function fileName(ReportDoc $kind, int $index, int $count, string $ext = 'pdf', ?string $original = null): string
    {
        if ($kind === ReportDoc::Other && $original) {
            // Имя у ТС — с хвостом «-случайное» (`addMedia` / загрузка формы), получателю — как называли.
            return preg_replace('/-[a-z0-9]{13,16}(\.[^.]+)$/i', '$1', $original);
        }
        $v = $this->vehicle;
        $vendor = trim((string) preg_replace('/\s*страхование.*$/ui', '', (string) $v->vendor?->name)) ?: (string) $v->vendor?->name;
        $car = mb_strtolower(trim(($v->brand?->name_ru ?: $v->brand?->name).' '.($v->model?->name_ru ?: $v->model?->name)));
        $parts = array_filter([$vendor, $kind->word().($count > 1 ? ' '.($index + 1) : ''), $car, $v->vin ? substr($v->vin, -4) : null]);

        return implode(' ', $parts).'.'.$ext;
    }
}
