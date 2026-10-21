/*
 * Sidus/AdminBundle Demo — Bulma stylesheet + minimal navbar burger toggle.
 *
 * Bulma is managed by AssetMapper's importmap (see importmap.php: the exact version is tracked
 * there, `php bin/console importmap:install` downloads it into assets/vendor/). Importing the CSS
 * from the entrypoint makes importmap() render it as a <link rel="stylesheet"> in the page head.
 *
 * Bulma is CSS-only, so the mobile "burger" menu needs a few lines of JS.
 */
import 'bulma/css/bulma.min.css';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.navbar-burger').forEach((burger) => {
        burger.addEventListener('click', () => {
            const target = document.getElementById(burger.dataset.target);
            burger.classList.toggle('is-active');
            target?.classList.toggle('is-active');
        });
    });
});
