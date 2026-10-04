import { Controller } from '@hotwired/stimulus';
import { STEP, glow, wait } from '../fill.js';

// Поля, которые вписал не человек, а система, пока он смотрел (Мигторг: ImportMigtorgLot::glow) — та же вспышка, что у
// текста про машину и читалки документов: поле на миг пустеет и проявляется по очереди. Значения уже сохранены, поэтому
// ни input, ни change не шлются — черновик и «Сохранить изменения» об этом не узнают. Список — значением: пришёл
// с открытием редактора или с его перерисовкой морфом (задача Мигторга дописала поля карточки).
export default class extends Controller {
    static values = { fields: Array };

    async fieldsValueChanged(names) {
        if (!names?.length) return;
        // Комбобокс — видимый текст (f-<имя>), id в скрытом поле не трогается.
        const items = names.map((name) => {
            const el = document.getElementById(`f-${name}`) ?? this.element.elements?.namedItem(name) ?? document.querySelector(`[name="${name}"]`);
            return el && el.value !== '' && !el.closest('[hidden]') ? { el, value: el.value } : null;
        }).filter(Boolean);
        items.forEach(({ el }) => { if (el.tagName !== 'SELECT') el.value = ''; });
        for (const { el, value } of items) {
            await wait(STEP);
            el.value = value;
            glow(el);
        }
    }
}
