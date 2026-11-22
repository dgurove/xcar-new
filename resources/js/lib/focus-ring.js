// Обводка фокуса — только при ходьбе клавиатурой (23.11.2026, владелец: «бесит, что зелёным обводит»): фокус, поставленный
// скриптом после нажатия мышью (окно машины, шторка, лента кадров), браузер тоже считает «видимым». Класс `kbd` у `html`
// (стили — `html:not(.kbd) :focus-visible`) меняется только при смене способа, а не на каждое нажатие.
const KEYS = new Set(['Tab', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight']);

export function focusRing() {
    const root = document.documentElement;
    let on = false;
    const set = (value) => { if (on !== value) { on = value; root.classList.toggle('kbd', value); } };
    // Стрелки в поле ввода — движение каретки, а не ходьба по экрану.
    const typing = (el) => el instanceof Element && (el.matches('input, textarea, select, trix-editor') || el.isContentEditable);
    document.addEventListener('keydown', (e) => {
        if (!KEYS.has(e.key) || e.metaKey || e.ctrlKey || e.altKey) return;
        if (e.key !== 'Tab' && typing(e.target)) return;
        set(true);
    }, true);
    document.addEventListener('pointerdown', () => set(false), true);
}
