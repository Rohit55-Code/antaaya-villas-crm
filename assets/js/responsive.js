/* Antaaya Villas CRM — responsive.js
   Phone / tablet app shell. Loaded right after app.js and leaves app.js untouched.
   - Slide-out menu (the sidebar) opened from the app bar / "More", closed by the backdrop,
     Escape, picking a page, or the phone's Back button.
   - Phone bottom navigation built from the role's own sidebar menu (same pages, same badges).
   - Notification bell moved into the app bar on phones / tablets in portrait, just left of the user's profile
     photo (tap the photo: name + role, view / change photo, Logout).
   - Every table cell gets data-label (its column heading) + a role class, so responsive.css
     can show each table row as a card. Re-applied whenever the app re-renders a table.
   - Table headings keep their icon on the same line; headings get data-label so responsive.css can
     leave out Manage Leads columns that don't fit on one line (they show in the Lead Summary).
   - Emails in tables wrap only after "@" or a dot.
   - Dialogs: page behind doesn't scroll, Back button closes the dialog instead of leaving
     the CRM, lead name shown under "Edit Client", current stage chip scrolled into view,
     the ✎ basic-details form scrolls with the stage details, Save rows pinned in long forms.
   - iPhone / iPad: no page zoom when tapping a form field.
   Breakpoints match assets/css/responsive.css. */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const body = document.body;
  const sidebar = $('.sidebar');
  const appbarEnd = $('#rsAppbarEnd');
  const bottomNav = $('#rsBottomNav');
  if (!sidebar || !appbarEnd || !bottomNav) return;

  // iPhone / iPad zoom the whole page into any form field whose text is under 16px. Form text here is
  // 14px, so on those devices only, the viewport gets maximum-scale=1 — that stops the jump on focus,
  // while iOS still lets people pinch-zoom as usual. (Android doesn't zoom into fields; left as is.)
  const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const vp = $('meta[name="viewport"]');
  if (isIOS && vp && !/maximum-scale/.test(vp.content)) vp.content += ', maximum-scale=1';

  const mqApp = matchMedia('(max-width: 1023.98px)');                                     // app bar + slide-out menu
  const mqTouch = matchMedia('(max-width: 1023.98px), (max-width: 1366px) and (pointer: coarse)'); // + touch tablets
  const navBtns = () => $$('.sidebar .nav button[data-page]');
  const onMq = (mq, fn) => (mq.addEventListener ? mq.addEventListener('change', fn) : mq.addListener(fn));

  /* ---------------- Slide-out menu ---------------- */
  const openNav = () => body.classList.add('rs-nav-open');
  const closeNav = () => body.classList.remove('rs-nav-open');
  $('#rsMenuBtn')?.addEventListener('click', () => (body.classList.contains('rs-nav-open') ? closeNav() : openNav()));
  $('#rsScrim')?.addEventListener('click', closeNav);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeNav(); });
  sidebar.addEventListener('click', e => { if (e.target.closest('.nav button')) closeNav(); });

  /* ---------------- Bottom navigation (phones) ---------------- */
  const SHORT = {
    dashboard: 'Dashboard', leads: 'Leads', sourceleads: 'Sources', visits: 'Visits', followups: 'Follow-ups',
    bookings: 'Bookings', villas: 'Villas', payments: 'Payments', possession: 'Possession', team: 'Team',
    usermgmt: 'Users', notiflog: 'Log',
  };
  // Day-to-day pages first; whatever doesn't fit goes under "More" (the slide-out menu).
  const PRIORITY = ['dashboard', 'leads', 'visits', 'followups', 'payments', 'bookings', 'sourceleads', 'villas', 'possession', 'team', 'usermgmt', 'notiflog'];
  const BADGES = { visits: 'visitBadge', followups: 'followupBadge' };
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let mainPages = [];
  function buildBottomNav() {
    const btns = navBtns();
    const pages = btns.map(b => b.dataset.page);
    const ordered = PRIORITY.filter(p => pages.includes(p)).concat(pages.filter(p => !PRIORITY.includes(p)));
    mainPages = ordered.length <= 5 ? ordered : ordered.slice(0, 4);
    bottomNav.innerHTML = mainPages.map(p => {
      const src = btns.find(b => b.dataset.page === p);
      const icon = src.querySelector('i')?.className || 'fa-solid fa-circle';
      const label = SHORT[p] || (src.querySelector('span')?.textContent || p).trim();
      return `<button type="button" data-page="${esc(p)}" aria-label="${esc(label)}"><span class="rs-bn-ico"><i class="${esc(icon)}"></i>${BADGES[p] ? '<b class="rs-bn-badge" hidden></b>' : ''}</span><span class="rs-bn-lbl">${esc(label)}</span></button>`;
    }).join('') + (ordered.length > 5 ? '<button type="button" data-more="1" aria-label="More"><span class="rs-bn-ico"><i class="fa-solid fa-grip"></i></span><span class="rs-bn-lbl">More</span></button>' : '');
    syncNav();
  }
  bottomNav.addEventListener('click', e => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.more) { openNav(); return; }
    const src = navBtns().find(x => x.dataset.page === b.dataset.page);
    if (src) src.click(); // the app's own nav() — same as tapping it in the menu
  });
  let lastPage = null;
  function syncNav() {
    const active = navBtns().find(b => b.classList.contains('active'));
    const page = active ? active.dataset.page : 'dashboard';
    $$('button', bottomNav).forEach(b => b.classList.toggle('active', b.dataset.more ? !mainPages.includes(page) : b.dataset.page === page));
    Object.entries(BADGES).forEach(([p, id]) => {
      const src = document.getElementById(id), dst = $(`button[data-page="${p}"] .rs-bn-badge`, bottomNav);
      if (!src || !dst) return;
      const on = src.style.display !== 'none' && src.textContent.trim() && src.textContent.trim() !== '0';
      dst.hidden = !on;
      dst.textContent = on ? src.textContent.trim() : '';
    });
    // Each page is a section of the same document: start a newly opened page at the top.
    if (page !== lastPage) {
      if (lastPage !== null && mqApp.matches) window.scrollTo(0, 0);
      lastPage = page;
      requestAnimationFrame(() => fitFilters()); // the new page's filters are visible now
    }
  }
  new MutationObserver(syncNav).observe($('.sidebar .nav'), { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['class', 'style'] });
  buildBottomNav();

  /* ---------------- Notification bell: in the app bar on small screens, left of the profile photo ---------------- */
  const bell = $('.notif-wrap');
  const bellHome = bell ? bell.parentElement : null;
  const bellNext = bell ? bell.nextElementSibling : null;
  function placeBell() {
    if (!bell) return;
    if (mqApp.matches) { if (bell.parentElement !== appbarEnd) appbarEnd.insertBefore(bell, $('#rsMe', appbarEnd)); }
    else if (bell.parentElement !== bellHome) bellHome.insertBefore(bell, bellNext && bellNext.parentElement === bellHome ? bellNext : null);
  }
  placeBell();
  onMq(mqApp, () => { if (!mqApp.matches) closeNav(); placeBell(); centerCurrent(); });

  /* ---------------- Profile photo at the right end of the app bar ----------------
     Same photo as the sidebar's (app.js updates that one when the photo is changed or removed — mirrored here).
     Tap: name + role, the photo (view / change, same as tapping the sidebar photo) and Logout. */
  const me = $('#rsMe'), meBtn = $('#rsMeBtn'), mePanel = $('#rsMePanel'), sideAvatar = $('#avatarWrap');
  if (me && meBtn && mePanel) {
    const syncMe = () => {
      const img = sideAvatar ? $('#avatarImg', sideAvatar) : null;
      const html = img ? `<img src="${esc(img.getAttribute('src'))}" alt="">` : '<i class="fa-solid fa-circle-user"></i>';
      $$('.rs-me-btn, .rs-me-pic', me).forEach(el => { if (el.innerHTML !== html) el.innerHTML = html; });
      const lbl = $('[data-act="photo"] span', me);
      if (lbl) lbl.textContent = img ? 'View / change photo' : 'Add profile photo';
    };
    const setMe = open => { mePanel.hidden = !open; meBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); };
    syncMe();
    if (sideAvatar) new MutationObserver(syncMe).observe(sideAvatar, { childList: true, subtree: true, attributes: true, attributeFilter: ['src'] });
    meBtn.addEventListener('click', () => setMe(mePanel.hidden));
    mePanel.addEventListener('click', e => {
      const b = e.target.closest('[data-act]');
      if (!b) return;
      setMe(false);
      if (b.dataset.act === 'photo') { if (sideAvatar) sideAvatar.click(); } // view the photo, or pick one if there's none
      else if (b.dataset.act === 'logout' && typeof logout === 'function') logout();
    });
    // closes on a tap anywhere else (the bell, the menu, the page), Escape, or when the app bar goes away
    document.addEventListener('pointerdown', e => { if (!mePanel.hidden && !me.contains(e.target)) setMe(false); }, true);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') setMe(false); });
    onMq(mqApp, () => { if (!mqApp.matches) setMe(false); });
  }

  /* ---------------- Tables → cards: label each cell with its column heading ---------------- */
  const TITLE_KEYS = ['client', 'name', 'salesperson', 'team member', 'villa name'];
  const ID_KEYS = ['lead id', 'lead', 'serial', '#'];
  const BADGE_SEL = '.badge, .ps-pill, .pay-pill';
  // Badge columns that read fine without their heading (HOT, Site Visit, Pending…) sit in one row under
  // the name; others (Keys "No", Agreement "Pending"…) keep their label like any other detail.
  const BADGE_INLINE = /^(stage|interest|category|status|visit type|type|booking status)$/i;
  const clean = s => (s || '').replace(/\s+/g, ' ').trim();
  // Table headings: the icon always stays on the same line as its heading (never alone above it)
  function joinHeadIcon(th) {
    if (th.querySelector(':scope > .rs-th-lead')) return;
    const icon = th.firstElementChild;
    if (!icon || icon.tagName !== 'I') return;
    const txt = icon.nextSibling;
    if (!txt || txt.nodeType !== 3) return;
    const m = txt.textContent.match(/^(\s*\S+)([\s\S]*)$/);
    if (!m) return;
    const lead = document.createElement('span');
    lead.className = 'rs-th-lead';
    th.insertBefore(lead, icon);
    lead.append(icon, m[1]);
    txt.textContent = m[2];
  }

  // Emails may wrap onto a second line only after "@" or a dot, never in the middle of a word
  function softBreakEmails(td) {
    if (td.querySelector('wbr')) return;
    const walker = document.createTreeWalker(td, NodeFilter.SHOW_TEXT);
    const nodes = [];
    for (let n = walker.nextNode(); n; n = walker.nextNode()) if (n.textContent.includes('@')) nodes.push(n);
    nodes.forEach(node => {
      const s = node.textContent, frag = document.createDocumentFragment();
      let last = 0;
      s.replace(/[^\s@]+@[^\s@]+/g, (m, off) => {
        frag.append(s.slice(last, off));
        const parts = m.replace(/([@.])/g, '$1\u0001').split('\u0001');
        parts.forEach((part, i) => { frag.append(part); if (i < parts.length - 1) frag.append(document.createElement('wbr')); });
        last = off + m.length;
        return m;
      });
      frag.append(s.slice(last));
      node.replaceWith(frag);
    });
  }

  function labelTables() {
    $$('.table-wrap > table').forEach(t => {
      const ths = $$('thead th', t);
      const heads = ths.map(th => clean(th.textContent));
      if (!heads.length) return;
      t.classList.add('rt');
      const low = heads.map(h => h.toLowerCase());
      // headings carry their text as data-label too, so responsive.css can show / hide a whole column
      ths.forEach((th, i) => { joinHeadIcon(th); if (th.dataset.label !== heads[i]) th.dataset.label = heads[i]; });
      // Manage Leads comes in three versions (Sales, Legal desk, Accounts desk) with different columns
      if (t.closest('#leadsTableWrap')) {
        const kind = low.includes('booking status') ? 'legal' : low.includes('paid') ? 'accounts' : 'sales';
        if (t.dataset.leads !== kind) t.dataset.leads = kind;
      }
      let title = low.findIndex(h => TITLE_KEYS.includes(h));
      if (title < 0) title = low.indexOf('lead');
      if (title < 0) title = 0;
      const id = low.findIndex((h, i) => i !== title && ID_KEYS.includes(h));
      $$(':scope > tbody > tr', t).forEach(tr => {
        const cells = Array.from(tr.children);
        const msg = cells.length === 1 && cells[0].colSpan > 1;
        tr.classList.toggle('rt-msg', msg);
        if (msg) return;
        tr.classList.toggle('rt-click', tr.hasAttribute('onclick'));
        cells.forEach((td, i) => {
          const text = clean(td.textContent);
          const ctl = td.querySelector('button, a.btn, select, input');
          const badgeOnly = !ctl && td.children.length > 0 && BADGE_INLINE.test(heads[i] || '') &&
            Array.from(td.childNodes).every(n => (n.nodeType === 1 ? n.matches(BADGE_SEL) : !clean(n.textContent)));
          const isTitle = i === title, isId = i === id;
          const isActions = !isTitle && !!td.querySelector('button, a.btn') && !td.querySelector('select') && (/^(action|actions)$/i.test(heads[i] || '') || i === cells.length - 1);
          if (td.dataset.label !== (heads[i] || '')) td.dataset.label = heads[i] || '';
          td.classList.toggle('rc-title', isTitle);
          td.classList.toggle('rc-id', isId);
          td.classList.toggle('rc-actions', isActions);
          td.classList.toggle('rc-badge', !isTitle && !isId && badgeOnly);
          td.classList.toggle('rc-empty', !isTitle && !ctl && !td.querySelector('img, .pay-mini') && (text === '' || text === '—'));
          td.classList.toggle('rc-wide', !isTitle && !isActions && (!!td.querySelector('select') || text.length > 34));
          const email = text.includes('@');
          td.classList.toggle('rc-nowrap', !!text && !email && !/\s/.test(text)); // laptop tables: don't split 2026-10-04 at the hyphen
          td.classList.toggle('rc-email', email);
          if (email) softBreakEmails(td);
        });
      });
    });
  }
  /* ---------------- Phones: filter dropdowns two per row — one per row when a choice doesn't fit ---------------- */
  const mqPhone = matchMedia('(max-width: 700px)');
  const textCtx = document.createElement('canvas').getContext('2d');
  function fitFilters() {
    $$('.main .toolbar-row').forEach(row => {
      if (!mqPhone.matches || !row.offsetParent) { row.classList.remove('rs-one-col'); return; }
      const sels = $$('select', row).filter(sel => sel.offsetParent);
      const gap = parseFloat(getComputedStyle(row).columnGap) || 8;
      const half = (row.clientWidth - gap) / 2; // width of a cell when two share the row
      const tooLong = sels.some(sel => {
        const st = getComputedStyle(sel);
        textCtx.font = `${st.fontWeight} ${st.fontSize} ${st.fontFamily}`;
        const text = (sel.options[sel.selectedIndex] || {}).text || '';
        const room = half - parseFloat(st.paddingLeft) - parseFloat(st.paddingRight) - parseFloat(st.borderLeftWidth) - parseFloat(st.borderRightWidth) - 18; // 18 = arrow
        return textCtx.measureText(text).width > room;
      });
      row.classList.toggle('rs-one-col', tooLong);
    });
  }
  document.addEventListener('change', e => { if (e.target.closest && e.target.closest('.toolbar-row')) fitFilters(); });
  window.addEventListener('resize', () => requestAnimationFrame(fitFilters));

  let queued = false;
  const queueLabels = () => { if (queued) return; queued = true; requestAnimationFrame(() => { queued = false; labelTables(); fitFilters(); }); };
  const main = $('.main');
  if (main) new MutationObserver(queueLabels).observe(main, { childList: true, subtree: true });
  labelTables();
  fitFilters();
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitFilters); // measure with the real font

  /* ---------------- Dialogs ---------------- */
  const modals = $$('.modal');
  const openModals = () => modals.filter(m => m.classList.contains('show'));
  const fab = () => $$('.page .top .btn.primary');
  fab().forEach(b => { if (!b.getAttribute('aria-label')) b.setAttribute('aria-label', clean(b.textContent)); });

  // Back button (phones / tablets): one history entry while a dialog or the menu is open;
  // Back closes it instead of leaving the CRM. Closing it on screen removes that entry again.
  let sentinel = false, ownBack = 0;
  function syncHistory() {
    if (!mqTouch.matches || ownBack) return; // wait until our own history.back() has landed
    const open = openModals().length > 0 || body.classList.contains('rs-nav-open');
    if (open && !sentinel) { history.pushState({ rsOverlay: 1 }, ''); sentinel = true; }
    else if (!open && sentinel) {
      sentinel = false;
      if (history.state && history.state.rsOverlay) { ownBack++; history.back(); }
    }
  }
  function overlaysChanged() {
    body.classList.toggle('rs-modal-open', openModals().length > 0);
    syncHistory();
    queueMark();
  }

  // Dialogs taller than the screen: the pinned Save / Cancel row gets its top shadow (responsive.css)
  function markScroll() {
    $$('.modal.show .modal-box').forEach(b => b.classList.toggle('rs-scroll', b.scrollHeight > b.clientHeight + 1));
  }
  let markQueued = false;
  function queueMark() {
    if (markQueued) return;
    markQueued = true;
    requestAnimationFrame(() => { markQueued = false; markScroll(); });
  }
  window.addEventListener('resize', queueMark);
  document.addEventListener('input', queueMark, true);
  document.addEventListener('click', queueMark, true);
  window.addEventListener('popstate', () => {
    if (ownBack) { ownBack--; syncHistory(); return; } // our own back(); a dialog may have opened meanwhile
    if (!sentinel) return;
    sentinel = false;
    closeNav();
    openModals().forEach(m => m.classList.remove('show')); // same as the dialog's own Cancel / ×
  });
  const mo = new MutationObserver(recs => {
    overlaysChanged();
    if (recs.some(r => r.target.id === 'clientModal' && r.target.classList.contains('show'))) onClientOpen();
  });
  modals.forEach(m => mo.observe(m, { attributes: true, attributeFilter: ['class'] }));
  mo.observe(body, { attributes: true, attributeFilter: ['class'] });

  // Edit Client / Add New Lead: the ✎ basic-details form scrolls together with the stage details, so the
  // heading + details card stay fixed on top and Last completed + the buttons stay fixed at the bottom.
  const basicEdit = $('#basicEdit'), midScroll = $('#clientMidScroll');
  if (basicEdit && midScroll) {
    if (basicEdit.parentElement !== midScroll) midScroll.insertBefore(basicEdit, midScroll.firstChild);
    // opened with ✎ while scrolled further down: bring it into view
    new MutationObserver(() => {
      if (!basicEdit.classList.contains('hidden') && midScroll.scrollHeight > midScroll.clientHeight) midScroll.scrollTop = 0;
    }).observe(basicEdit, { attributes: true, attributeFilter: ['class'] });
  }

  // Edit Client: lead name under the title (the details strip scrolls away on small screens)
  function onClientOpen() {
    const title = $('#clientTitle'), f = $('#clientForm');
    if (title && f && f.elements.name) title.dataset.sub = f.dataset.edit ? clean(f.elements.name.value) : '';
    const box = $('#clientModal .modal-box');
    if (box) box.scrollTop = 0;
    requestAnimationFrame(centerCurrent);
  }

  // Checkpoint chips / stage tabs are one swipeable row on phones: keep the current one in view
  function centerRow(row, item) {
    if (!row || !item || row.scrollWidth <= row.clientWidth + 2) return;
    row.scrollLeft = Math.max(0, item.offsetLeft - (row.clientWidth - item.offsetWidth) / 2);
  }
  function centerCurrent() {
    if (!mqApp.matches || !$('#clientModal.show')) return;
    $$('#clientModal .stage-checklist').forEach(list => centerRow(list, $('.chip-current', list) || $$('.chip-done', list).pop()));
    const tabs = $('#clientModal .tabs');
    centerRow(tabs, tabs && $('.tab.active', tabs));
  }
  let chipQueued = false;
  const chipObs = new MutationObserver(() => {
    if (chipQueued) return;
    chipQueued = true;
    requestAnimationFrame(() => { chipQueued = false; centerCurrent(); });
  });
  $$('#clientModal .stage-checklist').forEach(l => chipObs.observe(l, { childList: true }));      // checklist re-rendered
  $$('#clientModal .tabs .tab').forEach(t => chipObs.observe(t, { attributes: true, attributeFilter: ['class'] })); // tab switched
})();
