(() => {
  let lifecycle;
  const init = () => {
    const toggle = document.getElementById('pf-menu-toggle');
    const sidebar = document.getElementById('pf-sidebar');
    if (!toggle || !sidebar || toggle.dataset.ready) return;
    lifecycle?.abort();
    lifecycle = new AbortController();
    const options = {signal: lifecycle.signal};
    toggle.dataset.ready = '1';
    const root = document.documentElement;
    const mobile = window.matchMedia('(max-width: 1099px)');
    const backdrop = document.querySelector('.pf-sidebar-backdrop');
    const background = [document.querySelector('.pf-header'), document.getElementById('pf-main'), document.querySelector('body > .footer'), document.getElementById('cookie-banner')];
    let collapsed = false;
    try { collapsed = localStorage.getItem('pf-navigation-collapsed') === '1'; } catch (_) {}
    const sync = () => {
      const open = mobile.matches ? root.classList.contains('pf-mobile-open') : !collapsed;
      root.classList.toggle('pf-nav-collapsed', !mobile.matches && collapsed);
      sidebar.inert = !open;
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? 'Réduire le menu' : 'Ouvrir le menu');
      backdrop.hidden = !(mobile.matches && open);
      background.forEach(el => { if (el) el.inert = mobile.matches && open; });
      if (mobile.matches && open) { sidebar.setAttribute('role', 'dialog'); sidebar.setAttribute('aria-modal', 'true'); }
      else { sidebar.removeAttribute('role'); sidebar.removeAttribute('aria-modal'); }
    };
    const close = (restore = true) => { root.classList.remove('pf-mobile-open'); sync(); if (restore) toggle.focus(); };
    toggle.addEventListener('click', () => {
      if (mobile.matches) { root.classList.toggle('pf-mobile-open'); sync(); if (root.classList.contains('pf-mobile-open')) sidebar.querySelector('[data-pf-close]').focus(); }
      else { collapsed = !collapsed; try { localStorage.setItem('pf-navigation-collapsed', collapsed ? '1' : '0'); } catch (_) {} sync(); window.dispatchEvent(new Event('resize')); }
    }, options);
    document.querySelectorAll('[data-pf-close]').forEach(el => el.addEventListener('click', () => close(), options));
    sidebar.addEventListener('click', e => { if (mobile.matches && e.target.closest('a')) close(false); }, options);
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') { if (root.classList.contains('pf-mobile-open')) close(); document.querySelectorAll('.pf-header-dropdown[open]').forEach(el => { el.open = false; el.querySelector('summary').focus(); }); }
      if (e.key === 'Tab' && mobile.matches && root.classList.contains('pf-mobile-open')) {
        const focusable = [...sidebar.querySelectorAll('a,button,summary')].filter(el => el.getClientRects().length);
        const first = focusable[0], last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    }, options);
    document.addEventListener('click', e => document.querySelectorAll('.pf-header-dropdown[open]').forEach(el => { if (!el.contains(e.target)) el.open = false; }), options);
    mobile.addEventListener('change', () => close(false), options);
    root.classList.remove('pf-mobile-open'); sync();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  document.addEventListener('turbo:load', init);
})();
