import { confirmSheet } from './confirm';
import { post } from './lib/http';
import { plural } from './lib/plural';

// «Точно?» перед ценой продажи под вопросом (02.11.2026, владелец: Lexus RX 2610142042 — закупочная 3 200 000, вписали
// продажу 1 200 000 и опубликовали). Форма с `data-price-guard` до отправки спрашивает сервер (`/offers/price-check`,
// правило одно — `Margin::check`): `edit` — цены из формы против нынешних, `whole` — цены целиком (снова в продажу,
// переоценка), `publish` — публикация (номера галок `offers[]` или `data-price-guard-offer`; у формы состояния — только
// «Опубликовать»). Под вопросом — лист с «Да» и «Исправить»; «Да» — `price_ok=1` и та же отправка. Ответа нет — форма
// уходит как есть: сервер без согласия не пишет и сам ставит `price_ok` на повтор (ошибка `price_check`).
export function priceGuard() {
    window.addEventListener('submit', (event) => {
        const form = event.target;
        const mode = form?.dataset?.priceGuard;
        if (!mode || form.dataset.priceChecked || event.defaultPrevented) return;
        const data = new FormData(form, event.submitter ?? undefined);
        if (data.get('price_ok') === '1') return;
        if (mode === 'publish' && data.has('state') && data.get('state') !== 'open') return;
        // У формы галок другие кнопки (`formaction`: «Убрать из слота», «Удалить навсегда») — не публикация.
        const action = new URL(event.submitter?.hasAttribute('formaction') ? event.submitter.formAction : form.action, location.href).pathname;
        if (mode === 'publish' && !/\/(schedule|state)$/.test(action)) return;
        const body = new FormData();
        if (mode === 'sold') {
            // Продажа из гаража (12.11.2026): цена против закупочной сделки — адрес у формы (`data-price-guard-url`).
            if (!data.get('sold_price')) return;
            body.append('sold_price', data.get('sold_price'));
        } else if (mode === 'publish') {
            const ids = form.dataset.priceGuardOffer ? [form.dataset.priceGuardOffer] : data.getAll('offers[]');
            if (!ids.length) return;
            ids.forEach((id) => body.append('offers[]', id));
        } else {
            // Цена продажи — из поля, «Взять» оценку — суммой оценки; закупочная переоценки зовётся `price`.
            const floorKey = form.dataset.priceGuardFloor || 'floor_price';
            const asking = form.dataset.priceGuardAsking ?? data.get('asking_price');
            body.append('offer', form.dataset.priceGuardOffer);
            if (asking !== null) body.append('asking_price', asking);
            if (data.has(floorKey)) body.append('floor_price', data.get(floorKey));
            // «Сохранить и опубликовать» редактора — цены целиком: публикуют и с прежней ценой под вопросом.
            if (mode === 'whole' || data.get('then') === 'open') body.append('whole', '1');
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        const submitter = event.submitter;
        ask(body, mode, form.dataset.priceGuardUrl).then((ok) => {
            if (!ok) return;
            // Согласие — на эту отправку: Turbo собирает данные формы синхронно, дальше поле не нужно.
            const input = setOk(form);
            form.dataset.priceChecked = '1';
            try { form.requestSubmit(submitter?.form === form ? submitter : undefined); } finally { delete form.dataset.priceChecked; input.remove(); }
        });
    }, { capture: true });
}

async function ask(body, mode, url = '/offers/price-check') {
    let warnings;
    try {
        const r = await post(url, body, { quiet: true, timeout: 8000 });
        warnings = r?.ok ? (await r.json()).warnings : null;
    } catch { warnings = null; }
    if (!warnings?.length) return true;
    const one = warnings.length === 1;
    const publish = mode === 'publish';
    return confirmSheet(one ? warnings[0].title : `Цена под вопросом у ${warnings.length} ${plural(warnings.length, ['ТС', 'ТС', 'ТС'])}`, {
        text: warnings.map((w) => (publish || !one ? `${w.name}\n${w.text}` : w.text)).join('\n'),
        label: publish ? 'Всё равно опубликовать' : mode === 'sold' ? 'Да, верно' : 'Да, сохранить',
        cancel: 'Исправить',
        danger: false,
        warn: true,
    });
}

// Согласие — скрытым полем формы (и у полей, приписанных к ней атрибутом form: им своё не нужно).
function setOk(form) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'price_ok';
    input.value = '1';
    form.append(input);
    return input;
}

// Схема сделки не та, что обычно у поставщика (03.11.2026: Land Cruiser 781R приняли «через ПРАЙМ», а договор напрямую):
// форма с `data-scheme-guard`, у отмеченной схемы `data-ask` (`DealScheme::unusual`) — лист «Точно?» до отправки, «Да» —
// `scheme_ok=1` и та же отправка. Без скрипта сервер спросит сам ошибкой у схемы.
export function schemeGuard() {
    window.addEventListener('submit', (event) => {
        const form = event.target;
        if (!form?.hasAttribute?.('data-scheme-guard') || form.dataset.schemeChecked || event.defaultPrevented) return;
        const ask = form.querySelector('input[name="scheme"]:checked')?.dataset.ask;
        if (!ask || new FormData(form).get('scheme_ok') === '1') return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const submitter = event.submitter;
        confirmSheet(ask, { label: 'Да, так и есть', cancel: 'Исправить', danger: false, warn: true }).then((ok) => {
            if (!ok) return;
            const input = document.createElement('input');
            Object.assign(input, { type: 'hidden', name: 'scheme_ok', value: '1' });
            form.append(input);
            form.dataset.schemeChecked = '1';
            try { form.requestSubmit(submitter?.form === form ? submitter : undefined); } finally { delete form.dataset.schemeChecked; input.remove(); }
        });
    }, { capture: true });
}
