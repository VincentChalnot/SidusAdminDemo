/*
 * Sidus/AdminBundle Demo — minimal Bulma navbar burger toggle.
 * Bulma is CSS-only, so the mobile "burger" menu needs a few lines of JS.
 */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.navbar-burger').forEach((burger) => {
        burger.addEventListener('click', () => {
            const target = document.getElementById(burger.dataset.target);
            burger.classList.toggle('is-active');
            target?.classList.toggle('is-active');
        });
    });
});
