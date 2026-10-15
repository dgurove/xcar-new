// Отложить до паузы в наборе (поиск по ходу набора, подсказки, «Показать N»): ms после последнего вызова; cancel() —
// снять ожидание (поле очистили, ушли со страницы).
export function debounce(fn, ms) {
    let timer = 0;
    const run = (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), ms); };
    run.cancel = () => clearTimeout(timer);
    return run;
}
