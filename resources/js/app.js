// Оболочка: Turbo делает из серверных страниц приложение, Stimulus отвечает за
// поведение на месте. Контроллеры лежат в ./controllers/<имя>_controller.js
// и подключаются по имени файла: photos_controller.js → data-controller="photos".
import * as Turbo from '@hotwired/turbo';
import { Application } from '@hotwired/stimulus';
import { touchPrefetch } from './touch-prefetch';

const application = Application.start();
window.Stimulus = application;

const controllers = import.meta.glob('./controllers/*_controller.js', { eager: true });
for (const [path, module] of Object.entries(controllers)) {
    const name = path.match(/\/([\w-]+)_controller\.js$/)[1].replace(/_/g, '-');
    application.register(name, module.default);
}

Turbo.config.drive.progressBarDelay = 200;
touchPrefetch();

// View Transitions роняют промис, когда вкладка скрыта или переход перебит
// следующим: страница при этом в порядке, в консоли этому не место.
window.addEventListener('unhandledrejection', (event) => {
    if (['InvalidStateError', 'AbortError'].includes(event.reason?.name) && /transition/i.test(event.reason?.message || '')) {
        event.preventDefault();
    }
});
