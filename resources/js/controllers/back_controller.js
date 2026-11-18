import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { cameBack } from '../lib/sections';

// «‹ Раздел» в шапке: шаг назад по истории, если пришли изнутри приложения
// (Turbo восстановит снимок и сыграет pop), иначе — на корень раздела заменой.
// Экран, открытый возвратом в раздел (таб «Чаты» вернул в чат), — тоже заменой: запись истории перед ним — другой
// раздел (`sections.cameBack`, 18.11.2026).
export default class extends Controller {
    go(event) {
        event.preventDefault();
        const canGoBack = window.navigation?.canGoBack ?? history.length > 1;
        if (canGoBack && history.state?.turbo && !cameBack()) { history.back(); return; }
        Turbo.visit(this.element.href, { action: 'replace' });
    }
}
