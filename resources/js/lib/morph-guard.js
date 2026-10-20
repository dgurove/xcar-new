// Проверки морфа одной точкой. Turbo шлёт `turbo:before-morph-attribute` на каждый атрибут каждого элемента и
// `turbo:before-morph-element` на каждый элемент: на списке CRM — 24 тыс. событий. Каждое всплывало через весь DOM к
// четырём слушателям на document, и морф стоил 2,5 с на телефоне против 0,16 с без них (аудит 20.10.2026). Теперь
// событие ловится на window в фазе захвата, проверки зовутся из списка, дальше оно не идёт: Turbo смотрит только
// `defaultPrevented`. Своя проверка — `onMorphAttribute(fn)` / `onMorphElement(fn)`, вернут функцию снятия.
const attrs = new Set();
const elements = new Set();

addEventListener('turbo:before-morph-attribute', (e) => {
    e.stopImmediatePropagation();
    for (const fn of attrs) fn(e);
}, true);
addEventListener('turbo:before-morph-element', (e) => {
    e.stopImmediatePropagation();
    for (const fn of elements) fn(e);
}, true);

export function onMorphAttribute(fn) {
    attrs.add(fn);
    return () => attrs.delete(fn);
}

export function onMorphElement(fn) {
    elements.add(fn);
    return () => elements.delete(fn);
}
