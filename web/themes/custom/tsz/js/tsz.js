/**
 * @file
 * Mobile menu toggle and search toggle. Progressive enhancement: without JS the
 * menu and search form are simply visible.
 */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.tszHeader = {
    attach(context) {
      once('tsz-header', '.site-header', context).forEach((header) => {
        document.documentElement.classList.add('js');
        const toggle = header.querySelector('.nav-toggle');
        const menu = header.querySelector('.site-header__menu');
        toggle?.addEventListener('click', () => {
          const open = toggle.getAttribute('aria-expanded') === 'true';
          toggle.setAttribute('aria-expanded', String(!open));
          menu.classList.toggle('is-open', !open);
        });
        const search = header.querySelector('.site-header__search');
        if (search) {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'search-toggle';
          btn.setAttribute('aria-expanded', 'false');
          btn.setAttribute('aria-label', Drupal.t('Search'));
          btn.innerHTML = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M10 2a8 8 0 1 0 4.9 14.3l5.4 5.4 1.4-1.4-5.4-5.4A8 8 0 0 0 10 2zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12z"/></svg>';
          search.prepend(btn);
          btn.addEventListener('click', () => {
            const open = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', String(!open));
            search.classList.toggle('is-open', !open);
            if (!open) search.querySelector('input[type="search"], input[type="text"]')?.focus();
          });
        }
      });
    },
  };
}(Drupal, once));
