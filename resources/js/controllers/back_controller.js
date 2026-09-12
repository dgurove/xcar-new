import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

// «‹ Раздел» в шапке: шаг назад по истории, если пришли изнутри приложения
// (Turbo восстановит снимок и сыграет pop), иначе — на корень раздела заменой.
export default class extends Controller {
    go(event) {
        event.preventDefault();
        const canGoBack = window.navigation?.canGoBack ?? history.length > 1;
        if (canGoBack && history.state?.turbo) { history.back(); return; }
        Turbo.visit(this.element.href, { action: 'replace' });
    }
}
