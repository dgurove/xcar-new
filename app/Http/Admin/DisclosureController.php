<?php

namespace App\Http\Admin;

use App\Mail\LetterPrompt;
use App\Mail\Message;
use App\Offers\Actions\Disclose;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Deal;
use App\Offers\Disclosures;
use App\Offers\ReplyReview;
use App\Offers\SupplierInvoice;
use App\Support\Phone;
use App\Workflow\Outcome;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * «Разобрать ответ» в сделке CRM (`x-deal.reply-review`, 03.11.2026): показать менеджеру отмеченное из писем страховой или
 * «Ничего не показывать». Пункты сверяются с пересчитанными (`Disclosures::picked`): чужой документ не пройдёт.
 */
class DisclosureController
{
    /**
     * Окно «Что видит менеджер» (25.11.2026, `x-deal.manager-view`, `ManagerView`): показать и скрыть пункты сделки одним нажатием;
     * из письма (`message` — одно письмо окна писем, `reviews` — ждущие разбора) — с его строками подписания (`line[]` — ключи
     * `Disclosures::proposals`, значения считает сервер), своим сообщением и галкой шага.
     */
    public function view(Request $request, Deal $deal, \App\Offers\Actions\SetManagerView $set)
    {
        abort_unless($request->user()->canEditDeals() && $deal->buyer_id && $deal->isActive(), 404);
        $data = $request->validate([
            'show' => ['array'], 'show.*' => ['string', 'max:40'],
            'hide' => ['array'], 'hide.*' => ['string', 'max:40'],
            'line' => ['array'], 'line.*' => ['string', 'max:80'],
            'text' => ['nullable', 'string', 'max:5000'],
            'step' => ['nullable', 'integer'],
            'message' => ['nullable', 'integer'],
            'reviews' => ['nullable', 'boolean'],
        ]);
        $message = isset($data['message']) ? Message::with('thread')->find($data['message']) : null;
        abort_if(isset($data['message']) && $message?->thread?->offer_id !== $deal->offer_id, 404);
        $reviews = ! $message && $request->boolean('reviews');
        $letters = $message ? collect([$message]) : ($reviews ? ReplyReview::pending()->where('deal_id', $deal->id)->with('message')->get()->pluck('message')->filter()->values() : collect());
        $proposals = $letters->isNotEmpty() ? Disclosures::proposals($deal, $letters, $message?->id) : [];
        $lines = array_values(array_map(fn ($k) => $proposals[$k]['value'], array_filter($data['line'] ?? [], fn ($k) => ($proposals[$k]['kind'] ?? null) === 'text')));
        $step = isset($data['step']) ? Outcome::find($data['step']) : null;
        \App\Offers\Actions\SetManagerView::guard($data['show'] ?? [], $data['hide'] ?? [], $data['text'] ?? null, $message ?? ($reviews ? $letters->first() : null));
        $done = $set($deal, $request->user(), $data['show'] ?? [], $data['hide'] ?? [], $data['text'] ?? null, $message, $step, $lines, $reviews);
        $offer = $deal->offer->fresh();
        if ($done['moved'] && $offer->position()?->letterStatus() === 'unsent' && ($url = $offer->vendorLetterUrl())) {
            LetterPrompt::ask($request->user(), $offer, $url.'&then=close');
        }
        $said = array_filter([
            $done['shown'] ? 'Менеджер видит: '.mb_strtolower(implode(', ', $done['shown'])) : null,
            $done['hidden'] ? 'Скрыто: '.mb_strtolower(implode(', ', $done['hidden'])) : null,
            $done['moved'] ? 'шаг пройден' : null,
        ]);

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', $said ? implode(', ', $said) : 'Письмо разобрано');
    }

