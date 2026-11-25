{{-- Кнопка «Приложить» шага, что просит документ (19.10.2026, владелец: «серая кнопка — неочевидно, что приложить
     обязательно»): пока файла нет — главная кнопка шага, лаймовая во всю ширину, со словами что приложить; приложили —
     тихой строкой «Приложить ещё», а главной становится кнопка шага («Договор приложен»). Меняется потоком вместе с
     файлами (`files-stream`). С 24.11.2026 — «Приложить» одним документом (`x-ui.photo-pdf`, владелец: «как в нормальном
     приложении»): телефон предлагает медиатеку, камеру или файлы, шторка — что выбрали и название, сервер собирает один
     документ (`DealController::uploadDoc`); на шаге оплаты он же ответ шага, страница перечитывается. --}}
@php
    $has = $requirement->getMedia('files')->isNotEmpty();
    // Что приложить — словами шага: подписанный договор, платёжное поручение (оплата поставщику), иначе документ.
    $stage = $requirement->stage;
    $what = match (true) {
        (bool) preg_match('/договор/iu', $stage?->name.' '.$requirement->title) => 'contract',
        (bool) $stage?->isPayStep() || (bool) preg_match('/плат[её]ж/iu', (string) $requirement->title) => 'pay',
        default => 'doc',
    };
    $attachLabel ??= ['contract' => 'Приложить договор', 'pay' => 'Приложить документ об оплате', 'doc' => 'Приложить документ'][$what];
    // Название документа — заполнено: «Договор купли-продажи …», «Платёжное поручение …», иначе что просит шаг («ПТС»).
    $docName = match ($what) {
        'contract' => 'Договор купли-продажи',
        'pay' => 'Документ об оплате',
        default => \Illuminate\Support\Str::ucfirst(trim((string) preg_replace('/^(приложите|приложить|пришлите|прислать)\s+/iu', '', (string) $requirement->title))) ?: 'Документ',
    }.' '.$deal->offer->title();
@endphp
<div id="requirement-attach">
    <x-ui.photo-pdf :id="'req-'.$requirement->id" :url="'/deals/'.$deal->id.'/files/doc'" :title="['contract' => 'Договор купли-продажи', 'pay' => 'Документ об оплате', 'doc' => 'Документ'][$what]" :name="$docName" icon="clip" always
        :word="$what !== 'pay'" :label="$has ? 'Приложить ещё' : $attachLabel"
        :button="$has ? 'retreat-link mt-1 flex items-center justify-center gap-1.5' : 'btn btn-accent w-full gap-2'"/>
</div>
