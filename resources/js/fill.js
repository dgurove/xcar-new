// Заливка полей формы ответом справочника — одна на ✨ VIN и на вставленный текст про машину. Обычное поле — значение
// (у списка — только из его вариантов), комбобокс — скрытый id и видимый текст. Поля заполняются по очереди и
// вспыхивают (field-filled). overwrite: false — только пустые (VIN не трогает набранное руками), true — всё, что
// пришло (человек сам вставил текст).
export const STEP = 90;

export const wait = (ms) => new Promise((r) => setTimeout(r, ms));

export function glow(el) {
    const field = el.closest('.field') || el;
    field.classList.remove('field-filled');
    void field.offsetWidth;
    field.classList.add('field-filled');
    field.addEventListener('animationend', () => field.classList.remove('field-filled'), { once: true });
}

// Поле по имени: у формы — и те, что стоят вне её с form= («Деньги» в редакторе справа).
const field = (root, name) => (root.elements?.namedItem(name) ?? null) || root.querySelector(`[name="${name}"]`);

export function filler(root, { overwrite = false } = {}) {
    const steps = [];
    const writable = (el) => el && !el.disabled && (overwrite || el.value === '');

    return {
        get count() { return steps.length; },

        plain(name, value) {
            const el = field(root, name);
            if (value == null || value === '' || !writable(el) || String(el.value) === String(value)) return;
            if (el.tagName === 'SELECT' && ![...el.options].some((o) => o.value === String(value))) return;
            steps.push(() => {
                el.value = value;
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
                glow(el);
            });
        },

        combo(name, id, text) {
            const hidden = field(root, name);
            const shown = document.getElementById(`f-${name}`);
            if (!id || !writable(hidden) || String(hidden.value) === String(id)) return;
            steps.push(() => {
                hidden.value = id;
                if (shown) shown.value = text || '';
                hidden.dispatchEvent(new Event('change', { bubbles: true }));
                glow(shown || hidden);
            });
        },

        async run() {
            for (const step of steps) { step(); await wait(STEP); }
        },
    };
}
