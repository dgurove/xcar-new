// Отменённый переход не рисуется (18.11.2026, CRM: «часто не даёт переключаться между разделами, застревает в разделе,
// что бы ни выбрал — обратно перекидывает», и на ПК, и на телефоне).
//
// Turbo отменяет прежний визит, когда начинается новый, но отмена только рвёт запрос и снимает ждущий кадр: если ответ
// уже в руках (предзагруженный, или отрисовка ждёт своей очереди и паузы живого обновления — `live.js` держит морф,
// пока палец на экране), отменённый визит всё равно ставит свой адрес и рисует свою страницу. Так тихое перечитывание
// раздела (возврат в раздел, живое событие, возврат из фона) через полсекунды рисовало его поверх раздела, в который
// человек уже ушёл. Дальше хуже: память разделов (`lib/sections.js`) записывала адрес и снимок не того раздела, и
// «застревание» жило до перезапуска приложения — проверки на это стоят там же (`belongs`).
//
// Как визит оказывался без отмены: начатый прямо в обработчике `turbo:load` (перечитывание снимка старше двух минут и
// ленты чатов — `freshness` в `app.js`) Turbo тут же «забывает» — навигатор, закончив прежний визит, стирает ссылку на
// текущий, а это уже новый. Следующему переходу отменять нечего, и забытое перечитывание доезжает и рисуется: на проде
// это 1–3 секунды после каждого возврата в раздел. Здесь последний начатый визит помнится отдельно: начался новый, а
// прежний ещё едет мимо навигатора — он отменяется.
//
// Класса визита Turbo наружу не отдаёт — прототип берётся у первого же визита.
import * as Turbo from '@hotwired/turbo';

const CANCELED = 'canceled';

export const canceled = (visit) => visit?.state === CANCELED;

/** Идёт переход на другой экран (не перечитывание этого же): нажатие «остаться здесь» его останавливает. */
let started = null; // последний начатый визит: навигатор мог о нём забыть (см. выше), а он едет
document.addEventListener('turbo:visit', () => { started = Turbo.navigator.currentVisit ?? null; });

export function leaving() {
    const visit = Turbo.navigator.currentVisit ?? started;
    return !!visit && visit.state === 'started' && visit.location?.pathname !== location.pathname;
}

/** Остановить идущий переход. Turbo сам не снимает с <html> `aria-busy` и полосу загрузки — экран выглядел бы грузящимся. */
export function stopVisit() {
    Turbo.navigator.stop();
    if (started?.state === 'started') started.cancel();
    document.documentElement.removeAttribute('aria-busy');
    Turbo.session.adapter?.hideVisitProgressBar?.();
    document.querySelectorAll('.is-pending').forEach((el) => el.classList.remove('is-pending'));
}

export function visitGuard() {
    let last = null;
    document.addEventListener('turbo:visit', () => {
        const visit = Turbo.navigator.currentVisit;
        if (last && last !== visit && last.state === 'started') last.cancel();
        last = visit ?? null;
    });
    const patch = () => {
        const proto = Object.getPrototypeOf(Turbo.navigator.currentVisit ?? null);
        if (!proto?.loadResponse) return;
        document.removeEventListener('turbo:visit', patch);
        for (const name of ['loadResponse', 'loadCachedSnapshot', 'renderPageSnapshot']) {
            const original = proto[name];
            proto[name] = function (...args) { return canceled(this) ? undefined : original.apply(this, args); };
        }
    };
    document.addEventListener('turbo:visit', patch);
}
