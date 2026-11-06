import { Controller } from '@hotwired/stimulus';
import { glow } from '../fill.js';

// Реквизиты целиком в любое поле ИП или организации (`x-billing.buyer-fields`, 06.11.2026): блок из банка («Название
// организации / ИНН / ОГРН/ОГРНИП / Расчетный счет / Банк / БИК …» — подпись строкой, значение следующей, или «ИНН: …»)
// раскладывается по полям с подсветкой. Без подписей — по виду цифр: 20 с 40… — счёт, с 301… — корр., 9 с 04… — БИК,
// 15/13 — ОГРН(ИП), 12/10 — ИНН. Вставка одного значения — обычная вставка.
const LABELS = [
    [/инн\s*банка|адрес\s*банка|кпп\s*банка/i, null],
    [/корр|к\/с/i, 'corr_account'],
    [/расч[её]тн|р\/с/i, 'account'],
    [/бик/i, 'bik'],
    [/огрн/i, 'ogrn'],
    [/кпп/i, 'kpp'],
    [/^инн\b/i, 'inn'],
    [/руковод|директор/i, 'director'],
    [/адрес/i, 'legal_address'],
    [/наименование\s*банка|^банк/i, 'bank_name'],
    [/назван|наименован/i, 'org'],
];

const DIGITAL = ['inn', 'kpp', 'ogrn', 'bik', 'account', 'corr_account'];

function labelOf(text) {
    const clean = text.replace(/[:\s]+$/, '').trim();
    if (clean.length > 60) return undefined;
    for (const [re, key] of LABELS) if (re.test(clean)) return key;
    return undefined;
}

function byDigits(value) {
    const d = value.replace(/[\s-]/g, '');
    if (!/^\d+$/.test(d)) return null;
    if (d.length === 20) return d.startsWith('301') ? 'corr_account' : 'account';
    if (d.length === 9) return d.startsWith('04') ? 'bik' : 'kpp';
    if (d.length === 15 || d.length === 13) return 'ogrn';
    if (d.length === 12 || d.length === 10) return 'inn';
    return null;
}

export function parseRequisites(text) {
    const lines = text.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
    const out = {};
    const put = (key, value) => {
        if (!key || !value || key in out) return;
        out[key] = DIGITAL.includes(key) ? value.replace(/[\s-]/g, '') : value.trim();
    };
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const colon = line.match(/^([^:]{2,60}):\s*(.+)$/);
        if (colon && labelOf(colon[1]) !== undefined) {
            put(labelOf(colon[1]), colon[2]);
            continue;
        }
        const key = labelOf(line);
        if (key !== undefined && lines[i + 1] !== undefined && labelOf(lines[i + 1]) === undefined) {
            put(key, lines[i + 1]);
            i++;
            continue;
        }
        put(byDigits(line), line);
    }

    return out;
}

// «ИНДИВИДУАЛЬНЫЙ ПРЕДПРИНИМАТЕЛЬ ГАМИДОВ ТАГИР ГАСИНОВИЧ» → «Гамидов Тагир Гасинович» — ФИО ИП, если его ещё нет.
function fioOf(name) {
    const m = String(name || '').match(/^(?:индивидуальный\s+предприниматель|ип)\s+(.+)$/i);
    if (!m) return null;

    return m[1].toLowerCase().replace(/(^|[\s-])(\S)/g, (_, a, b) => a + b.toUpperCase());
}

export default class extends Controller {
    static values = { prefix: String };

    static targets = ['group'];

    connect() {
        this.onPaste = (event) => this.paste(event);
        this.element.addEventListener('paste', this.onPaste);
    }

    disconnect() {
        this.element.removeEventListener('paste', this.onPaste);
    }

    paste(event) {
        const field = event.target;
        if (!this.groupTargets.some((g) => g.contains(field))) return;
        const text = event.clipboardData?.getData('text/plain') || '';
        if (!/\n/.test(text.trim())) return;
        const found = parseRequisites(text);
        const fio = fioOf(found.org);
        const org = [...this.element.querySelectorAll(`[name="${this.prefixValue}[org]"]`)].some((f) => !f.disabled);
        if (fio && !org) {
            delete found.org;
            found.name = fio;
        }
        const filled = Object.entries(found).filter(([key, value]) => this.write(key, value, key !== 'name'));
        if (!filled.length) return;
        event.preventDefault();
        window.toast?.(`Разложено по полям: ${filled.length}`);
    }

    // Видимое поле этого имени (у ИП и организации свои ИНН — пишется во включённое); ФИО — только в пустое.
    write(key, value, overwrite) {
        const el = [...this.element.querySelectorAll(`[name="${this.prefixValue}[${key}]"]`)].find((f) => !f.disabled);
        if (!el || (!overwrite && el.value !== '') || el.value === value) return false;
        el.value = value;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        glow(el);

        return true;
    }
}
