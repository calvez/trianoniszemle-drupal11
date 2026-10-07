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

  /**
   * Photo galleries: 3+ consecutive image-only paragraphs in a body become a
   * thumbnail grid; clicking opens a lightbox (native <dialog>). Without JS the
   * images simply stay stacked.
   */
  Drupal.behaviors.tszGallery = {
    attach(context) {
      once('tsz-gallery', '.node__content', context).forEach((root) => {
        const host = root.querySelector('.field--name-body') || root;
        const isImageOnly = (el) => el.tagName === 'P' && el.querySelectorAll('img').length === 1
          && el.textContent.replace(/\s|\u00a0/g, '') === '';
        const runs = []; let run = [];
        [...host.children].forEach((el) => {
          if (isImageOnly(el)) { run.push(el); } else { if (run.length >= 3) runs.push(run); run = []; }
        });
        if (run.length >= 3) runs.push(run);
        runs.forEach((items) => {
          const grid = document.createElement('div');
          grid.className = 'gallery';
          items[0].before(grid);
          items.forEach((p) => grid.append(p));
        });
        const imgs = [...host.querySelectorAll('.gallery img')];
        if (!imgs.length) return;
        let dlg = document.querySelector('dialog.lightbox');
        if (!dlg) {
          dlg = document.createElement('dialog');
          dlg.className = 'lightbox';
          dlg.setAttribute('aria-label', Drupal.t('Image viewer'));
          dlg.innerHTML = '<button type="button" class="lightbox__close" aria-label="' + Drupal.t('Close') + '">&times;</button>'
            + '<button type="button" class="lightbox__nav lightbox__prev" aria-label="' + Drupal.t('Previous') + '">&#8249;</button>'
            + '<figure class="lightbox__figure"><img alt=""><figcaption></figcaption></figure>'
            + '<button type="button" class="lightbox__nav lightbox__next" aria-label="' + Drupal.t('Next') + '">&#8250;</button>';
          document.body.append(dlg);
        }
        const view = dlg.querySelector('img'); const cap = dlg.querySelector('figcaption'); let list = imgs; let i = 0;
        const show = (n) => { i = (n + list.length) % list.length; view.src = list[i].currentSrc || list[i].src; view.alt = list[i].alt; cap.textContent = list[i].alt + ' (' + (i + 1) + '/' + list.length + ')'; };
        imgs.forEach((img, n) => {
          img.tabIndex = 0; img.setAttribute('role', 'button');
          const open = () => { list = imgs; show(n); if (!dlg.open) dlg.showModal(); };
          img.addEventListener('click', open);
          img.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
        });
        if (!dlg.dataset.bound) {
          dlg.dataset.bound = '1';
          dlg.querySelector('.lightbox__close').addEventListener('click', () => dlg.close());
          dlg.querySelector('.lightbox__prev').addEventListener('click', () => show(i - 1));
          dlg.querySelector('.lightbox__next').addEventListener('click', () => show(i + 1));
          dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
          dlg.addEventListener('keydown', (e) => { if (e.key === 'ArrowLeft') show(i - 1); if (e.key === 'ArrowRight') show(i + 1); });
        }
      });
    },
  };
}(Drupal, once));