    public function store(Request $request, Deal $deal, Disclose $disclose)
    {
        abort_unless($deal->buyer_id && $deal->isActive(), 404);
        $data = $request->validate([
            'on' => ['array'], 'on.*' => ['string', 'max:80'],
            // Правленое значение пункта — `edit`, не `value` (25.11.2026): с ошибкой проверки old('value') массивом ронял поле
            // оценочной на странице машины — 500 вместо текста ошибки.
            'edit' => ['array'], 'edit.*' => ['nullable', 'string', 'max:300'],
            'card' => ['nullable', 'boolean'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'text' => ['nullable', 'string', 'max:5000'],
            'step' => ['nullable', 'integer'],
            'message' => ['nullable', 'integer'],
        ]);
        // Из окна писем (16.11.2026) — одно письмо, любое из веток этой машины, а не только стоящие на разборе.
        $message = isset($data['message']) ? Message::with('thread')->find($data['message']) : null;
        abort_if(isset($data['message']) && $message?->thread?->offer_id !== $deal->offer_id, 404);
        // card — «Показать контакты менеджеру» (`x-deal.contact-shown`): пункты — карточка и письма с контактом, как в шторке
        // (`Disclosures::contactLetters`); письмо на разборе он не разбирает.
        $card = ! $message && $request->boolean('card');
        $letters = $message ? collect([$message]) : ($card ? Disclosures::contactLetters($deal) : ReplyReview::pending()->where('deal_id', $deal->id)->with('message')->get()->pluck('message')->filter()->values());
        $pick = collect($data['on'] ?? [])->mapWithKeys(fn ($key) => [$key => $data['edit'][$key] ?? null])->all();
        $items = Disclosures::picked(Disclosures::proposals($deal, $letters, $message?->id, contact: $card), $pick, $data['text'] ?? null);
        if ($card) {
            $items = $this->ownContact($deal, $request, $items, $data);
        }
        $step = isset($data['step']) ? Outcome::find($data['step']) : null;
        ['moved' => $moved] = $disclose($deal, $request->user(), $items, $step, message: $message, card: $card,
            lead: $card && collect($items)->whereIn('kind', ['name', 'phone', 'address'])->isNotEmpty() ? 'Контакт для получения ТС' : null);
        // Шаг ушёл на этап с нашим письмом («Договор готов» → «Свободное время для подписания», 11.11.2026) — сразу вопрос про
        // письмо (`LetterPrompt`), как после «Принять»: черновик уже с днями покупателя.
        $offer = $deal->offer->fresh();
        if ($moved && $offer->position()?->letterStatus() === 'unsent' && ($url = $offer->vendorLetterUrl())) {
            LetterPrompt::ask($request->user(), $offer, $url.'&then=close');
        }

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', match (true) {
            $items !== [] => $moved ? 'Показано менеджеру, шаг пройден' : 'Показано менеджеру',
            $moved => 'Шаг пройден',
            default => 'Ответ разобран',
        });
    }

    /**
     * Шторка контакта (25.11.2026, владелец: «нужно здесь тоже сделать ввод контактов»): вписанные «Имя» и «Телефон» — пунктами
     * менеджеру (перед своим сообщением); в карточке ТС пусто — показанные имя и телефон (из письма или вписанные) встают туда
     * (`UpdateOffer`, «История»), чтобы контакт был и в «Вывозе», и в сводке ТС.
     *
     * @param  list<array{kind: string, value: string}>  $items
     * @return list<array{kind: string, value: string}>
     */
    private function ownContact(Deal $deal, Request $request, array $items, array $data): array
    {
        $own = [];
        if ($name = trim((string) ($data['contact_name'] ?? ''))) {
            $own[] = ['kind' => 'name', 'value' => $name];
        }
        if (($raw = trim((string) ($data['contact_phone'] ?? ''))) !== '') {
            $phone = Phone::normalize($raw) ?? throw ValidationException::withMessages(['contact_phone' => 'Номер не похож на телефон']);
            $own[] = ['kind' => 'phone', 'value' => Phone::format($phone)];
        }
        if ($own) {
            $tail = array_values(array_filter($items, fn ($i) => $i['kind'] === 'text'));
            $items = [...array_values(array_filter($items, fn ($i) => $i['kind'] !== 'text')), ...$own, ...$tail];
        }
        $offer = $deal->offer;
        $first = fn (string $kind) => collect($items)->firstWhere('kind', $kind)['value'] ?? null;
        $fill = array_filter([
            'insured_name' => $offer->insured_name ? null : $first('name'),
            'insured_phone' => $offer->insured_phone ? null : Phone::normalize($first('phone')),
        ]);
        if ($fill) {
            app(UpdateOffer::class)($offer, $fill, $request->user(), ['why' => 'Показали менеджеру контакт', 'contact_shown' => true]);
        }

        return $items;
    }

    /**
     * «Счёт поставщика» (`x-deal.supplier-invoice-sheet`, 10.11.2026): выбранный документ или новый файл — менеджеру счётом
     * (`SupplierInvoice::mark`), своё сообщение, и шаг «Счёт от поставщика получен», если нажали его.
     */
    public function invoice(Request $request, Deal $deal, Disclose $disclose)
    {
        abort_unless($deal->buyer_id && $deal->isActive(), 404);
        $data = $request->validate([
            'media' => ['nullable', 'integer'],
            'file' => ['nullable', 'file', 'max:20480'],
            'text' => ['nullable', 'string', 'max:2000'],
            'exit' => ['nullable', 'integer'],
        ]);
        $offer = $deal->offer;
        $exit = isset($data['exit']) ? Outcome::find($data['exit']) : null;
        abort_if($exit && ! SupplierInvoice::needs($deal, $exit), 404);
        if ($file = $request->file('file')) {
            $name = $file->getClientOriginalName();
            $media = $offer->addMedia($file)->usingFileName(OfferPhotoController::safeName($name))->withCustomProperties(['sha' => hash_file('sha256', $file->getRealPath())])->toMediaCollection('papers');
        } else {
            $media = SupplierInvoice::candidates($offer)->firstWhere('id', (int) ($data['media'] ?? 0));
        }
        if (! $media) {
            throw ValidationException::withMessages(['invoice' => 'Выберите счёт или добавьте файл']);
        }
        $items = [['kind' => 'file', 'value' => $media->name, 'media' => $media->id]];
        if ($text = trim((string) ($data['text'] ?? ''))) {
            $items[] = ['kind' => 'text', 'value' => mb_substr($text, 0, 2000)];
        }
        ['moved' => $moved] = $disclose($deal, $request->user(), $items, $exit, $media->id);

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', $moved ? 'Счёт показан менеджеру, шаг пройден' : 'Счёт показан менеджеру');
    }

    /**
     * Исправить показанное (25.11.2026, `EditDisclosure`): своё сообщение, контакты строками (имя, телефон, адрес; пустое — убрано),
     * документы машины галками. Документ — только этой машины.
     */
    public function update(Request $request, Deal $deal, \App\Offers\Disclosure $disclosure, \App\Offers\Actions\EditDisclosure $edit)
    {
        abort_unless($disclosure->deal_id === $deal->id && $deal->isActive(), 404);
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:5000'],
            'name' => ['array'], 'name.*' => ['nullable', 'string', 'max:120'],
            'phone' => ['array'], 'phone.*' => ['nullable', 'string', 'max:40'],
            'address' => ['array'], 'address.*' => ['nullable', 'string', 'max:300'],
            'files' => ['array'], 'files.*' => ['integer'],
        ]);
        $items = [];
        if (($text = trim((string) ($data['text'] ?? ''))) !== '') {
            $items[] = ['kind' => 'text', 'value' => mb_substr($text, 0, 5000)];
        }
        foreach (['name', 'phone', 'address'] as $kind) {
            foreach ($data[$kind] ?? [] as $i => $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                if ($kind === 'phone') {
                    $value = ($n = Phone::normalize($value)) ? Phone::format($n)
                        : throw ValidationException::withMessages(['phone.'.$i => 'Номер не похож на телефон']);
                }
                $items[] = ['kind' => $kind, 'value' => $value];
            }
        }
        $papers = $deal->offer->getMedia('papers')->keyBy('id');
        foreach (array_unique(array_map('intval', $data['files'] ?? [])) as $id) {
            $media = $papers->get($id) ?? abort(404);
            $items[] = ['kind' => 'file', 'value' => $media->name ?: $media->file_name, 'media' => $media->id];
        }
        $left = $edit($disclosure, $request->user(), $items);

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', $left ? 'Исправлено, менеджеру ушло заново' : 'Показ удалён');
    }

    /** Удалить показ целиком: у менеджера его больше нет, сообщение в чате стёрто (`EditDisclosure`). */
    public function destroy(Request $request, Deal $deal, \App\Offers\Disclosure $disclosure, \App\Offers\Actions\EditDisclosure $edit)
    {
        abort_unless($disclosure->deal_id === $deal->id && $deal->isActive(), 404);
        $edit($disclosure, $request->user(), null);

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', 'Показ удалён');
    }

    public function dismiss(Request $request, Deal $deal, Disclose $disclose)
    {
        $disclose->dismiss($deal, $request->user());

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', 'Менеджеру ничего не показано');
    }
}
