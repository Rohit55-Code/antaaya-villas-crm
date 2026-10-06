// Antaaya Villas CRM — frontend logic (talks to PHP/MySQL API)

let state = { clients: [], followups: [], visits: [], users: [], sourceLeads: [], leadRequests: [] };
function canManageSourceLeads() {
  const u = window.CURRENT_USER;
  return isAdminRole(u.role) || u.desk === 'entry';
}

const EYE_OPEN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>';
const EYE_CLOSED = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a20.3 20.3 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a20.3 20.3 0 0 1-3.22 4.44M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>';
function togglePwd(inputId, btn) {
  const input = document.getElementById(inputId);
  const showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  btn.innerHTML = showing ? EYE_CLOSED : EYE_OPEN;
  btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
}

function esc(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
// For a value embedded inside a single-quoted JS string INSIDE an
// onclick="..." attribute (e.g. onclick="deleteClient(1, '${jsAttr(name)}')").
// Any name with an apostrophe (Patrick D'Souza, O'Brien...) needs the quote
// escaped for JS *before* the whole thing gets HTML-escaped for the
// attribute — doing it the other way round (escape-for-HTML then try to
// backslash-escape) doesn't work, because by then the ' is already gone
// (turned into &#39;), so the backslash-escape is a no-op. The browser then
// decodes &#39; back into a bare ' when it parses the attribute, which
// silently breaks the JS string and makes the button do nothing.
function jsAttr(s) {
  return esc(String(s ?? '').replace(/\\/g, '\\\\').replace(/'/g, "\\'"));
}
function money(n) { n = Number(n || 0); return n ? '₹' + n.toLocaleString('en-IN') : '₹0'; }
// Rupee-prefixed amount field pair (see .amt-field in index.php): a plain-text
// display input showing Indian comma grouping (1,00,000), paired with a
// hidden numeric input (same field name as before) that's what actually gets
// submitted. Raw digits are shown while focused so editing isn't fighting the
// commas; it reformats on blur and whenever a lead's saved value is loaded.
function amtHidden(displayEl) { return displayEl.parentElement.querySelector('input[type="hidden"]'); }
function amtSanitize(displayEl) {
  let raw = displayEl.value.replace(/[^0-9.]/g, '');
  const firstDot = raw.indexOf('.');
  if (firstDot !== -1) raw = raw.slice(0, firstDot + 1) + raw.slice(firstDot + 1).replace(/\./g, '');
  amtHidden(displayEl).value = raw;
}
function amtEditMode(displayEl) { const v = amtHidden(displayEl).value; displayEl.value = Number(v) ? v : ''; } // 0.00 → empty, so typing doesn't append to it
function amtFormat(displayEl) {
  const v = amtHidden(displayEl).value;
  displayEl.value = Number(v) ? Number(v).toLocaleString('en-IN') : '';
}
// ---------- Final Villa Price → Booking Amount / Booking Date ----------
// Final Villa Price is a plain rupee number (it used to be free text like
// "₹2.35 Cr" — those old values are converted when a lead is opened). It is
// also the Final Sale Value (api/clients.php copies it into salevalue, which
// Payments / outstanding use), so there's no separate Sale Value field any more.
const BOOKING_PCT = 0.10; // Booking Amount = 10% of Final Villa Price
function inrToNumber(v) {
  if (v === null || v === undefined) return 0;
  const t = String(v).toLowerCase().replace(/[₹,\s]|rs\.?|inr/g, '');
  const m = t.match(/^(\d+(?:\.\d+)?)(cr|crore|crores|l|lac|lacs|lakh|lakhs|k)?$/);
  if (!m) return 0;
  const mul = { cr: 1e7, crore: 1e7, crores: 1e7, l: 1e5, lac: 1e5, lacs: 1e5, lakh: 1e5, lakhs: 1e5, k: 1e3 }[m[2]] || 1;
  return Math.round(parseFloat(m[1]) * mul * 100) / 100;
}
function inrWords(n) {
  n = Number(n) || 0;
  const trim = x => x.toFixed(2).replace(/\.?0+$/, '');
  return n >= 1e7 ? `₹${trim(n / 1e7)} Cr` : n >= 1e5 ? `₹${trim(n / 1e5)} Lakh` : '';
}
// A lead's villa price as a number (falls back to the old Final Sale Value for leads saved before v101).
function villaPriceNum(c) { return (c && (inrToNumber(c.final_villa_price) || Number(c.salevalue) || 0)) || 0; }
function villaPriceText(c) { const n = villaPriceNum(c); return n ? money(n) : ((c && c.final_villa_price) || ''); }
function renderVillaPriceWords() {
  const f = document.getElementById('clientForm'), el = document.getElementById('finalVillaPriceWords');
  if (!el || !f.elements.final_villa_price) return;
  const n = Number(f.elements.final_villa_price.value) || 0;
  el.textContent = n ? `${inrWords(n) ? inrWords(n) + ' · ' : ''}Booking amount (10%): ${money(Math.round(n * BOOKING_PCT))}` : '';
  renderBookingBreakdown();
}
// ---------- Agreement In Process → Stamp Duty / Registration Fee ----------
// Stamp Duty = 6% of Final Villa Price (editable). GST = 5% of whatever stamp duty is entered — calculated,
// shown in its own read-only field, not stored. Registration Fee defaults to ₹50,000 (editable).
// Auto-filled only while the field is empty or still auto (data-auto="1"); once typed in, it's left alone.
const STAMP_DUTY_PCT = 0.06, STAMP_DUTY_GST_PCT = 0.05, REGISTRATION_FEE_DEFAULT = 50000;
function stampDutyCalc(price) { return Math.round((Number(price) || 0) * STAMP_DUTY_PCT); }
function stampGstCalc(duty) { return Math.round((Number(duty) || 0) * STAMP_DUTY_GST_PCT); }
function syncAgreementFees(onOpen) {
  const f = document.getElementById('clientForm');
  const sdDisp = document.getElementById('stampDutyDisplay'), rfDisp = document.getElementById('registrationFeeDisplay');
  if (!sdDisp || !rfDisp) return;
  if (onOpen) {
    sdDisp.dataset.auto = Number(f.elements.stamp_duty_amount.value) > 0 ? '' : '1';
    rfDisp.dataset.auto = Number(f.elements.registration_fee.value) > 0 ? '' : '1';
  }
  const price = Number(f.elements.final_villa_price && f.elements.final_villa_price.value) || 0;
  if (sdDisp.dataset.auto === '1') f.elements.stamp_duty_amount.value = price ? String(stampDutyCalc(price)) : '';
  if (rfDisp.dataset.auto === '1') f.elements.registration_fee.value = String(REGISTRATION_FEE_DEFAULT);
  amtFormat(sdDisp); amtFormat(rfDisp);
  renderAgreementFeeHints();
}
// "Use ₹…" link puts the calculated / default value back after a manual edit.
function useAgreementFee(which) {
  const f = document.getElementById('clientForm');
  const price = Number(f.elements.final_villa_price && f.elements.final_villa_price.value) || 0;
  const [hidden, disp, v] = which === 'stamp'
    ? [f.elements.stamp_duty_amount, document.getElementById('stampDutyDisplay'), stampDutyCalc(price)]
    : [f.elements.registration_fee, document.getElementById('registrationFeeDisplay'), REGISTRATION_FEE_DEFAULT];
  if (disp.disabled) return;
  hidden.value = String(v); disp.dataset.auto = '1'; amtFormat(disp); renderAgreementFeeHints();
}
function renderAgreementFeeHints() {
  const f = document.getElementById('clientForm');
  const sdHint = document.getElementById('stampDutyHint'), rfHint = document.getElementById('registrationFeeHint');
  if (!sdHint || !rfHint) return;
  const price = Number(f.elements.final_villa_price && f.elements.final_villa_price.value) || 0;
  const editable = !document.getElementById('stampDutyDisplay').disabled;
  const useLink = (which, v) => editable ? ` · <a href="#" onclick="useAgreementFee('${which}');return false">Use ${money(v)}</a>` : '';
  const cur = Number(f.elements.stamp_duty_amount.value) || 0;
  if (price) {
    const auto = stampDutyCalc(price);
    sdHint.innerHTML = `6% of ${money(price)} = ${money(auto)}` + (cur !== auto ? useLink('stamp', auto) : '');
  } else sdHint.textContent = 'Set Final Villa Price (Booking Initiated) to calculate';
  // GST follows the stamp duty actually entered (so it tracks manual edits too).
  const gst = stampGstCalc(cur), gstDisp = document.getElementById('stampGstDisplay');
  if (gstDisp) gstDisp.value = cur ? gst.toLocaleString('en-IN') : '';
  const gstHint = document.getElementById('stampGstHint');
  if (gstHint) gstHint.textContent = cur ? `Stamp duty + GST = ${money(cur + gst)}` : '';
  const rf = Number(f.elements.registration_fee.value) || 0;
  rfHint.innerHTML = rf !== REGISTRATION_FEE_DEFAULT ? `Default ${money(REGISTRATION_FEE_DEFAULT)}` + useLink('reg', REGISTRATION_FEE_DEFAULT) : '';
}
// Net booking amount = Booking Amount (10%) − Token Amount already paid at Unit Blocked (never below 0).
function netBookingAmount(booking, token) { return Math.max(0, (Number(booking) || 0) - (Number(token) || 0)); }
// Booking Initiated: Booking Amount − Token Amount = Net Booking Amount, so Legal sees all three.
function renderBookingBreakdown() {
  const f = document.getElementById('clientForm');
  const tkEl = document.getElementById('bookingTokenDisplay'), netEl = document.getElementById('bookingNetDisplay');
  if (!tkEl || !netEl || !f.elements.bookingamount) return;
  const bk = Number(f.elements.bookingamount.value) || 0;
  const tk = f.elements.token_amount ? Number(f.elements.token_amount.value) || 0 : 0;
  const net = netBookingAmount(bk, tk);
  tkEl.value = tk ? tk.toLocaleString('en-IN') : '';
  netEl.value = bk ? net.toLocaleString('en-IN') : '';
  document.getElementById('bookingTokenHint').textContent = tk ? 'Paid at Unit Blocked' : '';
  document.getElementById('bookingNetHint').textContent = bk ? `${money(bk)} − ${money(tk)}${inrWords(net) ? ' · ' + inrWords(net) : ''}` : '';
}
// Typing the villa price re-calculates Booking Amount (10%) and fills Booking Date with today —
// the date only if it's empty or was auto-filled by this edit, so a saved booking date is never moved.
function onFinalVillaPriceInput(displayEl) {
  amtSanitize(displayEl);
  const f = document.getElementById('clientForm');
  const price = Number(f.elements.final_villa_price.value) || 0;
  const amtDisp = document.getElementById('bookingAmountDisplay');
  if (amtDisp && !amtDisp.disabled) {
    f.elements.bookingamount.value = price ? String(Math.round(price * BOOKING_PCT)) : '';
    amtFormat(amtDisp);
  }
  const bd = f.elements.bookingdate;
  if (bd && !bd.disabled && price && (!bd.value || bd.dataset.auto === '1')) { bd.value = today(); bd.dataset.auto = '1'; }
  renderVillaPriceWords();
  syncAgreementFees(false);
}
// Loads a lead's price/booking amount into the rupee fields (converting old "₹2.35 Cr"-style text).
function initBookingAmountFields(c) {
  const f = document.getElementById('clientForm');
  const pDisp = document.getElementById('finalVillaPriceDisplay');
  if (!pDisp) return; // salesperson view — Post Sales fields aren't rendered
  const n = villaPriceNum(c);
  f.elements.final_villa_price.value = n ? String(n) : ((c && c.final_villa_price) || '');
  amtFormat(pDisp);
  if (!n && c && c.final_villa_price) pDisp.value = c.final_villa_price; // unreadable old text — show as is
  // Price already known but no booking amount yet (older leads) → start from 10%.
  if (n && !(Number(f.elements.bookingamount.value) > 0)) f.elements.bookingamount.value = String(Math.round(n * BOOKING_PCT));
  amtFormat(document.getElementById('bookingAmountDisplay'));
  if (f.elements.bookingdate) f.elements.bookingdate.dataset.auto = '';
  renderVillaPriceWords();
  syncAgreementFees(true);
}
function today() { return new Date().toISOString().slice(0, 10); }
function nowTime() { const d = new Date(); return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); }
function clientName(id) { let c = state.clients.find(x => String(x.id) === String(id)); return c ? esc(c.name) : id; }
function openModal(id) { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
function badge(v) {
  let cls = v === 'HOT' ? 'hot' : v === 'WARM' ? 'warm' : v === 'COLD' ? 'cold' :
    (['Booking Confirmed', 'Completed', 'Handover Completed'].includes(v) ? 'green' : '');
  return `<span class="badge ${cls}">${esc(v) || '—'}</span>`;
}

// ---------- Lead stage pipeline ----------
// Single source of truth for the 25-point controlled stage list (fixed —
// no free-text/random statuses allowed). Grouped into the 6 pipeline tabs
// shown in Edit Client; each stage within a group becomes a lockable
// "checkpoint" chip so a lead is always fully traceable end to end.
const STAGE_GROUPS = [
  { key: 'new', label: 'New Lead', stages: ['New Lead', 'Contact Attempted', 'Contacted', 'Qualified', 'Requirement Understood', 'Brochure Sent'] },
  { key: 'visit', label: 'Site Visit', stages: ['Site Visit Scheduled', 'Site Visit Follow-up', 'Site Visit Completed'] },
  { key: 'engage', label: 'Follow-up / Negotiation', stages: ['Post Site Visit Follow-up', 'Re-Visit Scheduled', 'Re-Visit Follow-up', 'Re-Visit Completed', 'Negotiation Visit Scheduled', 'Negotiation Visit Follow-up', 'Unit Selection', 'Villa Blocked Follow-up', 'Unit Blocked'] },
  { key: 'book', label: 'Booking & Legal', stages: ['Booking Initiated', 'KYC Verification', 'Booking Confirmed', 'Agreement In Process', 'Registered'] },
  // Construction & Payment = the construction milestones of the payment schedule — each one raises that
  // stage's instalment demand (Accounts desk, see the payment section below / api/payment_lib.php).
  { key: 'construction', label: 'Construction & Payment', stages: ['Construction Customer', 'Plinth Completed', 'First Slab Completed', 'Second Slab Completed', 'Brickwork Completed', 'Plaster & Flooring Completed', 'Fittings Completed'] },
  { key: 'possession', label: 'Possession', stages: ['Possession Due', 'Possession Offered', 'Handover Completed'] },
  { key: 'closed', label: 'Closed', stages: ['Closed'] },
  { key: 'cancel', label: 'Cancelled', stages: ['Cancelled'] },
];
const STAGE_GROUP_OF = {};
STAGE_GROUPS.forEach(g => g.stages.forEach(s => STAGE_GROUP_OF[s] = g.key));
// Flat ordered list of every stage, derived from STAGE_GROUPS so there's one
// source of truth for the dropdown options and the group/colour lookup.
const ALL_STAGES = STAGE_GROUPS.flatMap(g => g.stages);
// Only the "live pipeline" groups/stages — excludes Closed/Cancelled, which
// are terminal side-states rather than steps in the forward flow.
const PIPELINE_GROUPS = STAGE_GROUPS.filter(g => g.key !== 'closed' && g.key !== 'cancel');
const PIPELINE_STAGES = PIPELINE_GROUPS.flatMap(g => g.stages);
function stageGroupKey(stage) { return STAGE_GROUP_OF[stage] || 'new'; }
// Sales vs Post Sales. Post Sales (Booking & Legal onward) is admin-only —
// salespeople see the Sales tabs plus read-only Booking / Possession summaries.
// Keep in step with POST_SALES_STAGES in api/sv_followup_lib.php.
// IT has full admin access (IT-only powers will be added later — see IS_IT).
function isAdminRole(r) { return r === 'admin' || r === 'it'; }
const IS_ADMIN = isAdminRole(window.CURRENT_USER.role);
// Entry Desk: no Bookings / Villa Inventory / Post Sales; Site Visits & Follow-ups are view-only
// (the lead opens read-only — summary + details, nothing to schedule, complete or edit).
const IS_ENTRY_DESK = !IS_ADMIN && window.CURRENT_USER.desk === 'entry';
// Post Sales team desks (Legal / Accounts): see only leads transferred to Post Sales and work
// only the Post Sales tabs (Booking & Legal → Possession). Admin handles both Sales and Post Sales.
const IS_POST_SALES = !IS_ADMIN && ['legal', 'accounts'].includes(window.CURRENT_USER.desk);
const CAN_POST_SALES = IS_ADMIN || IS_POST_SALES;
// Post Sales split: Legal desk works Booking & Legal only; Accounts desk works Construction &
// Payment + Possession, only for leads Legal has transferred at Registered. Admin sees everything.
// Keep in step with LEGAL_STAGES / ACCOUNTS_STAGES in api/sv_followup_lib.php.
const IS_LEGAL = IS_POST_SALES && window.CURRENT_USER.desk === 'legal';
const IS_ACCOUNTS = IS_POST_SALES && window.CURRENT_USER.desk === 'accounts';
const LEGAL_KEYS = ['book'];
const ACCOUNTS_KEYS = ['construction', 'possession'];
// Post Sales tabs this user actually works in (the Legal desk also gets read-only Accounts tabs).
// Possession is handled by admin: the Accounts desk works Construction & Payment only and sees
// Possession read-only (its leads still include Possession-stage ones — payments can still be due).
const MY_POST_KEYS = IS_LEGAL ? LEGAL_KEYS : IS_ACCOUNTS ? ['construction'] : ['book', 'construction', 'possession'];
// IT role: everything admin can do (IS_ADMIN is true for IT too), plus IT-only powers later.
const IS_IT = window.CURRENT_USER.role === 'it';
const viewLeadBtn = id => `<button type="button" class="btn" title="View lead" onclick="event.stopPropagation();openLeadSummary(${id})"><i class="fa-solid fa-eye"></i></button>`;
const SALES_KEYS = ['new', 'visit', 'engage'];
const POST_SALES_KEYS = ['book', 'construction', 'possession'];
function isPostSalesStage(stage) { return POST_SALES_KEYS.includes(stageGroupKey(stage)); }
// Sales → Post Sales handover ("Complete Sales & Transfer to Post Sales" at the end of
// Unit Blocked). The stage stays Unit Blocked (Booking Initiated is Post Sales' own first
// step), but the whole Sales side is shown as completed. window.__crmSalesHandover holds
// the open lead's sales_handover_at (set in openClient).
function isSalesHandedOver(stage) { return !!window.__crmSalesHandover && stage === 'Unit Blocked'; }
function leadHandedOver(c) { return !!(c && c.sales_handover_at && c.stage === 'Unit Blocked'); }
// Lead is with the Post Sales team (handed over by sales, or already in a Post Sales stage).
function leadWithPostSales(c) { return !!c && (leadHandedOver(c) || isPostSalesStage(c.stage)); }
// Legal → Accounts handover ("Legal Completed & Transfer to Accounts" at the end of Registered).
// Same pattern as the sales handover: the stage stays Registered (Construction Customer is the
// Accounts desk's own first step). window.__crmAccountsHandover = open lead's accounts_handover_at.
function isAccountsStage(stage) { return ACCOUNTS_KEYS.includes(stageGroupKey(stage)); }
function isAccountsHandedOver(stage) { return !!window.__crmAccountsHandover && stage === 'Registered'; }
function leadAccountsHandedOver(c) { return !!(c && c.accounts_handover_at && c.stage === 'Registered'); }
function leadWithAccounts(c) { return !!c && (leadAccountsHandedOver(c) || isAccountsStage(c.stage)); }
// Which desk a lead is with right now (admin dashboard switch, Team Overview, Manage Leads' desk filter):
// Sales = still in the sales stages, not transferred yet; Legal = with Post Sales, not yet with Accounts.
function leadWithSalesDesk(c) { return !!c && SALES_KEYS.includes(stageGroupKey(c.stage)) && !leadHandedOver(c); }
function leadWithLegalDesk(c) { return leadWithPostSales(c) && !leadWithAccounts(c); }
// Everything a Post Sales desk sees (same scope as api/clients.php) — incl. cancelled bookings and leads already passed on.
function legalDeskScope(c) { return !!c && (!!c.sales_handover_at || isPostSalesStage(c.stage)); }
function accountsDeskScope(c) { return !!c && (!!c.accounts_handover_at || isAccountsStage(c.stage)); }
// Stage cell in lead tables: salespeople just see "Post Sales" once the lead has left them;
// admin sees the real stage (plus a "→ Post Sales" tag while it's handed over at Unit Blocked).
// asDesk ('sales' | 'legal' | 'accounts'): draw it the way that desk sees it — used by admin's
// Sales / Legal / Accounts dashboard views, so a lead passed on still shows, at that desk's last step.
function leadStageCell(c, asDesk) {
  const asSales = asDesk ? asDesk === 'sales' : !CAN_POST_SALES;
  const asLegal = asDesk ? asDesk === 'legal' : IS_LEGAL;
  const asAccounts = asDesk ? asDesk === 'accounts' : IS_ACCOUNTS;
  if (asSales && leadWithPostSales(c)) return '<span class="badge stage-badge stage-book" title="Sales completed — with the Post Sales team">Post Sales</span>';
  // Accounts desk never sees the Booking & Legal stage names — a lead Legal just handed over reads "New from Legal".
  if (asAccounts && leadAccountsHandedOver(c)) return '<span class="badge stage-badge stage-construction" title="Legal completed — Construction Customer is next">New from Legal</span>';
  // Legal desk never sees the Accounts stage names — once transferred a lead reads "Registered → Accounts".
  if (asLegal && leadWithAccounts(c)) return stageBadge('Registered') + ' <span class="badge green" title="Legal completed — with the Accounts team">→ Accounts</span>';
  return stageBadge(c.stage) + (leadHandedOver(c) ? ' <span class="badge green" title="Sales completed">→ Post Sales</span>' : '')
    + (leadAccountsHandedOver(c) ? ' <span class="badge green" title="Legal completed">→ Accounts</span>' : '');
}
function stageBadge(v) {
  return `<span class="badge stage-badge stage-${stageGroupKey(v)}">${esc(v) || 'New Lead'}</span>`;
}
function pipelineIndex(stage) {
  const i = PIPELINE_STAGES.indexOf(stage);
  return i === -1 ? 0 : i;
}
// A checkpoint can be opened once every checkpoint before it is done — i.e.
// its index is <= (current index + 1). This is what "locks" future stages
// until the ones before them are actually completed.
function isStageUnlocked(stage, currentStage) {
  if (currentStage === 'Closed' || currentStage === 'Cancelled') return stage === currentStage;
  // Extra gate: while sitting at "Requirement Understood", "Brochure Sent"
  // stays locked until the post-contact WhatsApp greeting is confirmed
  // sent. Leads that already progressed further aren't retroactively
  // re-locked — this only blocks the forward move out of Requirement
  // Understood.
  if (stage === 'Brochure Sent' && currentStage === 'Requirement Understood' && !isWhatsappGreetingSent()) return false;
  // Booking & Legal gate: the next checkpoint stays locked until the current
  // legal checkpoint's mandatory documents are uploaded (KYC also needs
  // KYC Status = Verified). Same forward-only rule as above.
  // Construction & Payment works the same way: a construction checkpoint needs its payment demand
  // raised (or already paid in advance) and handover needs full payment — see payGateMissing().
  if (pipelineIndex(stage) > pipelineIndex(currentStage) && stageGateMissing(currentStage).length) return false;
  if (isVisitFollowupSkip(stage, currentStage)) return true;
  return pipelineIndex(stage) <= pipelineIndex(currentStage) + 1;
}
// The three follow-up stages. Each has the same details block (date, mode, re-follow-up,
// note) stored in {prefix}_followup_* columns and a typed row on the Follow-ups page.
// `from` = the stage a lead is in just before it (Add Follow-up moves it forward from there).
// Keep in step with FOLLOWUP_STAGES in api/sv_followup_lib.php.
const FOLLOWUP_STAGES = {
  'Site Visit Follow-up':        { prefix: 'sv',  type: 'After Scheduled Site Visit',  label: 'Site visit follow-up',       from: 'Site Visit Scheduled' },
  'Post Site Visit Follow-up':   { prefix: 'psv', type: 'Post Site Visit Follow-up',   label: 'Post site visit follow-up',  from: 'Site Visit Completed' },
  'Re-Visit Follow-up':          { prefix: 'rv',  type: 'Re-Visit Follow-up',          label: 'Re-visit follow-up',         from: 'Re-Visit Scheduled' },
  'Negotiation Visit Follow-up': { prefix: 'nv',  type: 'Negotiation Visit Follow-up', label: 'Negotiation visit follow-up', from: 'Negotiation Visit Scheduled' },
  'Villa Blocked Follow-up':     { prefix: 'vb',  type: 'Villa Blocked Follow-up',     label: 'Villa blocked follow-up',    from: 'Unit Selection' },
};
// Which follow-up stage applies to a lead: the latest one whose `from` stage it has reached.
function detectFollowupStage(stage) {
  let found = 'Site Visit Follow-up';
  const idx = PIPELINE_STAGES.indexOf(stage);
  if (idx === -1) return found;
  Object.entries(FOLLOWUP_STAGES).forEach(([name, cfg]) => { if (idx >= pipelineIndex(cfg.from)) found = name; });
  return found;
}
// "Site Visit Follow-up" / "Re-Visit Follow-up" / "Villa Blocked Follow-up" are optional: a lead
// sitting at Site Visit Scheduled (or Re-Visit Scheduled / Unit Selection) can go straight to the
// next stage without logging one.
function isVisitFollowupSkip(stage, currentStage) {
  return (stage === 'Site Visit Completed' && currentStage === 'Site Visit Scheduled') ||
         (stage === 'Re-Visit Completed' && currentStage === 'Re-Visit Scheduled') ||
         (stage === 'Unit Blocked' && currentStage === 'Unit Selection');
}
function isWhatsappGreetingSent() {
  const f = document.getElementById('clientForm');
  return !!(f && f.elements.req_whatsapp_sent && f.elements.req_whatsapp_sent.value === 'Yes');
}
// Lead History entry when a WhatsApp message / email is marked as sent
// (skips an identical entry already logged today, so flip-flopping the dropdown doesn't spam the history).
function logCommSent(type, note) {
  const log = window.__crmActivityLog || [];
  if (log.some(x => x.type === type && x.note === note && x.date === today())) return;
  addActivityLog(note, type);
}
function onWhatsappSentChange(sel) {
  const f = document.getElementById('clientForm');
  if (sel.value === 'Yes') logCommSent('WhatsApp Sent', 'WhatsApp greeting sent to client — Requirement Understood');
  renderStageTracker(f.elements.stage.value || 'New Lead'); // refresh lock states now that isWhatsappGreetingSent() has changed
  if (sel.value === 'Yes') setStage('Brochure Sent');
}
function onBrochureWhatsappSentChange(sel) {
  const f = document.getElementById('clientForm');
  if (sel.value === 'Yes') logCommSent('WhatsApp Sent', 'WhatsApp greeting sent to client — Brochure Sent');
  renderStageTracker(f.elements.stage.value || 'New Lead');
  if (sel.value === 'Yes') setStage('Site Visit Scheduled');
}
function copyWhatsappMessage() {
  const ta = document.getElementById('reqWhatsappMsg');
  ta.select();
  navigator.clipboard?.writeText(ta.value).catch(() => document.execCommand('copy'));
}
function copyBrochureWhatsappMessage(id) {
  const ta = document.getElementById(id || 'brochureWhatsappMsg1');
  ta.select();
  navigator.clipboard?.writeText(ta.value).catch(() => document.execCommand('copy'));
}
// Regenerate buttons: overwrite the greeting with a fresh default draft.
function regenerateReqWhatsapp() {
  if (window.__crmCanEdit === false) return;
  fillWhatsappGreetingDefault(document.getElementById('clientForm').elements.name.value || '', true);
}
function regenerateBrochureWhatsapp() {
  if (window.__crmCanEdit === false) return;
  fillBrochureWhatsappGreetingDefault(true);
}
// ---------- Call button (tel: link) ----------
// One click opens the phone's dialer with the lead's mobile number; the call is also logged in the
// lead's history (api/call_log.php) so everyone can see who called and when.
function leadPhone(c) { return c ? String(c.mobile || c.alt_mobile || '').trim() : ''; }
// "9876543210" / "09876543210" / "919876543210" → "+919876543210"; numbers already starting with + are kept.
function telNumber(raw) {
  const s = String(raw || '').trim();
  let d = s.replace(/\D/g, '');
  if (!d) return '';
  if (s.startsWith('+')) return '+' + d;
  if (d.length === 11 && d[0] === '0') d = d.slice(1);
  if (d.length === 10) return '+91' + d;
  if (d.length === 12 && d.startsWith('91')) return '+' + d;
  return d;
}
// Action-cell button. context (optional) is noted in the history, e.g. "Site Visit Follow-up".
function callBtn(c, context) {
  if (!c || !telNumber(leadPhone(c))) return '';
  const ctx = context ? `, '${jsAttr(context)}'` : '';
  return `<button type="button" class="btn call-btn" title="Call ${esc(leadPhone(c))}" onclick="event.stopPropagation();callLead(${Number(c.id)}${ctx})"><i class="fa-solid fa-phone"></i></button>`;
}
function callBtnById(id, context) { return callBtn(state.clients.find(x => String(x.id) === String(id)), context); }
function callLead(id, context) {
  const c = state.clients.find(x => String(x.id) === String(id));
  const tel = telNumber(leadPhone(c));
  if (!tel) { alert('No mobile number on this lead.'); return; }
  logLeadCall(c.id, context); // fire-and-forget — opening the dialer doesn't leave the page
  openDialer(tel);
}
function openDialer(tel) {
  const a = document.createElement('a');
  a.href = 'tel:' + tel;
  a.click();
}
// Edit Client: calls the lead that's open (Contact Attempted / follow-up stages / header).
// A new lead that isn't saved yet dials the number typed in (nothing to log against yet).
function callOpenLead(context) {
  const f = document.getElementById('clientForm');
  if (f && f.dataset.edit) { callLead(f.dataset.edit, context); return; }
  const tel = telNumber(f && f.elements.mobile ? f.elements.mobile.value : '');
  if (!tel) { alert('Enter the mobile number first.'); return; }
  openDialer(tel);
}
async function logLeadCall(id, context) {
  const res = await api('call_log.php', 'POST', { client_id: id, context: context || '' });
  if (!res || res.error || !res.log_entry) return;
  // Same as document uploads: mirror the entry into the open Edit Client (the next Save keeps it)
  // and into the cached lead (Lead Summary / reopening).
  const f = document.getElementById('clientForm');
  if (document.getElementById('clientModal')?.classList.contains('show') && f && String(f.dataset.edit) === String(id)) {
    (window.__crmActivityLog = window.__crmActivityLog || []).push(res.log_entry);
    renderActivityLog();
    refreshPayDemandCard();
  }
  const c = state.clients.find(x => String(x.id) === String(id));
  if (c && res.activity_log) c.activity_log = res.activity_log;
  if (document.getElementById('leadSummaryModal')?.classList.contains('show') && String(window.__crmSummaryId) === String(id)) openLeadSummary(id);
}
// Follow-up "Mode" selects: the call icon beside the select shows only while Mode = Call.
function updateModeCallBtns() {
  document.querySelectorAll('#clientForm .mode-call').forEach(w => {
    const sel = w.querySelector('select'), btn = w.querySelector('.call-btn');
    if (btn && !btn.hasAttribute('data-always')) btn.classList.toggle('hidden', !sel || sel.value !== 'Call');
  });
}
// Opens WhatsApp chat with the lead's WhatsApp (or mobile) number and the given text prefilled.
function leadWhatsappNum() {
  const f = document.getElementById('clientForm');
  let num = (f.elements.whatsapp.value || f.elements.mobile.value || '').replace(/\D/g, '');
  if (num.length === 10) num = '91' + num;
  return num;
}
function openWhatsappChat(text) {
  const num = leadWhatsappNum();
  if (!num) { alert('No WhatsApp / mobile number on this lead.'); return; }
  openWhatsapp(num, text);
}
// ---------- WhatsApp: phone → app (wa.me) · PC → WhatsApp desktop app, else WhatsApp Web (automatic) ----------
// Why not always WhatsApp Web on PC: WhatsApp Web cuts the link to whichever site opened it, so no website can send
// a later chat to that same tab — every click lands in a NEW tab and WhatsApp (one tab only) asks "Use here".
// The WhatsApp desktop app has no tabs: the chat opens in the window that's already open.
// Detection, no questions asked: the first click tries the app — if the browser hands over to it (the CRM window
// loses focus) this PC is remembered as "app"; if nothing happens within a moment, WhatsApp Web opens straight
// away and the PC is remembered as "web". "app" PCs fall back to Web by themselves if the app ever stops opening;
// "web" is re-checked after 7 days in case the app has been installed since.
// The app gets only the number — through that link the Windows app turns emojis into "?", so the message is copied
// for Ctrl+V instead. WhatsApp Web gets the full message.
const IS_PHONE = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
const WA_MODE_KEY = 'crm_wa_mode';
function waMode() {
  try {
    const v = JSON.parse(localStorage.getItem(WA_MODE_KEY) || 'null');
    if (!v || (v.mode !== 'app' && v.mode !== 'web')) return '';
    if (v.mode === 'web' && Date.now() - (v.at || 0) > 7 * 864e5) return ''; // look for the app again
    return v.mode;
  } catch (e) { return ''; }
}
function setWaMode(m) { try { localStorage.setItem(WA_MODE_KEY, JSON.stringify({ mode: m, at: Date.now() })); } catch (e) { /* private window — detect again next time */ } }
function waUsesTab() { return IS_PHONE || waMode() === 'web'; }
function openWhatsapp(num, text, note) {
  if (waUsesTab()) { openWhatsappUrl(whatsappChatUrl(num, text)); if (note) crmToast(note); return; }
  const known = waMode() === 'app';
  copyTextQuiet(text); // before the app takes focus — the clipboard needs this window focused
  let done = false, timer = null;
  const stop = () => { done = true; clearTimeout(timer); window.removeEventListener('blur', onLeave); document.removeEventListener('visibilitychange', onLeave); };
  const onLeave = () => {
    if (done) return;
    stop();
    setWaMode('app');
    crmToast('Chat opened in the WhatsApp app — the message is copied: press Ctrl+V, then Enter.' + (note ? ' ' + note : ''));
  };
  window.addEventListener('blur', onLeave);
  document.addEventListener('visibilitychange', onLeave);
  const a = document.createElement('a');
  a.href = `whatsapp://send?phone=${num}`;
  a.click();
  // Browsers allow a new tab for ~5 s after a click (Chrome / Edge), so the fallback stays inside that window.
  // A PC already known to have the app gets longer (a closed app can take a moment to start).
  timer = setTimeout(() => {
    if (done) return;
    stop();
    setWaMode('web');
    if (!openWhatsappUrl(whatsappChatUrl(num, text))) // pop-ups blocked for the CRM — only then is a click needed
      crmToast('Your browser blocked WhatsApp Web from opening.', 'Open WhatsApp Web', () => openWhatsappUrl(whatsappChatUrl(num, text)));
    else if (note) crmToast(note);
  }, known ? 4000 : 2200);
}
function copyTextQuiet(text) {
  const fallback = () => {
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e) { /* nothing else to try */ }
    ta.remove();
  };
  if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).catch(fallback); else fallback();
}
// Small note at the bottom of the screen, with an optional button.
function crmToast(msg, btnLabel, onBtn) {
  let t = document.getElementById('crmToast');
  if (!t) { t = document.createElement('div'); t.id = 'crmToast'; t.className = 'crm-toast'; document.body.appendChild(t); }
  t.innerHTML = `<i class="fa-brands fa-whatsapp"></i><span>${esc(msg)}</span>`
    + (btnLabel ? `<button type="button" class="btn btn-sm">${esc(btnLabel)}</button>` : '')
    + '<button type="button" class="crm-toast-x" title="Close">×</button>';
  if (btnLabel) t.querySelector('.btn').onclick = () => { t.classList.remove('show'); onBtn(); };
  t.querySelector('.crm-toast-x').onclick = () => t.classList.remove('show');
  t.classList.add('show');
  clearTimeout(t._hide);
  t._hide = setTimeout(() => t.classList.remove('show'), btnLabel ? 12000 : 7000);
}
// WhatsApp Web / wa.me in a tab — one named tab, reused while the browser allows it.
const WA_TAB = 'crm_whatsapp';
let waWin = null;
function openWhatsappUrl(url, win) {
  const target = win && !win.closed ? win : waWin && !waWin.closed ? waWin : null;
  if (target) {
    try { target.location.href = url; target.focus(); waWin = target; return target; } catch (e) { /* fall back to the named tab */ }
  }
  waWin = window.open(url, WA_TAB);
  if (waWin) waWin.focus();
  return waWin;
}
// Phones: wa.me opens the WhatsApp app with the message. PC (Web mode): WhatsApp Web.
function whatsappChatUrl(num, text) {
  const q = encodeURIComponent(text);
  return IS_PHONE ? `https://wa.me/${num}?text=${q}` : `https://web.whatsapp.com/send?phone=${num}&text=${q}`;
}
function openWhatsappFrom(id) {
  openWhatsappChat(document.getElementById(id).value);
}
// Drafts a default greeting only if the field is still empty, so it never
// overwrites a message someone already personalised and saved.
function fillWhatsappGreetingDefault(name, force) {
  const ta = document.getElementById('reqWhatsappMsg');
  if (ta && (force || !ta.value.trim())) {
    ta.value = `Hi ${name || 'there'}! 👋\n\nThank you for sharing your requirements with us for *Antaaya Villas, Lonavala* 🏡\n\nWe'll send across our brochure shortly — feel free to reach out anytime with questions.\n\nLooking forward to helping you find your perfect villa! 🙌`;
  }
}
// Same idea, but also folds in the Brochure Sent Date so the message stays
// accurate if that date is filled in/changed later — but only while the
// text is still the auto-generated draft (dataset.auto), never once the
// salesperson has actually edited it.
function fillBrochureWhatsappGreetingDefault(force) {
  const f = document.getElementById('clientForm');
  const cur = f.elements.brochure_whatsapp_message.value;
  const wasEmpty = !cur.trim();
  if (!force && !wasEmpty && f.elements.brochure_whatsapp_message.dataset.auto !== '1') return; // salesperson already customised it
  const name = f.elements.name.value || 'there';
  const dv = f.elements.brochure_date.value;
  const mode = f.elements.brochure_mode.value;
  const niceDate = dv ? new Date(dv + 'T00:00:00').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '';
  const via = mode ? ` via *${mode}*` : '';
  const msg = `Hi ${name}! 👋\n\nAs promised, we've sent across our *Antaaya Villas, Lonavala* brochure${via}${niceDate ? ' on ' + niceDate : ''} 📄🏡\n\nDo go through it, and let us know if you have any questions — happy to set up a site visit whenever you're ready!\n\nLooking forward to hearing from you. 🙌`;
  mirrorSync('brochure_whatsapp_message', msg);
  f.elements.brochure_whatsapp_message.dataset.auto = '1';
  document.querySelectorAll('[data-mirror="brochure_whatsapp_message"]').forEach(el => { el.dataset.auto = '1'; });
}
// Auto-derives the handful of legacy status fields (visit_status,
// agreement, registration) from the single source-of-truth stage, so
// clicking a checkpoint chip keeps everything consistent without the user
// having to also flip separate dropdowns — and so an edit never silently
// resets a further-along status back to its default (see api/clients.php,
// which defaults these fields when absent from the submitted payload).
function deriveLinkedFields(stage) {
  const idx = pipelineIndex(stage);
  const visitStatus = idx >= pipelineIndex('Site Visit Completed') ? 'Done'
    : idx >= pipelineIndex('Site Visit Scheduled') ? 'Scheduled' : 'Not Scheduled';
  const agreement = idx >= pipelineIndex('Registered') ? 'Signed'
    : idx >= pipelineIndex('Agreement In Process') ? 'Drafted' : 'Pending';
  const registration = idx >= pipelineIndex('Registered') ? 'Completed' : 'Pending';
  const f = document.getElementById('clientForm');
  if (f.elements.visit_status) f.elements.visit_status.value = visitStatus;
  if (f.elements.agreement) f.elements.agreement.value = agreement;
  if (f.elements.registration) f.elements.registration.value = registration;
}
// Sets the lead's current stage (from a checkpoint chip click, or the
// Mark Closed/Cancelled buttons) and re-renders everything that depends on
// it: the liquid bar, every tab's checklist, and the next-step panel.
function setStage(stage) {
  const f = document.getElementById('clientForm');
  const cur = f.elements.stage.value || 'New Lead';
  if (stage !== 'Closed' && stage !== 'Cancelled' && !isStageUnlocked(stage, cur)) return;
  window.__crmViewStage = null;
  f.elements.stage.value = stage;
  deriveLinkedFields(stage);
  renderStageTracker(stage);
  if (stage !== cur) logStageChange(stage);
  updateCancelButton(state.clients.find(x => String(x.id) === String(f.dataset.edit)) || null);
  if (stage === 'Booking Confirmed' && stage !== cur && f.elements.confirmation_date && !f.elements.confirmation_date.value) {
    f.elements.confirmation_date.value = today();
  }
  // If the new stage lives in a different tab (e.g. Requirement Understood
  // -> Brochure Sent still on "New Lead", but Brochure Sent -> Site Visit
  // Scheduled moves into the "Site Visit" tab), switch the visible tab so
  // the section is actually on screen instead of hidden under another tab.
  const targetTab = stageGroupKey(stage);
  if (PIPELINE_GROUPS.some(g => g.key === targetTab)) clientTab(targetTab);
}
// Chip click handler. Clicking the current stage or the one next-in-line
// actually advances the lead (same as before — calls setStage()). Clicking
// an already-completed checkpoint just OPENS that checkpoint's field
// section for reviewing/editing — it does not move the lead's real
// progress backward; the official stage (and every other tab's lock state)
// stays exactly where it was until Save.
function viewOrSetStage(stage) {
  const f = document.getElementById('clientForm');
  const cur = f.elements.stage.value || 'New Lead';
  if (!isStageUnlocked(stage, cur)) return;
  // Read-only lead (e.g. handed over to Post Sales): chips only open a stage's details to view.
  if (window.__crmCanEdit === false) {
    if (pipelineIndex(stage) > pipelineIndex(cur)) return;
    window.__crmViewStage = stage;
    applyStageLocks(cur);
    return;
  }
  if (pipelineIndex(stage) < pipelineIndex(cur)) {
    window.__crmViewStage = stage;
    applyStageLocks(cur);
    return;
  }
  setStage(stage);
}
// Footer cancel button: "Booking Cancelled" once the lead is with Post Sales (always on the
// Legal / Accounts desks), and locked once registration is done — the buyer is then final
// for that villa (api/clients.php refuses it too).
function isRegistrationDone(stage) { return isPostSalesStage(stage) && pipelineIndex(stage) >= pipelineIndex('Registered'); }
function isBookingSide(c) { return IS_POST_SALES || !!(c && leadWithPostSales(c)); }
function updateCancelButton(c) {
  const btn = document.getElementById('markCancelledBtn');
  if (!btn) return;
  const f = document.getElementById('clientForm');
  const stage = f.elements.stage.value || (c && c.stage) || 'New Lead';
  const saved = (c && c.stage) || stage;
  btn.textContent = isBookingSide(c) ? 'Booking Cancelled' : 'Mark Cancelled';
  if (stage !== 'Cancelled' && (isRegistrationDone(saved) || isRegistrationDone(stage))) {
    btn.disabled = true;
    btn.title = 'Registration completed — this client is the final buyer of the villa, so the booking can\'t be cancelled.';
  }
}
function markTerminal(stage) {
  if (window.__crmCanEdit === false) return; // read-only (e.g. salesperson on a lead with Post Sales)
  const f = document.getElementById('clientForm');
  const c = state.clients.find(x => String(x.id) === String(f.dataset.edit));
  if (stage === 'Cancelled' && (isRegistrationDone(f.elements.stage.value) || isRegistrationDone(c && c.stage))) {
    alert('Registration is completed — this client is the final buyer of the villa, so the booking can\'t be cancelled.');
    return;
  }
  if (stage === 'Cancelled' && isBookingSide(c)) {
    const villa = (c && c.villa) || f.elements.villa?.value || '';
    if (!confirm(`Cancel this booking?\n\n${villa ? villa + ' will go back to Available in Villa Inventory, and ' : ''}the salesperson and admin will be notified once you click Save Client.`)) return;
  } else if (stage === 'Cancelled' && !confirm('Mark this lead as Cancelled?')) return;
  if (stage === 'Closed' && !confirm('Mark this lead as Closed?')) return;
  setStage(stage);
}
// Liquid-fill progress bar across the 5 main pipeline groups — each segment
// fills proportionally to how many of that group's own checkpoints are
// done, instead of jumping straight to 100%/0%, so leads that are "3 of 6
// done" in New Lead visibly look partway through, not just "in progress".
function renderStageTracker(stage) {
  const el = document.getElementById('stageTracker');
  if (!el) return;
  stage = stage || 'New Lead';
  const realGroup = stageGroupKey(stage);
  const cancelled = realGroup === 'cancel', closed = realGroup === 'closed';
  // Salespeople: the 3 Sales groups + a single "Post Sales" node standing in for the rest.
  // Post Sales desks: a single "Sales" node (already done) + the 3 Post Sales groups.
  // Accounts desk: a single "Sales & Legal" node (already done) + Construction & Payment + Possession.
  const trackGroups = IS_ADMIN ? PIPELINE_GROUPS : IS_ACCOUNTS ? [
    { key: 'pre', label: 'Sales & Legal', stages: PIPELINE_GROUPS.filter(g => !ACCOUNTS_KEYS.includes(g.key)).flatMap(g => g.stages) },
    ...PIPELINE_GROUPS.filter(g => ACCOUNTS_KEYS.includes(g.key)),
  ] : IS_LEGAL ? [
    // Legal desk: a single "Sales" node (already done) + Booking & Legal + a single "Accounts" node.
    { key: 'sales', label: 'Sales', stages: PIPELINE_GROUPS.filter(g => SALES_KEYS.includes(g.key)).flatMap(g => g.stages) },
    ...PIPELINE_GROUPS.filter(g => LEGAL_KEYS.includes(g.key)),
    { key: 'accounts', label: 'Accounts', stages: PIPELINE_GROUPS.filter(g => ACCOUNTS_KEYS.includes(g.key)).flatMap(g => g.stages) },
  ] : IS_POST_SALES ? [
    { key: 'sales', label: 'Sales', stages: PIPELINE_GROUPS.filter(g => SALES_KEYS.includes(g.key)).flatMap(g => g.stages) },
    ...PIPELINE_GROUPS.filter(g => POST_SALES_KEYS.includes(g.key)),
  ] : [
    ...PIPELINE_GROUPS.filter(g => SALES_KEYS.includes(g.key)),
    { key: 'post', label: 'Post Sales', stages: PIPELINE_GROUPS.filter(g => POST_SALES_KEYS.includes(g.key)).flatMap(g => g.stages) },
  ];
  const handed = isSalesHandedOver(stage);
  const accHanded = isAccountsHandedOver(stage);
  // Legal desk: once with Accounts the lead sits on the single "Accounts" node, reading "Registered".
  const legalDone = IS_LEGAL && (accHanded || ACCOUNTS_KEYS.includes(realGroup));
  const curGroup = handed ? (CAN_POST_SALES ? 'book' : 'post')
    : legalDone ? 'accounts'
    : accHanded && CAN_POST_SALES ? 'construction'
    : IS_ACCOUNTS && !ACCOUNTS_KEYS.includes(realGroup) ? 'pre'
    : IS_POST_SALES && SALES_KEYS.includes(realGroup) ? 'sales'
    : !CAN_POST_SALES && POST_SALES_KEYS.includes(realGroup) ? 'post' : realGroup;
  const curGroupIdx = trackGroups.findIndex(g => g.key === curGroup);

  // Steps and fill-lines are built separately, then interleaved: each line
  // sits in the gap between the step before it and the step after it, sized
  // to reflect in-group progress (a "liquid fill" rather than a hard jump).
  const stepEls = trackGroups.map((g, i) => {
    let cls = 'liquid-step';
    if (cancelled) cls += ' ls-locked';
    else if (i < curGroupIdx || closed) cls += ' ls-done';
    else if (i === curGroupIdx) cls += ' ls-current';
    else cls += ' ls-locked';
    return `<div class="${cls}"><span class="liquid-dot"></span><span class="liquid-label">${esc(g.label)}</span></div>`;
  });
  const lineEls = trackGroups.slice(0, -1).map((g, i) => {
    let pct = 0;
    if (cancelled) pct = 0;
    else if (closed || i < curGroupIdx) pct = 100;
    else if (i === curGroupIdx) {
      const doneInGroup = handed ? 0 : g.stages.indexOf(stage) + 1;
      pct = Math.max(0, Math.round((doneInGroup / g.stages.length) * 100));
    }
    return `<div class="liquid-line-wrap"><div class="liquid-line-fill" style="width:${pct}%"></div></div>`;
  });
  let interleaved = '';
  stepEls.forEach((s, i) => { interleaved += s; if (lineEls[i]) interleaved += lineEls[i]; });

  const curLabel = cancelled ? 'Cancelled' : closed ? 'Closed' : IS_ACCOUNTS && accHanded ? 'New from Legal' : legalDone ? 'Registered' : stage;
  const editable = window.__crmCanEdit !== false;
  const curPIdx = pipelineIndex(stage);
  el.innerHTML = `<div class="liquid-track-row">${interleaved}</div>` +
    `<div class="liquid-cur-label">Current stage: <b>${esc(curLabel)}</b>` +
    (handed ? '<span class="ho-tag"><i class="fa-solid fa-circle-check"></i> Sales completed — with Post Sales</span>' : '') +
    (accHanded || legalDone ? `<span class="ho-tag"><i class="fa-solid fa-circle-check"></i> Legal completed${IS_ACCOUNTS ? ' — Construction Customer is next' : ' — with Accounts'}</span>` : '') +
    (cancelled || closed || !editable || (!CAN_POST_SALES && (isPostSalesStage(stage) || handed)) || !canJumpToStage(stage) ? '' :
      `<span class="stage-jump"><select id="stageJumpSel">${PIPELINE_STAGES.filter(s => pipelineIndex(s) <= curPIdx && canJumpToStage(s)).map(s => `<option value="${esc(s)}" ${s === stage ? 'selected' : ''}>${esc(s)}</option>`).join('')}</select>` +
      `<button type="button" class="btn btn-sm" onclick="forceSetStage(document.getElementById('stageJumpSel').value)">Go</button></span>`) +
    `</div>`;

  renderAllChecklists(stage);
  renderNextStepPanel(stage);
  applyStageLocks(stage);
  renderSalesHandover(stage);
  renderAccountsHandover(stage);
}
// "Go to stage" correction dropdown: which stages this user may move a lead back to.
function canJumpToStage(s) {
  if (IS_ADMIN) return true;
  if (IS_LEGAL) return stageGroupKey(s) === 'book';
  if (IS_ACCOUNTS) return stageGroupKey(s) === 'construction'; // Possession stages are admin's
  return !isPostSalesStage(s);
}
// Jumps the lead back to an already-completed checkpoint for corrections
// (e.g. a lead was mis-progressed and needs to go back to "New Lead").
// Only allows moving backward/staying — the dropdown itself is filtered to
// current-or-earlier stages, and this is enforced here too so skipping
// ahead is never possible from this control (that's still only done one
// checkpoint at a time via the chips). Nothing is saved until "Save Client".
function forceSetStage(stage) {
  if (!stage || !PIPELINE_STAGES.includes(stage)) return;
  const f = document.getElementById('clientForm');
  const cur = f.elements.stage.value || 'New Lead';
  if (stage === cur) return;
  if (pipelineIndex(stage) > pipelineIndex(cur)) return;
  if (!canJumpToStage(stage)) return; // each desk only moves within its own stages
  // Same rules as api/clients.php: the sales transfer is undone by going back into Sales (before
  // Unit Blocked), the Accounts transfer by going back before Registered.
  const undoSales = !!window.__crmSalesHandover && !isPostSalesStage(stage) && stage !== 'Unit Blocked';
  const undoAcc = !!window.__crmAccountsHandover && pipelineIndex(stage) < pipelineIndex('Registered');
  if (!confirm(`Move this lead's current stage back to "${stage}"?` + (undoSales ? '\n\nThis also undoes the transfer to Post Sales.' : '')
    + (undoAcc ? '\n\nThis also undoes the transfer to Accounts.' : ''))) return;
  window.__crmViewStage = null;
  if (undoAcc) { window.__crmAccountsHandover = null; addActivityLog('Transfer to Accounts undone — lead moved back to Legal', 'Accounts Handover'); }
  if (undoSales) { window.__crmSalesHandover = null; addActivityLog('Transfer to Post Sales undone — lead moved back to Sales', 'Sales Handover'); }
  f.elements.stage.value = stage;
  deriveLinkedFields(stage);
  renderStageTracker(stage);
  // TEMPORARY — FOR TESTING ONLY. Forcing a lead all the way back to "New
  // Lead" wipes its Lead History log back down to just the original "Lead
  // Generated" entry, so testers can re-run a lead's journey from scratch
  // without old stage/attempt logs cluttering it. Remove this whole `if`
  // block once testing is done — Lead History should never be erasable in
  // production; the `else` branch (normal stage-change logging) should stay.
  if (stage === 'New Lead') {
    const genEntry = (window.__crmActivityLog || []).find(x => x.type === 'Lead Generated');
    window.__crmActivityLog = genEntry ? [genEntry] : [{ date: f.elements.created.value || today(), time: '00:00', type: 'Lead Generated', note: 'Lead added to the system' }];
    renderActivityLog();
  } else {
    logStageChange(stage);
  }
}
function previewStage(v) { renderStageTracker(v); }

// Renders the checkpoint chips for one stage group into #chk-<groupKey>.
// Each chip sets the lead's stage on click; chips beyond the next
// not-yet-done checkpoint are locked (greyed, unclickable) until the ones
// before them are completed, so a lead can't skip steps.
function renderChecklist(groupKey, currentStage) {
  const el = document.getElementById('chk-' + groupKey);
  if (!el) return;
  const group = STAGE_GROUPS.find(g => g.key === groupKey);
  const curIdx = pipelineIndex(currentStage);
  const handed = isSalesHandedOver(currentStage) || isAccountsHandedOver(currentStage);
  el.innerHTML = group.stages.map((s, i) => {
    const idx = pipelineIndex(s);
    let cls = 'chip', locked = false;
    if (s === currentStage && handed) cls += ' chip-done'; // sales finished — Unit Blocked shows as completed too
    else if (s === currentStage) cls += ' chip-current';
    else if (idx < curIdx) cls += ' chip-done';
    else if (window.__crmCanEdit !== false && (idx === curIdx + 1 || isVisitFollowupSkip(s, currentStage)) && !stageGateMissing(currentStage).length) { /* next available — plain chip, clickable */ }
    else { cls += ' chip-locked'; locked = true; }
    const icon = s === currentStage && !handed ? '<i class="fa-solid fa-circle-dot"></i>' : idx <= curIdx ? '<i class="fa-solid fa-check"></i>' : locked ? '<i class="fa-solid fa-lock"></i>' : '<i class="fa-regular fa-circle"></i>';
    return `<button type="button" class="${cls}" ${locked ? 'disabled' : ''} onclick="viewOrSetStage('${esc(s)}')">${icon} ${esc(s)}</button>`;
  }).join('');
}
function renderAllChecklists(currentStage) {
  STAGE_GROUPS.filter(g => g.key !== 'closed' && g.key !== 'cancel').forEach(g => renderChecklist(g.key, currentStage));
}
// Decides which single checkpoint's section should be visible for a group
// tab: whatever's explicitly being reviewed (window.__crmViewStage) if it
// belongs to this group, else the lead's real current stage if it's in
// this group, else — for a group the lead hasn't reached yet — its first
// (locked) checkpoint, or — for a group the lead has already moved past
// entirely — its last (still-editable) checkpoint.
function groupVisibleStage(group, currentStage) {
  const viewStage = window.__crmViewStage;
  if (viewStage && group.stages.includes(viewStage)) return viewStage;
  if (group.stages.includes(currentStage)) return currentStage;
  const lastIdx = pipelineIndex(group.stages[group.stages.length - 1]);
  return pipelineIndex(currentStage) > lastIdx ? group.stages[group.stages.length - 1] : group.stages[0];
}
// Locks/unlocks each per-checkpoint field section (.stage-section) to match
// the lead's current stage — same isStageUnlocked() rule as the chips, so a
// section's fields open up exactly when its chip does, and (per Rohit's
// request) a checkpoint that's already been passed STAYS unlocked/editable
// rather than re-locking, so past details can always be corrected later.
// Only ONE section per group is ever shown at a time (accordion-style) —
// see groupVisibleStage() above — instead of stacking every checkpoint's
// fields with a scrollbar. window.__crmCanEdit (set in openClient) is
// folded in here so this is the one place that decides a stage-section
// field's final disabled state.
function applyStageLocks(currentStage) {
  const visibleByGroup = {};
  PIPELINE_GROUPS.forEach(g => { visibleByGroup[g.key] = groupVisibleStage(g, currentStage); });
  document.querySelectorAll('#clientForm .stage-section').forEach(sec => {
    const stage = sec.dataset.stage;
    const locked = !isStageUnlocked(stage, currentStage);
    const visible = stage === visibleByGroup[stageGroupKey(stage)];
    sec.classList.toggle('ss-hidden', !visible);
    sec.classList.toggle('ss-locked', locked);
    sec.classList.toggle('ss-current', stage === currentStage);
    sec.classList.toggle('ss-done', !locked && stage !== currentStage);
    const disable = locked || window.__crmCanEdit === false;
    sec.querySelectorAll('input, select, textarea').forEach(el => {
      el.disabled = disable;
      el.readOnly = disable || el.dataset.ro === '1'; // data-ro: always read-only (calculated display fields)
    });
  });
  document.querySelectorAll('#clientForm .vpick[data-mode="multi"]').forEach(r => { if (r.dataset.built) vpickRender(r); });
  renderFinalVillaList();
  renderLegalDocs();
  renderPayDemands();
  renderAgreementFeeHints();
  updateModeCallBtns();
}

// ---------- Booking & Legal documents (KYC, agreement, registration…) ----------
// Rules (which slots show / which are mandatory) come from window.LEGAL_DOCS,
// emitted by index.php from api/legal_docs_lib.php — same rules the server
// enforces in api/clients.php, so UI and save can never disagree.
function legalForm() { return document.getElementById('clientForm'); }
function legalApplicantType() { const el = legalForm().elements.applicant_type; return (el && el.value) || 'Resident Individual'; }
function legalHasCo() { const el = legalForm().elements.co_applicant_name; return !!(el && el.value.trim()); }
function legalDocSlots(stage) {
  const L = window.LEGAL_DOCS;
  if (!L) return [];
  let show, req;
  if (stage === 'KYC Verification') {
    const k = L.kycByType[legalApplicantType()] || L.kycByType['Resident Individual'];
    show = [...k.show]; req = [...k.required];
    if (legalHasCo()) { show.push(...L.coDocs); req.push(...L.coDocs); }
  } else if (L.stageDocs[stage]) {
    show = L.stageDocs[stage].show; req = L.stageDocs[stage].required;
  } else return [];
  return show.map(t => ({ type: t, label: L.types[t], required: req.includes(t) }));
}
// What's still missing before a lead can move past this checkpoint ([] = free to move on).
function legalGateMissing(stage) {
  const L = window.LEGAL_DOCS;
  if (!L || !L.gated.includes(stage)) return [];
  if (isAccountsHandedOver(stage)) return []; // Legal completed — checked when it was transferred
  const have = new Set((window.__crmDocs || []).map(d => d.doc_type));
  const miss = legalDocSlots(stage).filter(s => s.required && !have.has(s.type)).map(s => s.label);
  const ks = legalForm().elements.kyc_status;
  if (stage === 'KYC Verification' && (!ks || ks.value !== 'Verified')) miss.push('KYC Status = Verified');
  return miss;
}
function fmtFileSize(n) { n = Number(n) || 0; return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
function legalFileChip(d, canEdit) {
  const name = d.doc_type === 'other' && d.doc_label ? `${d.doc_label} — ${d.original_name}` : d.original_name;
  const url = 'api/client_documents.php?action=download&id=' + encodeURIComponent(d.id);
  const meta = `${fmtFileSize(d.size)} · ${d.uploaded_at || ''}${d.uploaded_by_name ? ' · ' + d.uploaded_by_name : ''}`;
  return `<span class="ld-file" title="${esc(meta)}">
      <a href="${url}&inline=1" target="_blank" rel="noopener"><i class="fa-regular fa-eye"></i> ${esc(name)}</a>
      <a href="${url}" title="Download"><i class="fa-solid fa-download"></i></a>
      ${canEdit ? `<button type="button" title="Delete" onclick="deleteLegalDoc(${Number(d.id)}, '${jsAttr(name)}')"><i class="fa-solid fa-trash-can"></i></button>` : ''}
    </span>`;
}
function renderLegalDocs() {
  const payDocs = document.getElementById('payDocs');
  if (payDocs) payDocs.dataset.docStage = payDocsStage(); // Construction & Payment: the stage picked in its stage strip
  document.querySelectorAll('#clientForm .legal-docs').forEach(el => {
    const stage = el.dataset.docStage;
    const sec = el.closest('.stage-section');
    const isPay = el === payDocs;
    const title = `<div class="al-title"><i class="fa-solid fa-folder-open"></i> Documents${isPay ? ` <span class="ld-stage-name">— ${esc(payDocsStageLabel(stage))}</span>` : ''}</div>`;
    if (!window.__crmDocsClientId) { el.innerHTML = title + '<div class="ld-none">Save the lead first to upload documents.</div>'; return; }
    if (window.__crmDocsError) { el.innerHTML = title + `<div class="ld-hint">${esc(window.__crmDocsError)}</div>`; return; }
    if (!window.__crmDocs) { el.innerHTML = title + '<div class="ld-none">Loading documents…</div>'; return; }

    const canEdit = window.__crmCanEdit !== false && !(sec && sec.classList.contains('ss-locked'));
    const docs = window.__crmDocs;
    const slots = legalDocSlots(stage);
    const req = slots.filter(s => s.required);
    const doneReq = req.filter(s => docs.some(d => d.doc_type === s.type)).length;
    const busy = window.__legalUploading;
    const upBtn = (type, text) => canEdit
      ? `<button type="button" class="btn btn-sm" ${busy ? 'disabled' : ''} onclick="pickLegalDoc('${type}', '${jsAttr(stage)}')">${busy === type + '|' + stage ? '<i class="fa-solid fa-spinner fa-spin"></i> Uploading…' : `<i class="fa-solid fa-upload"></i> ${text}`}</button>` : '';

    // Accounts checkpoints share the same document types (architect certificate, site photos, signed
    // demand letter…) — each stage keeps its own files. Booking & Legal types each belong to one stage.
    const perStage = isAccountsStage(stage);
    const rows = slots.map(s => {
      const files = docs.filter(d => d.doc_type === s.type && (!perStage || d.stage === stage));
      return `<div class="ld-row ${files.length ? '' : 'ld-missing'}">
        <div class="ld-name">${files.length ? '<i class="fa-solid fa-circle-check" style="color:#15803d"></i>' : '<i class="fa-regular fa-file"></i>'} ${esc(s.label)}
          ${s.required ? '<span class="ld-req">Mandatory</span>' : '<span class="ld-opt">Optional</span>'}</div>
        <div class="ld-files">${files.length ? files.map(d => legalFileChip(d, canEdit)).join('') : '<span class="ld-none">Not uploaded</span>'}</div>
        ${upBtn(s.type, files.length ? 'Add' : 'Upload')}
      </div>`;
    });
    const others = docs.filter(d => d.doc_type === 'other' && d.stage === stage);
    rows.push(`<div class="ld-row">
        <div class="ld-name"><i class="fa-regular fa-folder"></i> Other Documents <span class="ld-opt">Optional</span></div>
        <div class="ld-files">${others.length ? others.map(d => legalFileChip(d, canEdit)).join('') : '<span class="ld-none">None</span>'}</div>
        ${upBtn('other', 'Upload')}
      </div>`);

    const f = legalForm();
    const isCurrent = (f.elements.stage.value || 'New Lead') === stage;
    const missing = isCurrent ? legalGateMissing(stage) : [];
    el.innerHTML = `<div class="ld-top">${title}${req.length ? `<span class="ld-count ${doneReq === req.length ? 'ok' : ''}">${doneReq} / ${req.length} mandatory uploaded</span>` : ''}</div>` +
      (isPay ? payDocsStripHtml(stage) : '') +
      rows.join('') +
      (missing.length ? `<div class="ld-hint"><i class="fa-solid fa-lock"></i> Next step unlocks once done: ${esc(missing.join(', '))}</div>` : '') +
      '<div class="ld-none" style="margin-top:4px">PDF, JPG, PNG, WEBP, DOC or DOCX — max 10 MB each.</div>';
  });
}
// Re-checks the gate after anything it depends on changes (documents, applicant type, co-applicant, KYC status).
function refreshLegalGate() {
  const cur = legalForm().elements.stage.value || 'New Lead';
  renderAllChecklists(cur);
  applyStageLocks(cur); // also re-renders the document blocks
}
async function loadLegalDocs(clientId) {
  if (String(window.__crmDocsClientId) !== String(clientId || null)) window.__payDocsStage = null; // another lead: back to its current stage
  window.__crmDocsClientId = clientId || null;
  window.__crmDocsError = null;
  window.__crmDocs = clientId ? null : [];
  renderLegalDocs();
  if (!clientId) return;
  let res;
  try { res = await api('client_documents.php?client_id=' + encodeURIComponent(clientId)); }
  catch (e) { res = { error: 'Could not load documents — check your connection.' }; }
  if (String(window.__crmDocsClientId) !== String(clientId)) return; // another lead was opened meanwhile
  if (Array.isArray(res)) window.__crmDocs = res;
  else { window.__crmDocs = []; window.__crmDocsError = (res && res.error) || 'Could not load documents.'; }
  refreshLegalGate();
}
function pickLegalDoc(type, stage) {
  let label = '';
  if (type === 'other') {
    label = prompt('Document name (e.g. Bank NOC, Power of Attorney):');
    if (label === null || !label.trim()) return;
    label = label.trim();
  }
  window.__legalPick = { type, stage, label };
  const inp = document.getElementById('legalDocInput');
  inp.value = '';
  inp.click();
}
async function uploadLegalDoc(inp) {
  const file = inp.files && inp.files[0];
  const pick = window.__legalPick;
  const clientId = window.__crmDocsClientId;
  if (!file || !pick || !clientId) return;
  if (file.size > 10 * 1024 * 1024) { alert('File must be under 10 MB.'); return; }
  const fd = new FormData();
  fd.append('client_id', clientId);
  fd.append('doc_type', pick.type);
  fd.append('stage', pick.stage);
  if (pick.label) fd.append('doc_label', pick.label);
  fd.append('file', file);
  window.__legalUploading = pick.type + '|' + pick.stage;
  renderLegalDocs();
  let res;
  try {
    const r = await fetch('api/client_documents.php', { method: 'POST', body: fd });
    if (r.status === 401) { window.location.href = 'login.php'; return; }
    res = await r.json().catch(() => ({ error: `Upload failed (HTTP ${r.status}) — the file may be larger than the server allows.` }));
  } catch (e) { res = { error: 'Could not reach the server — the upload did not go through.' }; }
  window.__legalUploading = null;
  if (!res || res.error) { alert((res && res.error) || 'Upload failed.'); renderLegalDocs(); return; }
  applyServerLogEntry(clientId, res);
  await loadLegalDocs(clientId);
}
async function deleteLegalDoc(id, name) {
  if (!confirm(`Delete "${name}"? This removes the file permanently.`)) return;
  const res = await api('client_documents.php', 'POST', { action: 'delete', id });
  if (!res || res.error) { alert((res && res.error) || 'Delete failed.'); return; }
  applyServerLogEntry(window.__crmDocsClientId, res);
  await loadLegalDocs(window.__crmDocsClientId);
}
// Document upload/delete Lead History entries are written by api/client_documents.php itself
// (so they're kept even if the lead isn't saved afterwards). Mirror that entry into the open
// form's log (the next Save carries it) and the cached lead (Lead Summary / reopening).
function applyServerLogEntry(clientId, res) {
  if (!res || !res.log_entry) return;
  if (String(window.__crmDocsClientId) === String(clientId)) {
    if (!window.__crmActivityLog) window.__crmActivityLog = [];
    window.__crmActivityLog.push(res.log_entry);
    renderActivityLog();
  }
  const c = state.clients.find(x => String(x.id) === String(clientId));
  if (c && res.activity_log) c.activity_log = res.activity_log;
}
function onKycStatusChange(sel) {
  const f = legalForm();
  if (sel.value === 'Verified' && !f.elements.kyc_verified_date.value) f.elements.kyc_verified_date.value = today();
  updateKycPanel();
  refreshLegalGate();
}
// KYC Status panel colour / pill / guidance line follow the selected status.
function updateKycPanel() {
  const panel = document.getElementById('kycPanel');
  if (!panel) return;
  const f = legalForm();
  const st = f.elements.kyc_status.value || '';
  const vd = f.elements.kyc_verified_date.value;
  panel.dataset.status = st;
  document.getElementById('kycPill').textContent = st || 'Not set';
  document.getElementById('kycPanelMsg').innerHTML = {
    '': '<i class="fa-solid fa-triangle-exclamation"></i> KYC Status not set — Booking Confirmed unlocks only once it is <b>Verified</b>.',
    'Pending': '<i class="fa-solid fa-hourglass-half"></i> Waiting for the client\'s KYC details / documents.',
    'Submitted': '<i class="fa-solid fa-magnifying-glass"></i> Documents received — check them, then mark <b>Verified</b> or <b>Rejected</b>.',
    'Verified': vd ? `<i class="fa-solid fa-circle-check"></i> KYC verified on ${esc(fmtDay(vd))}.` : '<i class="fa-solid fa-circle-exclamation"></i> Verified — please fill in the Verified On date.',
    'Rejected': '<i class="fa-solid fa-circle-xmark"></i> Rejected — write the reason in KYC Note and ask the client to re-submit.',
  }[st] || '';
}
function fmtDay(d) { return d ? new Date(String(d).slice(0, 10) + 'T00:00:00').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : ''; }
// ---------- Accounts desk: Construction & Payment (one simple screen) ----------
// Construction Status drives it: pick the next status (Agreement → Plinth → … → OC received) and
// "Update & Raise Demand" moves the lead to that stage AND raises that stage's payment demand in one
// step — anything still unpaid from earlier is added to it automatically (carry forward).
// Receive Payment logs money in (applied oldest-first). Amounts are on the Final Villa Price only —
// no GST / TDS. Rules: api/payment_lib.php · data: api/payments.php (saved straight away, like documents).
const PAY = window.PAY_CONFIG || { template: [], gated: [], modes: [], defaultDueDays: 15 };
const PAY_STATUS_LABEL = {
  agreement: 'Agreement Executed', plinth: 'Plinth Completed', slab1: 'First Slab Completed', slab2: 'Second Slab Completed',
  walls: 'Brickwork Completed', plaster: 'Plaster, Flooring & Plumbing Done', fittings: 'Doors, Windows & Fittings Done', possession: 'OC / CC Received (Possession)',
};
function payData() { return window.__crmPay || null; }
function payUI() { return window.__payUI || (window.__payUI = { pay: false, busy: false }); }
function payPct(n) { return `${Math.round((Number(n) || 0) * 100) / 100}%`; }
function payClient() { return state.clients.find(x => String(x.id) === String(window.__crmPayClientId)) || null; }
function payCanEdit() { const p = payData(); return !!(p && p.can_edit) && window.__crmCanEdit !== false; }
function payAddDays(iso, n) { const d = new Date(String(iso).slice(0, 10) + 'T00:00:00'); d.setDate(d.getDate() + (Number(n) || 0)); return d.toISOString().slice(0, 10); }
function payLatest() { const p = payData(); return p && p.demands && p.demands.length ? p.demands[p.demands.length - 1] : null; }
function payPill(text, cls) { return `<span class="pay-pill ${cls || ''}">${esc(text)}</span>`; }
function payAmtInput(id, oninput) {
  return `<div class="amt-field"><i class="fa-solid fa-indian-rupee-sign"></i><input type="text" inputmode="decimal" id="${id}D" oninput="amtSanitize(this);${oninput || ''}" onfocus="amtEditMode(this)" onblur="amtFormat(this)"><input type="hidden" id="${id}"></div>`;
}

// Moving forward past a construction checkpoint needs its demand raised; handover needs full payment.
// Same rules as pay_gate_missing() in api/payment_lib.php.
function payGateMissing(stage) {
  if (!PAY.gated.includes(stage) || !document.getElementById('payTracker')) return [];
  if (window.__crmPayError) return [window.__crmPayError];
  const p = payData();
  if (!p) return ['payment details (loading…)'];
  if (!p.plan) return ['payment schedule'];
  if (stage === 'Possession Offered') return p.summary.fully_paid ? [] : [`full payment — ${money(p.summary.balance)} still to receive`];
  return p.schedule.filter(r => r.seq >= 2 && r.stage === stage && !r.demanded && r.balance > 0.5).map(r => `${PAY_STATUS_LABEL[r.code] || r.short} (Construction Status)`);
}
function stageGateMissing(stage) { return [...legalGateMissing(stage), ...payGateMissing(stage)]; }

async function loadPayments(clientId, notYet) {
  window.__crmPayClientId = clientId || null;
  window.__crmPayNA = !!notYet;
  window.__crmPay = null; window.__crmPayError = null;
  window.__payUI = { pay: false, busy: false };
  paySubTab('demand');
  renderPayTracker(); renderPayDemands();
  if (!clientId || notYet) return;
  let res;
  try { res = await api('payments.php?client_id=' + encodeURIComponent(clientId)); }
  catch (e) { res = { error: 'Could not load payments — check your connection.' }; }
  if (String(window.__crmPayClientId) !== String(clientId)) return; // another lead was opened meanwhile
  if (res && !res.error) window.__crmPay = res;
  else window.__crmPayError = (res && res.error) || 'Could not load payments.';
  renderPayTracker();
  refreshLegalGate(); // re-checks the stage gates, re-renders sections + documents
}
// The server writes its own Lead History entries — fold them into the open form's history so the
// next Save Client keeps them (dropping the local "Moved to X" if the server made that move itself).
function payMergeServerLog(serverJson, movedTo) {
  let server = [];
  try { server = JSON.parse(serverJson || '[]') || []; } catch (e) { server = []; }
  if (!Array.isArray(server)) return;
  const key = x => `${x.date}|${x.time}|${x.type}|${x.note}`;
  const have = new Set(server.map(key));
  const local = (window.__crmActivityLog || []).filter(x => !have.has(key(x)) && !(movedTo && x.type === 'Stage Change' && x.note === 'Moved to ' + movedTo));
  window.__crmActivityLog = [...server, ...local];
  renderActivityLog();
}
async function payApi(body, formData, onFail) {
  const ui = payUI();
  if (ui.busy) return null;
  ui.busy = true; renderPayTracker();
  let res;
  try {
    if (formData) {
      const r = await fetch('api/payments.php', { method: 'POST', body: formData });
      if (r.status === 401) { window.location.href = 'login.php'; return null; }
      res = await r.json().catch(() => ({ error: `Server error (HTTP ${r.status}) — nothing was saved. The file may be larger than the server allows.` }));
    } else res = await api('payments.php', 'POST', body);
  } catch (e) { res = { error: 'Could not reach the server — nothing was saved.' }; }
  ui.busy = false;
  if (!res || res.error) { renderPayTracker(); if (onFail) onFail(); alert((res && res.error) || 'Something went wrong — please try again.'); return null; }
  const cid = window.__crmPayClientId;
  window.__crmPay = res.data;
  payMergeServerLog(res.activity_log, res.moved_to);
  const c = state.clients.find(x => String(x.id) === String(cid));
  if (c) { Object.assign(c, res.client || {}); c.activity_log = res.activity_log; }
  const f = document.getElementById('clientForm');
  if (res.moved_to && String(f.dataset.edit) === String(cid)) {
    f.elements.stage.value = res.moved_to; // already saved by the server
    deriveLinkedFields(res.moved_to);
    window.__crmViewStage = null;
    renderStageTracker(res.moved_to);
  } else refreshLegalGate();
  renderPayTracker();
  return res;
}

// ---- The screen ----
function renderPayTracker() {
  const full = document.getElementById('payTracker'), mini = document.getElementById('payTrackerMini'), sched = document.getElementById('paySchedule');
  if (full) full.innerHTML = payScreenHtml();
  if (mini) mini.innerHTML = payPossessionHtml();
  const p = payData();
  const ready = window.__crmPayClientId && !window.__crmPayError && p && p.plan;
  if (sched) sched.innerHTML = ready ? payScheduleHtml() : payScreenHtml(); // not ready: same loading / notice as tab 1
  const n = document.getElementById('paySubCount');
  if (n) n.textContent = ready && p.payments.length ? p.payments.length : '';
  if (ready) setTimeout(payPrepWaPdf, 0); // WhatsApp letter PDF, made in the background
}
// Construction & Payment inner tabs: 'demand' (bar, receive payment, status + demand, other details,
// documents) | 'schedule' (payment schedule + payments received).
function paySubTab(sub) {
  window.__paySubTab = sub === 'schedule' ? 'schedule' : 'demand';
  document.querySelectorAll('#paySubTabs button').forEach(b => b.classList.toggle('active', b.dataset.sub === window.__paySubTab));
  document.querySelectorAll('#ct-construction .pay-sub').forEach(el => { el.hidden = el.dataset.sub !== window.__paySubTab; });
}
function payScreenHtml() {
  if (!window.__crmPayClientId) return window.__crmPayNA ? '<div class="pay-card"><div class="pd-hint">Payments start once the Legal team transfers this lead to Accounts.</div></div>' : '';
  if (window.__crmPayError) return `<div class="pay-card"><div class="pay-warn"><i class="fa-solid fa-triangle-exclamation"></i> ${esc(window.__crmPayError)}</div></div>`;
  const p = payData();
  if (!p) return '<div class="pay-card"><div class="pd-hint"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div></div>';
  if (!p.plan) return `<div class="pay-card"><div class="pay-warn"><i class="fa-solid fa-triangle-exclamation"></i> ${p.price ? 'The payment schedule hasn\'t been set up yet — only the Accounts team or an admin can open it.' : 'Final Villa Price is not set — ask the Legal team or an admin to fill it in (Booking Initiated).'}</div></div>`;
  return paySummaryHtml(false) + (payUI().pay ? payFormHtml() : '')
    + `<div class="pay-cards">${payStatusCardHtml()}${payDemandCardHtml()}</div>`; // schedule + payments: own tab (renderPayTracker)
}
// Villa price + one 100% bar + Received / Due now / Pending.
function paySummaryHtml(compact) {
  const p = payData();
  if (!p || !p.plan) return '';
  const s = p.summary, c = payClient();
  const od = Math.min(s.overdue_pct, s.due_pct), due = Math.max(0, s.due_pct - od);
  const seg = (cls, w, label) => w > 0 ? `<div class="pb-seg ${cls}" style="width:${w}%" title="${esc(label)} — ${payPct(w)}">${w >= 6 ? payPct(w) : ''}</div>` : '';
  const tile = (cls, lbl, amt, pct, extra) => `<div class="pt-tile ${cls}"><span>${lbl}</span><b>${money(amt)}</b><small>${payPct(pct)}${extra || ''}</small></div>`;
  return `<div class="pay-card pay-sum">
    <div class="pay-head"><div class="pay-title"><i class="fa-solid fa-indian-rupee-sign"></i> Villa Price <b>${money(s.base_price)}</b><span class="pay-muted">${c && c.villa ? esc(c.villa) : ''}</span></div>
      ${!compact && payCanEdit() && !payUI().pay && !s.fully_paid ? `<button type="button" class="btn btn-sm primary" ${payUI().busy ? 'disabled' : ''} onclick="togglePayForm(true)"><i class="fa-solid fa-plus"></i> Receive Payment</button>` : ''}</div>
    <div class="pay-bar">${seg('pb-rec', s.received_pct, 'Received')}${seg('pb-od', od, 'Overdue')}${seg('pb-due', due, 'Due now')}</div>
    <div class="pay-tiles">
      ${tile('t-rec', 'Received', s.credited, s.received_pct)}
      ${tile(s.overdue > 0 ? 't-od' : 't-due', s.overdue > 0 ? 'Due now · overdue' : 'Due now', s.due_now, s.due_pct, s.due_now > 0.5 && s.next_due_date ? ` · ${s.overdue > 0 ? 'since' : 'by'} ${esc(fmtDay(s.next_due_date))}` : '')}
      ${tile('t-up', 'Pending (later stages)', s.upcoming, s.upcoming_pct)}
    </div>
    ${compact ? '<div class="pd-hint" style="margin-top:8px">Construction status, demands and payments are in the <a href="#" onclick="clientTab(\'construction\');return false">Construction &amp; Payment</a> tab.</div>' : ''}
  </div>`;
}
// Possession tab (admin): construction status + the payment progress bar only — no prices / amounts;
// those stay in Construction & Payment.
function payPossessionHtml() {
  const p = payData();
  if (!p || !p.plan) return '';
  const s = p.summary;
  const done = p.schedule.filter(r => r.seq >= 2 && r.demanded);
  const cur = done[done.length - 1] || null;
  const allDone = !p.schedule.some(r => r.seq >= 2 && !r.demanded);
  const od = Math.min(s.overdue_pct, s.due_pct), due = Math.max(0, s.due_pct - od);
  const seg = (cls, w, label) => w > 0 ? `<div class="pb-seg ${cls}" style="width:${w}%" title="${esc(label)} — ${payPct(w)}">${w >= 6 ? payPct(w) : ''}</div>` : '';
  const key = (cls, label, w) => `<span class="pp-key"><i class="${cls}"></i>${label} ${payPct(w)}</span>`;
  return `<div class="pay-card pay-sum">
    <div class="pay-head"><div class="pay-title"><i class="fa-solid fa-person-digging"></i> Construction Status
      <b>${cur ? esc(PAY_STATUS_LABEL[cur.code] || cur.short) : 'Not started'}</b>${cur && cur.milestone_date ? `<span class="pay-muted">· ${esc(fmtDay(cur.milestone_date))}</span>` : ''}${allDone ? '<span class="pay-muted">· All stages done</span>' : ''}</div></div>
    <div class="pay-bar">${seg('pb-rec', s.received_pct, 'Received')}${seg('pb-od', od, 'Overdue')}${seg('pb-due', due, 'Due now')}</div>
    <div class="pp-keys">${key('pb-rec', 'Received', s.received_pct)}${od > 0 ? key('pb-od', 'Overdue', od) : ''}${due > 0 ? key('pb-due', 'Due now', due) : ''}${key('pb-up', 'Pending', s.upcoming_pct)}</div>
    <div class="pd-hint" style="margin-top:8px">Demands and payments are in the <a href="#" onclick="clientTab('construction');return false">Construction &amp; Payment</a> tab.</div>
  </div>`;
}
// Construction Status: current stage + "update to" (= raise that stage's demand).
function payStatusCardHtml() {
  const p = payData(), can = payCanEdit();
  const done = p.schedule.filter(r => r.seq >= 2 && r.demanded);
  const cur = done[done.length - 1] || null;
  const next = p.schedule.filter(r => r.seq >= 2 && !r.demanded);
  let html = `<div class="pay-card"><div class="pc-title"><i class="fa-solid fa-person-digging"></i> Construction Status</div>
    <div class="pc-cur">${cur ? `<b>${esc(PAY_STATUS_LABEL[cur.code] || cur.short)}</b>${cur.milestone_date ? ` <span class="pay-muted">· ${esc(fmtDay(cur.milestone_date))}</span>` : ''}` : '<b>Not started</b> <span class="pay-muted">· Agreement instalment not demanded yet</span>'}</div>`;
  if (!next.length) return html + '<div class="pd-ok"><i class="fa-solid fa-circle-check"></i> All stages done — the full villa price has been demanded.</div></div>';
  if (!can) return html + `<div class="pd-hint">Next: ${esc(PAY_STATUS_LABEL[next[0].code] || next[0].short)}</div></div>`;
  const opts = next.map((r, i) => `<option value="${esc(r.stage)}">${esc(PAY_STATUS_LABEL[r.code] || r.short)} (${payPct(r.pct)})${i ? '' : ' — next'}</option>`).join('');
  html += `<div class="pay-status-grid">
      <div class="field"><label>Update Status To</label><select id="payStatusSel" onchange="payStatusPreview()">${opts}</select></div>
      <div class="field"><label>Completed On</label><input type="date" id="payStatusDate" value="${today()}" max="${today()}"></div>
      <div class="field"><label>Payment Due By</label><input type="date" id="payStatusDue" value="${payAddDays(today(), p.plan.due_days)}" min="${today()}"></div>
    </div>
    <div class="pay-preview" id="payStatusPreview">${payStatusPreviewText(next[0].stage)}</div>
    <button type="button" class="btn btn-sm primary" ${payUI().busy ? 'disabled' : ''} onclick="updateConstructionStatus()"><i class="fa-solid fa-check"></i> Update &amp; Raise Demand</button>`;
  const last = payLatest();
  if (last && p.stage === last.stage) html += ` <button type="button" class="pay-link" onclick="undoConstructionStatus()">Undo last update</button>`;
  return html + '</div>';
}
// "₹63,75,000 (Plinth 15%) + ₹35,00,000 unpaid = ₹98,75,000 to pay"
function payStatusPreviewText(stage) {
  const p = payData(), s = p.summary;
  const target = p.schedule.find(r => r.stage === stage && r.seq >= 2);
  if (!target) return '';
  const rows = p.schedule.filter(r => r.seq >= 2 && !r.demanded && r.seq <= target.seq);
  const inst = rows.reduce((t, r) => t + r.amount, 0);
  const before = p.schedule.filter(r => r.seq < rows[0].seq).reduce((t, r) => t + r.amount, 0);
  const unpaid = Math.max(0, before - s.credited), adv = Math.min(Math.max(0, s.credited - before), inst);
  const total = Math.max(0, inst + unpaid - adv);
  return `Demand: <b>${money(inst)}</b> (${rows.map(r => `${esc(r.short)} ${payPct(r.pct)}`).join(' + ')})`
    + (unpaid > 0.5 ? ` + <b>${money(unpaid)}</b> unpaid from earlier` : '') + (adv > 0.5 ? ` − ${money(adv)} paid in advance` : '')
    + ` = <b class="pay-total">${money(total)}</b> to pay`;
}
function payStatusPreview() { const el = document.getElementById('payStatusPreview'); if (el) el.innerHTML = payStatusPreviewText(document.getElementById('payStatusSel').value); }
async function updateConstructionStatus() {
  const sel = document.getElementById('payStatusSel'), date = document.getElementById('payStatusDate').value, due = document.getElementById('payStatusDue').value;
  if (!sel || !date || !due) { alert('Pick the completed date and the payment due date.'); return; }
  const txt = document.getElementById('payStatusPreview').textContent;
  if (!confirm(`Update construction status to "${sel.options[sel.selectedIndex].text.replace(/ \(.*$/, '')}"?\n\n${txt}, due by ${fmtDay(due)}.\n\nThis saves right away.`)) return;
  await payApi({ action: 'update_status', client_id: window.__crmPayClientId, stage: sel.value, milestone_date: date, due_date: due });
}
async function undoConstructionStatus() {
  const d = payLatest();
  if (!d || !confirm(`Undo "${PAY_STATUS_LABEL[d.code] || d.short}"?\n\nDemand #${d.demand_no} (${money(d.total_due)}) is withdrawn and the lead goes back one stage.`)) return;
  await payApi({ action: 'undo_status', client_id: window.__crmPayClientId });
}
// Latest demand: amount to pay + Letter / Email / WhatsApp.
function payDemandCardHtml() {
  const p = payData(), s = p.summary, d = payLatest(), can = payCanEdit();
  if (!d) return `<div class="pay-card"><div class="pc-title"><i class="fa-solid fa-file-invoice-dollar"></i> Payment Demand</div><div class="pd-hint">No demand yet — update the construction status to raise the first one.</div></div>`;
  const dueNow = s.due_now, paid = dueNow <= 0.5, od = !paid && s.overdue > 0;
  // Each channel turns into a reminder once it has been sent on that channel (or the due date has passed).
  const late = d.due_date < today();
  const emRem = !paid && (d.email_count > 0 || late), waRem = !paid && (!!d.whatsapp_sent_at || late), reminder = emRem || waRem;
  const pill = paid ? payPill('Paid', 'pp-paid') : od ? payPill(`Overdue ${s.overdue_days}d`, 'pp-od') : payPill('Due', 'pp-due');
  const breakup = `${money(d.instalment)} ${esc(d.short)} (${payPct(d.pct)})` + (d.carried_forward > 0 ? ` + ${money(d.carried_forward)} unpaid from earlier` : '')
    + (d.advance_adjusted > 0 ? ` − ${money(d.advance_adjusted)} paid in advance` : '');
  // Call — beside WhatsApp while something is due; logged in Lead History as "Called … · Payment Demand #N".
  const payLeadTel = leadPhone(state.clients.find(x => String(x.id) === String(window.__crmPayClientId)));
  const sent = [d.email_sent_at ? `✓ Emailed ${esc(fmtSentAt(d.email_sent_at))}` : '', d.whatsapp_sent_at ? `✓ WhatsApp ${esc(fmtSentAt(d.whatsapp_sent_at))}` : ''].filter(Boolean).join(' · ') || 'Not sent to the client yet';
  return `<div class="pay-card" id="payDemandCard"><div class="pc-title"><i class="fa-solid fa-file-invoice-dollar"></i> Payment Demand #${d.demand_no} ${pill}</div>
    <div class="pc-amt">${paid ? `<b>${money(d.total_due)}</b> <span class="pay-muted">— paid in full</span>` : `<b>${money(dueNow)}</b> <span class="pay-muted">to pay by ${esc(fmtDay(d.due_date))}</span>`}</div>
    <div class="pd-hint">${breakup}${!paid && Math.abs(dueNow - d.total_due) > 0.5 ? ` · demand was ${money(d.total_due)}` : ''}</div>
    <div class="wa-row pc-actions">
      <a class="btn btn-sm" href="api/payments.php?action=letter&id=${Number(d.id)}${reminder ? '&reminder=1' : ''}" target="_blank" rel="noopener" onclick="payLogLetter(${Number(d.id)}, ${reminder ? 1 : 0})"><i class="fa-solid fa-print"></i> ${reminder ? 'Reminder' : 'Letter'}</a>
      ${can && !paid ? `<button type="button" class="btn btn-sm" ${payUI().busy ? 'disabled' : ''} onclick="sendDemandEmail(${Number(d.id)})"><i class="fa-solid fa-envelope"></i> ${emRem ? 'Email Reminder' : 'Email'}</button>
      <button type="button" class="btn btn-sm" ${payUI().busy ? 'disabled' : ''} onclick="sendDemandWhatsapp(${Number(d.id)})"><i class="fa-brands fa-whatsapp"></i> ${waRem ? 'WhatsApp Reminder' : 'WhatsApp'}</button>` : ''}
      ${!paid && payLeadTel ? `<button type="button" class="btn btn-sm call-btn" title="Call ${esc(payLeadTel)}" onclick="callOpenLead('Payment Demand #${Number(d.demand_no)}')"><i class="fa-solid fa-phone"></i> Call</button>` : ''}
    </div>
    ${paid ? `<div class="pd-hint">${sent}</div>` : payDemandLogHtml(d)}</div>`;
}
// Demand Log — every Letter / Reminder print, Email, WhatsApp and Call for this demand, newest first, until it's
// paid (each resend adds a line instead of overwriting the last one). Built from the lead's history: the server
// writes these entries ("Payment demand #6 emailed…", "Payment reminder #6 sent on WhatsApp…", "Demand note #6
// opened to print…", "Called … · Payment Demand #6"); calls placed from the payment lists ("· Payment due") count
// from the day the demand was raised.
function payDemandLogEntries(d) {
  const no = new RegExp('#' + Number(d.demand_no) + '(?!\\d)');
  return (window.__crmActivityLog || []).filter(x => {
    const n = String(x.note || '');
    if (['Email Sent', 'WhatsApp Sent', 'Letter Printed'].includes(x.type)) return no.test(n);
    if (x.type === 'Call') return /Payment Demand #/.test(n) ? no.test(n) : / · Payment due$/.test(n) && String(x.date || '') >= String(d.demand_date || '');
    return false;
  }).slice().reverse();
}
function payDemandLogText(x) {
  const n = String(x.note || ''), kind = /reminder/i.test(n) ? 'Reminder' : 'Demand';
  const amt = n.match(/— (₹[\d,]+(?:\.\d+)?) due/);
  const due = amt ? ` · ${shortInr(amt[1].replace(/[₹,]/g, ''))} due` : '';
  if (x.type === 'Email Sent') { const to = n.match(/emailed to (\S+)/); return `${kind} emailed${to ? ' to ' + to[1] : ''}${due}`; }
  if (x.type === 'WhatsApp Sent') return `${kind} sent on WhatsApp${/PDF attached/.test(n) ? ' with PDF' : ''}${due}`;
  if (x.type === 'Letter Printed') return `${kind === 'Reminder' ? 'Reminder' : 'Demand letter'} opened to print / PDF${due}`;
  const num = n.match(/^Called (\S+)/);
  return `Called${num ? ' ' + num[1] : ''}`;
}
function payDemandLogHtml(d) {
  const list = payDemandLogEntries(d);
  return `<div class="attempt-log compact pay-dlog"><div class="al-title"><i class="fa-solid fa-clock-rotate-left"></i> Demand Log${list.length ? ` <span class="pay-muted">· ${list.length}</span>` : ''}</div>`
    + (list.length ? `<div class="attempt-log-list compact">${historyItemsHtml(list.map(x => ({ x, text: payDemandLogText(x), full: [x.note || ''] })))}</div>`
      : '<div class="attempt-log-empty">Not sent to the client yet — Letter, Email, WhatsApp and Call show here.</div>') + '</div>';
}
// Re-draws only the Payment Demand card (a call / letter print adds a log line without resetting the
// Receive Payment form or anything else on the screen).
function refreshPayDemandCard() {
  const el = document.getElementById('payDemandCard');
  if (el && payData() && payData().plan) el.outerHTML = payDemandCardHtml();
}
// Letter / Reminder link: the page opens in a new tab as before; the server logs it in Lead History.
async function payLogLetter(id, reminder) {
  const cid = window.__crmPayClientId;
  const res = await api('payments.php', 'POST', { action: 'letter_opened', id, reminder });
  if (!res || res.error || res.skipped || String(window.__crmPayClientId) !== String(cid)) return;
  payMergeServerLog(res.activity_log);
  const c = state.clients.find(x => String(x.id) === String(cid));
  if (c && res.activity_log) c.activity_log = res.activity_log;
  refreshPayDemandCard();
}
// Stage | % | Amount | Status — plus the payments received.
function payScheduleHtml() {
  const p = payData(), can = payCanEdit();
  const status = r => r.status === 'Paid' ? payPill('Paid', 'pp-paid') : r.status === 'Paid in advance' ? payPill('Paid in advance', 'pp-paid')
    : r.status === 'Overdue' ? payPill(`Overdue · ${money(r.balance)}`, 'pp-od') : r.status === 'Partially paid' ? payPill(`${money(r.balance)} due`, 'pp-part')
    : r.status === 'Due' ? payPill(`Due · ${money(r.balance)}`, 'pp-due') : payPill('Not due yet');
  const rows = p.schedule.map(r => `<tr class="${r.stage === p.stage && r.seq > 1 ? 'pt-cur' : ''}"><td><b>${esc(r.seq === 1 ? 'Booking' : (PAY_STATUS_LABEL[r.code] || r.short))}</b>${r.seq === 1 ? ' <small>(paid to Legal)</small>' : r.milestone_date ? ` <small>· ${esc(fmtDay(r.milestone_date))}</small>` : ''}</td>
      <td class="num">${payPct(r.pct)}</td><td class="num">${money(r.amount)}</td><td>${status(r)}</td></tr>`).join('');
  const pays = p.payments.slice().reverse().map(x => `<tr><td>${esc(fmtDay(x.pay_date))}</td><td class="num"><b>${money(x.amount)}</b></td>
      <td>${esc(x.source === 'booking' ? 'Booking (Legal)' : (x.mode || '—'))}${x.ref_no ? ` <small>· ${esc(x.ref_no)}</small>` : ''}${x.doc_id ? ` <a href="api/client_documents.php?action=download&id=${Number(x.doc_id)}&inline=1" target="_blank" rel="noopener" title="Payment proof"><i class="fa-regular fa-file-lines"></i></a>` : ''}</td>
      <td><div class="pay-act"><a href="api/payments.php?action=receipt&id=${Number(x.id)}" target="_blank" rel="noopener" title="Receipt"><i class="fa-solid fa-receipt"></i></a>${can ? `<button type="button" title="Delete" onclick="deletePayment(${Number(x.id)})"><i class="fa-solid fa-trash-can"></i></button>` : ''}</div></td></tr>`).join('');
  return `<div class="pay-card"><div class="pay-two">
    <div><div class="pc-title"><i class="fa-solid fa-list-check"></i> Payment Schedule</div>
      <div class="pay-tw"><table class="pay-table"><thead><tr><th>Stage</th><th class="num">%</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>${rows}</tbody>
      <tfoot><tr><td>Total</td><td class="num">100%</td><td class="num">${money(p.plan.base_price)}</td><td></td></tr></tfoot></table></div></div>
    <div><div class="pc-title"><i class="fa-solid fa-sack-dollar"></i> Payments Received <span class="pay-muted" style="margin-left:auto">${money(p.summary.credited)}</span></div>
      ${p.payments.length ? `<div class="pay-tw"><table class="pay-table"><thead><tr><th>Date</th><th class="num">Amount</th><th>Mode / Ref</th><th></th></tr></thead><tbody>${pays}</tbody></table></div>` : '<div class="pd-hint">No payments yet.</div>'}</div>
  </div></div>`;
}

// ---- Receive Payment ----
function togglePayForm(open) {
  payUI().pay = !!open;
  renderPayTracker();
  if (open) { payFormChanged(); document.getElementById('payForm')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
}
function payFormHtml() {
  return `<div class="pay-form" id="payForm">
    <h4><i class="fa-solid fa-sack-dollar"></i> Receive Payment</h4>
    <div class="grid">
      <div class="field"><label>Amount Received *</label>${payAmtInput('payF_amt', 'payFormChanged()')}<div class="amt-hint" id="payF_amtHint"></div></div>
      <div class="field"><label>Payment Date *</label><input type="date" id="payF_date" value="${today()}" max="${today()}"></div>
      <div class="field"><label>Mode</label><select id="payF_mode">${PAY.modes.map(m => `<option>${esc(m)}</option>`).join('')}</select></div>
      <div class="field"><label>Cheque / UTR No.</label><input id="payF_ref" maxlength="100"></div>
      <div class="field"><label>Proof <small>(optional)</small></label><input type="file" id="payF_file" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
    </div>
    <div class="pf-note" id="payF_preview"></div>
    <div class="pf-actions"><button type="button" class="btn btn-sm" onclick="togglePayForm(false)">Cancel</button>
      <button type="button" class="btn btn-sm primary" ${payUI().busy ? 'disabled' : ''} onclick="submitPayment()"><i class="fa-solid fa-check"></i> Save Payment</button></div>
  </div>`;
}
function payFormChanged() {
  const s = payData().summary, g = id => document.getElementById(id);
  const cr = Number(g('payF_amt').value) || 0;
  g('payF_amtHint').textContent = cr ? inrWords(cr) : '';
  const after = Math.max(0, s.due_now - cr), adv = Math.max(0, cr - s.due_now);
  g('payF_preview').innerHTML = cr ? (s.due_now > 0.5 ? `Due now ${money(s.due_now)} → <b>${after > 0.5 ? money(after) + ' still due' : 'fully cleared'}</b>` : 'Nothing is due right now')
    + (adv > 0.5 ? ` · ${money(adv)} goes in advance to the next stage` : '')
    + (s.credited + cr > s.base_price + 1 ? '<br><span style="color:var(--danger)">More than the balance left on the villa price — check the amount.</span>' : '') : '';
}
async function submitPayment() {
  const g = id => document.getElementById(id);
  const date = g('payF_date').value, amt = Number(g('payF_amt').value) || 0, file = g('payF_file').files[0];
  if (amt <= 0) { alert('Enter the amount received.'); return; }
  if (!date) { alert('Pick the payment date.'); return; }
  if (file && file.size > 10 * 1024 * 1024) { alert('Proof file must be under 10 MB.'); return; }
  if (!confirm(`Save ${money(amt)} received on ${fmtDay(date)}?`)) return;
  const fd = new FormData();
  Object.entries({ action: 'add_payment', client_id: window.__crmPayClientId, pay_date: date, amount: amt, mode: g('payF_mode').value, ref_no: g('payF_ref').value.trim() })
    .forEach(([k, v]) => fd.append(k, v));
  if (file) fd.append('file', file);
  if (await payApi(null, fd)) { payUI().pay = false; renderPayTracker(); }
}
async function deletePayment(id) {
  const x = payData().payments.find(y => String(y.id) === String(id));
  if (!x || !confirm(`Delete the payment of ${money(x.amount)} on ${fmtDay(x.pay_date)}?${x.source === 'booking' ? '\n\nThis is the booking amount collected by Legal.' : ''}`)) return;
  await payApi({ action: 'delete_payment', id });
}

// ---- Send the demand ----
function payWhatsappText(d, reminder, pdfAttached) {
  const s = payData().summary, c = payClient() || {}, villa = c.villa || 'your villa';
  const bank = PAY.bank && PAY.bank.account ? PAY.bank : null;
  const L = [`Dear ${c.name || 'Sir / Madam'},`, ''];
  if (reminder) {
    L.push(`Gentle reminder 🙏 — the payment for *${villa}* (Demand #${d.demand_no}, ${d.short}) ${d.due_date < today() ? 'was' : 'is'} due on *${fmtDay(d.due_date)}*.`, '',
      `Amount due now: *${money(s.due_now)}*`, '', 'Kindly arrange the payment at the earliest. Please ignore this if already paid.');
  } else {
    L.push(d.code === 'agreement' ? `Thank you for completing the Agreement for Sale for *${villa}* at Antaaya Villas, Lonavala. 🏡`
      : d.code === 'possession' ? `Great news! 🎉 We've received the Occupancy Certificate and *${villa}* is ready for possession.`
      : `Good news! 🏗️ The *${d.short}* stage of your villa *${villa}* at Antaaya Villas, Lonavala is complete.`, '',
      `• ${d.short} instalment (${payPct(d.pct)}): ${money(d.instalment)}`);
    if (d.carried_forward > 0) L.push(`• Unpaid from earlier: ${money(d.carried_forward)}`);
    if (d.advance_adjusted > 0) L.push(`• Paid in advance: −${money(d.advance_adjusted)}`);
    // Money received after the demand was raised comes off — always ask for the live balance.
    const still = Math.max(0, s.due_now), got = d.total_due - still;
    if (got > 0.5) L.push(`• Received after this demand: −${money(got)}`);
    L.push(`• *Total payable: ${money(still)}*`, '', `📅 Kindly pay by *${fmtDay(d.due_date)}*.`);
  }
  if (pdfAttached) L.push('', `📎 ${reminder ? 'Payment reminder' : 'Demand note'} attached (PDF).`);
  if (bank) L.push('', `Bank: ${[bank.name, bank.bank, 'A/c ' + bank.account, bank.ifsc ? 'IFSC ' + bank.ifsc : ''].filter(Boolean).join(' · ')}`);
  L.push('', '— Accounts Team, Antaaya Villas');
  return L.join('\n');
}
// WhatsApp demand / reminder goes with the A4 letter as a real PDF file (no link). WhatsApp chat links can't carry
// a file, so the browser's share sheet does it: the PDF is made in the background as soon as the demand shows
// (payPrepWaPdf, from renderPayTracker), and the click shares it straight away — message + PDF attached —
// the Accounts user picks the client's chat in WhatsApp. The message is copied too, in case WhatsApp leaves it out.
// No share sheet with files (e.g. a PC that uses WhatsApp Web): the PDF downloads and the chat opens to drag it in.
function payWaKey(d) {
  const s = payData().summary, rem = !!d.whatsapp_sent_at || d.due_date < today();
  return { rem, key: [d.id, rem ? 1 : 0, s.credited, s.due_now].join('|') };
}
function payPrepWaPdf() {
  const p = payData(), d = payLatest();
  if (!p || !p.plan || !d || !payCanEdit() || !(p.summary.due_now > 0.5)) return null;
  const { rem, key } = payWaKey(d);
  const cur = window.__payWaPdf;
  if (cur && cur.key === key) return cur.promise;
  const entry = { key, file: null, promise: null };
  entry.promise = fetch(`api/payments.php?action=letter&id=${Number(d.id)}&pdf=1${rem ? '&reminder=1' : ''}`, { credentials: 'same-origin' })
    .then(r => {
      if (!r.ok || !/pdf/i.test(r.headers.get('Content-Type') || '')) throw new Error('no pdf');
      const m = /filename="([^"]+)"/.exec(r.headers.get('Content-Disposition') || '');
      return r.blob().then(b => new File([b], m ? m[1] : `${rem ? 'Payment-Reminder' : 'Demand-Note'}-${d.demand_no}.pdf`, { type: 'application/pdf' }));
    })
    .then(f => { entry.file = f; return f; })
    .catch(() => { if (window.__payWaPdf === entry) window.__payWaPdf = null; return null; });
  window.__payWaPdf = entry;
  return entry.promise;
}
// Fallback: the PDF downloads, the chat opens with the message — drag the file in.
function payWaFallback(num, text, pdf) {
  const url = URL.createObjectURL(pdf), a = document.createElement('a');
  a.href = url; a.download = pdf.name; document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 60000);
  openWhatsapp(num, text, `${pdf.name} downloaded — drag it into the chat to attach it.`);
}
async function sendDemandWhatsapp(id) {
  const d = payData().demands.find(x => String(x.id) === String(id));
  if (!d || payUI().busy) return;
  const num = leadWhatsappNum();
  if (!num) { alert('No WhatsApp / mobile number on this lead.'); return; }
  const { rem, key } = payWaKey(d);
  const text = payWhatsappText(d, rem, true);
  const ready = window.__payWaPdf && window.__payWaPdf.key === key ? window.__payWaPdf.file : null;
  const pdf = ready || await payPrepWaPdf(); // only waits if clicked before the background PDF was done
  if (!pdf) { alert('Could not make the letter PDF — nothing was sent. Please try again.'); return; }
  const log = () => payApi({ action: 'whatsapp_sent', id, shared: 1 });
  const canShareFile = !!(navigator.canShare && navigator.share && navigator.canShare({ files: [pdf] }));
  if (!canShareFile || (!IS_PHONE && waMode() === 'web')) { payWaFallback(num, text, pdf); log(); return; }
  copyTextQuiet(text);
  try {
    await navigator.share({ files: [pdf], text });
  } catch (e) {
    if (e && e.name === 'NotAllowedError') { crmToast('Letter PDF ready.', 'Share on WhatsApp', () => sendDemandWhatsapp(id)); return; } // waited too long for a fresh click
    crmToast('Not shared.', 'Download PDF & open chat', () => { payWaFallback(num, text, pdf); log(); });
    return;
  }
  await log();
  crmToast('Shared with the letter PDF attached. The message is copied too — paste it if WhatsApp left it out.');
}
async function sendDemandEmail(id) {
  const d = payData().demands.find(x => String(x.id) === String(id)), c = payClient();
  if (!d) return;
  if (!c || !c.email) { alert('This client has no email address — add it in the lead\'s basic details first.'); return; }
  const reminder = d.email_count > 0 || d.due_date < today();
  if (!confirm(`Email the ${reminder ? 'payment reminder' : 'payment demand'} to ${c.email}?\n\nThe A4 letter goes along as a PDF attachment.`)) return;
  if (await payApi({ action: 'send_email', id })) alert(`Emailed to ${c.email} with the letter PDF attached.`);
}

// Possession Offered: keys only once the villa price is fully received.
function renderPayDemands() {
  document.querySelectorAll('#clientForm .pay-demand').forEach(el => {
    const p = payData();
    if (!window.__crmPayClientId || !p || !p.plan) { el.innerHTML = ''; return; }
    const s = p.summary;
    el.innerHTML = '<div class="pc-title"><i class="fa-solid fa-key"></i> Final Payment Check</div>' + (s.fully_paid
      ? `<div class="pd-ok"><i class="fa-solid fa-circle-check"></i> Full payment received — ${money(s.credited)} (100%). Handover can go ahead.</div>`
      : `<div class="pd-due-now od"><i class="fa-solid fa-lock"></i> ${money(s.balance)} still to be received — Handover Completed unlocks once the villa price is fully paid.</div>`);
  });
}
// Construction & Payment documents are filed stage by stage (Agreement, Plinth, First Slab…). The strip
// shows every construction stage reached so far: the current one opens by default, earlier ones stay
// open to view / add to anytime; stages not reached yet stay hidden.
function payDocsStages() {
  const cur = pipelineIndex(document.getElementById('clientForm').elements.stage.value);
  // + "OC / CC Received" (filed under Possession Due): the final demand is still the Accounts desk's.
  return PAY.template.filter(t => t.code !== 'booking' && (stageGroupKey(t.stage) === 'construction' || t.code === 'possession'))
    .map(t => t.stage).filter((st, i) => i === 0 || pipelineIndex(st) <= cur);
}
function payDocsStage() {
  const list = payDocsStages();
  if (window.__payDocsStage && list.includes(window.__payDocsStage)) return window.__payDocsStage;
  return list[list.length - 1] || 'Construction Customer';
}
function payDocsStageLabel(stage) {
  const t = PAY.template.find(x => x.stage === stage);
  return (t && PAY_STATUS_LABEL[t.code]) || stage;
}
function payDocsStripHtml(active) {
  const list = payDocsStages(), docs = window.__crmDocs || [];
  const cur = document.getElementById('clientForm').elements.stage.value;
  return `<div class="ld-stages">${list.map(st => {
    const n = docs.filter(d => d.stage === st && d.doc_type !== 'payment_proof').length;
    return `<button type="button" class="ld-stg${st === active ? ' on' : ''}" onclick="pickPayDocsStage('${jsAttr(st)}')" title="${esc(st)}">`
      + `${esc(payDocsStageLabel(st))}${st === cur ? ' <small>· current</small>' : ''}${n ? ` <span class="ld-stg-n">${n}</span>` : ''}</button>`;
  }).join('')}</div>`;
}
function pickPayDocsStage(stage) { window.__payDocsStage = stage; renderLegalDocs(); }
// Enter inside the payment inputs must not submit the whole Edit Client form.
document.getElementById('clientForm').addEventListener('keydown', e => {
  if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.closest('.pay-tracker')) e.preventDefault();
});
// Mini "paid" bar for tables: received (green) + due (amber / red when overdue).
function payMiniBar(c) {
  const price = villaPriceNum(c), rec = +c.received || 0, due = +c.dueamount || 0;
  if (!price) return '—';
  const rp = Math.min(100, rec / price * 100), dp = Math.min(100 - rp, due / price * 100);
  const od = due > 0 && c.duedate && c.duedate < today();
  return `<span class="pay-mini" title="${esc(`Received ${money(rec)} · due ${money(due)} · of ${money(price)}`)}"><span class="pm-bar"><i style="width:${rp}%;background:#16a34a"></i><i style="width:${dp}%;background:${od ? '#dc2626' : '#f59e0b'}"></i></span><span class="pm-txt">${payPct(Math.round(rp * 100) / 100)}</span></span>`;
}

// "What was just done / what's next" guidance panel at the bottom of the
// modal — always reflects the lead's true current position in the 24-step
// pipeline, however it was reached.
function renderNextStepPanel(stage) {
  const el = document.getElementById('nextStepPanel');
  if (!el) return;
  const curGroup = stageGroupKey(stage);
  if (curGroup === 'cancel') {
    el.innerHTML = `<div class="ns-col ns-done"><div class="ns-label">Status</div><div class="ns-text">Lead Cancelled</div></div>`;
    return;
  }
  if (curGroup === 'closed') {
    el.innerHTML = `<div class="ns-col ns-done"><div class="ns-label">Status</div><div class="ns-text">Lead Closed</div></div>`;
    return;
  }
  const idx = pipelineIndex(stage);
  const next = PIPELINE_STAGES[idx + 1];
  const accNote = ' <span style="font-size:11px;font-weight:600;color:var(--muted)">(handled by Accounts team)</span>';
  // Legal desk: its work ends at Registered → Transfer to Accounts; what Accounts does next isn't shown.
  if (IS_LEGAL && (isAccountsHandedOver(stage) || isAccountsStage(stage) || stage === 'Registered')) {
    const done = isAccountsHandedOver(stage) || isAccountsStage(stage);
    el.innerHTML =
      `<div class="ns-col ns-done"><div class="ns-label">Last completed</div><div class="ns-text">${done ? 'Legal completed — Registered' : 'Registered'}</div></div>` +
      `<div class="ns-col ns-next"><div class="ns-label">Next up</div><div class="ns-text">${done ? 'Handled by the Accounts team' : 'Transfer to Accounts'}</div></div>`;
    return;
  }
  if (isAccountsHandedOver(stage)) {
    el.innerHTML =
      `<div class="ns-col ns-done"><div class="ns-label">Last completed</div><div class="ns-text">${IS_ACCOUNTS ? 'Legal completed — transferred to Accounts' : 'Legal completed — Registered'}</div></div>` +
      `<div class="ns-col ns-next"><div class="ns-label">Next up</div><div class="ns-text">${esc(next)}${IS_ACCOUNTS ? '' : accNote}</div></div>`;
    return;
  }
  if (isSalesHandedOver(stage)) {
    el.innerHTML =
      `<div class="ns-col ns-done"><div class="ns-label">Last completed</div><div class="ns-text">Sales completed — Unit Blocked</div></div>` +
      `<div class="ns-col ns-next"><div class="ns-label">Next up</div><div class="ns-text">${esc(next)} <span style="font-size:11px;font-weight:600;color:var(--muted)">(handled by Post Sales team)</span></div></div>`;
    return;
  }
  el.innerHTML =
    `<div class="ns-col ns-done"><div class="ns-label">Last completed</div><div class="ns-text">${esc(stage)}</div></div>` +
    `<div class="ns-col ns-next"><div class="ns-label">Next up</div><div class="ns-text">${next ? esc(next) : 'Final stage — Handover Completed'}${!CAN_POST_SALES && next && isPostSalesStage(next) ? ' <span style="font-size:11px;font-weight:600;color:var(--muted)">(handled by Post Sales team)</span>' : ''}${IS_LEGAL && next && isAccountsStage(next) ? accNote : ''}${IS_ACCOUNTS && next && stageGroupKey(next) === 'possession' ? ' <span style="font-size:11px;font-weight:600;color:var(--muted)">(handled by Admin)</span>' : ''}</div></div>`;
}

// ---------- Sales → Post Sales handover (end of Unit Blocked) ----------
function renderSalesHandover(stage) {
  const box = document.getElementById('salesHandoverBox');
  if (!box) return;
  box.className = 'handover-box';
  const fmt = ts => ts ? new Date(String(ts).replace(' ', 'T')).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '';
  if (isSalesHandedOver(stage) || isPostSalesStage(stage)) {
    box.classList.add('ho-done');
    const when = window.__crmSalesHandover ? ` on ${esc(fmt(window.__crmSalesHandover))}` : '';
    box.innerHTML = `<div class="ho-text"><b><i class="fa-solid fa-circle-check"></i> Sales completed${when}</b>This lead has been transferred to the Post Sales team (booking, legal &amp; payments).</div>` +
      (CAN_POST_SALES ? `<button type="button" class="btn ho-btn" onclick="switchPhase('post')"><i class="fa-solid fa-file-contract"></i> Go to Post Sales</button>` : '');
    return;
  }
  if (stage !== 'Unit Blocked' || window.__crmCanEdit === false) { box.innerHTML = ''; return; }
  box.innerHTML = `<div class="ho-text"><b>Sales work done?</b>Marks this lead as completed by the sales team and hands it over to Post Sales. The lead becomes read-only for sales after this.</div>` +
    `<button type="button" class="btn ho-btn" onclick="completeSalesHandover()"><i class="fa-solid fa-flag-checkered"></i> Complete Sales &amp; Transfer to Post Sales</button>`;
}
function completeSalesHandover() {
  const f = document.getElementById('clientForm');
  if ((f.elements.stage.value || '') !== 'Unit Blocked') return;
  const miss = [];
  if (!finalVillaGet().length) miss.push('final villa (Unit Selection)');
  if (!f.elements.block_date.value) miss.push('Block Date');
  if (miss.length) { alert('Please fill in the ' + miss.join(' and ') + ' before transferring to Post Sales.'); return; }
  if (!confirm('Complete sales for this lead and transfer it to the Post Sales team?' + (IS_ADMIN ? '' : '\n\nYou will not be able to edit it after this.'))) return;
  document.getElementById('salesHandoverField').value = '1';
  f.requestSubmit ? f.requestSubmit() : f.querySelector('.client-modal-footer button.primary').click();
}

// ---------- Legal → Accounts handover (end of Registered) ----------
function renderAccountsHandover(stage) {
  const box = document.getElementById('accountsHandoverBox');
  if (!box) return;
  box.className = 'handover-box';
  const fmt = ts => ts ? new Date(String(ts).replace(' ', 'T')).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '';
  if (isAccountsHandedOver(stage) || isAccountsStage(stage)) {
    box.classList.add('ho-done');
    const when = window.__crmAccountsHandover ? ` on ${esc(fmt(window.__crmAccountsHandover))}` : '';
    box.innerHTML = `<div class="ho-text"><b><i class="fa-solid fa-circle-check"></i> Legal completed${when}</b>This lead has been transferred to the Accounts team (construction, payments &amp; possession).</div>` +
      (IS_ADMIN ? `<button type="button" class="btn ho-btn" onclick="clientTab('construction')"><i class="fa-solid fa-indian-rupee-sign"></i> Go to Construction &amp; Payment</button>` : '');
    return;
  }
  if (stage !== 'Registered' || window.__crmCanEdit === false || !(IS_ADMIN || IS_LEGAL)) { box.innerHTML = ''; return; }
  box.innerHTML = `<div class="ho-text"><b>Legal work done?</b>Marks booking &amp; legal as completed and hands the lead over to the Accounts team. The lead becomes read-only for Legal after this.</div>` +
    `<button type="button" class="btn ho-btn" onclick="completeAccountsHandover()"><i class="fa-solid fa-flag-checkered"></i> Transfer to Accounts</button>`;
}
function completeAccountsHandover() {
  const f = document.getElementById('clientForm');
  if ((f.elements.stage.value || '') !== 'Registered') return;
  const miss = [];
  if (!f.elements.registration_date.value) miss.push('Registration Date');
  miss.push(...legalGateMissing('Registered'));
  if (miss.length) { alert('Please complete the following before transferring to Accounts:\n\n• ' + miss.join('\n• ')); return; }
  if (!confirm('Legal work is complete — transfer this lead to the Accounts team?' + (IS_ADMIN ? '' : '\n\nYou will not be able to edit it after this.'))) return;
  document.getElementById('accountsHandoverField').value = '1';
  f.requestSubmit ? f.requestSubmit() : f.querySelector('.client-modal-footer button.primary').click();
}

// ---------- Unit Selection: final villa(s) + offered price per villa ----------
// A client can buy more than one villa, so "Villa Selected (Final)" is a tick-list
// of the shortlisted villas, each with its own offered price. Stored in hidden
// fields: villa ("A, B" — Villa Inventory syncs on each), villa_offers (JSON
// {villa: price}) and offered_price (readable summary, used in history/summary).
// Splits a comma-separated villa list. A villa's serial can itself contain a comma (EMIRA combos:
// "A4-1,2"), so two neighbouring pieces are joined back together whenever the joined text is a
// known villa ("EMIRA 1 - A4-1" + "2" -> "EMIRA 1 - A4-1,2"). Same rule as villa_list() in api/clients.php.
function splitVillaText(text) {
  const parts = String(text || '').split(',').map(s => s.trim());
  const out = [];
  for (let i = 0; i < parts.length; i++) {
    let v = parts[i];
    if (i + 1 < parts.length && parts[i + 1] && findVilla(v + ',' + parts[i + 1])) { v += ',' + parts[i + 1]; i++; }
    out.push(v);
  }
  return out;
}
function villaListOf(text) { return [...new Set(splitVillaText(text).map(canonVilla).filter(Boolean))]; }
function finalVillaGet() { const f = document.getElementById('clientForm'); return f.elements.villa ? villaListOf(f.elements.villa.value) : []; }
function finalVillaOffers() {
  const f = document.getElementById('clientForm');
  let o = {};
  try { o = JSON.parse(f.elements.villa_offers.value || '{}') || {}; } catch (e) { o = {}; }
  const out = {};
  Object.entries(o).forEach(([k, v]) => { out[canonVilla(k)] = v; });
  // Older leads only have a single offered_price — attach it to their one villa.
  const sel = finalVillaGet();
  if (!Object.keys(out).length && sel.length === 1 && f.elements.offered_price.value) out[sel[0]] = f.elements.offered_price.value;
  return out;
}
function finalVillaStore(selected, offers) {
  const f = document.getElementById('clientForm');
  const keep = {};
  selected.forEach(v => { if ((offers[v] || '').trim()) keep[v] = offers[v].trim(); });
  f.elements.villa.value = selected.join(', ');
  f.elements.villa_offers.value = selected.length ? JSON.stringify(keep) : '';
  f.elements.offered_price.value = selected.length === 1 ? (keep[selected[0]] || '')
    : selected.filter(v => keep[v]).map(v => `${v}: ${keep[v]}`).join('; ');
}
function dropFinalVilla(lbl) {
  const sel = finalVillaGet();
  if (sel.includes(lbl)) finalVillaStore(sel.filter(v => v !== lbl), finalVillaOffers());
}
function renderFinalVillaList() {
  const f = document.getElementById('clientForm');
  const box = document.getElementById('finalVillaList');
  if (!box || !f.elements.villa_shortlist) return;
  const names = villaListOf(f.elements.villa_shortlist.value);
  const selected = finalVillaGet();
  selected.forEach(v => { if (!names.includes(v)) names.push(v); }); // older/free-typed value not in shortlist
  const offers = finalVillaOffers();
  const disabled = f.elements.villa.disabled;
  box.classList.toggle('disabled', disabled);
  box.innerHTML = names.length ? names.map((n, i) => {
    const on = selected.includes(n);
    return `<div class="fv-row"><label><input type="checkbox" data-fv="${i}" ${on ? 'checked' : ''} ${disabled ? 'disabled' : ''}> ${esc(n)}</label>` +
      `<input type="text" class="fv-price" data-fvp="${i}" placeholder="Offered price, e.g. ₹2.35 Cr" value="${esc(offers[n] || '')}" ${!on || disabled ? 'disabled' : ''}></div>`;
  }).join('') + (selected.length > 1 ? `<div class="fv-count"><i class="fa-solid fa-house-circle-check"></i> ${selected.length} villas selected for this client</div>` : '')
    : '<div class="fv-empty">Shortlist villas first — they appear here to tick as final.</div>';
  const collect = () => {
    const sel = [], off = {};
    names.forEach((n, i) => {
      if (box.querySelector(`[data-fv="${i}"]`).checked) sel.push(n);
      off[n] = box.querySelector(`[data-fvp="${i}"]`).value;
    });
    finalVillaStore(sel, off);
  };
  box.querySelectorAll('[data-fv]').forEach(cb => cb.onchange = () => { collect(); renderFinalVillaList(); });
  box.querySelectorAll('[data-fvp]').forEach(inp => inp.oninput = collect);
}

// ---------- Basic-details summary strip ----------
function renderLeadSummary(c) {
  const el = document.getElementById('leadSummary');
  if (!el) return;
  const rows = c ? [
    ['Lead ID', c.lead_code || '—'], ['Name', c.name || '—'], ['Mobile', c.mobile || '—'],
    ['Email', c.email || '—'], ['City', c.city || '—'], ['Source', c.source || '—'],
    ['Category', c.category || '—'], ['Sales Person', c.salesperson_name || '—'],
  ] : [['Lead ID', 'Auto-generated'], ['Name', '—'], ['Mobile', '—'], ['Email', '—']];
  // Lead came through a broker (any Broker* category, or broker fields
  // filled in regardless of category) — surface those details here too.
  if (c && (c.broker_name || c.broker_contact || c.broker_email)) {
    rows.push(['Broker Name', c.broker_name || '—'], ['Broker Contact', c.broker_contact || '—'], ['Broker Email', c.broker_email || '—']);
  }
  const tel = c && telNumber(leadPhone(c));
  el.innerHTML = rows.map(([l, v]) => `<div class="ls-item"><div class="ls-label">${esc(l)}</div><div class="ls-value">${esc(v)}${l === 'Mobile' && tel
    ? ` <button type="button" class="ls-call" title="Call ${esc(leadPhone(c))}" onclick="callLead(${Number(c.id)})"><i class="fa-solid fa-phone"></i></button>` : ''}</div></div>`).join('') +
    `<button type="button" class="ls-edit-btn" title="Edit basic details" onclick="toggleBasicEdit()"><i class="fa-solid fa-pen"></i></button>`;
}
// Interest Level is one shared value, editable from New Lead, Contacted, or
// Site Visit Completed alike (whichever the salesperson has open at the
// time) — every copy of the field always mirrors the same value.
function syncInterest(v) {
  const f = document.getElementById('clientForm');
  if (f.elements.interest) f.elements.interest.value = v;
  document.querySelectorAll('[data-interest-mirror]').forEach(el => { el.value = v; });
}
// Generic version of the same trick for the Brochure Sent bypass: the real
// name="..." field is a hidden canonical input, and any number of visible
// [data-mirror="field"] inputs (one in Brochure Sent, one in Site Visit
// Completed) just mirror it, so filling it in from either place works.
function mirrorSync(field, v) {
  const f = document.getElementById('clientForm');
  if (f.elements[field]) f.elements[field].value = v;
  document.querySelectorAll(`[data-mirror="${field}"]`).forEach(el => { el.value = v; });
}
// Toggles the duplicate Brochure Sent panel inside Site Visit Completed —
// for leads where the brochure genuinely goes out after the visit instead
// of before it.
function toggleBrochureBypass(checked) {
  const f = document.getElementById('clientForm');
  if (f.elements.brochure_bypass) f.elements.brochure_bypass.value = checked ? 'Yes' : 'No';
  document.getElementById('brochureInVisitBlock')?.classList.toggle('hidden', !checked);
}
// Marks the brochure greeting as "no longer just the auto-draft" the
// moment the salesperson edits it from either copy, so
// fillBrochureWhatsappGreetingDefault() stops overwriting it.
function clearBrochureAuto() {
  const f = document.getElementById('clientForm');
  f.elements.brochure_whatsapp_message.dataset.auto = '';
  document.querySelectorAll('[data-mirror="brochure_whatsapp_message"]').forEach(el => { el.dataset.auto = ''; });
}
function toggleBasicEdit() {
  document.getElementById('basicEdit').classList.toggle('hidden');
}
// Saves only the Basic Details block via the same clients.php endpoint as
// the full form, but — unlike the main Save Client button — keeps the
// Edit Client modal open on whichever stage tab was showing: it just
// collapses the inline edit block back into the summary strip and
// refreshes that strip with the saved values (reloading state.clients so
// server-derived fields like Category/Sales Person name stay accurate).
async function saveBasicDetails() {
  const f = document.getElementById('clientForm');
  const o = Object.fromEntries(new FormData(f).entries());
  o.id = f.dataset.edit || null;
  let result;
  try {
    result = await api('clients.php', 'POST', o);
  } catch (err) {
    alert('Could not reach the server — the save did not go through. Check your connection and try again.');
    return;
  }
  if (!result || result.error) { alert((result && result.error) || 'Save failed — please try again.'); return; }
  await loadAll();
  const c = state.clients.find(x => String(x.id) === String(o.id));
  renderLeadSummary(c || null);
  document.getElementById('basicEdit').classList.add('hidden');
}

// ---------- Lead Summary overlay (click a row anywhere to open) ----------
function historyIcon(type) {
  return {
    'Lead Generated': 'fa-solid fa-flag', 'Stage Change': 'fa-solid fa-route', 'Contact Attempted': 'fa-solid fa-phone', 'Call': 'fa-solid fa-phone-flip',
    'Note': 'fa-solid fa-pen', 'Follow-up': 'fa-solid fa-phone-volume', 'Unit Selection': 'fa-solid fa-list-check', 'Unit Blocked': 'fa-solid fa-lock', 'Booking Initiated': 'fa-solid fa-file-invoice-dollar', 'Sales Handover': 'fa-solid fa-flag-checkered', 'Accounts Handover': 'fa-solid fa-right-left', 'Reassigned': 'fa-solid fa-user-gear', 'Document': 'fa-solid fa-file-arrow-up', 'WhatsApp Sent': 'fa-brands fa-whatsapp', 'Site Visit': 'fa-solid fa-location-dot', 'Email Sent': 'fa-solid fa-envelope-circle-check',
    'KYC Verification': 'fa-solid fa-user-shield', 'Booking Confirmed': 'fa-solid fa-circle-check', 'Agreement In Process': 'fa-solid fa-file-signature',
    'Registered': 'fa-solid fa-stamp', 'Construction Customer': 'fa-solid fa-person-digging', 'Plinth Completed': 'fa-solid fa-layer-group',
    'First Slab Completed': 'fa-solid fa-building', 'Second Slab Completed': 'fa-solid fa-city', 'Brickwork Completed': 'fa-solid fa-trowel-bricks',
    'Plaster & Flooring Completed': 'fa-solid fa-paint-roller', 'Fittings Completed': 'fa-solid fa-door-open',
    'Payment Received': 'fa-solid fa-sack-dollar', 'Letter Printed': 'fa-solid fa-print', 'Payment Deleted': 'fa-solid fa-trash-can',
    'Possession Due': 'fa-solid fa-hourglass-half', 'Possession Offered': 'fa-solid fa-key', 'Handover Completed': 'fa-solid fa-house-circle-check'
  }[type] || 'fa-solid fa-circle-dot';
}
// ---- Lead History, short form (Lead Summary) ----
// The saved history keeps the full wording (shown on hover); the summary shows a compact
// version: money as ₹4.25 Cr / ₹42.5 L, dates as "29 Sep", short labels, no file names,
// and back-to-back document uploads by the same person on the same day merged into one line.
const H_MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
function hDay(iso) {
  const m = String(iso || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return iso || '';
  return `${+m[3]} ${H_MON[+m[2] - 1]}${+m[1] !== new Date().getFullYear() ? ' ' + m[1] : ''}`;
}
function shortInr(n) {
  n = Number(n) || 0;
  const t = (x, d) => x.toFixed(d).replace(/\.?0+$/, '');
  // Cr to 3 decimals, L to 2 — so ₹1,57,50,000 reads ₹1.575 Cr and ₹33,75,000 reads ₹33.75 L (same figure as the tables)
  return n >= 1e7 ? `₹${t(Math.round(n / 1e4) / 1e3, 3)} Cr` : n >= 1e5 ? `₹${t(Math.round(n / 1e3) / 1e2, 2)} L` : money(n);
}
const H_LABELS = {
  'Final villa price': 'Price', 'Booking amount': 'Amount', 'Booking date': 'Date', 'Payment mode': '', 'Cheque / Txn ref.': 'Ref',
  'KYC status': 'Status', 'Verified on': 'on', 'KYC note': 'Note', 'Agreement note': 'Note', 'Applicant type': '', 'Confirmation date': 'Confirmed', 'Draft shared on': 'Draft',
  'Agreement date': 'Agreement', 'Registration date': 'Date', 'Registration no.': 'No.', 'Sub-Registrar office': 'SRO',
  'Amount received': 'Received', 'Last payment date': 'Paid on', 'Next payment due': 'Next due', 'Next payment amount': 'Due',
  'Expected possession': 'Expected', 'Possession date': 'Date', 'Handover date': 'Date', 'Keys handed over': 'Keys',
  'Allotment date': 'Allotment', 'Construction status': 'Status', 'Last site update': 'Updated', 'Fit-out status': 'Fit-out',
};
function shortDocLabel(l) { return String(l).replace(/\s*\((signed|if different from Aadhaar)\)/i, '').replace(/^Applicant (Passport-size )?/, '').trim(); }
function shortHistoryText(note, underStage) {
  let t = String(note || '');
  t = t.replace(/₹[\d,]+(?:\.\d+)?/g, m => shortInr(m.replace(/[₹,]/g, '')))
    .replace(/\b(\d{1,2}) (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]* (\d{4})\b/g,
      (m, d, mo, y) => `${+d} ${mo.slice(0, 3)}${+y !== new Date().getFullYear() ? ' ' + y : ''}`)
    .replace(/\b\d{4}-\d{2}-\d{2}\b/g, d => hDay(d));
  const m = t.match(/^(.+?) — (.+)$/);
  if (m && m[1] === underStage && !POST_SALES_LOG_TYPES.includes(m[1])) return m[2]; // "Unit Blocked — …" under its own heading
  if (!m || !POST_SALES_LOG_TYPES.includes(m[1])) return t;
  const parts = m[2].split(' · ').map(p => {
    const kv = p.match(/^([^:]+): (.*?)(?: \(was (.*)\))?$/);
    if (!kv) return p;
    const [, label, val, was] = kv;
    if (!val || val === '-') return '';
    const short = label in H_LABELS ? H_LABELS[label] : label;
    const v = /^".*"$/.test(val) && val.length > 42 ? val.slice(0, 40) + '…"' : val;
    return `${short ? short + ' ' : ''}${was ? was + ' → ' : ''}${v}`;
  }).filter(Boolean);
  return m[1] === underStage ? parts.join(' · ') : `${m[1]}: ${parts.join(' · ')}`;
}
function compactHistory(log, underStage) {
  const out = [];
  const docRe = /^Document (uploaded|deleted) — (.*) \(([^)]*)\)(?: · (.+))?$/;
  log.forEach(x => {
    const d = x.type === 'Document' ? String(x.note || '').match(docRe) : null;
    if (d) {
      const prev = out[out.length - 1];
      const label = shortDocLabel(d[2]);
      if (prev && prev.doc === d[1] && prev.x.by === x.by && prev.x.date === x.date) {
        prev.items.push(label); prev.full.push(x.note); prev.x = { ...prev.x, time: x.time };
        return;
      }
      out.push({ x, doc: d[1], items: [label], full: [x.note] });
      return;
    }
    out.push({ x, text: shortHistoryText(x.note, underStage), full: [x.note || ''] });
  });
  return out.map(r => {
    if (r.doc) {
      const verb = r.doc === 'uploaded' ? 'Uploaded' : 'Deleted';
      r.text = r.items.length > 1 ? `${verb} ${r.items.length} documents: ${r.items.join(', ')}` : `${verb} ${r.items[0]}`;
    }
    return r;
  });
}
// Splits the history into one block per lead stage (pipeline order). An entry goes to the stage it
// names (detail lines are typed with their checkpoint, document lines carry " · <stage>");
// anything else goes to the stage the lead was in at that moment. "Moved to X" lines become the
// block's heading (when / who) instead of a line of their own.
function historySections(log, currentStage) {
  const secs = new Map();
  const sec = k => { if (!secs.has(k)) secs.set(k, { key: k, entries: [], moved: null }); return secs.get(k); };
  let cur = null;
  log.forEach(x => {
    const note = String(x.note || '');
    const mv = x.type === 'Stage Change' ? note.match(/^Moved to (.+)$/) : null;
    if (mv) { cur = mv[1].trim(); const s = sec(cur); if (!s.moved) s.moved = x; return; }
    let k;
    if (IS_POST_SALES && ['Lead Generated', 'Sales Handover'].includes(x.type)) k = 'Sales';
    else if (IS_ACCOUNTS && x.type === 'Accounts Handover') k = 'Sales'; // top of the Accounts history
    else if (x.type === 'Lead Generated') k = 'New Lead';
    else if (PIPELINE_STAGES.includes(x.type)) k = x.type;
    else if (x.type === 'Document') {
      const m = note.match(/ · ([^·()]+)$/);
      k = m && PIPELINE_STAGES.includes(m[1].trim()) ? m[1].trim() : (cur || currentStage);
    } else k = cur || 'New Lead';
    sec(k).entries.push(x);
  });
  const rank = k => k === 'Sales' ? -1 : (k === 'Closed' || k === 'Cancelled') ? 9999 : (PIPELINE_STAGES.indexOf(k) === -1 ? 9998 : PIPELINE_STAGES.indexOf(k));
  return [...secs.values()].sort((a, b) => rank(a.key) - rank(b.key));
}
// Same list pattern as the Sales history — just ordered stage by stage: each "Moved to X" line is
// followed by everything done for X (details, documents), in pipeline order, short wording.
// grouped = true (admin / IT): the same list split under Sales / Legal / Accounts headings, so the
// whole journey — every desk's work — reads in one place.
const HISTORY_PHASES = [
  { key: 'sales', label: 'Sales History', icon: 'fa-user-tie' },
  { key: 'legal', label: 'Legal History', icon: 'fa-file-contract' },
  { key: 'accounts', label: 'Accounts History', icon: 'fa-indian-rupee-sign' },
];
// Which desk's part of the journey a history block belongs to. A block named after a current stage
// goes by that stage's tab (Sales tabs / Booking & Legal / Construction & Payment + Possession).
// Anything else — old stage names from before the pipeline was renamed ("Negotiation Visit",
// "Unit Shortlisted"…), Closed / Cancelled, notes — goes by WHEN it happened: before the sales →
// Post Sales transfer = Sales, before the Legal → Accounts transfer = Legal, after it = Accounts.
function historyPhaseOf(key, ts, cuts) {
  if (key === 'Sales' || key === 'New Lead') return 'sales';
  if (PIPELINE_STAGES.includes(key)) {
    const g = stageGroupKey(key);
    return g === 'book' ? 'legal' : ACCOUNTS_KEYS.includes(g) ? 'accounts' : 'sales';
  }
  if (cuts.acc && ts >= cuts.acc) return 'accounts';
  if (cuts.sales && ts >= cuts.sales) return 'legal';
  return 'sales';
}
const histTs = x => x ? `${String(x.date || '').slice(0, 10)} ${String(x.time || '').slice(0, 5)}`.trim() : '';
// Transfer moments, from the history's own transfer lines (same clock as every other line),
// falling back to the saved transfer timestamps on the lead.
function historyCuts(log, c) {
  const last = re => { let t = ''; log.forEach(x => { if (re(x)) t = histTs(x); }); return t; };
  const sales = last(x => x.type === 'Sales Handover' && /^Sales completed/.test(x.note || ''))
    || (c && c.sales_handover_at ? String(c.sales_handover_at).slice(0, 16) : '');
  const acc = last(x => x.type === 'Accounts Handover' && /^Legal completed/.test(x.note || ''))
    || (c && c.accounts_handover_at ? String(c.accounts_handover_at).slice(0, 16) : '');
  return { sales, acc };
}
function renderHistorySections(log, currentStage, grouped, c) {
  const secs = historySections(log, currentStage);
  const blockItems = sec => [
    ...(sec.moved ? [{ x: sec.moved, text: sec.moved.note || '', full: [sec.moved.note || ''] }] : []),
    ...compactHistory(sec.entries, sec.moved ? sec.key : null),
  ];
  if (!grouped) {
    const items = secs.flatMap(blockItems);
    return items.length ? historyItemsHtml(items) : '<div class="attempt-log-empty">No history yet.</div>';
  }
  // Admin / IT: every block tagged with its desk, then each desk's blocks in the order they happened.
  const cuts = historyCuts(log, c);
  const blocks = secs.map(sec => {
    const all = [sec.moved, ...sec.entries].filter(Boolean);
    const start = all.map(histTs).filter(Boolean).sort()[0] || '';
    return { start, phase: historyPhaseOf(sec.key, start, cuts), items: blockItems(sec) };
  }).filter(b => b.items.length);
  if (!blocks.length) return '<div class="attempt-log-empty">No history yet.</div>';
  return HISTORY_PHASES.map(ph => {
    const mine = blocks.filter(b => b.phase === ph.key).sort((a, b) => a.start.localeCompare(b.start));
    const list = mine.flatMap(b => b.items);
    // Legal / Accounts headings show only once the lead has been transferred to that desk (Sales →
    // Post Sales, Legal → Accounts) — or if that desk already has history of its own (e.g. a lead an
    // admin moved back), so nothing is ever hidden. Before that, the summary shows Sales History only.
    if (!list.length && ph.key !== 'sales' && !(ph.key === 'legal' ? leadWithPostSales(c) : leadWithAccounts(c))) return '';
    const head = `<div class="ls-hist-phase ls-hist-${ph.key}"><i class="fa-solid ${ph.icon}"></i> ${ph.label} <span>${list.length}</span></div>`;
    return head + (list.length ? historyItemsHtml(list) : `<div class="attempt-log-empty" style="padding:8px 10px">${ph.key === 'accounts' ? 'Transferred to Accounts — no activity yet.' : ph.key === 'legal' ? 'Transferred to Legal — no activity yet.' : 'No sales history.'}</div>`);
  }).join('');
}
function historyItemsHtml(items) {
  return items.map(({ x, text, full }) => `
          <div class="attempt-log-item" title="${esc(full.join('\n'))}">
            <div class="ali-ico"><i class="${historyIcon(x.type)}"></i></div>
            <div class="ali-body">
              <div class="ali-note">${esc(text)}</div>
              <div class="ali-meta">${esc(x.date || '')}${x.time ? ' · ' + esc(formatTime12(x.time)) : ''}${x.by ? ' · by ' + esc(x.by) : ''}</div>
            </div>
          </div>`).join('');
}
function formatTime12(t) {
  if (!t || !t.includes(':')) return t || '';
  let [h, m] = t.split(':').map(Number);
  const ampm = h >= 12 ? 'PM' : 'AM';
  h = h % 12 || 12;
  return `${h}:${String(m).padStart(2, '0')} ${ampm}`;
}
function openLeadSummary(id) {
  const c = state.clients.find(x => String(x.id) === String(id));
  if (!c) return;
  window.__crmSummaryId = c.id; // a call placed from here refreshes this history
  let log = [];
  try { log = c.activity_log ? JSON.parse(c.activity_log) : []; } catch (e) { log = []; }
  if (!log.some(x => x.type === 'Lead Generated')) {
    log = [{ date: c.created_date || '', time: '', type: 'Lead Generated', note: 'Lead added to the system', by: c.entry_channel === 'Form' ? 'Website Form' : (c.created_by_name || '') }, ...log];
  }
  // Oldest first, so the history reads top-to-bottom like a real timeline.
  log.sort((a, b) => `${a.date} ${a.time}`.localeCompare(`${b.date} ${b.time}`));

  const idx = pipelineIndex(c.stage);
  const nextStage = PIPELINE_STAGES[idx + 1];

  // Lead details, four to a row: contact · source & owner · requirement. Purpose, Configuration, Budget
  // and Added By live here (Manage Leads leaves them out of its table on laptops).
  // Each entry: [label, value, icon, sub-line, extra class].
  const details = [
    ['Mobile', c.mobile || '—', 'fa-phone'], ['Email', c.email || '—', 'fa-envelope', '', 'lsum-wide'], ['City', c.city || '—', 'fa-location-dot'],
    // Source = Reference → who referred this lead, under the source.
    ['Source / Reference', c.source || '—', 'fa-share-nodes', c.source === 'Reference' && c.subsource ? `Referred by ${c.subsource}` : ''],
    ['Sales Person', c.salesperson_name || '—', 'fa-user-tie'],
    ['Added By', c.entry_channel === 'Form' ? `🌐 Website Form (${c.entry_type || 'Form'})` : (c.created_by_name || '—'), 'fa-user-plus'],
    ['Added On', c.created_date || '—', 'fa-calendar-plus'],
    ['Purpose', c.purpose || '—', 'fa-bullseye'], ['Configuration', c.config || '—', 'fa-house'], ['Budget', c.budget || '—', 'fa-indian-rupee-sign'],
  ];
  // (emails may wrap only after "@" or a dot)
  const factHtml = ([l, v, ic, sub, cls]) => `<div class="lsum-item${cls ? ' ' + cls : ''}"><label><i class="fa-solid ${ic}"></i> ${esc(l)}</label><div>${cls === 'lsum-wide' ? esc(v).replace(/([@.])/g, '$1<wbr>') : esc(v)}${sub ? `<small>${esc(sub)}</small>` : ''}</div></div>`;
  // Lead came through a broker (any Broker* category, or broker fields
  // filled in regardless of category) → surface the broker's own contact
  // details too.
  const brokerDetails = (c.broker_name || c.broker_contact || c.broker_email) ? [
    ['Broker Name', c.broker_name || '—', 'fa-user-tie'], ['Broker Contact', c.broker_contact || '—', 'fa-phone'], ['Broker Email', c.broker_email || '—', 'fa-envelope', '', 'lsum-wide'],
  ] : [];

  // Scheduled visit of any type (Site Visit / Re-Visit / Negotiation Visit): was the calendar
  // invite emailed and the WhatsApp message sent?
  let visitRows = '';
  Object.values(VISIT_KINDS).forEach(k => {
    if (!isVisitKindStage(k, c.stage) || !c[k.date]) return;
    const slot = `${c[k.date]} ${(c[k.time] || '').slice(0, 5)}`;
    const [sentSlot, sentTo] = (c[k.inv + '_for'] || '').split('|');
    const sentAt = c[k.inv + '_sent_at'];
    let inviteHtml;
    if (sentAt) {
      inviteHtml = (sentSlot && sentSlot !== slot)
        ? `<span style="color:var(--warn)">⚠ Sent for an earlier date/time (${esc(fmtSentAt(sentAt))}) — resend</span>`
        : `<span style="color:var(--accent)">✓ Emailed to ${esc(sentTo || c.email || '')} · ${esc(fmtSentAt(sentAt))}</span>`;
    } else {
      inviteHtml = '<span style="color:var(--muted)">Not sent yet</span>';
    }
    const waHtml = c[k.wa] === 'Yes'
      ? '<span style="color:var(--accent)">✓ Message sent to client</span>'
      : '<span style="color:var(--muted)">Not sent yet</span>';
    visitRows += `<div class="lsum-item"><label><i class="fa-solid fa-location-dot"></i> Scheduled ${esc(k.label)}</label><div>${esc(c[k.date])}${c[k.time] ? ' at ' + esc(formatTime12(c[k.time])) : ''}</div></div>
        <div class="lsum-item"><label><i class="fa-solid fa-envelope-circle-check"></i> Calendar Invite (Email)</label><div>${inviteHtml}</div></div>
        <div class="lsum-item"><label><i class="fa-brands fa-whatsapp"></i> WhatsApp Visit Details</label><div>${waHtml}</div></div>`;
  });

  // Follow-up details — built from the followups table's own history for each
  // stage type (not the client's prefixed columns, which only ever hold the
  // latest occurrence): if a re-follow-up was spawned, the earlier completed
  // one is shown in green plus the still-pending re-follow-up date; once
  // there's no Pending row left for that type (both done), the block drops.
  let svfuRows = '';
  Object.entries(FOLLOWUP_STAGES).forEach(([name, cfg]) => {
    const rows = state.followups.filter(f => f.client_id === c.id && f.followup_type === cfg.type).sort((a, b) => a.id - b.id);
    if (!rows.length) return;
    const pending = rows.find(f => f.status === 'Pending');
    if (!pending) return; // follow-up and any re-follow-up are both done
    const prevDone = rows.filter(f => f.status === 'Completed').pop();

    const mainHtml = prevDone
      ? `<span style="color:var(--accent)">✓ ${esc(prevDone.followup_date)}${prevDone.mode ? ' via ' + esc(prevDone.mode) : ''} — Completed</span>`
      : `${esc(pending.followup_date)}${pending.mode ? ' via ' + esc(pending.mode) : ''}`;
    const reLabel = prevDone ? 'Re-follow-up Pending' : 'Re-follow-up Required';
    const reText = prevDone ? esc(pending.followup_date) : (pending.next_date ? `Yes — ${esc(pending.next_date)}` : 'No');

    svfuRows += `<div class="lsum-item"><label><i class="fa-solid fa-phone-volume"></i> ${esc(name)}</label><div>${mainHtml}</div></div>
        <div class="lsum-item"><label><i class="fa-solid fa-rotate"></i> ${reLabel}</label><div>${reText}</div></div>`;
  });

  // Unit Selection / Unit Blocked details — shown once the lead has reached that stage.
  let unitRows = '';
  const row = (ic, l, v) => v ? `<div class="lsum-item"><label><i class="fa-solid ${ic}"></i> ${esc(l)}</label><div>${esc(v)}</div></div>` : '';
  if (idx >= pipelineIndex('Unit Selection')) {
    const vl = villaListOf(c.villa);
    unitRows += row('fa-house-circle-check', vl.length > 1 ? `Villas Selected (${vl.length})` : 'Villa Selected', vl.join(', ')) + row('fa-tag', 'Offered Price', c.offered_price);
  }
  if (idx >= pipelineIndex('Unit Blocked')) {
    unitRows += row('fa-lock', 'Unit Blocked On', c.block_date) + row('fa-indian-rupee-sign', 'Token Amount', Number(c.token_amount) ? money(c.token_amount) : '');
  }
  const postSales = leadWithPostSales(c);
  if (IS_ACCOUNTS) {
    // Accounts desk: villa price + when Legal handed it over (no booking / legal details).
    unitRows += row('fa-tag', 'Final Villa Price', villaPriceText(c) || 'Not set')
      + row('fa-right-left', 'Transferred from Legal', c.accounts_handover_at ? fmtDay(String(c.accounts_handover_at).slice(0, 10)) : '');
  } else if (IS_POST_SALES) {
    // Post Sales desks: booking essentials always shown in the summary.
    unitRows += row('fa-tag', 'Final Villa Price', villaPriceText(c) || 'Not set')
      + row('fa-calendar-check', 'Booking Date', c.bookingdate || 'Not booked yet')
      + row('fa-money-bill', 'Booking Amount', Number(c.bookingamount) ? money(c.bookingamount) : 'Not set')
      + (Number(c.bookingamount) && Number(c.token_amount) ? row('fa-money-bill-transfer', 'Net Booking (after token)', money(netBookingAmount(c.bookingamount, c.token_amount))) : '')
      // when Sales handed it over (Manage Leads leaves the Transferred column out on laptops)
      + row('fa-right-left', 'Transferred from Sales', c.sales_handover_at ? fmtDay(String(c.sales_handover_at).slice(0, 10)) : '');
  } else {
    // Admin: villa price + Post Sales' booking date. Salespeople: only the date the booking was confirmed.
    if (IS_ADMIN && idx >= pipelineIndex('Booking Initiated')) unitRows += row('fa-tag', 'Final Villa Price', villaPriceText(c));
    if (postSales && IS_ADMIN) unitRows += row('fa-calendar-check', 'Booking Date', c.bookingdate || 'Not booked yet');
    else if (postSales && !IS_ENTRY_DESK) unitRows += row('fa-calendar-check', 'Booking Date', salesBookingDate(c) || 'Not confirmed yet');
  }
  // Post Sales progress at a glance (admin + Legal / Accounts desks) — only rows with a value show.
  // KYC onward sits in its own card below the villa / booking card (psRows) so neither gets crowded.
  let psRows = '';
  // Payment position (both Accounts desk and admin): received %, due now (+ pay-by / overdue), balance.
  const payRowsOf = () => {
    const price = villaPriceNum(c), rec = +c.received || 0, due = +c.dueamount || 0;
    const od = due > 0.5 && c.duedate && c.duedate < today();
    return row('fa-person-digging', 'Construction', [c.construction, c.construction_milestone ? c.construction_milestone + ' done' : ''].filter(Boolean).join(' · '))
      + row('fa-indian-rupee-sign', 'Amount Received', rec ? money(rec) + (price ? ` of ${money(price)} (${Math.round(rec / price * 1000) / 10}%)` : '') : '')
      + row('fa-hourglass-half', 'Payment Due Now', due > 0.5 ? money(due) + (c.duedate ? ` · ${od ? 'overdue since' : 'by'} ${fmtDay(c.duedate)}` : '') : (c.pay_demanded != null && c.pay_demanded !== '' ? 'Nothing due' : ''))
      + row('fa-scale-unbalanced', 'Balance to Receive', price && (rec || c.pay_demanded != null) ? money(Math.max(0, price - rec)) : '');
  };
  if (IS_ACCOUNTS) {
    psRows += payRowsOf()
      + row('fa-hourglass-half', 'Expected Possession', fmtDay(c.expected_possession_date))
      + row('fa-key', 'Handover Date', fmtDay(c.handover));
  } else if (CAN_POST_SALES && postSales) {
    if (!IS_POST_SALES && Number(c.bookingamount)) unitRows += row('fa-money-bill', 'Booking Amount', money(c.bookingamount));
    if (idx >= pipelineIndex('KYC Verification')) {
      const ks = c.kyc_status || 'Not set';
      psRows += row('fa-user-shield', 'KYC Status', ks + (ks === 'Verified' && c.kyc_verified_date ? ` — ${fmtDay(c.kyc_verified_date)}` : ''));
    }
    psRows += row('fa-circle-check', 'Booking Confirmed On', fmtDay(c.confirmation_date))
      + row('fa-file-signature', 'Agreement Date', fmtDay(c.agreement_date))
      + row('fa-stamp', 'Registered On', fmtDay(c.registration_date));
    // Legal desk stops at Registered On — construction / payment / handover are Accounts' details.
    if (!IS_LEGAL) psRows += (leadWithAccounts(c) ? payRowsOf() : '')
      + row('fa-key', 'Handover Date', fmtDay(c.handover));
  }
  // Admin / IT: when the lead changed desks — its own card, right under Current Stage.
  let transferRows = '';
  if (IS_ADMIN) {
    const whoOf = id => (state.users.find(u => String(u.id) === String(id)) || {}).name;
    const moved = (at, by) => at ? fmtDay(String(at).slice(0, 10)) + (whoOf(by) ? ` · by ${whoOf(by)}` : '') : '';
    transferRows = row('fa-flag-checkered', 'Sales → Legal', moved(c.sales_handover_at, c.sales_handover_by))
      + row('fa-right-left', 'Legal → Accounts', moved(c.accounts_handover_at, c.accounts_handover_by));
  }
  // Salespeople: a lead with Post Sales shows its full SALES history. Only Post Sales entries
  // (booking / documents / moves into Post Sales stages) and anything logged after the final
  // "Sales completed" handover are left out — nothing earlier is cut off.
  const salesView = !CAN_POST_SALES && postSales;
  if (salesView) {
    const isPostEntry = x => ['Booking Initiated', 'Document', ...POST_SALES_LOG_TYPES].includes(x.type) ||
      (x.type === 'Stage Change' && isPostSalesStage(String(x.note || '').replace(/^Moved to /, '')));
    let lastHandover = -1;
    log.forEach((x, i) => { if (x.type === 'Sales Handover' && /^Sales completed/.test(x.note || '')) lastHandover = i; });
    log = log.filter((x, i) => (lastHandover === -1 || i <= lastHandover) && !isPostEntry(x));
  }
  // Accounts desk: history starts at the Legal → Accounts transfer (who / when), then only what the
  // Accounts team has done since — the Sales and Booking & Legal history is left out.
  if (IS_ACCOUNTS) {
    const gen = log.find(x => x.type === 'Lead Generated');
    let lastAcc = -1;
    log.forEach((x, i) => { if (x.type === 'Accounts Handover' && /^Legal completed/.test(x.note || '')) lastAcc = i; });
    const ho = lastAcc > -1 ? log[lastAcc] : null;
    const byUser = (state.users.find(u => String(u.id) === String(c.accounts_handover_by)) || {}).name;
    const hoAt = String(c.accounts_handover_at || '');
    const transfer = {
      date: ho ? ho.date : hoAt.slice(0, 10), time: ho ? ho.time : hoAt.slice(11, 16), type: 'Accounts Handover',
      note: 'Transferred to Accounts' + (c.villa ? ` — ${c.villa}` : ''), by: (ho && ho.by) || byUser || 'Legal',
    };
    const accStages = PIPELINE_GROUPS.filter(g => ACCOUNTS_KEYS.includes(g.key)).flatMap(g => g.stages);
    // Calls placed with the Call button after the transfer belong to the Accounts history too.
    const isAccWork = x => accStages.includes(x.type) || (x.type === 'Call' && histTs(x) >= hoAt.slice(0, 16)) ||
      (x.type === 'Stage Change' && /^Moved to /.test(x.note || '') && isAccountsStage(String(x.note).replace(/^Moved to /, ''))) ||
      (x.type === 'Document' && / · Handover Completed$/.test(x.note || ''));
    const after = lastAcc > -1 ? log.slice(lastAcc + 1) : log.filter(isAccWork);
    log = [gen || null, (ho || hoAt) ? transfer : null, ...after.filter(x => x.type !== 'Lead Generated')].filter(Boolean);
  }
  // Legal desk: history starts fresh — Lead Generated, then who transferred it and when,
  // then only what's been done on the lead since the transfer (the Sales history is left out).
  if (IS_LEGAL) {
    const gen = log.find(x => x.type === 'Lead Generated');
    let lastHandover = -1;
    log.forEach((x, i) => { if (x.type === 'Sales Handover' && /^Sales completed/.test(x.note || '')) lastHandover = i; });
    const ho = lastHandover > -1 ? log[lastHandover] : null;
    const byUser = (state.users.find(u => String(u.id) === String(c.sales_handover_by)) || {}).name;
    const hoAt = String(c.sales_handover_at || '');
    // Whoever transferred the lead to Post Sales (the salesperson — or admin, if admin did it).
    const transferredBy = (ho && ho.by) || byUser || c.salesperson_name || 'Sales';
    const transfer = {
      date: ho ? ho.date : hoAt.slice(0, 10), time: ho ? ho.time : hoAt.slice(11, 16), type: 'Sales Handover',
      note: `Transferred to Post Sales` + (c.villa ? ` — ${c.villa}` : ''),
      by: transferredBy,
    };
    const isPostWork = x => ['Booking Initiated', 'Document', 'Note', 'Accounts Handover', ...POST_SALES_LOG_TYPES].includes(x.type) ||
      (x.type === 'Call' && histTs(x) >= hoAt.slice(0, 16)) || // calls placed after the sales → Post Sales transfer
      (x.type === 'Stage Change' && /^Moved to /.test(x.note || '') && isPostSalesStage(String(x.note).replace(/^Moved to /, ''))) ||
      (x.type === 'Stage Change' && /Cancelled|Closed/.test(x.note || ''));
    const after = lastHandover > -1 ? log.slice(lastHandover + 1) : log.filter(isPostWork);
    // The Lead Generated line credits the person who handed the lead over, not whoever keyed it in.
    const genLine = gen ? { ...gen, by: transferredBy } : null;
    log = [genLine, (ho || hoAt) ? transfer : null, ...after.filter(x => x.type !== 'Lead Generated')].filter(Boolean);
    // Once with Accounts, the history ends at the Legal → Accounts transfer — Accounts' own work
    // (construction stages, payments, demands, documents, possession) is left out.
    if (leadWithAccounts(c)) {
      const cut = historyCuts(log, c).acc;
      const accStage = s => !!s && isAccountsStage(String(s).trim());
      const isAccEntry = x => {
        const note = String(x.note || '');
        const mv = x.type === 'Stage Change' && note.match(/^Moved to (.+)$/);
        const doc = x.type === 'Document' && note.match(/ · ([^·()]+)$/);
        if ((mv && accStage(mv[1])) || (doc && accStage(doc[1])) || (PIPELINE_STAGES.includes(x.type) && accStage(x.type))) return true;
        return !!cut && histTs(x) > cut;
      };
      log = log.filter(x => !isAccEntry(x));
    }
  }

  // Admin / IT: one card per desk — Sales, Legal, Accounts — each with its own details on a 4-column grid,
  // and who passed the lead on (and when) in that card's header, instead of one mixed card.
  let deskCards = '';
  if (IS_ADMIN) {
    const item = (ic, l, v, sub) => v ? `<div class="lsum-item"><label><i class="fa-solid ${ic}"></i> ${esc(l)}</label><div>${esc(v)}${sub ? `<small>${esc(sub)}</small>` : ''}</div></div>` : '';
    const whoOf = id => (state.users.find(u => String(u.id) === String(id)) || {}).name;
    const fromNote = (desk, at, by) => at ? `From ${desk} · ${fmtDay(String(at).slice(0, 10))}${whoOf(by) ? ` · by ${whoOf(by)}` : ''}` : '';
    const card = (key, icon, title, note, body) => body ? `<div class="ls-desk ls-desk-${key}">
        <div class="ls-desk-head"><span><i class="fa-solid ${icon}"></i> ${title}</span>${note ? `<em>${esc(note)}</em>` : ''}</div>
        <div class="ls-desk-grid">${body}</div></div>` : '';
    // Sales: visits / follow-ups while they're pending, then the unit picked and blocked.
    const vl = villaListOf(c.villa);
    const salesBody = (postSales ? '' : visitRows + svfuRows)
      + (idx >= pipelineIndex('Unit Selection') ? item('fa-house-circle-check', vl.length > 1 ? `Villas Selected (${vl.length})` : 'Villa Selected', vl.join(', ')) + item('fa-tag', 'Offered Price', c.offered_price) : '')
      + (idx >= pipelineIndex('Unit Blocked') ? item('fa-lock', 'Unit Blocked On', fmtDay(c.block_date)) + item('fa-indian-rupee-sign', 'Token Amount', Number(c.token_amount) ? money(c.token_amount) : '') : '')
      + (!postSales ? item('fa-calendar-days', 'Next Follow-up', fmtDay(c.nextfollow_date)) : '');
    deskCards += card('sales', 'fa-user-tie', 'Sales', '', salesBody);
    // Legal: booking → registration.
    if (legalDeskScope(c)) {
      const ks = c.kyc_status || 'Not set';
      deskCards += card('legal', 'fa-file-contract', 'Legal', fromNote('Sales', c.sales_handover_at, c.sales_handover_by),
        item('fa-tag', 'Final Villa Price', villaPriceText(c))
        + item('fa-money-bill', 'Booking Amount', Number(c.bookingamount) ? money(c.bookingamount) : '')
        + item('fa-calendar-check', 'Booking Date', fmtDay(c.bookingdate) || 'Not booked yet')
        + (idx >= pipelineIndex('KYC Verification') ? item('fa-user-shield', 'KYC Status', ks, ks === 'Verified' ? fmtDay(c.kyc_verified_date) : '') : '')
        + item('fa-circle-check', 'Booking Confirmed On', fmtDay(c.confirmation_date))
        + item('fa-file-signature', 'Agreement Date', fmtDay(c.agreement_date))
        + item('fa-stamp', 'Registered On', fmtDay(c.registration_date)));
    }
    // Accounts: construction + payment position (on the Final Villa Price), then possession once it's set.
    if (accountsDeskScope(c)) {
      const price = villaPriceNum(c), rec = +c.received || 0, due = +c.dueamount || 0;
      const od = due > 0.5 && c.duedate && c.duedate < today();
      const demanded = c.pay_demanded != null && c.pay_demanded !== '';
      const pct = v => price ? `${Math.round(v / price * 1000) / 10}% of villa price` : '';
      deskCards += card('accounts', 'fa-indian-rupee-sign', 'Accounts', fromNote('Legal', c.accounts_handover_at, c.accounts_handover_by),
        item('fa-person-digging', 'Construction', c.construction_milestone ? `${c.construction_milestone} done` : (c.construction || 'Not started'))
        + item('fa-circle-check', 'Amount Received', rec ? money(rec) : (demanded ? '₹0' : ''), rec ? pct(rec) : '')
        + item('fa-hourglass-half', 'Payment Due Now', due > 0.5 ? money(due) : (demanded ? 'Nothing due' : ''),
          due > 0.5 && c.duedate ? `${od ? 'Overdue since' : 'By'} ${fmtDay(c.duedate)}` : '')
        + item('fa-scale-unbalanced', 'Balance to Receive', price && (rec || demanded) ? money(Math.max(0, price - rec)) : '')
        + item('fa-calendar-day', 'Expected Possession', fmtDay(c.expected_possession_date))
        + item('fa-key', 'Handover Date', fmtDay(c.handover)));
    }
  }
  // Legal desk: once with Accounts the lead reads "Registered · Legal completed" — no Accounts stage names.
  const legalDone = IS_LEGAL && leadWithAccounts(c);
  document.getElementById('leadSummaryBody').innerHTML = `
    <div class="ls-header">
      <div class="ls-avatar"><i class="fa-solid fa-user"></i></div>
      <div><p class="ls-name">${esc(c.name)}</p><p class="ls-sub"><b>${esc(c.category || '—')}</b> · <b>${esc(c.lead_code || ('Lead #' + c.id))}</b></p></div>
    </div>
    <div class="ls-body">
      <div class="ls-list ls-facts">
        ${details.map(factHtml).join('')}
        <div class="lsum-item"><label><i class="fa-solid fa-fire"></i> Interest</label><div>${badge(c.interest)}</div></div>
      </div>
      ${brokerDetails.length ? `<div class="ls-list ls-facts">
        ${brokerDetails.map(factHtml).join('')}
      </div>` : ''}
      <div class="ls-stagebar">
        <div class="ls-stage-col"><div class="lbl">Current Stage</div><div style="font-size:13.5px;font-weight:700;color:var(--ink)">${IS_ENTRY_DESK && postSales ? 'Post Sales' : IS_ACCOUNTS && leadAccountsHandedOver(c) ? 'New from Legal' : legalDone ? 'Registered' : esc(c.stage)}${leadHandedOver(c) ? ' · Sales completed' : ''}${(leadAccountsHandedOver(c) || legalDone) && !IS_ACCOUNTS && CAN_POST_SALES ? ' · Legal completed' : ''}${IS_LEGAL && leadWithAccounts(c) ? '<div style="font-size:11px;font-weight:600;color:var(--muted);margin-top:2px">With the Accounts team</div>' : ''}${salesView ? '<div style="font-size:11px;font-weight:600;color:var(--muted);margin-top:2px">With the Post Sales team</div>' : ''}</div></div>
        <div class="ls-stage-arrow"><i class="fa-solid fa-arrow-right-long"></i></div>
        <div class="ls-stage-col"><div class="lbl">Next Up</div><div style="font-size:13.5px;font-weight:700;color:var(--accent)">${IS_ENTRY_DESK && postSales ? 'Handled by Post Sales team' : legalDone ? 'Handled by the Accounts team' : (nextStage ? esc(nextStage) : 'Final stage') + (postSales && nextStage && !CAN_POST_SALES ? ' (Post Sales)' : '') + (nextStage && isAccountsStage(nextStage) && (IS_LEGAL || IS_ADMIN) ? ' (Accounts)' : '') + (IS_ADMIN && nextStage && stageGroupKey(nextStage) === 'book' ? ' (Legal)' : '')}</div></div>
      </div>
      ${IS_ADMIN ? deskCards : ''}
      ${!IS_ADMIN && (visitRows || svfuRows || unitRows || c.nextfollow_date) ? `<div class="ls-list">
        ${IS_POST_SALES ? '' : visitRows}
        ${IS_POST_SALES ? '' : svfuRows}
        ${unitRows}
        ${c.nextfollow_date && !IS_POST_SALES ? `<div class="lsum-item"><label><i class="fa-solid fa-calendar-days"></i> Next Follow-up</label><div>${esc(c.nextfollow_date)}</div></div>` : ''}
      </div>` : ''}
      ${psRows && !IS_ADMIN ? `<div class="ls-list">${psRows}</div>` : ''}
      ${`<div class="ls-history-title"><i class="fa-solid fa-clock-rotate-left"></i> ${salesView ? 'Sales History' : IS_ACCOUNTS ? 'Accounts History' : IS_POST_SALES ? 'Post Sales History' : IS_ADMIN ? 'Full Lead History — Sales · Legal · Accounts' : 'Lead History'}</div>
      <div class="ls-history-list attempt-log-list">
        ${renderHistorySections(log, c.stage, IS_ADMIN, c)}
      </div>`}
      <div class="ls-footer">
        ${telNumber(leadPhone(c)) ? `<button type="button" class="btn call-btn" title="Call ${esc(leadPhone(c))}" onclick="callLead(${c.id})"><i class="fa-solid fa-phone"></i> Call</button>` : ''}
        <button type="button" class="btn" onclick="closeModal('leadSummaryModal')">Close</button>
        <button type="button" class="btn primary" onclick="closeModal('leadSummaryModal');openClient(${c.id})">${salesView || IS_ENTRY_DESK || (IS_LEGAL && leadWithAccounts(c)) ? '<i class="fa-solid fa-eye"></i> View Lead' : '<i class="fa-solid fa-pen-to-square"></i> Edit Lead'}</button>
      </div>
    </div>`;
  openModal('leadSummaryModal');
}

// Manage Leads stage filter dropdown — populated from ALL_STAGES so it can
// never drift out of sync with the controlled stage list above.
(function populateStageFilter() {
  const sel = document.getElementById('stageFilter');
  if (!sel) return;
  // Legal desk: its own stages only — every lead past Registered is one "Transferred to Accounts" option.
  const stages = IS_ACCOUNTS ? ['Registered', ...ALL_STAGES.filter(s => isAccountsStage(s) || s === 'Closed')]
    : IS_LEGAL ? ['Unit Blocked', ...ALL_STAGES.filter(s => stageGroupKey(s) === 'book'), '__accounts', 'Closed', 'Cancelled']
    : IS_POST_SALES ? ['Unit Blocked', ...ALL_STAGES.filter(s => isPostSalesStage(s) || s === 'Closed' || s === 'Cancelled')]
    : IS_ADMIN ? ['__sales', '__legal', '__accounts', ...ALL_STAGES] : ALL_STAGES;
  // Accounts desk: a lead Legal just handed over (still "Registered") reads "New from Legal".
  const label = s => IS_ACCOUNTS && s === 'Registered' ? 'New from Legal (construction pending)'
    : IS_LEGAL && s === 'Unit Blocked' ? 'Booking Pending (Unit Blocked)'
    : IS_LEGAL && s === 'Registered' ? 'Registered — transfer pending'
    : IS_ADMIN && s === '__sales' ? 'Sales desk — all sales stages'
    : IS_ADMIN && s === '__legal' ? 'Legal desk — Booking pending → Registered'
    : IS_ADMIN && s === '__accounts' ? 'Accounts desk — Construction → Possession'
    : s === '__accounts' ? 'Transferred to Accounts' : s;
  sel.innerHTML = '<option value="">All Clients</option>' +
    stages.map(s => `<option value="${esc(s)}">${esc(label(s))}</option>`).join('');
})();

async function api(path, method = 'GET', body = null) {
  const opts = { method, headers: { 'Content-Type': 'application/json' } };
  if (body) opts.body = JSON.stringify(body);
  const res = await fetch('api/' + path, opts);
  if (res.status === 401) { window.location.href = 'login.php'; return null; }
  try {
    return await res.json();
  } catch (err) {
    // Server returned something that isn't JSON (a PHP fatal error page,
    // usually) — surface that as a real error instead of failing the
    // fetch silently, which used to look like "Save" just did nothing.
    return { error: `Server error (HTTP ${res.status}) — the save did not go through. Check the server error log.` };
  }
}

async function logout() {
  await api('logout.php', 'POST');
  window.location.href = 'login.php';
}

function setAvatarUI(photoUrl) {
  const wrap = document.querySelector('.avatar-wrap');
  const existingImg = document.getElementById('avatarImg');
  const existingIcon = document.getElementById('avatarIcon');
  const cropDelete = document.getElementById('cropDelete');

  if (photoUrl) {
    const img = existingImg || document.createElement('img');
    img.id = 'avatarImg';
    img.src = photoUrl + '?t=' + Date.now();
    img.alt = 'Profile photo';
    if (existingIcon) existingIcon.replaceWith(img);
    else if (!existingImg) wrap.prepend(img);
    cropDelete?.classList.remove('hidden');
  } else {
    const icon = existingIcon || document.createElement('i');
    icon.id = 'avatarIcon';
    icon.className = 'fa-solid fa-circle-user';
    if (existingImg) existingImg.replaceWith(icon);
    else if (!existingIcon) wrap.prepend(icon);
    cropDelete?.classList.add('hidden');
  }
}

// Clicking the small sidebar avatar: view the photo (WhatsApp-style) if one
// exists, otherwise go straight to picking a file.
document.getElementById('avatarWrap')?.addEventListener('click', () => {
  if (document.getElementById('avatarImg')) openPhotoViewer();
  else document.getElementById('avatarInput').click();
});

function openPhotoViewer() {
  const src = document.getElementById('avatarImg')?.src;
  if (!src) return;
  document.getElementById('viewerImg').src = src;
  document.getElementById('photoViewer').classList.remove('hidden');
}

function closePhotoViewer() {
  document.getElementById('photoViewer').classList.add('hidden');
}

function openEditFromViewer() {
  const src = document.getElementById('viewerImg').src;
  closePhotoViewer();
  openCropper(src);
}

// ---------- Avatar cropper (WhatsApp/Instagram-style: full image visible, drag to reposition, slider to zoom, dark spotlight mask outside the circular selection) ----------
let cropState = null;

function onAvatarFileSelected(file) {
  if (!file) return;
  const reader = new FileReader();
  reader.onload = () => openCropper(reader.result);
  reader.readAsDataURL(file);
}

function openCropper(dataUrl) {
  const img = document.getElementById('cropImg');
  const square = document.getElementById('cropCircle');
  const zoomSlider = document.getElementById('cropZoom');
  zoomSlider.value = 100;

  // Show the modal FIRST — measuring .crop-mask while the overlay is still
  // "hidden" (display:none) returns offsetWidth 0, which zeroed out the
  // zoom scale and rendered the photo invisible (the reported black-circle bug).
  document.getElementById('cropOverlay').classList.remove('hidden');

  img.onload = () => {
    const maskSize = document.querySelector('.crop-mask').offsetWidth;
    const baseScale = maskSize / Math.min(img.naturalWidth, img.naturalHeight);
    cropState = { baseScale, offsetX: 0, offsetY: 0, zoom: 1 };
    img.style.width = img.naturalWidth + 'px';
    img.style.height = img.naturalHeight + 'px';
    img.style.marginLeft = (-img.naturalWidth / 2) + 'px';
    img.style.marginTop = (-img.naturalHeight / 2) + 'px';
    img.style.left = '50%';
    img.style.top = '50%';
    applyCropTransform();
  };
  img.src = dataUrl;

  let dragging = false, startX = 0, startY = 0, startOffX = 0, startOffY = 0;
  // Pointer events = mouse, finger and pen alike, so the photo can be dragged on phones / tablets too
  square.onpointerdown = (e) => {
    if (!cropState) return;
    e.preventDefault();
    dragging = true; startX = e.clientX; startY = e.clientY;
    startOffX = cropState.offsetX; startOffY = cropState.offsetY;
  };
  window.onpointermove = (e) => {
    if (!dragging || !cropState) return;
    cropState.offsetX = startOffX + (e.clientX - startX);
    cropState.offsetY = startOffY + (e.clientY - startY);
    applyCropTransform();
  };
  window.onpointerup = window.onpointercancel = () => { dragging = false; };

  zoomSlider.oninput = () => {
    cropState.zoom = zoomSlider.value / 100;
    applyCropTransform();
  };
}

function applyCropTransform() {
  const img = document.getElementById('cropImg');
  const scale = cropState.baseScale * cropState.zoom;
  img.style.transform = `translate(${cropState.offsetX}px, ${cropState.offsetY}px) scale(${scale})`;
}

function closeCropper() {
  document.getElementById('cropOverlay').classList.add('hidden');
  document.getElementById('avatarInput').value = '';
  cropState = null;
}

async function saveCroppedAvatar() {
  const img = document.getElementById('cropImg');
  const mask = document.querySelector('.crop-mask');
  const imgRect = img.getBoundingClientRect();
  const maskRect = mask.getBoundingClientRect(); // the real circular selection area
  const scaleX = img.naturalWidth / imgRect.width;
  const scaleY = img.naturalHeight / imgRect.height;

  const sx = (maskRect.left - imgRect.left) * scaleX;
  const sy = (maskRect.top - imgRect.top) * scaleY;
  const sw = maskRect.width * scaleX;
  const sh = maskRect.height * scaleY;

  const canvas = document.createElement('canvas');
  canvas.width = 400;
  canvas.height = 400;
  canvas.getContext('2d').drawImage(img, sx, sy, sw, sh, 0, 0, 400, 400);

  canvas.toBlob(async (blob) => {
    const ok = await uploadAvatar(blob);
    if (ok) closeCropper();
  }, 'image/jpeg', 0.92);
}

async function uploadAvatar(fileOrBlob) {
  if (!fileOrBlob) return false;
  const fd = new FormData();
  fd.append('photo', fileOrBlob, 'avatar.jpg');
  try {
    const res = await fetch('api/profile_photo.php', { method: 'POST', body: fd });
    if (res.status === 401) { window.location.href = 'login.php'; return false; }
    const data = await res.json();
    if (data?.error) { alert(data.error); return false; }
    setAvatarUI(data.photo);
    return true;
  } catch (err) {
    alert('Photo upload failed — the server did not return a valid response. Check that migration_v8.sql has been run and check the server error log.');
    return false;
  }
}

async function deleteAvatar(e) {
  e.stopPropagation();
  e.preventDefault();
  if (!confirm('Remove your profile photo?')) return;
  const res = await fetch('api/profile_photo.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'delete' })
  });
  if (res.status === 401) { window.location.href = 'login.php'; return; }
  const data = await res.json();
  if (data?.error) { alert(data.error); return; }
  setAvatarUI(null);
  closeCropper();
  closePhotoViewer();
}

function nav(page) {
  document.querySelectorAll('.page').forEach(x => x.classList.add('hidden'));
  document.getElementById(page).classList.remove('hidden');
  document.querySelectorAll('.nav button').forEach(x => x.classList.toggle('active', x.dataset.page === page));
  renderAll();
  if (page === 'usermgmt') loadUserMgmt();
  if (page === 'notiflog') renderNotifLog();
  if (page === 'villas') renderVillas();
}
document.querySelectorAll('.nav button').forEach(b => b.onclick = () => nav(b.dataset.page));

async function loadAll() {
  const [clients, followups, visits, users, sourceLeads, leadRequests, assignmentLog, villas] = await Promise.all([
    api('clients.php'), api('followups.php'), api('visits.php'), api('users.php'),
    canManageSourceLeads() ? api('source_leads.php') : Promise.resolve([]),
    api('lead_requests.php'), api('assignment_log.php'), api('villas.php')
  ]);
  state.clients = clients || [];
  state.followups = followups || [];
  state.visits = visits || [];
  state.users = users || [];
  state.sourceLeads = Array.isArray(sourceLeads) ? sourceLeads : [];
  state.leadRequests = Array.isArray(leadRequests) ? leadRequests : [];
  state.assignmentLog = Array.isArray(assignmentLog) ? assignmentLog : [];
  state.villas = Array.isArray(villas) ? villas : [];
  renderAll();
}

// ---------- Villa Inventory ----------
let villaFilter = 'all';
function filterVillas(f, btn) {
  villaFilter = f;
  document.querySelectorAll('[data-villafilter]').forEach(b => b.classList.toggle('active', b === btn));
  renderVillas();
}
function renderVillas() {
  const tbody = document.getElementById('villaTable');
  if (!tbody) return;
  const all = state.villas || [];
  document.getElementById('vCountAll').textContent = all.length;
  ['available', 'negotiation', 'blocked', 'booked', 'sold'].forEach(st => {
    const el = document.getElementById('vCount' + st.charAt(0).toUpperCase() + st.slice(1));
    if (el) el.textContent = all.filter(v => v.status === st).length;
  });

  // Fixed order by serial: A1-01, A1-02, A2-01 … A10-01 (natural/numeric sort).
  const list = all.filter(v => villaFilter === 'all' || v.status === villaFilter)
    .sort((a, b) => a.serial.localeCompare(b.serial, undefined, { numeric: true }));

  // Admin + sales team can update status; Entry Desk and the Legal / Accounts desks are view-only
  // (their stage moves update the inventory automatically).
  const isAdmin = !IS_ENTRY_DESK && !IS_POST_SALES;
  document.getElementById('villaAdminCol').style.display = isAdmin ? '' : 'none';

  const statusBadge = s => {
    const map = { available: 'green', blocked: 'warm', negotiation: 'warm', booked: 'hot', sold: 'hot' };
    return `<span class="badge ${map[s] || ''}">${s.charAt(0).toUpperCase() + s.slice(1)}</span>`;
  };

  tbody.innerHTML = list.map(v => `
    <tr>
      <td><b>${esc(v.name)}</b></td>
      <td>${esc(v.serial)}</td>
      <td>${esc(v.villa_type.toUpperCase())}</td>
      <td>${statusBadge(v.status)}</td>
      <td>${v.linked_client_id ? `${esc(v.client_name)} (${esc(v.lead_code || '')})` : (v.status === 'available' ? '—' : '<span style="color:var(--muted)">No CRM record</span>')}</td>
      <td ${isAdmin ? '' : 'style="display:none"'}>
        <select onchange="updateVillaStatus(${v.id}, this.value)">
          <option value="available" ${v.status === 'available' ? 'selected' : ''}>Available</option>
          <option value="negotiation" ${v.status === 'negotiation' ? 'selected' : ''}>Negotiation</option>
          <option value="blocked" ${v.status === 'blocked' ? 'selected' : ''}>Blocked</option>
          <option value="booked" ${v.status === 'booked' ? 'selected' : ''}>Booked</option>
          <option value="sold" ${v.status === 'sold' ? 'selected' : ''}>Sold</option>
        </select>
      </td>
    </tr>
  `).join('') || '<tr><td colspan="6" style="text-align:center;color:var(--muted)">No villas match this filter.</td></tr>';
}
async function updateVillaStatus(id, status) {
  if (IS_ENTRY_DESK || IS_POST_SALES) return; // view-only desks
  // Manual admin override (e.g. correcting a mistake, or backfilling a
  // pre-CRM sale with no lead attached). Setting back to "available"
  // clears any linked lead; other statuses keep whatever lead was linked.
  const villa = (state.villas || []).find(v => v.id === id);
  const linked_client_id = status === 'available' ? null : (villa?.linked_client_id ?? null);
  const result = await api('villas.php', 'POST', { id, status, linked_client_id });
  if (!result || result.error) { alert((result && result.error) || 'Update failed.'); await loadAll(); return; }
  await loadAll();
  nav('villas');
}


// ---------- Quick Add (role-specific "+ Add Lead") ----------
function openQuickAdd() {
  if (IS_POST_SALES) return; // Post Sales desks don't add leads
  const u = window.CURRENT_USER;
  // Broker desk and Owner desk salespeople both handle either type of
  // lead day-to-day, so they get the same full picker as admin (Enquiry /
  // Broker Entry / Site Visit / Manual). Whichever form they use, the
  // lead still lands on their own desk — resolve_salesperson() on the
  // backend already self-assigns to the logged-in sales user regardless
  // of form type. Entry desk stays locked to the Enquiry form, since
  // that's their one job.
  if (isAdminRole(u.role) || !u.desk || u.desk === 'broker' || u.desk === 'owner') { openModal('quickPickerModal'); return; }
  if (u.desk === 'entry') return openQuickForm('enquiry');
  openModal('quickPickerModal'); // fallback
}
function openQuickForm(type) {
  const map = { enquiry: 'quickEnquiryForm', broker: 'quickBrokerForm', newlead: 'quickNewLeadForm', walkin: 'quickWalkinForm' };
  const modalMap = { enquiry: 'quickEnquiryModal', broker: 'quickBrokerModal', newlead: 'quickNewLeadModal', walkin: 'quickWalkinModal' };
  if (type === 'sitevisit') type = 'walkin'; // old name
  document.getElementById(map[type]).reset();
  if (type === 'walkin') {
    const wf = document.getElementById('quickWalkinForm');
    wf.elements.date.value = today();
    wf.elements.time_in.value = nowTime();
    initVillaPickers(); // same Villa Shown dropdown as Edit Client (options come live from Villa Inventory)
  }
  openModal(modalMap[type]);
}
const DUPLICATE_ALERTS_KEY = 'crm_duplicate_alerts';
function getDuplicateAlerts() {
  try { return JSON.parse(localStorage.getItem(DUPLICATE_ALERTS_KEY) || '[]'); } catch { return []; }
}
function saveDuplicateAlerts(list) {
  try { localStorage.setItem(DUPLICATE_ALERTS_KEY, JSON.stringify(list)); } catch { /* ignore */ }
}
function addDuplicateAlert(id, leadCode, name, salespersonId, salespersonName) {
  const list = getDuplicateAlerts().filter(a => String(a.id) !== String(id)); // no repeats for the same lead
  list.unshift({ id, leadCode: leadCode || id, name, salespersonId, salespersonName, ts: Date.now() });
  saveDuplicateAlerts(list);
}
function dismissDuplicateAlert(id) {
  saveDuplicateAlerts(getDuplicateAlerts().filter(a => String(a.id) !== String(id)));
  renderDuplicateAlerts();
}
function findDuplicateLead(id, leadCode, name) {
  dismissDuplicateAlert(id);
  nav('leads');
  const box = document.getElementById('leadSearch');
  if (box) { box.value = leadCode || name; renderLeads(); }
}
// True when the duplicate belongs to the OTHER sales desk than the person
// who just tried to add it — that's when "Find Lead" would fail (it isn't
// in their own Manage Leads view) and a transfer request is the real fix.
function isOtherDeskLead(a) {
  const u = window.CURRENT_USER;
  return (u.desk === 'broker' || u.desk === 'owner') && a.salespersonId && String(a.salespersonId) !== String(u.id);
}
async function requestDuplicateLead(id) {
  const result = await api('lead_requests.php', 'POST', { action: 'create', client_id: id });
  if (result && result.error) { alert(result.error); return; }
  dismissDuplicateAlert(id);
  alert(result && result.already_pending ? 'You already have a pending request for this lead.' : 'Request sent — waiting for confirmation.');
  await loadAll();
}
function renderDuplicateAlerts() {
  const box = document.getElementById('duplicateAlerts');
  if (!box) return;
  const list = getDuplicateAlerts();
  box.innerHTML = list.length ? list.map(a => isOtherDeskLead(a) ? `
    <div style="display:flex;align-items:center;gap:10px;background:#fff7ed;border:1px solid #fdba74;border-radius:8px;padding:10px 14px;margin-bottom:8px">
      <span style="flex:1;font-size:13px">⚠ <b>${esc(a.name)}</b> (Lead ID: ${esc(a.leadCode)}) already exists — this lead belongs to <b>${esc(a.salespersonName || 'another')}'s</b> desk.</span>
      <button class="btn primary" onclick="requestDuplicateLead('${a.id}')">Request Lead</button>
      <button class="btn" onclick="dismissDuplicateAlert('${a.id}')">Cancel</button>
    </div>
  ` : `
    <div style="display:flex;align-items:center;gap:10px;background:#fff7ed;border:1px solid #fdba74;border-radius:8px;padding:10px 14px;margin-bottom:8px">
      <span style="flex:1;font-size:13px">⚠ Duplicate attempt — <b>${esc(a.name)}</b> (Lead ID: ${esc(a.leadCode)}) already exists.</span>
      <button class="btn" onclick="findDuplicateLead('${a.id}', '${jsAttr(a.leadCode)}', '${jsAttr(a.name)}')">Find Lead</button>
      <button class="btn" onclick="dismissDuplicateAlert('${a.id}')">Dismiss</button>
    </div>
  `).join('') : '';
}

// ---------- Cross-desk lead transfer requests ----------
function renderLeadRequests() {
  const box = document.getElementById('leadRequestAlerts');
  if (!box) return;
  const u = window.CURRENT_USER;
  const incoming = state.leadRequests.filter(r => String(r.to_user_id) === String(u.id));
  const outgoing = state.leadRequests.filter(r => String(r.from_user_id) === String(u.id));
  box.innerHTML = [
    ...incoming.map(r => `
      <div style="display:flex;align-items:center;gap:10px;background:#eef6ff;border:1px solid #93c5fd;border-radius:8px;padding:10px 14px;margin-bottom:8px">
        <span style="flex:1;font-size:13px">📩 <b>${esc(r.from_name)}</b> requested lead <b>${esc(r.client_name)}</b> (${esc(r.lead_code)}) from your desk.</span>
        <button class="btn primary" onclick="respondLeadRequest(${r.id}, 'confirm')">Confirm</button>
        <button class="btn" onclick="respondLeadRequest(${r.id}, 'reject')">Reject</button>
      </div>`),
    ...outgoing.map(r => `
      <div style="display:flex;align-items:center;gap:10px;background:#f4f4f4;border:1px solid #ddd;border-radius:8px;padding:10px 14px;margin-bottom:8px">
        <span style="flex:1;font-size:13px">⏳ Waiting for <b>${esc(r.to_name)}</b> to confirm lead <b>${esc(r.client_name)}</b> (${esc(r.lead_code)}).</span>
      </div>`)
  ].join('');
}
async function respondLeadRequest(requestId, decision) {
  const result = await api('lead_requests.php', 'POST', { action: 'respond', request_id: requestId, decision });
  if (result && result.error) { alert(result.error); return; }
  await loadAll();
}

// ---------- Notification bell (mobile-style: stays until cleared) ----------
const NOTIF_CLEARED_KEY = 'crm_notif_cleared';
function getClearedNotifs() {
  try { return JSON.parse(localStorage.getItem(NOTIF_CLEARED_KEY) || '[]'); } catch { return []; }
}
function saveClearedNotifs(list) {
  try { localStorage.setItem(NOTIF_CLEARED_KEY, JSON.stringify(list)); } catch { /* ignore */ }
}
function toggleNotifPanel() {
  const p = document.getElementById('notifPanel');
  if (!p) return;
  p.style.display = p.style.display === 'none' ? 'flex' : 'none';
}
document.addEventListener('click', (e) => {
  const wrap = document.querySelector('.notif-wrap');
  const panel = document.getElementById('notifPanel');
  if (wrap && panel && panel.style.display !== 'none' && !wrap.contains(e.target)) panel.style.display = 'none';
});

// One-time "baseline" of ids that existed before the notification feature
// started watching a list — so new-item alerts (source leads, site visits)
// only fire for things that show up AFTER this point, not for the entire
// existing backlog on first load in this browser.
function ensureBaseline(storageKey, currentIds) {
  if (localStorage.getItem(storageKey) === null) {
    try { localStorage.setItem(storageKey, JSON.stringify(currentIds)); } catch { /* ignore */ }
  }
}
function isBaseline(storageKey, id) {
  try { return (JSON.parse(localStorage.getItem(storageKey) || '[]')).includes(id); } catch { return false; }
}

function buildNotifItems() {
  const u = window.CURRENT_USER;
  const cleared = getClearedNotifs();
  const items = [];

  // 1. Cross-desk lead transfer requests (ask/waiting/outcome).
  state.leadRequests.forEach(r => {
    const key = `req_${r.id}_${r.status}`;
    if (cleared.includes(key)) return;
    if (r.status === 'Pending' && String(r.to_user_id) === String(u.id)) {
      items.push({
        key, ts: r.created_at, secAgo: r.created_ago, icon: 'fa-inbox', color: '#175cd3',
        html: `<b>${esc(r.from_name)}</b> requested lead <b>${esc(r.client_name)}</b> (${esc(r.lead_code)}) from your desk.`,
        actions: `<button class="btn primary" onclick="event.stopPropagation();respondLeadRequest(${r.id},'confirm');toggleNotifPanel()">Accept</button>
                  <button class="btn" onclick="event.stopPropagation();respondLeadRequest(${r.id},'reject');toggleNotifPanel()">Reject</button>`
      });
    } else if (r.status === 'Pending' && String(r.from_user_id) === String(u.id)) {
      items.push({
        key, ts: r.created_at, secAgo: r.created_ago, icon: 'fa-hourglass-half', color: '#b54708',
        html: `Waiting for <b>${esc(r.to_name)}</b> to confirm lead <b>${esc(r.client_name)}</b> (${esc(r.lead_code)}).`
      });
    } else if (r.status !== 'Pending' && String(r.from_user_id) === String(u.id)) {
      const ok = r.status === 'Confirmed';
      items.push({
        key, ts: r.resolved_at || r.created_at, secAgo: r.resolved_ago != null ? r.resolved_ago : r.created_ago,
        icon: ok ? 'fa-circle-check' : 'fa-circle-xmark', color: ok ? '#16940a' : '#cf180b',
        html: `<b>${esc(r.to_name)}</b> ${ok ? 'accepted' : 'rejected'} your request for lead <b>${esc(r.client_name)}</b> (${esc(r.lead_code)}).`
      });
    }
  });

  // 2. Direct lead assignments ("X assigned lead Y to you") — via Reassign
  // or a new lead handed straight to you. Clicking opens that lead.
  (state.assignmentLog || []).forEach(a => {
    const key = `asg_${a.id}`;
    if (cleared.includes(key)) return;
    if (a.type === 'post_sales_transfer') {
      items.push({
        key, ts: a.created_at, secAgo: a.seconds_ago, icon: 'fa-right-left', color: '#16940a',
        html: `<b>${esc(a.from_name || 'Sales')}</b> transferred <b>${esc(a.client_name)}</b> (${esc(a.lead_code)}) to Post Sales${a.label ? ` — ${esc(a.label)}` : ''}. Booking pending.`,
        onclick: `openClient(${a.client_id});toggleNotifPanel()`,
        assignmentLogId: a.id
      });
      return;
    }
    if (a.type === 'accounts_transfer') {
      items.push({
        key, ts: a.created_at, secAgo: a.seconds_ago, icon: 'fa-right-left', color: '#0e7490',
        html: `<b>${esc(a.from_name || 'Legal')}</b> transferred <b>${esc(a.client_name)}</b> (${esc(a.lead_code)}) to Accounts${a.label ? ` — ${esc(a.label)}` : ''}. Construction pending.`,
        onclick: `openClient(${a.client_id});toggleNotifPanel()`,
        assignmentLogId: a.id
      });
      return;
    }
    if (a.type === 'booking_cancelled') {
      items.push({
        key, ts: a.created_at, secAgo: a.seconds_ago, icon: 'fa-ban', color: '#cf180b',
        html: `<b>${esc(a.from_name || 'Post Sales')}</b> cancelled the booking of <b>${esc(a.client_name)}</b> (${esc(a.lead_code)})${a.label ? ` — ${esc(a.label)}` : ''}.`,
        onclick: `openLeadSummary(${a.client_id});toggleNotifPanel()`,
        assignmentLogId: a.id
      });
      return;
    }
    if (a.type === 'reschedule_proposed') {
      items.push({
        key, ts: a.created_at, secAgo: a.seconds_ago, icon: 'fa-calendar-days', color: '#b54708',
        html: `<b>${esc(a.from_name || 'Someone')}</b> logged that <b>${esc(a.client_name)}</b> (${esc(a.lead_code)}) proposed a new time — ${esc(a.label)}.`,
        onclick: `openClient(${a.client_id});toggleNotifPanel()`,
        assignmentLogId: a.id
      });
      return;
    }
    const verb = a.type === 'reassigned' ? 'reassigned' : 'assigned';
    items.push({
      key, ts: a.created_at, secAgo: a.seconds_ago, icon: a.type === 'reassigned' ? 'fa-rotate' : 'fa-user-check', color: '#175cd3',
      html: `<b>${esc(a.from_name || 'Someone')}</b> ${verb} lead <b>${esc(a.client_name)}</b> (${esc(a.lead_code)}) to you.`,
      onclick: `openClient(${a.client_id});toggleNotifPanel()`,
      assignmentLogId: a.id
    });
  });

  // 3. New source leads — entry desk + admin only. Clicking opens Source Leads.
  if (canManageSourceLeads()) {
    const ids = state.sourceLeads.map(s => s.id);
    ensureBaseline('crm_notif_baseline_src', ids);
    state.sourceLeads.forEach(s => {
      const key = `src_${s.id}`;
      if (cleared.includes(key) || isBaseline('crm_notif_baseline_src', s.id)) return;
      items.push({
        key, ts: s.received_at || s.created_at, icon: 'fa-globe', color: '#7b2ff7',
        html: `New source lead: <b>${esc(s.name)}</b>${s.site ? ` from ${esc(s.site)}` : ''}.`,
        onclick: `nav('sourceleads');toggleNotifPanel()`
      });
    });
  }

  // 4. Follow-ups due today (or overdue) — shown to admin and the owning
  // salesperson. Clicking opens Follow-ups.
  state.followups.forEach(f => {
    if (f.status !== 'Pending') return;
    const due = f.next_date || f.followup_date;
    if (!due || due > today()) return;
    if (!isAdminRole(u.role) && String(f.salesperson_id) !== String(u.id)) return;
    const key = `fu_${f.id}_${due}`;
    if (cleared.includes(key)) return;
    items.push({
      key, ts: due, icon: 'fa-calendar-days', color: '#b54708',
      html: `Follow-up due for <b>${esc(f.client_name)}</b>.`,
      onclick: `nav('followups');toggleNotifPanel()`
    });
  });

  // 5. Newly scheduled site visits — admin + the owning salesperson.
  const visitIds = state.visits.map(v => v.id);
  ensureBaseline('crm_notif_baseline_visits', visitIds);
  state.visits.forEach(v => {
    if (v.status !== 'Scheduled') return;
    const key = `visit_${v.id}`;
    if (cleared.includes(key) || isBaseline('crm_notif_baseline_visits', v.id)) return;
    items.push({
      key, ts: v.visit_date, icon: 'fa-calendar-check', color: '#05357c',
      html: `Site visit scheduled for <b>${esc(v.client_name)}</b> on ${esc(v.visit_date)}${v.visit_time ? ' at ' + esc(v.visit_time) : ''}.`,
      onclick: `nav('visits');toggleNotifPanel()`
    });
  });

  // 6. Legal desk: leads transferred before transfer notifications were saved server-side
  // (anything newer already came through as a post_sales_transfer item above).
  if (IS_LEGAL) {
    const logged = new Set((state.assignmentLog || []).filter(a => a.type === 'post_sales_transfer').map(a => String(a.client_id)));
    state.clients.filter(c => leadHandedOver(c) && !logged.has(String(c.id))).forEach(c => {
      const key = `ps_${c.id}_${c.sales_handover_at}`;
      if (cleared.includes(key)) return;
      items.push({
        key, ts: c.sales_handover_at, icon: 'fa-right-left', color: '#16940a',
        html: `<b>${esc(c.salesperson_name || 'Sales')}</b> transferred <b>${esc(c.name)}</b> (${esc(c.lead_code || '')}) to Post Sales — booking pending.`,
        onclick: `openClient(${c.id});toggleNotifPanel()`
      });
    });
  }
  // 7. Accounts desk: same fallback for Legal → Accounts transfers.
  if (IS_ACCOUNTS) {
    const logged = new Set((state.assignmentLog || []).filter(a => a.type === 'accounts_transfer').map(a => String(a.client_id)));
    state.clients.filter(c => leadAccountsHandedOver(c) && !logged.has(String(c.id))).forEach(c => {
      const key = `acc_${c.id}_${c.accounts_handover_at}`;
      if (cleared.includes(key)) return;
      items.push({
        key, ts: c.accounts_handover_at, icon: 'fa-right-left', color: '#0e7490',
        html: `Legal transferred <b>${esc(c.name)}</b> (${esc(c.lead_code || '')}) to Accounts — construction pending.`,
        onclick: `openClient(${c.id});toggleNotifPanel()`
      });
    });
  }

  // 8. Accounts desk + admin: payment overdue (oldest unpaid demand is past its pay-by date).
  if (IS_ACCOUNTS || IS_ADMIN) {
    state.clients.filter(c => +c.dueamount > 0.5 && c.duedate && c.duedate < today() && c.stage !== 'Cancelled').forEach(c => {
      const key = `payod_${c.id}_${c.duedate}`;
      if (cleared.includes(key)) return;
      items.push({
        key, ts: c.duedate, icon: 'fa-indian-rupee-sign', color: '#cf180b',
        html: `Payment overdue: <b>${esc(c.name)}</b> (${esc(c.lead_code || '')}) — ${money(c.dueamount)} was due on ${esc(fmtDay(c.duedate))}.`,
        onclick: `openClient(${c.id});toggleNotifPanel()`
      });
    });
  }

  items.sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || '')));
  return items;
}

// ---------- Admin: Notification Log (full history, filterable, never cleared) ----------
async function renderNotifLog() {
  const sel = document.getElementById('notifLogSalesperson');
  if (sel && sel.options.length <= 1) {
    state.users.filter(u => !isAdminRole(u.role) && isSalesUser(u)).forEach(u => {
      const o = document.createElement('option');
      o.value = u.id; o.textContent = u.name;
      sel.appendChild(o);
    });
  }
  const date = document.getElementById('notifLogDate')?.value || '';
  const spId = document.getElementById('notifLogSalesperson')?.value || '';
  const type = document.getElementById('notifLogType')?.value || '';
  const qs = new URLSearchParams({ action: 'log' });
  if (date) qs.set('date', date);
  if (spId) qs.set('salesperson_id', spId);
  if (type) qs.set('type', type);
  const rows = await api(`assignment_log.php?${qs.toString()}`);
  const tbody = document.getElementById('notifLogTable');
  if (!tbody) return;
  // Site labels for a Source Lead row's "From" column, keyed by source_leads.site.
  const SITE_NAMES = { antaaya: 'antaayavillas.com', akruti: 'akrutideveloper.com' };
  const notifTypeLabel = t => ({
    assigned: 'Lead Assigned', reassigned: 'Lead Reassigned',
    source_new: 'New Source Lead', source_assigned: 'Source Lead Assigned', source_rejected: 'Source Lead Rejected',
    reschedule_proposed: 'Client Proposed New Time',
    post_sales_transfer: 'Transferred to Post Sales', accounts_transfer: 'Transferred to Accounts', booking_cancelled: 'Booking Cancelled',
  }[t] || t);
  tbody.innerHTML = (rows && rows.length) ? rows.map(a => {
    const isSource = (a.type || '').startsWith('source_');
    const from = a.from_name ? esc(a.from_name) : (a.type === 'source_new' ? `Website (${esc(SITE_NAMES[a.source_site] || a.source_site || 'form')})` : 'Someone');
    const to = a.to_name ? esc(a.to_name) + (a.type === 'booking_cancelled' ? ' <span style="color:var(--muted)">+ Admin</span>' : '')
      : (isSource ? '<span style="color:var(--muted)">Entry Desk / Admin</span>' : a.type === 'post_sales_transfer' ? '<span style="color:var(--muted)">Legal team</span>'
        : a.type === 'accounts_transfer' ? '<span style="color:var(--muted)">Accounts team</span>' : '—');
    const lead = a.client_id
      ? `${esc(a.client_name)} (${esc(a.lead_code)})${['reschedule_proposed', 'post_sales_transfer', 'accounts_transfer', 'booking_cancelled'].includes(a.type) && a.label ? ` — ${esc(a.label)}` : ''}`
      : `${esc(a.label || '—')}${isSource ? ' <span class="badge" style="background:#f3eaff;color:#7c3aed">Source Lead</span>' : ''}`;
    return `<tr>
      <td>${esc(a.created_at)}</td>
      <td>${from}</td>
      <td>${to}</td>
      <td>${lead}</td>
      <td>${esc(notifTypeLabel(a.type))}</td>
      <td><button class="btn danger" onclick="deleteNotifLog(${a.id})"><i class="fa-solid fa-trash"></i></button></td>
    </tr>`;
  }).join('') : '<tr><td colspan="6" style="text-align:center;color:var(--muted)">No records for this filter.</td></tr>';
}

async function deleteNotifLog(id) {
  if (!confirm('Permanently delete this notification log entry? This cannot be undone.')) return;
  await api('assignment_log.php', 'POST', { action: 'delete', id });
  renderNotifLog();
}

function timeAgo(ts, secAgo) {
  // Prefer an exact server-computed seconds-elapsed value (TIMESTAMPDIFF)
  // over parsing the timestamp string in the browser — that avoids any
  // server/browser timezone mismatch producing a wrong "Xh ago".
  let diffSec;
  if (secAgo != null && !isNaN(secAgo)) {
    diffSec = secAgo;
  } else {
    if (!ts) return '';
    const then = new Date(String(ts).replace(' ', 'T'));
    if (isNaN(then.getTime())) return '';
    diffSec = Math.floor((Date.now() - then.getTime()) / 1000);
  }
  if (diffSec < 45) return 'just now';
  const m = Math.floor(diffSec / 60);
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h ago`;
  const d = Math.floor(h / 24);
  if (d < 7) return `${d}d ago`;
  const w = Math.floor(d / 7);
  if (w < 5) return `${w}w ago`;
  return `${Math.floor(d / 30)}mo ago`;
}

function renderNotifications() {
  const listEl = document.getElementById('notifList');
  const countEl = document.getElementById('notifCount');
  if (!listEl || !countEl) return;
  const items = buildNotifItems();
  countEl.style.display = items.length ? 'flex' : 'none';
  countEl.textContent = items.length;
  listEl.innerHTML = items.length ? items.map(i => `
    <div class="notif-item" ${i.onclick ? `style="cursor:pointer" onclick="${i.onclick}"` : ''}>
      <i class="fa-solid ${i.icon} notif-ico" style="color:${i.color}"></i>
      <div class="notif-body">${i.html}${i.actions ? `<div class="notif-actions">${i.actions}</div>` : ''}<span class="notif-time">${timeAgo(i.ts, i.secAgo)}</span></div>
      <button class="notif-x" title="Clear" onclick="event.stopPropagation();clearOneNotif('${i.key}')"><i class="fa-solid fa-xmark"></i></button>
    </div>`).join('') : '<div class="notif-empty">You\'re all caught up.</div>';
}
function persistAssignmentClear(key) {
  if (!key.startsWith('asg_')) return;
  const id = key.slice(4);
  fetch('api/assignment_log.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'clear', id })
  }).then(() => {
    state.assignmentLog = (state.assignmentLog || []).filter(a => String(a.id) !== String(id));
  }).catch(() => { /* best-effort — localStorage still hides it locally */ });
}
function clearOneNotif(key) {
  persistAssignmentClear(key);
  const cleared = getClearedNotifs();
  if (!cleared.includes(key)) cleared.push(key);
  saveClearedNotifs(cleared);
  renderNotifications();
}
function clearAllNotifs() {
  const cleared = getClearedNotifs();
  buildNotifItems().forEach(i => {
    if (!cleared.includes(i.key)) cleared.push(i.key);
    persistAssignmentClear(i.key);
  });
  saveClearedNotifs(cleared);
  renderNotifications();
}

async function submitQuickForm(type, formEl, modalId) {
  const o = Object.fromEntries(new FormData(formEl).entries());
  const result = await api('public_intake.php', 'POST', { form_type: type, ...o });
  if (result && result.error) { alert(result.error); return; }
  closeModal(modalId);
  if (result && result.duplicate) {
    addDuplicateAlert(result.id, result.lead_code, result.name, result.salesperson_id, result.salesperson_name);
    renderDuplicateAlerts();
    nav('dashboard');
    return;
  }
  await loadAll();
  nav('leads');
  // Walk-in site visit: open the new lead straight on its Site Visit Completed stage.
  if (result && result.walkin && result.id) openClient(result.id);
}
document.getElementById('quickEnquiryForm').onsubmit = (e) => {
  e.preventDefault(); submitQuickForm('enquiry', e.target, 'quickEnquiryModal');
};
document.getElementById('quickBrokerForm').onsubmit = (e) => {
  e.preventDefault(); submitQuickForm('broker', e.target, 'quickBrokerModal');
};
document.getElementById('quickNewLeadForm').onsubmit = (e) => {
  e.preventDefault(); submitQuickForm('sitevisit', e.target, 'quickNewLeadModal');
};
document.getElementById('quickWalkinForm').onsubmit = (e) => {
  e.preventDefault(); submitQuickForm('sitevisit', e.target, 'quickWalkinModal');
};

// ---------- Client modal ----------
function openClient(id) {
  const f = document.getElementById('clientForm');
  f.reset();
  // Hidden inputs aren't cleared by reset() — clear the ones that may be absent from a lead's data.
  ['villa', 'villa_offers', 'offered_price', 'sales_handover', 'accounts_handover', 'token_amount', 'final_villa_price', 'bookingamount', 'stamp_duty_amount', 'registration_fee'].forEach(n => { if (f.elements[n]) f.elements[n].value = ''; });
  if (f.elements.registration) f.elements.registration.value = 'Pending';
  if (f.elements.bookingdate) f.elements.bookingdate.dataset.auto = '';
  renderVillaPriceWords();
  updateKycPanel();
  f.elements.brochure_whatsapp_message.dataset.auto = ''; // don't carry over the auto-fill flag between leads
  window.__crmViewStage = null;
  // Booking & Legal documents belong to the lead being opened — clear the
  // previous lead's list before anything renders (loadLegalDocs refills it).
  window.__crmDocsClientId = id || null; window.__crmDocs = id ? null : []; window.__crmDocsError = null;
  document.getElementById('basicEdit').classList.add('hidden');
  clientTab('new', document.querySelector('#clientModal .tab'));
  populateSalespersonSelect();
  initVillaPickers();

  let c = id ? state.clients.find(x => String(x.id) === String(id)) : null;
  document.getElementById('clientTitle').textContent = c ? 'Edit Client' : 'Add New Lead';

  // Read-only mode: this happens when someone can see a lead (because they
  // created it) but don't own it — e.g. an Entry desk user viewing an enquiry they
  // added that auto-assigned to the Broker or Owner desk. Editing stays limited to
  // admin and whoever the lead is actually assigned to. Computed up front
  // (before any rendering) so applyStageLocks() — called from within
  // renderStageTracker below — already knows the right final disabled state.
  window.__crmSalesHandover = (c && c.sales_handover_at) || null;
  window.__crmAccountsHandover = (c && c.accounts_handover_at) || null;
  const handedOver = !!(c && !CAN_POST_SALES && (isPostSalesStage(c.stage) || c.sales_handover_at));
  // Legal desk: edits a lead with Post Sales until it's transferred to Accounts (then read-only,
  // status progress only). Accounts desk: edits only leads transferred to Accounts.
  const withAccounts = leadWithAccounts(c);
  const canEdit = IS_LEGAL ? (leadWithPostSales(c) || !!(c && c.sales_handover_at)) && !withAccounts
    : IS_ACCOUNTS ? withAccounts
    : c ? (IS_ADMIN || String(c.salesperson_id) === String(window.CURRENT_USER.id)) && !handedOver : true;
  window.__crmCanEdit = canEdit;

  if (!c) {
    f.elements.created.value = today();
    f.elements.interest.value = 'WARM';
    syncInterest('WARM');
    f.elements.salesperson_id.value = window.CURRENT_USER.id;
    renderLeadSummary(null);
    document.getElementById('basicEdit').classList.remove('hidden'); // new lead: start with details open
    f.elements.stage.value = 'New Lead';
    vpickRefreshAll(); syncVillaSelectedOptions('');
    renderStageTracker('New Lead');
    loadActivityLog(null);
    addActivityLog('Lead added to the system', 'Lead Generated');
    fillWhatsappGreetingDefault(f.elements.name.value || '');
    toggleBrochureBypass(false);
    document.getElementById('brochureBypassChk').checked = false;
    fillBrochureWhatsappGreetingDefault();
  } else {
    Object.keys(c).forEach(k => {
      const mapKey = {
        alt_mobile: 'alt', created_date: 'created', firstcall_date: 'firstcall',
        lastcontact_date: 'lastcontact', nextfollow_date: 'nextfollow', followup_mode: 'fumode',
        keys_handed: 'keys'
      }[k] || k;
      if (f.elements[mapKey]) f.elements[mapKey].value = c[k] ?? '';
    });
    vpickRefreshAll(); // rebuild shortlist chips from the loaded value
    syncVillaSelectedOptions(c.villa || ''); // rebuild "Villa Selected" options from the loaded shortlist
    if (document.getElementById('tokenAmountDisplay')) amtFormat(document.getElementById('tokenAmountDisplay'));
    initBookingAmountFields(c);
    updateKycPanel();
    if (!f.elements.fumode.value) f.elements.fumode.value = 'Call'; // default Contacted Mode when not yet set
    syncInterest(f.elements.interest.value || 'WARM');
    // Propagate the loaded Brochure Sent values into both mirror copies of
    // the fields (Brochure Sent section + Site Visit Completed bypass).
    mirrorSync('brochure_date', f.elements.brochure_date.value);
    mirrorSync('brochure_mode', f.elements.brochure_mode.value);
    mirrorSync('brochure_whatsapp_message', f.elements.brochure_whatsapp_message.value);
    mirrorSync('brochure_whatsapp_sent', f.elements.brochure_whatsapp_sent.value);
    const bypassOn = f.elements.brochure_bypass.value === 'Yes';
    document.getElementById('brochureBypassChk').checked = bypassOn;
    toggleBrochureBypass(bypassOn);
    renderLeadSummary(c);
    f.elements.stage.value = c.stage || 'New Lead';
    renderStageTracker(c.stage || 'New Lead');
    loadActivityLog(c);
    fillWhatsappGreetingDefault(c.name || '');
    fillBrochureWhatsappGreetingDefault();
    // Open on whichever tab actually holds this lead's current stage
    // (e.g. a lead already at Site Visit Scheduled opens on the Site Visit
    // tab, not New Lead) instead of always defaulting to New Lead.
    // A lead sales has handed over opens straight on Post Sales: admin → Booking & Legal,
    // salesperson → their read-only Booking tab (Possession tab once it's at Possession).
    let openTab = leadHandedOver(c) ? 'book' : leadAccountsHandedOver(c) ? 'construction' : stageGroupKey(c.stage || 'New Lead');
    if (IS_POST_SALES && !POST_SALES_KEYS.includes(openTab)) openTab = MY_POST_KEYS[0]; // Post Sales desks: Post Sales tabs only
    if (IS_ACCOUNTS) openTab = 'construction'; // Accounts always lands on its own working tab
    if (PIPELINE_GROUPS.some(g => g.key === openTab)) clientTab(openTab);
  }
  if (!IS_ADMIN) f.elements.salesperson_id.disabled = true;
  else f.elements.salesperson_id.disabled = false;

  initVisitShare(c);
  updateAllRefollowUI();
  updateModeCallBtns();
  if (f.elements.applicant_type && !f.elements.applicant_type.value) f.elements.applicant_type.value = 'Resident Individual';
  if (document.querySelector('#clientForm .legal-docs')) loadLegalDocs(c ? c.id : null); // admin only — salespeople have no Post Sales sections
  if (document.getElementById('payTracker')) loadPayments(c ? c.id : null, !!c && !leadWithAccounts(c)); // admin + Accounts desk
  renderSalesSummaryTabs(c);
  renderAccountsSummaryTabs(c);

  // Fields inside a .stage-section are governed by applyStageLocks() (run
  // as part of renderStageTracker() above), which already folds in
  // window.__crmCanEdit — skip them here so this loop can't undo their
  // per-checkpoint lock state.
  f.querySelectorAll('input, select, textarea').forEach(el => {
    if (el.name === 'salesperson_id') return;
    if (el.closest('.stage-section')) return;
    if (el.tagName === 'SELECT') { el.disabled = !canEdit; }
    else { el.readOnly = !canEdit; el.disabled = false; }
  });
  const saveBtn = f.querySelector('.client-modal-footer button.primary');
  saveBtn.style.display = canEdit ? '' : 'none';
  // Chips/edit-icon/terminal buttons aren't <input>/<select>/<textarea>, so
  // they need their own read-only gate here (locking still applies on top
  // of this whenever canEdit is true — see renderChecklist()).
  f.querySelectorAll('.ls-edit-btn').forEach(el => el.disabled = !canEdit || IS_POST_SALES); // Post Sales desks don't edit the client's sales details // chips stay clickable (view-only when read-only — see viewOrSetStage)
  const cancelBtn = document.getElementById('markCancelledBtn');
  if (cancelBtn) {
    cancelBtn.disabled = !canEdit;
    cancelBtn.title = handedOver ? 'Lead is with the Post Sales team — only an admin can cancel it' : '';
    updateCancelButton(c);
    cancelBtn.style.display = IS_ACCOUNTS ? 'none' : ''; // Accounts only gets registered leads — booking can't be cancelled
  }
  let notice = document.getElementById('readOnlyNotice');
  if (!canEdit) {
    if (!notice) {
      notice = document.createElement('p');
      notice.id = 'readOnlyNotice';
      notice.style.cssText = 'font-size:12px;color:var(--warn);margin:-8px 0 14px';
      f.insertBefore(notice, f.firstChild);
    }
    notice.textContent = IS_ENTRY_DESK ? 'View only — Entry Desk. This lead is handled by ' + (c.salesperson_name || 'the assigned salesperson') + '.'
      : handedOver
      ? `Read-only — sales completed; this lead is with the Post Sales team (${c.stage}). Only an admin can edit it now.`
      : IS_LEGAL && withAccounts
      ? 'Read-only — legal completed; this lead is with the Accounts team. Only an admin can edit it now.'
      : IS_ACCOUNTS
      ? 'Read-only — this lead is still with the Legal team.'
      : `Read-only — assigned to ${c.salesperson_name || 'another salesperson'}. Only they or an admin can edit this lead.`;
  } else if (notice) {
    notice.remove();
  }

  f.dataset.edit = id || '';
  openModal('clientModal');
}
function isPostSalesDesk(u) { return !isAdminRole(u.role) && (['legal', 'accounts'].includes(u.desk) || ['legal', 'accounts'].includes(u.role)); }
// Users who can own leads: everyone except the Post Sales desks (admin / IT included, as before).
function isSalesUser(u) { return !isPostSalesDesk(u); }
function populateSalespersonSelect() {
  // Leads belong to a salesperson — the Post Sales desks are never owners.
  document.getElementById('salespersonSelect').innerHTML =
    state.users.filter(isSalesUser).map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('');
}
// "Villa Shown" (Site Visit Completed) combo list — lets the salesperson
// pick from the live Villa Inventory (shown as "NAME - SERIAL", e.g.
// "ALARA 1 - A1-01") or just type a name/serial freely (datalist doesn't
// restrict input). A "Sample Villa" entry is always included first as a
// default option even if inventory is empty.
// ---------- Villa picker: type-or-select combo, chips for the multi (shortlist) one ----------
const villaLabel = v => `${v.name} - ${v.serial}`;
function findVilla(text) {
  const t = (text || '').trim().toLowerCase();
  if (!t) return null;
  return (state.villas || []).find(v => villaLabel(v).toLowerCase() === t || v.name.toLowerCase() === t || v.serial.toLowerCase() === t) || null;
}
// "ALARA 1" / "A1-01" / "ALARA 1 - A1-01" -> "ALARA 1 - A1-01" (unknown text is left as typed).
function canonVilla(text) { const v = findVilla(text); return v ? villaLabel(v) : (text || '').trim(); }
function vpickOptions(root) {
  const cid = document.getElementById('clientForm').dataset.edit;
  let list = (state.villas || []).filter(v => root.dataset.scope !== 'available' || v.status === 'available' || (cid && String(v.linked_client_id) === String(cid)));
  list = list.slice().sort((a, b) => a.serial.localeCompare(b.serial, undefined, { numeric: true }));
  return list;
}
const vpickHidden = root => root.querySelector('input[type=hidden]');
const vpickText = root => root.querySelector(root.dataset.mode === 'multi' ? '.vp-input' : 'input:not([type=hidden])');
const vpickDisabled = root => (vpickHidden(root) || vpickText(root)).disabled;
function vpickGet(root) {
  return [...new Set(splitVillaText(vpickHidden(root).value).map(canonVilla).filter(Boolean))];
}
function vpickSet(root, arr) {
  vpickHidden(root).value = arr.join(', ');
  vpickRender(root);
  syncVillaSelectedOptions();
}
function vpickRender(root) {
  if (root.dataset.mode === 'multi') {
    const box = root.querySelector('.vp-box');
    box.querySelectorAll('.vp-chip').forEach(n => n.remove());
    const input = box.querySelector('.vp-input');
    vpickGet(root).forEach(lbl => {
      const chip = document.createElement('span');
      chip.className = 'vp-chip';
      chip.innerHTML = `${esc(lbl)}<button type="button" title="Remove">&times;</button>`;
      chip.querySelector('button').onclick = e => {
        e.stopPropagation();
        if (vpickDisabled(root)) return;
        dropFinalVilla(lbl); // removed from the shortlist → no longer a final pick either
        vpickSet(root, vpickGet(root).filter(x => x !== lbl));
      };
      box.insertBefore(chip, input);
    });
    box.classList.toggle('disabled', vpickDisabled(root));
  }
  if (root.classList.contains('open')) vpickPanel(root);
}
function vpickPanel(root) {
  const multi = root.dataset.mode === 'multi';
  const q = vpickText(root).value.trim().toLowerCase();
  const chosen = multi ? vpickGet(root) : [];
  let opts = vpickOptions(root).filter(v => !q || villaLabel(v).toLowerCase().includes(q));
  const panel = root.querySelector('.vp-panel');
  let html = '';
  if (!multi && 'sample villa'.includes(q)) html += `<div class="vp-opt" data-val="Sample Villa"><span class="vp-name">Sample Villa</span></div>`;
  html += opts.map(v => `<div class="vp-opt${chosen.includes(villaLabel(v)) ? ' sel' : ''}" data-val="${esc(villaLabel(v))}">` +
    (multi ? '<span class="vp-check"></span>' : '') +
    `<span class="vp-name">${esc(v.name)} <em>- ${esc(v.serial)}</em></span><span class="vp-st" data-s="${esc(v.status)}">${esc(v.status.charAt(0).toUpperCase() + v.status.slice(1))}</span></div>`).join('');
  panel.innerHTML = html || '<div class="vp-empty">No matching villas</div>';
  root._active = -1;
}
function vpickPick(root, val) {
  if (root.dataset.mode === 'multi') {
    const cur = vpickGet(root);
    vpickSet(root, cur.includes(val) ? cur.filter(x => x !== val) : [...cur, val]);
    vpickText(root).value = '';
    vpickPanel(root);
    vpickText(root).focus();
  } else {
    const t = vpickText(root);
    t.value = val;
    t.dispatchEvent(new Event('input', { bubbles: true }));
    vpickClose(root);
  }
}
function vpickOpen(root) {
  if (vpickDisabled(root)) return;
  document.querySelectorAll('.vpick.open').forEach(r => { if (r !== root) vpickClose(r); });
  root.classList.add('open');
  vpickPanel(root);
}
function vpickClose(root) {
  root.classList.remove('open');
  if (root.dataset.mode === 'multi') vpickText(root).value = '';
}
function vpickRefreshAll() {
  initVillaPickers();
  document.querySelectorAll('#clientForm .vpick').forEach(r => { if (r.dataset.mode === 'multi') vpickRender(r); });
}
function initVillaPickers() {
  document.querySelectorAll('.vpick').forEach(root => { // Edit Client + the Walk-in Site Visit quick form
    if (root.dataset.built) return;
    root.dataset.built = '1';
    const multi = root.dataset.mode === 'multi';
    const caret = document.createElement('i');
    caret.className = 'fa-solid fa-chevron-down vp-caret';
    const panel = document.createElement('div');
    panel.className = 'vp-panel';
    if (multi) {
      const box = document.createElement('div');
      box.className = 'vp-box';
      box.innerHTML = `<input type="text" class="vp-input" autocomplete="off" placeholder="${esc(root.dataset.placeholder || '')}">`;
      root.appendChild(box);
      box.addEventListener('mousedown', e => { if (e.target === box) { e.preventDefault(); vpickText(root).focus(); } });
    }
    root.appendChild(caret);
    root.appendChild(panel);
    const t = vpickText(root);
    t.addEventListener('focus', () => vpickOpen(root));
    t.addEventListener('click', () => vpickOpen(root));
    t.addEventListener('input', () => { if (!vpickDisabled(root)) { root.classList.add('open'); vpickPanel(root); } });
    panel.addEventListener('mousedown', e => {
      e.preventDefault();
      const o = e.target.closest('.vp-opt');
      if (o) vpickPick(root, o.dataset.val);
    });
    t.addEventListener('keydown', e => {
      const items = [...panel.querySelectorAll('.vp-opt')];
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!root.classList.contains('open')) { vpickOpen(root); return; }
        root._active = Math.max(0, Math.min(items.length - 1, (root._active ?? -1) + (e.key === 'ArrowDown' ? 1 : -1)));
        items.forEach((o, i) => o.classList.toggle('active', i === root._active));
        items[root._active]?.scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter' && root.classList.contains('open')) {
        const pick = items[root._active >= 0 ? root._active : (multi ? 0 : -1)];
        if (pick) { e.preventDefault(); vpickPick(root, pick.dataset.val); }
        else if (!multi) vpickClose(root); // free text stays as typed
        else e.preventDefault();
      } else if (e.key === 'Escape') { vpickClose(root); }
      else if (e.key === 'Backspace' && multi && !t.value) {
        const cur = vpickGet(root);
        if (cur.length) { dropFinalVilla(cur[cur.length - 1]); vpickSet(root, cur.slice(0, -1)); }
      }
    });
  });
}
document.addEventListener('mousedown', e => {
  document.querySelectorAll('.vpick.open').forEach(r => { if (!r.contains(e.target)) vpickClose(r); });
});
// Rebuilds the "Villa(s) Selected (Final)" tick-list from the shortlist chips. Pass
// desiredVilla to (re)load a saved value ("A, B"); older name-only values are canonicalised.
function syncVillaSelectedOptions(desiredVilla) {
  const f = document.getElementById('clientForm');
  if (!f.elements.villa_shortlist || !f.elements.villa) return;
  if (desiredVilla !== undefined) {
    const offers = finalVillaOffers(); // read before villa changes (single-villa legacy fallback uses it)
    finalVillaStore(villaListOf(desiredVilla), offers);
  }
  renderFinalVillaList();
}
function clientTab(tab, btn) {
  if (IS_POST_SALES && !POST_SALES_KEYS.includes(tab) && !['acctpay', 'acctpos'].includes(tab)) { tab = MY_POST_KEYS[0]; btn = null; } // Post Sales desks: Post Sales tabs only
  // Legal desk: no Accounts tabs — a lead already with Accounts opens on Booking & Legal (read-only).
  if (IS_LEGAL && (ACCOUNTS_KEYS.includes(tab) || ['acctpay', 'acctpos'].includes(tab))) { tab = 'book'; btn = null; }
  // Accounts desk: Possession is handled by admin — read-only status tab.
  if (IS_ACCOUNTS && tab === 'possession') { tab = 'acctpos'; btn = null; }
  // Salespeople don't have the Post Sales tabs — show the read-only summary instead.
  if (!document.getElementById('ct-' + tab)) { tab = tab === 'possession' ? 'salespos' : 'salesbook'; btn = null; }
  if (!document.getElementById('ct-' + tab)) tab = 'new';
  setPhaseUI(POST_SALES_KEYS.includes(tab) || ['acctpay', 'acctpos'].includes(tab) ? 'post' : 'sales');
  document.querySelectorAll('.ctab').forEach(x => x.classList.add('hidden'));
  document.getElementById('ct-' + tab).classList.remove('hidden');
  document.querySelectorAll('#clientModal .tab').forEach(x => x.classList.remove('active'));
  if (!btn) btn = document.querySelector(`#clientModal .tab[onclick*="clientTab('${tab}'"]`);
  if (btn) btn.classList.add('active');
  // Switching main tabs always shows that group's real position again,
  // discarding any in-progress "review a past checkpoint" view.
  window.__crmViewStage = null;
  const f = document.getElementById('clientForm');
  if (f.elements.stage) applyStageLocks(f.elements.stage.value || 'New Lead');
}

// Admin's Sales / Post Sales switch: shows only that phase's tabs.
function setPhaseUI(phase) {
  document.querySelectorAll('#phaseSwitch button').forEach(b => b.classList.toggle('active', b.dataset.phase === phase));
  if (IS_ADMIN) document.querySelectorAll('#clientModal .tabs .tab').forEach(t => t.classList.toggle('phase-hidden', t.dataset.phase !== phase));
}
function switchPhase(phase) {
  // Open the phase on the lead's current tab if it's in that phase, else the phase's natural tab.
  const cur = stageGroupKey(document.getElementById('clientForm').elements.stage.value || 'New Lead');
  const keys = phase === 'post' ? POST_SALES_KEYS : SALES_KEYS;
  clientTab(keys.includes(cur) ? cur : (phase === 'post' ? 'book' : 'engage'));
}
// Sales team's "Booking Date" = the day the booking was CONFIRMED (Confirmation Date in
// Booking Confirmed), not Post Sales' own Booking Initiated date. A lead counts as booked
// only once it has reached Booking Confirmed.
function isBookingConfirmed(c) {
  const st = (c && c.stage) || '';
  if (st === 'Closed') return !!(c && c.confirmation_date);
  return isPostSalesStage(st) && pipelineIndex(st) >= pipelineIndex('Booking Confirmed');
}
function salesBookingDate(c) { return (c && c.confirmation_date) || ''; }
// Booking status label — shared by the Edit Client Booking tab and the Bookings page.
function bookingStatusOf(c) {
  const stage = (c && c.stage) || 'New Lead';
  if (stage === 'Cancelled') return { text: 'Cancelled', cls: 'ps-red' };
  if (!isPostSalesStage(stage)) {
    if (leadHandedOver(c)) return { text: 'Transferred to Post Sales — booking pending', cls: 'ps-green' };
    if (stage === 'Unit Blocked') return { text: 'Unit Blocked — awaiting booking', cls: 'ps-blue' };
    return { text: 'Not booked yet', cls: '' };
  }
  if (pipelineIndex(stage) < pipelineIndex('Booking Confirmed')) return { text: 'Booking in process', cls: 'ps-blue' };
  return { text: 'Booking Confirmed', cls: 'ps-green' };
}
// Salesperson-only read-only tabs: booking date/status and possession handover date/status.
function renderSalesSummaryTabs(c) {
  const bEl = document.getElementById('salesBookSummary'), pEl = document.getElementById('salesPosSummary');
  if (!bEl || !pEl) return;
  const stage = (c && c.stage) || 'New Lead';
  const idx = pipelineIndex(stage);
  const post = isPostSalesStage(stage);
  const cancelled = stage === 'Cancelled';
  const pill = (t, cls) => `<span class="ps-pill ${cls || ''}">${esc(t)}</span>`;
  const item = (l, v) => `<div class="ps-item"><label>${esc(l)}</label><div>${v}</div></div>`;
  const fmtD = d => d ? esc(new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })) : '—';

  const bs = bookingStatusOf(c);
  const bStatus = pill(bs.text, bs.cls);
  bEl.innerHTML = `<div class="ps-head"><i class="fa-solid fa-house-circle-check"></i> Booking</div>
    <div class="ps-grid">${item('Booking Date', salesBookingDate(c) ? fmtD(salesBookingDate(c)) : 'Not confirmed yet')}${item('Booking Status', bStatus)}</div>
    <p class="ps-note">Booking, legal and payment steps are handled by the Post Sales team — shown here read-only.</p>`;

  let pStatus;
  if (cancelled) pStatus = pill('Cancelled', 'ps-red');
  else if (stage === 'Handover Completed') pStatus = pill('Handover Completed', 'ps-green');
  else if (['Possession Due', 'Possession Offered'].includes(stage)) pStatus = pill(stage, 'ps-blue');
  else if (stageGroupKey(stage) === 'construction') pStatus = pill('Under Construction', 'ps-blue');
  else pStatus = pill('Not started');
  pEl.innerHTML = `<div class="ps-head"><i class="fa-solid fa-key"></i> Possession</div>
    <div class="ps-grid">${item('Handover Date', fmtD(c && c.handover))}${item('Handover Status', pStatus)}</div>
    <p class="ps-note">Updated by the Post Sales team — shown here read-only.</p>`;
}
// Read-only tab — Accounts desk: Possession (handled by admin). Filled only if this user's page has it
// (the Legal desk has no Accounts tabs).
function renderAccountsSummaryTabs(c) {
  const payEl = document.getElementById('acctPaySummary'), posEl = document.getElementById('acctPosSummary');
  if (!payEl && !posEl) return;
  const stage = (c && c.stage) || 'New Lead';
  const pill = (t, cls) => `<span class="ps-pill ${cls || ''}">${esc(t)}</span>`;
  const item = (l, v) => `<div class="ps-item"><label>${esc(l)}</label><div>${v}</div></div>`;
  const fmtD = d => d ? esc(fmtDay(d)) : '—';
  const withAcc = leadWithAccounts(c);
  const g = stageGroupKey(stage);
  let accStatus;
  if (stage === 'Cancelled') accStatus = pill('Cancelled', 'ps-red');
  else if (!withAcc) accStatus = pill(stage === 'Registered' ? 'Registered — transfer to Accounts pending' : 'With Legal team', 'ps-blue');
  else if (leadAccountsHandedOver(c)) accStatus = pill('Transferred to Accounts — construction pending', 'ps-green');
  else accStatus = pill(stage, g === 'possession' ? 'ps-green' : 'ps-blue');
  const price = villaPriceNum(c), rec = +((c && c.received) || 0), due = +((c && c.dueamount) || 0);
  if (payEl) payEl.innerHTML = `<div class="ps-head"><i class="fa-solid fa-indian-rupee-sign"></i> Construction &amp; Payment</div>
    <div class="ps-grid">${item('Accounts Status', accStatus)}${item('Transferred On', c && c.accounts_handover_at ? fmtD(String(c.accounts_handover_at).slice(0, 10)) : '—')}
      ${item('Construction', esc((c && c.construction) || '—'))}${item('Last Milestone Done', esc((c && c.construction_milestone) || '—'))}
      ${item('Paid', c ? payMiniBar(c) : '—')}${item('Amount Received', rec ? money(rec) + (price ? ` of ${money(price)}` : '') : '—')}
      ${item('Payment Due Now', due > 0.5 ? money(due) + (c.duedate ? ` · by ${fmtD(c.duedate)}` : '') : '—')}${item('Balance to Receive', price ? money(Math.max(0, price - rec)) : '—')}</div>
    <p class="ps-note">Construction and payments are handled by the Accounts team — shown here read-only.</p>`;
  let pStatus;
  if (stage === 'Cancelled') pStatus = pill('Cancelled', 'ps-red');
  else if (stage === 'Handover Completed') pStatus = pill('Handover Completed', 'ps-green');
  else if (g === 'possession') pStatus = pill(stage, 'ps-blue');
  else if (g === 'construction') pStatus = pill('Under Construction', 'ps-blue');
  else pStatus = pill('Not started');
  const bal = price ? Math.max(0, price - rec) : 0;
  const fullPay = !price ? '—' : bal <= 0.5 ? pill('Fully received', 'ps-green') : `${money(bal)} still to receive`;
  if (posEl) posEl.innerHTML = `<div class="ps-head"><i class="fa-solid fa-key"></i> Possession</div>
    <div class="ps-grid">${item('Possession Status', pStatus)}${item('Expected Possession', fmtD(c && c.expected_possession_date))}
      ${item('Possession Date', fmtD(c && c.posdate))}${item('Handover Date', fmtD(c && c.handover))}
      ${item('Keys Handed Over', esc((c && c.keys_handed) || 'No'))}${item('Final Payment', fullPay)}</div>
    <p class="ps-note">Possession is handled by the admin — shown here read-only.${IS_ACCOUNTS ? ' Keys are handed over only once the villa price is fully received.' : ''}</p>`;
}

// ---------- Lead activity log (Notes tab) ----------
// A single shared JSON log (stored in clients.activity_log) that both the
// automatic Contact Attempted entries and manual notes write into, so it
// can be reused anywhere else in the app that needs this lead's history.
function loadActivityLog(c) {
  let log = [];
  try { log = c && c.activity_log ? JSON.parse(c.activity_log) : []; } catch (e) { log = []; }
  window.__crmActivityLog = Array.isArray(log) ? log : [];
  renderActivityLog();
}
function renderActivityLog() {
  const el = document.getElementById('activityLogList');
  document.getElementById('activityLogField').value = JSON.stringify(window.__crmActivityLog || []);
  if (!el) return;
  // Scoped to Contact Attempted — only show attempt-related entries here,
  // not every stage's history.
  const log = (window.__crmActivityLog || []).filter(x => x.type === 'Contact Attempted' || x.type === 'Note' || x.type === 'Call');
  if (!log.length) { el.innerHTML = '<div class="attempt-log-empty">No activity logged yet.</div>'; return; }
  const icon = t => t === 'Note' ? 'fa-pen' : t === 'Call' ? 'fa-phone-flip' : 'fa-phone';
  el.innerHTML = log.slice().reverse().map(x =>
    `<div class="attempt-log-item">
       <div class="ali-ico"><i class="fa-solid ${icon(x.type)}"></i></div>
       <div class="ali-body">
         <div class="ali-note">${esc(x.note || '')}</div>
         <div class="ali-meta">${esc(x.date || '')}${x.time ? ' · ' + esc(formatTime12(x.time)) : ''}${x.by ? ' · by ' + esc(x.by) : ''}</div>
       </div>
     </div>`
  ).join('');
}
function addActivityLog(note, type) {
  if (!window.__crmActivityLog) window.__crmActivityLog = [];
  window.__crmActivityLog.push({ date: today(), time: nowTime(), type: type || '', note: note || '', by: window.CURRENT_USER.name }); // who did it — survives reassignment
  renderActivityLog();
}
// Generic "Lead History" entry for any stage move — separate from the
// Attempt Log's own more specific entries — so every stage a lead has ever
// passed through, and when, can always be reconstructed later.
function logStageChange(stage) {
  addActivityLog(`Moved to ${stage}`, 'Stage Change');
}
function addManualNote() {
  const ta = document.getElementById('manualNoteInput');
  const val = (ta.value || '').trim();
  if (!val) return;
  addActivityLog(val, 'Note');
  ta.value = '';
}
// Auto-fills First Call Date / Attempt Time with "now" whenever a result is
// picked (the salesperson can still edit either field afterwards), logs the
// attempt, and — when the result is "Connected" — advances the lead
// straight into the Contacted stage with the same date, switching the view
// there automatically.
function onAttemptResultChange(sel) {
  const f = document.getElementById('clientForm');
  if (!sel.value) {
    f.elements.firstcall.value = '';
    f.elements.attempt_time.value = '';
    return;
  }
  f.elements.firstcall.value = today();
  f.elements.attempt_time.value = nowTime();
  addActivityLog(`Contact Attempted — ${sel.value}`, 'Contact Attempted');
  if (sel.value === 'Connected') {
    f.elements.lastcontact.value = today();
    setStage('Contacted'); // also logs the stage change itself
  }
}
// Once both Qualified fields are filled in (either answer counts, "Not
// set" is what blocks it), the lead is fully qualified — move it on to
// Requirement Understood automatically.
function checkQualifiedComplete() {
  const f = document.getElementById('clientForm');
  if (f.elements.budget_confirmed.value && f.elements.decision_maker.value) {
    setStage('Requirement Understood');
  }
}
// Follow-up stages: the Re-follow-up Date only shows when Re-follow-up is "Yes". prefix = sv | psv | rv.
function updateStageRefollowUI(prefix) {
  const f = document.getElementById('clientForm');
  const sel = f.elements[prefix + '_refollow'];
  const dt = f.elements[prefix + '_refollow_date'];
  const yes = !!sel && sel.value === 'Yes';
  document.getElementById(prefix + 'RefollowDateWrap')?.classList.toggle('hidden', !yes);
  if (!yes && dt && !dt.disabled) dt.value = '';
}
function updateAllRefollowUI() {
  Object.values(FOLLOWUP_STAGES).forEach(cfg => updateStageRefollowUI(cfg.prefix));
}
// Adds a Lead History line when a follow-up stage's details are new/changed on save.
function logFollowupsIfChanged(f) {
  const p0 = state.clients.find(x => String(x.id) === String(f.dataset.edit)) || {};
  Object.values(FOLLOWUP_STAGES).forEach(cfg => {
    const p = cfg.prefix;
    const el = f.elements[p + '_followup_date'];
    if (!el || el.disabled || !el.value) return;
    const cur = { d: el.value, m: f.elements[p + '_followup_mode'].value, n: f.elements[p + '_followup_note'].value.trim(), r: f.elements[p + '_refollow'].value, rd: f.elements[p + '_refollow_date'].value };
    if (cur.d === (p0[p + '_followup_date'] || '') && cur.m === (p0[p + '_followup_mode'] || '') && cur.n === (p0[p + '_followup_note'] || '').trim() &&
        cur.r === (p0[p + '_refollow'] || '') && cur.rd === (p0[p + '_refollow_date'] || '')) return;
    let note = `${cfg.label} — ${cur.d}${cur.m ? ' via ' + cur.m : ''}${cur.n ? ': ' + cur.n : ''}`;
    if (cur.r === 'Yes' && cur.rd) note += ` · Re-follow-up on ${cur.rd}`;
    else if (cur.r === 'No') note += ' · No re-follow-up needed';
    addActivityLog(note, 'Follow-up');
  });
}
// Lead History entries for Unit Selection / Unit Blocked details, logged only when they changed.
function logUnitDetailsIfChanged(f) {
  const p0 = state.clients.find(x => String(x.id) === String(f.dataset.edit)) || {};
  const val = n => (f.elements[n] && !f.elements[n].disabled ? String(f.elements[n].value || '').trim() : null);
  const norm = (n, v) => ['token_amount', 'bookingamount'].includes(n) ? String(Number(v) || 0)
    : n === 'final_villa_price' ? String(inrToNumber(v) || String(v ?? '').trim()) : String(v ?? '').trim();
  const changed = names => names.some(n => val(n) !== null && norm(n, val(n)) !== norm(n, p0[n]));
  const logged = t => (window.__crmActivityLog || []).some(x => x.type === t); // already has an entry of this kind
  const us = ['villa', 'offered_price'];
  if ((changed(us) || !logged('Unit Selection')) && us.some(n => val(n))) {
    const parts = [];
    if (val('villa')) parts.push(`Villa: ${val('villa')}`);
    if (val('offered_price')) parts.push(`Offered price: ${val('offered_price')}`);
    addActivityLog(`Unit Selection — ${parts.join(' · ')}`, 'Unit Selection');
  }
  const ub = ['block_date', 'token_amount'];
  const hasUb = !!(val('block_date') || Number(val('token_amount')));
  if ((changed(ub) || !logged('Unit Blocked')) && hasUb) {
    const parts = [];
    if (val('villa')) parts.push(`Villa: ${val('villa')}`);
    if (val('block_date')) parts.push(`Blocked on ${val('block_date')}`);
    if (Number(val('token_amount'))) parts.push(`Token: ${money(val('token_amount'))}`);
    addActivityLog(`Unit Blocked — ${parts.join(' · ')}`, 'Unit Blocked');
  }
  logPostSalesDetailsIfChanged(f, p0);
}
// Post Sales Lead History: one line per checkpoint listing every detail that was filled in or
// changed on this save (old value shown as "was …"), typed with the checkpoint's name.
// Kinds: money | date | text (quoted, shortened) | updated (value not written into history).
const POST_SALES_LOG_FIELDS = {
  'Booking Initiated': [['final_villa_price', 'Final villa price', 'money'], ['bookingamount', 'Booking amount', 'money'], ['bookingdate', 'Booking date', 'date'],
    ['booking_payment_mode', 'Payment mode'], ['booking_txn_ref', 'Cheque / Txn ref.']],
  'KYC Verification': [['kyc_status', 'KYC status'], ['kyc_verified_date', 'Verified on', 'date'], ['applicant_type', 'Applicant type'],
    ['applicant_pan', 'PAN', 'updated'], ['applicant_aadhaar_last4', 'Aadhaar (last 4)', 'updated'], ['applicant_dob', 'Date of birth', 'updated'],
    ['applicant_occupation', 'Occupation'], ['applicant_address', 'KYC address', 'updated'], ['co_applicant_name', 'Co-applicant'],
    ['co_applicant_relation', 'Co-applicant relation'], ['co_applicant_pan', 'Co-applicant PAN', 'updated'],
    ['co_applicant_aadhaar_last4', 'Co-applicant Aadhaar (last 4)', 'updated'], ['kyc_note', 'KYC note', 'text']],
  'Booking Confirmed': [['confirmation_date', 'Confirmation date', 'date']],
  'Agreement In Process': [['draft_agreement_date', 'Draft shared on', 'date'], ['agreement_date', 'Agreement date', 'date'],
    ['stamp_duty_amount', 'Stamp duty', 'money'], ['registration_fee', 'Registration fee', 'money'], ['agreement_notes', 'Agreement note', 'text']],
  'Registered': [['registration_date', 'Registration date', 'date'], ['registration_doc_no', 'Registration no.'], ['sub_registrar_office', 'Sub-Registrar office']],
  // Payment schedule / demands / payments write their own history lines (api/payments.php) — typed with
  // the checkpoint's name, so every construction checkpoint is listed here (even without form fields).
  'Construction Customer': [['loan', 'Home loan'], ['loan_bank', 'Loan bank'], ['fitout', 'Fit-out status']],
  'Plinth Completed': [], 'First Slab Completed': [], 'Second Slab Completed': [], 'Brickwork Completed': [],
  'Plaster & Flooring Completed': [], 'Fittings Completed': [],
  'Possession Due': [['expected_possession_date', 'Expected possession', 'date']],
  'Possession Offered': [['inspection', 'Final inspection'], ['snagging', 'Snagging'], ['posletter', 'Possession letter'], ['posdate', 'Possession date', 'date']],
  'Handover Completed': [['handover', 'Handover date', 'date'], ['keys', 'Keys handed over'], ['allotment_date', 'Allotment date', 'date']],
};
const POST_SALES_LOG_TYPES = Object.keys(POST_SALES_LOG_FIELDS);
function logPostSalesDetailsIfChanged(f, p0) {
  const dbKey = { keys: 'keys_handed' };
  for (const [stage, list] of Object.entries(POST_SALES_LOG_FIELDS)) {
    const parts = [];
    for (const [name, label, kind] of list) {
      const el = f.elements[name];
      if (!el || el.disabled) continue; // checkpoint locked / not rendered — nothing submitted for it
      const now = String(el.value ?? '').trim();
      const was = String(p0[dbKey[name] || name] ?? '').trim();
      const norm = v => kind === 'money' ? String((name === 'final_villa_price' ? inrToNumber(v) : Number(v)) || 0)
        : kind === 'date' ? v.slice(0, 10) : v.toUpperCase();
      if (norm(now) === norm(was)) continue;
      // A dropdown that was never saved just showing its first option isn't a real change.
      if (!was && el.tagName === 'SELECT' && el.options.length && now === el.options[0].value) continue;
      if (kind === 'updated') { parts.push(`${label} ${now ? 'updated' : 'removed'}`); continue; }
      if (kind === 'text') { parts.push(now ? `${label}: "${now.length > 90 ? now.slice(0, 90) + '…' : now}"` : `${label} cleared`); continue; }
      const show = v => kind === 'money' ? ((name === 'final_villa_price' ? inrToNumber(v) : Number(v)) ? money(name === 'final_villa_price' ? inrToNumber(v) : v) : '')
        : kind === 'date' ? fmtDay(v) : v;
      const nv = show(now), ov = show(was);
      parts.push(nv ? `${label}: ${nv}${ov ? ` (was ${ov})` : ''}` : `${label} cleared`);
    }
    if (parts.length) addActivityLog(`${stage} — ${parts.join(' · ')}`, stage);
  }
}
document.getElementById('clientForm').onsubmit = async (e) => {
  e.preventDefault();
  const sf = e.target.elements;
  for (const [name, cfg] of Object.entries(FOLLOWUP_STAGES)) {
    const re = sf[cfg.prefix + '_refollow'];
    if (re && !re.disabled && re.value === 'Yes' && !sf[cfg.prefix + '_refollow_date'].value) {
      alert(`Please pick the Re-follow-up Date (${name}).`);
      return;
    }
  }
  for (const [k, label] of [['applicant_pan', 'PAN No.'], ['co_applicant_pan', 'Co-applicant PAN']]) {
    const el = sf[k];
    if (el && !el.disabled && el.value.trim()) {
      el.value = el.value.trim().toUpperCase();
      if (!/^[A-Z]{5}[0-9]{4}[A-Z]$/.test(el.value)) { alert(`${label} looks invalid — expected format ABCDE1234F.`); return; }
    }
  }
  for (const k of ['applicant_aadhaar_last4', 'co_applicant_aadhaar_last4']) {
    const el = sf[k];
    if (el && !el.disabled && el.value.trim() && !/^\d{4}$/.test(el.value.trim())) { alert('Aadhaar: enter only the last 4 digits.'); return; }
  }
  logFollowupsIfChanged(e.target);
  logUnitDetailsIfChanged(e.target);
  const handover = sf.sales_handover && sf.sales_handover.value === '1';
  if (handover) addActivityLog('Sales completed — lead transferred to Post Sales', 'Sales Handover');
  const accHandover = sf.accounts_handover && sf.accounts_handover.value === '1';
  if (accHandover) addActivityLog('Legal completed — lead transferred to Accounts', 'Accounts Handover');
  let o = Object.fromEntries(new FormData(e.target).entries());
  o.id = e.target.dataset.edit || null;
  const undoHandover = () => {
    const log = window.__crmActivityLog || [];
    if (accHandover) {
      sf.accounts_handover.value = '';
      if (log.length && log[log.length - 1].type === 'Accounts Handover') { log.pop(); renderActivityLog(); }
    }
    if (!handover) return;
    sf.sales_handover.value = '';
    if (log.length && log[log.length - 1].type === 'Sales Handover') { log.pop(); renderActivityLog(); }
  };
  let result;
  try {
    result = await api('clients.php', 'POST', o);
  } catch (err) {
    undoHandover();
    alert('Could not reach the server — the save did not go through. Check your connection and try again.');
    return;
  }
  if (!result || result.error) { undoHandover(); alert((result && result.error) || 'Save failed — please try again.'); return; }
  sf.sales_handover.value = '';
  if (sf.accounts_handover) sf.accounts_handover.value = '';
  for (const [kind, k] of Object.entries(VISIT_KINDS)) {
    if (isVisitKindStage(k, o.stage) && document.getElementById(k.idp + 'InviteAuto')?.checked) {
      await sendVisitInvite(true, result.id, kind); // no-op unless date/time/email changed since last invite
    }
  }
  if (handover && CAN_POST_SALES) {
    // Admin: stay on the lead and jump to its Post Sales tabs.
    await loadAll();
    nav('leads');
    openClient(result.id);
    switchPhase('post');
    return;
  }
  if (accHandover && IS_ADMIN) {
    // Admin: stay on the lead and jump to Construction & Payment.
    await loadAll();
    nav('leads');
    openClient(result.id);
    clientTab('construction');
    return;
  }
  closeModal('clientModal');
  await loadAll();
  nav('leads');
  if (handover) alert('Sales completed — the lead has been transferred to the Post Sales team.');
  if (accHandover) alert('Legal completed — the lead has been transferred to the Accounts team.');
};

// ---------- Site Visit Scheduled: calendar invite + WhatsApp message ----------
function fmtVisitDate(d) {
  return d ? new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' }) : '';
}
function fmtVisitTime(t) {
  if (!t) return '';
  const [h, m] = t.split(':').map(Number);
  return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
}
// Visit types that get a calendar invite (email) + WhatsApp message. Keys match `kind` in api/visit_invite.php.
const VISIT_KINDS = {
  site:        { stage: 'Site Visit Scheduled', followupStage: 'Site Visit Follow-up', label: 'Site Visit',        noun: 'site visit',        idp: 'visit',       date: 'visit_date',             time: 'visit_time',             pickup: 'visit_pickup', wa: 'visit_whatsapp_sent',       inv: 'visit_invite' },
  revisit:     { stage: 'Re-Visit Scheduled',   followupStage: 'Re-Visit Follow-up',   label: 'Re-Visit',          noun: 're-visit',          idp: 'revisit',     date: 'revisit_date',           time: 'revisit_time',           pickup: null,           wa: 'revisit_whatsapp_sent',     inv: 'revisit_invite' },
  negotiation: { stage: 'Negotiation Visit Scheduled', followupStage: 'Negotiation Visit Follow-up', label: 'Negotiation Visit', noun: 'negotiation visit', idp: 'negotiation', date: 'negotiation_visit_date', time: 'negotiation_visit_time', pickup: null,           wa: 'negotiation_whatsapp_sent', inv: 'negotiation_invite' },
};
// A scheduled visit's details stay live at its own stage and at its optional follow-up stage
// (Site Visit Scheduled → Site Visit Follow-up, Re-Visit Scheduled → Re-Visit Follow-up,
// Negotiation Visit Scheduled → Negotiation Visit Follow-up). Negotiation Visit is logged
// Completed once the lead reaches Unit Selection.
function isVisitKindStage(k, stage) {
  return stage === k.stage || (!!k.followupStage && stage === k.followupStage);
}
// Every scheduled-but-not-yet-completed visit across all 3 kinds (Site Visit,
// Re-Visit, Negotiation Visit), read straight off each lead's own fields —
// used by both the Dashboard panel and the Site Visits page so a Re-Visit or
// Negotiation Visit shows up there too, not just a first Site Visit.
function pendingScheduledVisits() {
  const rows = [];
  state.clients.forEach(c => {
    Object.entries(VISIT_KINDS).forEach(([kind, k]) => {
      if (isVisitKindStage(k, c.stage) && c[k.date]) {
        rows.push({
          client_id: c.id, client_name: c.name, kind, kindLabel: k.label,
          visit_date: c[k.date], visit_time: k.time ? c[k.time] : '',
          pickup: k.pickup ? c[k.pickup] : '', salesperson_name: c.salesperson_name,
        });
      }
    });
  });
  return rows;
}
function visitTypeBadge(kind) {
  const k = VISIT_KINDS[kind];
  const colors = { site: '#eaf2ff|#1d4ed8', revisit: '#fdf0e3|#c2660d', negotiation: '#f3eaff|#7c3aed' };
  const [bg, fg] = (colors[kind] || '#eee|#555').split('|');
  return `<span class="badge" style="background:${bg};color:${fg}">${esc(k ? k.label : kind)}</span>`;
}
// Same location as the calendar invite (config/site.php VISIT_LOCATION). A place name/address also gets
// a Google Maps link (what the calendar builds from it); a pasted Maps link is used as-is.
function siteLocationLines() {
  const loc = (window.SITE_LOCATION || '').trim();
  if (!loc) return '';
  if (/^https?:\/\//i.test(loc)) return `📍 Location: ${loc}\n`;
  return `📍 Location: ${loc}\nhttps://www.google.com/maps/search/?api=1&query=${encodeURIComponent(loc)}\n`;
}
// Drafts the message until the salesperson edits it by hand (dataset.auto).
function fillVisitWhatsapp(force, kind = 'site') {
  const k = VISIT_KINDS[kind];
  const f = document.getElementById('clientForm');
  const ta = document.getElementById(k.idp + 'WhatsappMsg');
  if (!ta) return;
  if (!force && ta.value.trim() && ta.dataset.auto !== '1') return;
  const d = f.elements[k.date].value, t = f.elements[k.time].value;
  if (!d || !t) { ta.value = ''; ta.dataset.auto = '1'; return; }
  const sp = f.elements.salesperson_id.selectedOptions[0]?.text || window.CURRENT_USER.name;
  ta.value = `Hi ${f.elements.name.value || 'there'}! 👋\n\nYour ${k.noun} to *Antaaya Villas, Lonavala* is confirmed 🏡\n\n` +
    `📅 Date: *${fmtVisitDate(d)}*\n⏰ Time: *${fmtVisitTime(t)}*\n` +
    (k.pickup && f.elements[k.pickup].value === 'Yes' ? `🚗 Pickup: our team will call you to confirm the pickup point\n` : '') +
    siteLocationLines() +
    `\nYour contact: ${sp}\n\nLooking forward to meeting you! 🙌`;
  ta.dataset.auto = '1';
}
function onVisitWhatsappSentChange(sel, kind = 'site') {
  if (sel.value !== 'Yes') return;
  const k = VISIT_KINDS[kind];
  const f = document.getElementById('clientForm');
  const d = f.elements[k.date].value, t = f.elements[k.time].value;
  logCommSent('WhatsApp Sent', `WhatsApp visit details sent to client — ${k.stage}${d ? ` (${fmtVisitDate(d)}${t ? ', ' + fmtVisitTime(t) : ''})` : ''}`);
}
function copyVisitWhatsapp(kind = 'site') {
  const ta = document.getElementById(VISIT_KINDS[kind].idp + 'WhatsappMsg');
  ta.select();
  navigator.clipboard?.writeText(ta.value).catch(() => document.execCommand('copy'));
}
function openVisitWhatsapp(kind = 'site') {
  openWhatsappChat(document.getElementById(VISIT_KINDS[kind].idp + 'WhatsappMsg').value);
}
function fmtSentAt(s) {
  return s ? new Date(s.replace(' ', 'T')).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
}
function showInviteStatus(sentAt, email, kind = 'site') {
  const el = document.getElementById(VISIT_KINDS[kind].idp + 'InviteStatus');
  if (!el) return;
  el.textContent = sentAt ? `✓ Invite last sent ${fmtSentAt(sentAt)}${email ? ' to ' + email : ''}` : 'Not sent yet.';
}
// Called from openClient: draft messages + invite status for every visit type.
function initVisitShare(c) {
  Object.entries(VISIT_KINDS).forEach(([kind, k]) => {
    const ta = document.getElementById(k.idp + 'WhatsappMsg');
    if (ta) ta.dataset.auto = '';
    fillVisitWhatsapp(true, kind);
    const auto = document.getElementById(k.idp + 'InviteAuto');
    if (auto) auto.checked = true;
    const sentAt = c && c[k.inv + '_sent_at'];
    showInviteStatus(sentAt, sentAt ? ((c[k.inv + '_for'] || '').split('|')[1] || c.email || '') : '', kind);
  });
}
// auto=true: called after Save; server skips it if this date/time/email was already sent.
// clientRequested=true: this is a resend because the client proposed a
// different time (see sendRescheduledInvite below) — wording + admin notify differ server-side.
async function sendVisitInvite(auto, clientId, kind = 'site', clientRequested = false) {
  const k = VISIT_KINDS[kind];
  const f = document.getElementById('clientForm');
  const id = clientId || f.dataset.edit;
  if (!auto && window.__crmCanEdit === false) { alert('Read-only — only the assigned salesperson or an admin can send this.'); return; }
  if (!id) { if (!auto) alert('Save the lead first, then send the invite.'); return; }
  if (!f.elements[k.date].value || !f.elements[k.time].value) { if (!auto) alert(`Set both the ${k.label} date and time first.`); return; }
  if (!f.elements.email.value.trim()) { if (!auto) alert('This client has no email address.'); return; }
  const btn = document.getElementById(k.idp + (clientRequested ? 'RescheduleBtn' : 'InviteBtn'));
  if (btn && !auto) { btn.disabled = true; }
  const r = await api('visit_invite.php', 'POST', {
    id, kind, visit_date: f.elements[k.date].value, visit_time: f.elements[k.time].value,
    visit_pickup: k.pickup ? f.elements[k.pickup].value : '', email: f.elements.email.value.trim(),
    only_if_changed: auto ? 1 : 0, client_requested: clientRequested ? 1 : 0
  });
  if (btn) btn.disabled = false;
  if (!r || r.error) { alert((r && r.error) || 'Invite failed.'); return r; }
  if (!r.skipped) {
    showInviteStatus(r.sent_at, r.email, kind);
    // Server already saved this entry; mirror it in the open form so the next Save doesn't drop it.
    if (r.log_entry) { (window.__crmActivityLog = window.__crmActivityLog || []).push(r.log_entry); renderActivityLog(); }
    if (!auto) {
      alert(clientRequested
        ? ('Updated invite sent to ' + r.email + '. ' + (r.mail_warning ? 'Sales team NOT emailed — ' + r.mail_warning + '.' : 'Sales team notified by email and in the CRM.'))
        : ('Calendar invite sent to ' + r.email));
    }
  }
  return r;
}
// Google Calendar's "Propose a new time" reply (to the invite sendVisitInvite
// emailed out) lands only in the assigned salesperson's own Gmail inbox — the
// CRM has no way to see it automatically. So: update the date/time fields
// above to what the client proposed, then click this — it resends the
// invite (same event, updated) worded as a reschedule, and notifies every
// admin by email + a permanent CRM notification.
async function sendRescheduledInvite(kind) {
  const k = VISIT_KINDS[kind];
  const f = document.getElementById('clientForm');
  if (!f.elements[k.date].value || !f.elements[k.time].value) {
    alert(`Update the ${k.label} date and time above to what the client proposed, then click this again.`);
    return;
  }
  if (!confirm(`Send the client an updated invite for ${f.elements[k.date].value} at ${f.elements[k.time].value}, and notify the sales team?`)) return;
  await sendVisitInvite(false, null, kind, true);
}
// Wired up once: keep each WhatsApp draft in step with its date/time/pickup edits.
Object.entries(VISIT_KINDS).forEach(([kind, k]) => {
  [k.date, k.time, k.pickup].filter(Boolean).forEach(n => {
    const form = document.getElementById('clientForm');
    const el = form.elements[n];
    if (!el) return;
    const onEdit = () => {
      fillVisitWhatsapp(false, kind);
      // New date/time → any earlier WhatsApp message is now out of date, so "Sent?" resets.
      if (n !== k.pickup) form.elements[k.wa].value = '';
    };
    el.addEventListener('input', onEdit); el.addEventListener('change', onEdit);
  });
});

// ---------- Follow-up modal ----------
function openFollowup() {
  populateClientSelect('fuClient', c => pipelineIndex(c.stage) >= pipelineIndex('Requirement Understood') && (IS_ADMIN || (!c.sales_handover_at && !isPostSalesStage(c.stage))));
  document.getElementById('followForm').reset();
  document.getElementById('followForm').elements.date.value = today();
  updateFuRefollowUI();
  onFuClientChange();
  openModal('followModal');
}
// Follow-up Type follows the chosen lead's current stage (the server detects it the same way).
function onFuClientChange() {
  const sel = document.getElementById('fuType');
  const c = state.clients.find(x => String(x.id) === String(document.getElementById('fuClient').value));
  sel.value = c ? FOLLOWUP_STAGES[detectFollowupStage(c.stage)].type : '';
}
function updateFuRefollowUI() {
  const f = document.getElementById('followForm');
  const yes = f.elements.refollow.value === 'Yes';
  document.getElementById('fuRefollowDateWrap').classList.toggle('hidden', !yes);
  if (!yes) f.elements.refollow_date.value = '';
}
document.getElementById('followForm').onsubmit = async (e) => {
  e.preventDefault();
  const o = Object.fromEntries(new FormData(e.target).entries());
  if (o.refollow === 'Yes' && !o.refollow_date) { alert('Please pick the Re-follow-up Date.'); return; }
  o.ldate = today(); o.ltime = nowTime(); // for the Lead History entry
  const result = await api('followups.php', 'POST', o);
  if (result && result.error) { alert(result.error); return; }
  closeModal('followModal');
  await loadAll();
};

// ---------- Visit modal ----------
// Client dropdown here is deliberately restricted to leads that have at
// least reached "Requirement Understood" — this form is for scheduling a
// site visit for a qualified lead, not a generic client picker showing
// every brand-new/unqualified lead in the system.
function openVisit() {
  populateClientSelect('visitClient', c => pipelineIndex(c.stage) >= pipelineIndex('Requirement Understood'));
  document.getElementById('visitForm').reset();
  document.getElementById('visitForm').elements.date.value = today();
  onVisitClientChange();
  openModal('visitModal');
}
// Visit type follows the chosen lead's current stage (the server detects it the same way).
function detectVisitKind(stage) {
  const idx = PIPELINE_STAGES.indexOf(stage);
  if (idx === -1) return 'site';
  if (idx >= pipelineIndex(VISIT_KINDS.negotiation.stage)) return 'negotiation';
  if (idx >= pipelineIndex(VISIT_KINDS.revisit.stage)) return 'revisit';
  return 'site';
}
function onVisitClientChange() {
  const c = state.clients.find(x => String(x.id) === String(document.getElementById('visitClient').value));
  document.getElementById('visitKind').value = c ? detectVisitKind(c.stage) : '';
}
document.getElementById('visitForm').onsubmit = async (e) => {
  e.preventDefault();
  const o = Object.fromEntries(new FormData(e.target).entries());
  const result = await api('visits.php', 'POST', o);
  if (result && result.error) { alert(result.error); return; }
  closeModal('visitModal');
  await loadAll();
};

function populateClientSelect(id, filterFn) {
  const list = filterFn ? state.clients.filter(filterFn) : state.clients;
  document.getElementById(id).innerHTML = '<option value="">Select client</option>' +
    list.map(c => `<option value="${c.id}">${esc(c.name)} (${esc(c.lead_code || c.id)})</option>`).join('');
}

// Edit icon on a follow-up row: opens that lead on the follow-up stage section matching the
// row's type (or the lead's own stage if the row has none) — if the lead has reached that
// stage; otherwise just opens the lead.
function goEditFollowup(clientId, type) {
  openClient(clientId);
  const f = document.getElementById('clientForm');
  const cur = f.elements.stage.value || 'New Lead';
  const target = Object.keys(FOLLOWUP_STAGES).find(n => FOLLOWUP_STAGES[n].type === type) || detectFollowupStage(cur);
  if (pipelineIndex(cur) >= pipelineIndex(target)) {
    clientTab(stageGroupKey(target));
    window.__crmViewStage = target;
    applyStageLocks(cur);
  }
}
async function completeFU(id, spawnNext) {
  await api('complete_followup.php', 'POST', { id, date: today(), time: nowTime(), spawn_next: !!spawnNext });
  await loadAll();
}

// ---------- Renderers ----------
let cachedWeatherIcon = null; // {icon, color} — set once weather data is fetched
function weatherToIcon(code, isDay) {
  const day = isDay === 1 || isDay === true;
  if (code === 0) return day ? { icon: 'fa-sun', color: '#f5a623' } : { icon: 'fa-moon', color: '#4c51bf' };
  if (code === 1 || code === 2) return day ? { icon: 'fa-cloud-sun', color: '#e8a33d' } : { icon: 'fa-cloud-moon', color: '#6366f1' };
  if (code === 3) return { icon: 'fa-cloud', color: '#4f7ea8' };
  if (code === 45 || code === 48) return { icon: 'fa-smog', color: '#7c93a8' };
  if ([51, 53, 55, 56, 57].includes(code)) return { icon: 'fa-cloud-rain', color: '#4a90d9' };
  if ([61, 63, 65, 66, 67, 80, 81, 82].includes(code)) return { icon: 'fa-cloud-showers-heavy', color: '#2563eb' };
  if ([71, 73, 75, 77, 85, 86].includes(code)) return { icon: 'fa-snowflake', color: '#93c5fd' };
  if ([95, 96, 99].includes(code)) return { icon: 'fa-bolt', color: '#7c3aed' };
  return day ? { icon: 'fa-sun', color: '#f5a623' } : { icon: 'fa-moon', color: '#4c51bf' };
}
async function refreshWeatherIcon() {
  try {
    const res = await fetch('https://api.open-meteo.com/v1/forecast?latitude=18.75&longitude=73.41&current_weather=true');
    const data = await res.json();
    if (data && data.current_weather) {
      cachedWeatherIcon = weatherToIcon(data.current_weather.weathercode, data.current_weather.is_day);
      renderGreeting();
    }
  } catch (e) { /* offline / blocked — keep the time-of-day icon */ }
}
function renderGreeting() {
  const el = document.getElementById('greetingText');
  const nameEl = document.getElementById('greetingName');
  const iconEl = document.getElementById('greetingIcon');
  if (!el || !nameEl) return;
  const h = new Date().getHours();
  el.textContent = h < 12 ? 'Good Morning' : h < 17 ? 'Good Afternoon' : 'Good Evening';
  nameEl.textContent = (window.CURRENT_USER && window.CURRENT_USER.name) || 'there';
  if (iconEl) {
    // Time-of-day fallback (shown instantly, before/if weather can't load),
    // overridden by the real weather-based icon+color once fetched.
    const fallback = h < 12 ? { icon: 'fa-cloud-sun', color: '#f5a623' }
      : h < 17 ? { icon: 'fa-sun', color: '#e8801a' }
      : h < 19 ? { icon: 'fa-cloud-sun', color: '#c2410c' }
      : { icon: 'fa-moon', color: '#4c51bf' };
    const pick = cachedWeatherIcon || fallback;
    iconEl.className = `fa-solid ${pick.icon}`;
    iconEl.style.color = pick.color;
  }
}
// Shared bits for the two Post Sales dashboards.
const psFmtD = d => d ? esc(new Date(String(d).slice(0, 10) + 'T00:00:00').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })) : '—';
const psOpenBtn = id => `<button type="button" class="btn" title="Open lead" onclick="event.stopPropagation();openClient(${id})"><i class="fa-solid fa-pen-to-square"></i></button>`;
function psSet(id, v) { const el = document.getElementById(id); if (el) el.textContent = v; }
// Accounts dashboard: every client with a payment due, oldest pay-by date first — full width with
// Lead ID, client + mobile, villa, construction status, paid bar, amount due (% of villa price),
// pay-by date with Overdue / Due-in days, and a button straight to Construction & Payment.
function psDuesTable(list) {
  const dues = list.filter(c => c.stage !== 'Cancelled' && +c.dueamount > 0.5)
    .sort((a, b) => String(a.duedate || '9999').localeCompare(String(b.duedate || '9999')));
  const muted = t => `<div style="font-size:11px;color:var(--muted)">${t}</div>`;
  const days = d => Math.round((new Date(d + 'T00:00:00') - new Date(today() + 'T00:00:00')) / 86400000);
  const when = c => {
    if (!c.duedate) return muted('No pay-by date');
    const n = days(String(c.duedate).slice(0, 10));
    return n < 0 ? payPill(`Overdue ${-n}d`, 'pp-od') : n === 0 ? payPill('Due today', 'pp-od') : payPill(`Due in ${n}d`, 'pp-due');
  };
  const MAX = 10;
  return {
    dues,
    html: dues.length
      ? '<div class="table-wrap"><table><thead><tr>'
      + '<th><i class="fa-solid fa-calendar-day"></i> Pay By</th><th><i class="fa-solid fa-hashtag"></i> Lead ID</th><th><i class="fa-solid fa-user"></i> Client</th>'
      + '<th><i class="fa-solid fa-house-chimney"></i> Villa</th><th><i class="fa-solid fa-person-digging"></i> Construction Status</th>'
      + '<th><i class="fa-solid fa-hourglass-half"></i> Due Amount</th><th><i class="fa-solid fa-chart-simple"></i> Paid</th><th style="text-align:right"><i class="fa-solid fa-gear"></i> Action</th>'
      + '</tr></thead><tbody>'
      + dues.slice(0, MAX).map(c => {
        const price = villaPriceNum(c), due = +c.dueamount;
        const dem = c.pay_demanded == null || c.pay_demanded === '' ? null : +c.pay_demanded;
        return `<tr onclick="openLeadSummary(${c.id})" style="cursor:pointer">
          <td><b>${psFmtD(c.duedate)}</b><div style="margin-top:3px">${when(c)}</div></td>
          <td>${esc(c.lead_code || c.id)}</td>
          <td><b>${esc(c.name)}</b>${muted(esc(c.mobile) || '')}</td>
          <td>${esc(c.villa) || '—'}${price ? muted(money(price)) : ''}</td>
          <td>${payConstructionCell(c, dem)}</td>
          <td><b>${money(due)}</b>${price ? muted(payPct(Math.round(due / price * 10000) / 100) + ' of villa price') : ''}</td>
          <td>${payMiniBar(c)}</td>
          <td onclick="event.stopPropagation()"><div class="actions" style="justify-content:flex-end">${callBtn(c, 'Payment due')}<button type="button" class="btn" title="Open Construction &amp; Payment" onclick="openClient(${c.id});setTimeout(() => clientTab('construction'), 0)"><i class="fa-solid fa-pen-to-square"></i></button></div></td></tr>`;
      }).join('')
      + '</tbody></table></div>'
      + (dues.length > MAX ? `<div style="text-align:right;margin-top:8px"><a href="#" onclick="setPayFilter('due');nav('payments');return false">View all ${dues.length} in Payments →</a></div>` : '')
      : '<div class="empty">No upcoming payment dues.</div>'
  };
}
// Legal desk dashboard — leads transferred by sales: booking & legal, then handed to Accounts.
// Admin / IT: same dashboard (Legal view), on every lead the Legal desk would see.
function renderLegalDashboard(all = state.clients) {
  const live = all.filter(c => c.stage !== 'Cancelled' && c.stage !== 'Closed');
  const pending = all.filter(leadHandedOver);
  const inLegal = all.filter(c => stageGroupKey(c.stage) === 'book' && !leadAccountsHandedOver(c));
  const ready = all.filter(c => c.stage === 'Registered' && !c.accounts_handover_at);
  const withAcc = live.filter(leadWithAccounts);
  const cancelled = all.filter(c => c.stage === 'Cancelled').length;
  psSet('psTransferred', live.length);
  psSet('psTransferredSub', cancelled ? `Active · ${cancelled} cancelled` : 'Active, from the sales team');
  psSet('psPending', pending.length);
  psSet('psLegal', inLegal.length);
  psSet('psReady', ready.length);
  psSet('psAccounts', withAcc.length);

  // Booking Pending — full width: everything Legal needs to start the booking (villa, price,
  // token, block date, who sent it and when) plus how long it has been waiting.
  const MAX = 10;
  const muted = t => `<div style="font-size:11px;color:var(--muted)">${t}</div>`;
  const waitDays = c => c.sales_handover_at ? Math.max(0, Math.round((new Date(today() + 'T00:00:00') - new Date(String(c.sales_handover_at).slice(0, 10) + 'T00:00:00')) / 86400000)) : null;
  const waitPill = n => n == null ? '' : `<div style="margin-top:3px"><span class="ps-pill ${n >= 7 ? 'ps-red' : n >= 3 ? '' : 'ps-blue'}">${n === 0 ? 'Today' : `${n}d waiting`}</span></div>`;
  const pendSorted = [...pending].sort((a, b) => String(b.sales_handover_at).localeCompare(String(a.sales_handover_at)));
  document.getElementById('psDashPending').innerHTML = pendSorted.length
    ? '<div class="table-wrap"><table><thead><tr>'
    + '<th><i class="fa-solid fa-hashtag"></i> Lead ID</th><th><i class="fa-solid fa-user"></i> Client</th><th><i class="fa-solid fa-house-chimney"></i> Villa</th>'
    + '<th><i class="fa-solid fa-tag"></i> Final Villa Price</th><th><i class="fa-solid fa-indian-rupee-sign"></i> Token Amount</th><th><i class="fa-solid fa-lock"></i> Unit Blocked On</th>'
    + '<th><i class="fa-solid fa-user-tie"></i> Sales Person</th><th><i class="fa-solid fa-right-left"></i> Transferred</th><th style="text-align:right"><i class="fa-solid fa-gear"></i> Action</th>'
    + '</tr></thead><tbody>'
    + pendSorted.slice(0, MAX).map(c => `<tr onclick="openLeadSummary(${c.id})" style="cursor:pointer">
        <td>${esc(c.lead_code || c.id)}</td>
        <td><b>${esc(c.name)}</b>${muted(esc(c.mobile) || '')}</td>
        <td>${esc(c.villa) || '—'}${c.offered_price ? muted('Offered ' + esc(c.offered_price)) : ''}</td>
        <td>${esc(villaPriceText(c)) || '—'}</td>
        <td>${+c.token_amount ? money(c.token_amount) : '—'}</td>
        <td>${psFmtD(c.block_date)}</td>
        <td>${esc(c.salesperson_name) || '—'}</td>
        <td>${psFmtD(c.sales_handover_at)}${waitPill(waitDays(c))}</td>
        <td><div class="actions" style="justify-content:flex-end">${callBtn(c)}${psOpenBtn(c.id)}</div></td></tr>`).join('')
    + '</tbody></table></div>'
    + (pendSorted.length > MAX ? `<div style="text-align:right;margin-top:8px"><a href="#" onclick="${IS_ADMIN ? "goToBookings('pending')" : "goToLeads({ stage: 'Unit Blocked' })"};return false">View all ${pendSorted.length} in ${IS_ADMIN ? 'Bookings' : 'Manage Leads'} →</a></div>` : '')
    : '<div class="empty">No leads waiting for booking.</div>';

  const pill = c => { const b = bookingStatusOf(c); return `<span class="ps-pill ${b.cls}">${esc(b.text)}</span>`; };
  document.getElementById('psDashLeads').innerHTML = all.slice(0, 8).map(c =>
    `<tr onclick="openLeadSummary(${c.id})" style="cursor:pointer"><td>${esc(c.lead_code || c.id)}</td><td><b>${esc(c.name)}</b></td><td>${esc(c.mobile) || '—'}</td><td>${esc(c.villa) || '—'}</td><td>${leadStageCell(c, 'legal')}</td><td>${pill(c)}</td><td>${esc(c.salesperson_name) || '—'}</td><td onclick="event.stopPropagation()"><div class="actions" style="justify-content:flex-end">${callBtn(c) || '—'}</div></td></tr>`
  ).join('') || '<tr><td colspan="8" class="empty">No leads transferred to Post Sales yet.</td></tr>';
}
// Accounts desk dashboard — payments only. Cards use the same figures as the Payments page
// (payLeadRows / payTotals); then Payment Due (oldest first) and the recent leads.
// Admin / IT: same dashboard (Accounts view), on every lead the Accounts desk would see.
function renderAccountsDashboard(all = state.clients) {
  const live = all.filter(c => c.stage !== 'Cancelled' && c.stage !== 'Closed' && leadWithAccounts(c));
  const fresh = all.filter(leadAccountsHandedOver).length;
  const T = payTotals(payLeadRows());
  psSet('acTransferred', live.length);
  psSet('acTransferredSub', fresh ? `${fresh} new from Legal — construction pending` : 'Active, from the legal team');
  psSet('acRec', shortInr(T.rec)); psSet('acRecSub', T.total ? `${payPct(T.recPct)} of ${shortInr(T.total)} villa value` : '—');
  psSet('acDue', shortInr(T.due)); psSet('acDueSub', T.dueCount ? `${T.dueCount} client${T.dueCount === 1 ? '' : 's'} to pay` : 'Nothing due right now');
  psSet('acOd', shortInr(T.od)); psSet('acOdSub', T.odCount ? `${T.odCount} past the pay-by date` : 'Nothing overdue');
  psSet('acUp', shortInr(T.later));
  document.getElementById('acDashDues').innerHTML = psDuesTable(all).html;

  const muted = t => `<div style="font-size:11px;color:var(--muted)">${t}</div>`;
  document.getElementById('acDashLeads').innerHTML = all.filter(c => c.stage !== 'Cancelled').slice(0, 8).map(c => {
    const due = +c.dueamount || 0, rec = +c.received || 0, od = due > 0.5 && c.duedate && c.duedate < today();
    const dem = c.pay_demanded == null || c.pay_demanded === '' ? null : +c.pay_demanded;
    return `<tr onclick="openLeadSummary(${c.id})" style="cursor:pointer"><td>${esc(c.lead_code || c.id)}</td>
      <td><b>${esc(c.name)}</b>${muted(esc(c.mobile) || '')}</td><td>${esc(c.villa) || '—'}</td>
      <td>${payConstructionCell(c, dem)}</td><td>${rec ? money(rec) : '—'}</td>
      <td>${due > 0.5 ? `<b>${money(due)}</b> ${od ? payPill('Overdue', 'pp-od') : payPill('Due', 'pp-due')}` : '—'}</td>
      <td>${payMiniBar(c)}</td><td onclick="event.stopPropagation()"><div class="actions" style="justify-content:flex-end">${callBtn(c, due > 0.5 ? 'Payment due' : '') || '—'}</div></td></tr>`;
  }).join('') || '<tr><td colspan="8" class="empty">No leads transferred to Accounts yet.</td></tr>';
}
// Admin / IT dashboard: Sales | Legal | Accounts switch — each view is that desk's own dashboard.
// The choice is remembered on this browser only (falls back to Sales).
const DASH_DESK_KEY = 'crm_dash_desk';
function switchDashDesk(desk) {
  const sw = document.getElementById('dashDeskSwitch');
  if (!sw) return;
  if (!['sales', 'legal', 'accounts'].includes(desk)) desk = 'sales';
  sw.querySelectorAll('button').forEach(b => b.classList.toggle('active', b.dataset.desk === desk));
  document.querySelectorAll('#dashboard .dash-desk').forEach(d => d.classList.toggle('hidden', d.dataset.desk !== desk));
  try { localStorage.setItem(DASH_DESK_KEY, desk); } catch (e) { /* private window — just not remembered */ }
}
(function restoreDashDesk() {
  let d = 'sales';
  try { d = localStorage.getItem(DASH_DESK_KEY) || 'sales'; } catch (e) { /* ignore */ }
  switchDashDesk(d);
})();
function renderAdminDeskViews() {
  const all = state.clients;
  psSet('dsSales', all.filter(leadWithSalesDesk).length);
  psSet('dsLegal', all.filter(leadWithLegalDesk).length);
  psSet('dsAccounts', all.filter(leadWithAccounts).length);
  renderLegalDashboard(all.filter(legalDeskScope));
  renderAccountsDashboard(all.filter(accountsDeskScope));
}
function renderDashboard() {
  renderGreeting();
  if (IS_LEGAL) { renderLegalDashboard(); return; }
  if (IS_ACCOUNTS) { renderAccountsDashboard(); return; }
  if (IS_ADMIN) renderAdminDeskViews();
  const pendingFU = state.followups.filter(f => f.status === 'Pending').length;
  const fuBadge = document.getElementById('followupBadge');
  if (fuBadge) { fuBadge.style.display = pendingFU ? 'flex' : 'none'; fuBadge.textContent = pendingFU; }
  // Upcoming (not yet completed) visits of any kind — Site Visit, Re-Visit or
  // Negotiation Visit — plus any manually logged visit still marked Scheduled.
  const pendingRows = pendingScheduledVisits();
  const pendingKeys = new Set(pendingRows.map(r => `${r.client_id}|${r.visit_date}`));
  const pendingVisits = pendingKeys.size + state.visits.filter(v => v.status === 'Scheduled' && !pendingKeys.has(`${v.client_id}|${v.visit_date}`)).length;
  const visitBadge = document.getElementById('visitBadge');
  if (visitBadge) { visitBadge.style.display = pendingVisits ? 'flex' : 'none'; visitBadge.textContent = pendingVisits; }
  document.getElementById('kLeads').textContent = state.clients.length;
  document.getElementById('kHot').textContent = state.clients.filter(c => c.interest === 'HOT').length;
  document.getElementById('kVisits').textContent = state.visits.length;
  document.getElementById('kBookings').textContent = state.clients.filter(isBookingConfirmed).length;
  document.getElementById('kCancelled').textContent =
    state.clients.filter(c => c.stage === 'Cancelled').length;

  let fu = state.followups.filter(x => x.status === 'Pending')
    .sort((a, b) => (a.next_date || '').localeCompare(b.next_date || '')).slice(0, 8);
  document.getElementById('dashFollowups').innerHTML = fu.length
    ? '<div class="table-wrap"><table><thead><tr><th>Date</th><th>Client</th><th>Type</th><th>Mode</th><th>Note</th><th>Status</th><th style="text-align:right">Action</th></tr></thead><tbody>' +
    fu.map(x => `<tr><td>${esc(x.next_date || x.followup_date)}</td><td>${esc(x.client_name)}</td><td>${fuTypeBadge(x)}</td><td>${esc(x.mode)}</td><td>${esc(x.next_action || (x.followup_type ? x.discussion : '')) || '—'}</td><td>${badge(x.status)}</td><td><div class="actions" style="justify-content:flex-end">${callBtnById(x.client_id, fuTypeLabel(x.followup_type) || 'Follow-up')}${IS_ENTRY_DESK ? viewLeadBtn(x.client_id) : `<button type="button" class="btn" title="Open lead's follow-up stage" onclick="goEditFollowup(${x.client_id}, '${esc(x.followup_type || '')}')"><i class="fa-solid fa-pen-to-square"></i></button>`}</div></td></tr>`).join('') +
    '</tbody></table></div>'
    : '<div class="empty">No pending follow-ups.</div>';

  // Scheduled visits of any kind (Site Visit / Re-Visit / Negotiation Visit)
  // still in their pending checkpoint — read straight off the lead record
  // itself; a real `visits` row is only logged once that kind is actually
  // marked Completed (see sync_site_visit/sync_revisit_visit/
  // sync_negotiation_visit in api/clients.php).
  let sv = pendingRows.sort((a, b) => (a.visit_date || '').localeCompare(b.visit_date || '')).slice(0, 8);
  document.getElementById('dashSiteVisits').innerHTML = sv.length
    ? '<div class="table-wrap"><table><thead><tr><th>Date</th><th>Time</th><th>Client</th><th>Visit Type</th><th>Sales Person</th><th>Pickup</th><th style="text-align:right">Action</th></tr></thead><tbody>' +
    sv.map(r => `<tr><td>${esc(r.visit_date)}</td><td>${esc(r.visit_time) || '—'}</td><td><b>${esc(r.client_name)}</b></td><td>${visitTypeBadge(r.kind)}</td><td>${esc(r.salesperson_name) || '—'}</td><td>${esc(r.pickup) || '—'}</td><td><div class="actions" style="justify-content:flex-end">${IS_ENTRY_DESK ? viewLeadBtn(r.client_id) : `<button type="button" class="btn" onclick="goMarkVisitDone(${r.client_id}, '${r.kind}')" title="Mark Visit Done"><i class="fa-solid fa-calendar-check"></i></button>`}</div></td></tr>`).join('') +
    '</tbody></table></div>'
    : '<div class="empty">No scheduled visits.</div>';

  // Every lead, same as the sales desk — one already passed on reads "Post Sales" (admin too, in the Sales view).
  document.getElementById('dashLeads').innerHTML = state.clients.slice(0, 8).map(c =>
    `<tr onclick="openLeadSummary(${c.id})" style="cursor:pointer"><td>${esc(c.lead_code || c.id)}</td><td><b>${esc(c.name)}</b></td><td>${esc(c.mobile) || '—'}</td><td>${leadStageCell(c, 'sales')}</td><td>${badge(c.interest)}</td><td>${esc(c.nextfollow_date) || '—'}</td><td>${esc(c.salesperson_name) || '—'}</td><td onclick="event.stopPropagation()"><div class="actions" style="justify-content:flex-end">${callBtn(c) || '—'}</div></td></tr>`
  ).join('');
}

function openTotalLeadsBreakdown() {
  const counts = {};
  state.clients.forEach(c => {
    const cat = c.category && c.category.trim() ? c.category : 'Uncategorized';
    counts[cat] = (counts[cat] || 0) + 1;
  });
  const meta = {
    'Owner': { color: '#05357c', bg: '#eaf1ff', iconBg: '#d7e5ff', icon: 'fa-house' },
    'Broker': { color: '#e05200', bg: '#fff1e8', iconBg: '#ffe1cc', icon: 'fa-handshake' },
    'Broker Reference': { color: '#7b2ff7', bg: '#f4ecff', iconBg: '#e4d1fb', icon: 'fa-user-tag' },
    'Uncategorized': { color: '#475467', bg: '#eef2f6', iconBg: '#dbe2e8', icon: 'fa-circle-question' },
  };
  const order = ['Owner', 'Broker', 'Broker Reference', 'Uncategorized'];
  const cats = [...new Set([...order, ...Object.keys(counts)])].filter(c => counts[c]);
  document.getElementById('totalLeadsBreakdown').innerHTML =
    '<div class="cards" style="grid-template-columns:repeat(' + (cats.length + 1) + ',1fr);margin-bottom:0">' +
    cats.map(c => {
      const m = meta[c] || meta['Uncategorized'];
      return `<div class="card" style="background:linear-gradient(150deg, ${m.bg} 0%, #fff 60%);cursor:pointer" onclick="goToLeadsByCategory('${jsAttr(c)}')" title="View ${esc(c)} leads in Manage Leads">
        <div class="card-icon" style="background:${m.iconBg};color:${m.color}"><i class="fa-solid ${m.icon}"></i></div>
        <div class="label">${esc(c).toUpperCase()}</div>
        <div class="num" style="color:${m.color}">${counts[c]}</div>
        <div class="sub">leads</div>
      </div>`;
    }).join('') +
    `<div class="card" style="background:linear-gradient(150deg, #eef2f6 0%, #fff 60%);cursor:pointer" onclick="goToLeadsByCategory('')" title="View all leads in Manage Leads">
        <div class="card-icon" style="background:#dbe2e8;color:#475467"><i class="fa-solid fa-layer-group"></i></div>
        <div class="label">TOTAL</div>
        <div class="num" style="color:#475467">${state.clients.length}</div>
        <div class="sub">all categories</div>
      </div>` +
    '</div>';
  openModal('totalLeadsModal');
}
// Clicking a "Leads by Category" card jumps to Manage Leads pre-filtered by
// that category. Empty string (Total card, or an "Uncategorized" bucket that
// has no matching filter option) clears the Category filter to show everyone.
// Dashboard card → Manage Leads with a fresh filter set (every other filter cleared).
function goToLeads(f) {
  const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
  set('leadSearch', f.search); set('stageFilter', f.stage); set('interestFilter', f.interest);
  set('addedByFilter', ''); set('categoryFilter', f.category);
  nav('leads');
}
// Dashboard card → another page (skipped if that page isn't in this desk's menu).
function goToPage(page) {
  if (!document.querySelector(`.nav button[data-page="${page}"]`)) return;
  nav(page);
}
// Admin's Legal view / Team Overview → Bookings with a Booking Progress filter (see bookingStatusFilters()).
function goToBookings(status) {
  renderBookings(); // makes sure the filter options exist
  const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
  set('bookingSearch', ''); set('bookingVillaFilter', ''); set('bookingStatusFilter', status);
  goToPage('bookings');
}
function goToLeadsByCategory(cat) {
  closeModal('totalLeadsModal');
  goToLeads({ category: cat }); // unknown category (e.g. Uncategorized) → the select falls back to "Any"
}

function populateAddedByFilter() {
  const sel = document.getElementById('addedByFilter');
  if (!sel) return;
  const current = sel.value;
  const names = [...new Set(state.clients.map(c => c.created_by_name).filter(Boolean))].sort();
  sel.innerHTML = '<option value="">Added By (Anyone)</option>' +
    names.map(n => `<option value="${esc(n)}">${esc(n)}</option>`).join('');
  sel.value = current; // keep the selection across re-renders
}
function renderLeads() {
  populateAddedByFilter();
  let q = (document.getElementById('leadSearch')?.value || '').toLowerCase();
  let s = document.getElementById('stageFilter')?.value || '';
  let i = document.getElementById('interestFilter')?.value || '';
  let addedBy = document.getElementById('addedByFilter')?.value || '';
  let cat = document.getElementById('categoryFilter')?.value || '';
  // Legal desk: "Registered" = still with Legal (transfer pending); "__accounts" = transferred to Accounts.
  // Admin: "__sales" / "__legal" / "__accounts" = every lead with that desk right now.
  const stageOk = c => !s ? true
    : s === '__accounts' ? leadWithAccounts(c)
    : s === '__sales' ? leadWithSalesDesk(c)
    : s === '__legal' ? leadWithLegalDesk(c)
    : IS_LEGAL && s === 'Registered' ? c.stage === 'Registered' && !c.accounts_handover_at
    : c.stage === s;
  let arr = state.clients.filter(c =>
    (!q || [c.lead_code, c.name, c.mobile, c.broker_name, c.broker_contact].join(' ').toLowerCase().includes(q)) &&
    stageOk(c) && (!i || c.interest === i) && (!addedBy || c.created_by_name === addedBy) &&
    (!cat || c.category === cat)
  );
  if (IS_POST_SALES) { renderPostSalesLeads(arr); return; }
  document.getElementById('leadTable').innerHTML = arr.length ? arr.map(c => `<tr onclick="openLeadSummary(${c.id})" style="cursor:pointer">
    <td>${esc(c.lead_code || c.id)}</td><td><b>${esc(c.name)}</b></td><td>${esc(c.mobile) || '—'}</td>
    <td>${badge(c.category)}</td>
    <td>${esc(c.source) || '—'}</td><td>${esc(c.purpose) || '—'}</td><td>${esc(c.config) || '—'}</td>
    <td>${esc(c.budget) || '—'}</td><td>${leadStageCell(c)}</td><td>${badge(c.interest)}</td>
    <td>${esc(c.nextfollow_date) || '—'}</td><td>${esc(c.salesperson_name) || '—'}</td>
    <td>${c.entry_channel === 'Form' ? '🌐 Website Form (' + esc(c.entry_type) + ')' : esc(c.created_by_name) || '—'}</td>
    <td onclick="event.stopPropagation()"><div class="actions">${callBtn(c)}<button class="btn" title="${IS_ENTRY_DESK ? 'View lead' : 'Edit lead'}" onclick="openClient(${c.id})"><i class="fa-solid ${IS_ENTRY_DESK ? 'fa-eye' : 'fa-pen-to-square'}"></i></button>${canReassignLead(c) ? `<button class="btn" onclick="openReassign(${c.id}, '${jsAttr(c.name)}')"><i class="fa-solid fa-user-gear"></i></button>` : ''
    }${IS_ADMIN ? `<button class="btn danger" onclick="deleteClient(${c.id}, '${jsAttr(c.name)}')"><i class="fa-solid fa-trash"></i></button>` : ''
    }</div></td>
  </tr>`).join('') : '<tr><td colspan="14" class="empty">No clients found.</td></tr>';
}
// Manage Leads for the Accounts desk: construction / payment columns.
function renderAccountsLeads(arr) {
  const head = document.getElementById('leadHead');
  if (head && !head.dataset.ps) {
    head.dataset.ps = '1';
    const th = (icon, label) => `<th><i class="fa-solid ${icon}"></i> ${label}</th>`;
    head.innerHTML = '<tr>' + th('fa-hashtag', 'Lead ID') + th('fa-user', 'Client') + th('fa-phone', 'Mobile') + th('fa-house-chimney', 'Villa') +
      th('fa-tag', 'Final Villa Price') + th('fa-layer-group', 'Stage') + th('fa-hourglass-half', 'Due Now') +
      th('fa-calendar-day', 'Pay By') + th('fa-right-left', 'Transferred') + th('fa-chart-simple', 'Paid') + th('fa-gear', 'Action') + '</tr>';
  }
  const fmtD = d => d ? esc(fmtDay(String(d).slice(0, 10))) : '—';
  document.getElementById('leadTable').innerHTML = arr.length ? arr.map(c => {
    const due = +c.dueamount || 0;
    return `<tr onclick="openLeadSummary(${c.id})" style="cursor:pointer">
    <td>${esc(c.lead_code || c.id)}</td><td><b>${esc(c.name)}</b></td><td>${esc(c.mobile) || '—'}</td>
    <td>${esc(c.villa) || '—'}</td><td>${esc(villaPriceText(c)) || '—'}</td><td>${leadStageCell(c)}</td>
    <td>${due > 0.5 ? `<b>${money(due)}</b>` : '—'}</td>
    <td>${due > 0.5 ? fmtD(c.duedate) + (c.duedate && c.duedate < today() ? ' ' + payPill('Overdue', 'pp-od') : '') : '—'}</td><td>${fmtD(c.accounts_handover_at)}</td>
    <td>${payMiniBar(c)}</td>
    <td onclick="event.stopPropagation()"><div class="actions">${callBtn(c, +c.dueamount > 0.5 ? 'Payment due' : '')}<button class="btn" title="Edit lead" onclick="openClient(${c.id})"><i class="fa-solid fa-pen-to-square"></i></button></div></td>
  </tr>`;
  }).join('') : '<tr><td colspan="11" class="empty">No leads transferred to Accounts yet.</td></tr>';
}
// Manage Leads for the Legal desk: Post Sales columns instead of the sales ones.
function renderPostSalesLeads(arr) {
  if (IS_ACCOUNTS) { renderAccountsLeads(arr); return; }
  const head = document.getElementById('leadHead');
  if (head && !head.dataset.ps) {
    head.dataset.ps = '1';
    const th = (icon, label) => `<th><i class="fa-solid ${icon}"></i> ${label}</th>`;
    head.innerHTML = '<tr>' + th('fa-hashtag', 'Lead ID') + th('fa-user', 'Client') + th('fa-phone', 'Mobile') + th('fa-house-chimney', 'Villa') +
      th('fa-tag', 'Final Villa Price') + th('fa-layer-group', 'Stage') + th('fa-circle-info', 'Booking Status') + th('fa-calendar-check', 'Booking Date') +
      th('fa-user-tie', 'Sales Person') + th('fa-right-left', 'Transferred') + th('fa-gear', 'Action') + '</tr>';
  }
  const fmtD = d => d ? esc(new Date(String(d).slice(0, 10) + 'T00:00:00').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })) : '—';
  const pill = c => { const b = bookingStatusOf(c); return `<span class="ps-pill ${b.cls}">${esc(b.text)}</span>`; };
  document.getElementById('leadTable').innerHTML = arr.length ? arr.map(c => `<tr onclick="openLeadSummary(${c.id})" style="cursor:pointer">
    <td>${esc(c.lead_code || c.id)}</td><td><b>${esc(c.name)}</b></td><td>${esc(c.mobile) || '—'}</td>
    <td>${esc(c.villa) || '—'}</td><td>${esc(villaPriceText(c)) || '—'}</td><td>${leadStageCell(c)}</td><td>${pill(c)}</td>
    <td>${fmtD(c.bookingdate)}</td><td>${esc(c.salesperson_name) || '—'}</td><td>${fmtD(c.sales_handover_at)}</td>
    <td onclick="event.stopPropagation()"><div class="actions">${callBtn(c)}${leadWithAccounts(c) ? `<button class="btn" title="View lead (with Accounts)" onclick="openClient(${c.id})"><i class="fa-solid fa-eye"></i></button>` : `<button class="btn" title="Edit lead" onclick="openClient(${c.id})"><i class="fa-solid fa-pen-to-square"></i></button>`}</div></td>
  </tr>`).join('') : '<tr><td colspan="11" class="empty">No leads transferred to Post Sales yet.</td></tr>';
}
function canReassignLead(c) {
  const u = window.CURRENT_USER;
  return isAdminRole(u.role)
    || (u.desk === 'entry' && String(c.created_by) === String(u.id))
    || ((u.desk === 'broker' || u.desk === 'owner') && String(c.salesperson_id) === String(u.id));
}
function openReassign(id, name) {
  document.getElementById('reassignClientId').value = id;
  document.getElementById('reassignClientName').textContent = name;
  document.getElementById('reassignSelect').innerHTML = state.users
    .filter(u => (u.desk === 'broker' || u.desk === 'owner') && String(u.id) !== String(window.CURRENT_USER.id))
    .map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('');
  openModal('reassignModal');
}
async function confirmReassign() {
  const id = document.getElementById('reassignClientId').value;
  const salesperson_id = document.getElementById('reassignSelect').value;
  const result = await api('reassign.php', 'POST', { id, salesperson_id });
  if (result && result.error) { alert(result.error); return; }
  closeModal('reassignModal');
  await loadAll();
}

async function deleteClient(id, name) {
  if (!confirm(`Permanently delete "${name}"? This also removes their follow-ups, site visits, documents and payments, and frees any villa it holds in Villa Inventory. This cannot be undone.`)) return;
  const result = await api('clients.php', 'DELETE', { id });
  if (result && result.error) { alert(result.error); return; }
  await loadAll();
}

// Follow-up type label (e.g. "After Scheduled Site Visit"); untyped rows are plain follow-ups.
// The stored type for the site-visit follow-up is still "After Scheduled Site Visit"
// (existing rows/keys rely on it); it is only DISPLAYED as "Site Visit Follow-up".
const fuTypeLabel = t => t === 'After Scheduled Site Visit' ? 'Site Visit Follow-up' : t;
function fuTypeBadge(x) {
  return x.followup_type
    ? `<span class="badge" style="background:#eaf2ff;color:#1d4ed8">${esc(fuTypeLabel(x.followup_type))}</span>`
    : '<span style="color:var(--muted)">General</span>';
}
function friendlyDiscussion(text) {
  return text === "(Auto-synced from lead's Next Follow-up field)"
    ? '<em style="color:var(--muted)">Set via lead\'s Next Follow-up field</em>' : (esc(text) || '—');
}
function renderFollowups() {
  let q = (document.getElementById('fuSearch')?.value || '').toLowerCase();
  let s = document.getElementById('fuStatus')?.value || '';
  let t = document.getElementById('fuTypeFilter')?.value || '';
  let arr = state.followups.filter(x =>
    (!q || (x.client_name || '').toLowerCase().includes(q)) && (!s || x.status === s) &&
    (!t || x.followup_type === t)
  );
  document.getElementById('fuTable').innerHTML = arr.length ? arr.map(x => {
    const canAct = IS_ADMIN || String(x.salesperson_id) === String(window.CURRENT_USER.id);
    if (IS_ENTRY_DESK) return `<tr>
    <td>${esc(x.followup_date)}</td><td>${esc(x.client_name)}</td><td>${fuTypeBadge(x)}</td><td>${esc(x.mode)}</td>
    <td>${esc(x.next_date) || '—'}</td><td>${badge(x.status)}</td>
    <td><div class="actions" style="justify-content:flex-end">${callBtnById(x.client_id, fuTypeLabel(x.followup_type) || 'Follow-up')}${viewLeadBtn(x.client_id)}</div></td>
  </tr>`;
    return `<tr>
    <td>${esc(x.followup_date)}</td><td>${esc(x.client_name)}</td><td>${fuTypeBadge(x)}</td><td>${esc(x.mode)}</td>
    <td>${esc(x.next_date) || '—'}</td><td>${badge(x.status)}</td>
    <td><div class="actions" style="justify-content:flex-end">${callBtnById(x.client_id, fuTypeLabel(x.followup_type) || 'Follow-up')}${x.status !== 'Completed' && canAct ? `<button class="btn" title="Mark Complete" onclick="completeFU(${x.id})"><i class="fa-solid fa-check"></i></button>${x.next_date ? `<button class="btn" title="Complete — re-follow-up on ${esc(x.next_date)} still pending" onclick="completeFU(${x.id}, true)"><i class="fa-solid fa-clock-rotate-left"></i></button>` : ''}` : ''}<button class="btn" title="Open lead's follow-up stage" onclick="goEditFollowup(${x.client_id}, '${esc(x.followup_type || '')}')"><i class="fa-solid fa-pen-to-square"></i></button></div></td>
  </tr>`; }).join('') : '<tr><td colspan="7" class="empty">No follow-ups found.</td></tr>';
}

function renderVisits() {
  // A completed visit gets a real row in the `visits` table (see
  // sync_site_visit/sync_revisit_visit/sync_negotiation_visit in
  // api/clients.php — Negotiation Visit only once the lead reaches Unit
  // Selection, not merely Negotiation Visit Follow-up). Anything still
  // pending/scheduled (any of the 3 kinds) is pulled straight off the lead
  // record via pendingScheduledVisits(), skipped here once a real logged row
  // already covers that same lead+date+kind (e.g. also logged manually).
  const loggedKeys = new Set(state.visits.map(v => `${v.client_id}|${v.visit_date}|${v.kind}`));
  const scheduled = pendingScheduledVisits()
    .filter(r => !loggedKeys.has(`${r.client_id}|${r.visit_date}|${r.kind}`))
    .map(r => ({
      client_id: r.client_id, client_name: r.client_name, kind: r.kind,
      visit_date: r.visit_date, visit_time: r.visit_time,
      visitors: '—', villa: '—', status: 'Scheduled', outcome: '—', _pending: true
    }));
  // Search (client name / visit type) + Visit Type, Status and Visit Date filters.
  const q = (document.getElementById('visitSearch')?.value || '').trim().toLowerCase();
  const fType = document.getElementById('visitTypeFilter')?.value || '';
  const fStatus = document.getElementById('visitStatusFilter')?.value || '';
  const fDate = document.getElementById('visitDateFilter')?.value || '';
  const typeName = k => (VISIT_KINDS[k]?.label || k || '').toLowerCase();
  const merged = [...scheduled, ...state.visits]
    .filter(x => (!q || (x.client_name || '').toLowerCase().includes(q) || typeName(x.kind).includes(q)) &&
      (!fType || x.kind === fType) && (!fStatus || (/complet/i.test(x.status || '') ? 'Completed' : 'Scheduled') === fStatus) && (!fDate || String(x.visit_date || '').slice(0, 10) === fDate))
    .sort((a, b) => (b.visit_date || '').localeCompare(a.visit_date || ''));
  document.getElementById('visitTable').innerHTML = merged.length ? merged.map(x => `<tr>
    <td>${esc(x.client_name)}</td><td>${visitTypeBadge(x.kind)}</td><td>${esc(x.visit_date)}</td><td>${esc(x.visit_time) || '—'}</td>
    <td>${esc(x.visitors)}</td><td>${esc(x.villa) || '—'}</td><td>${badge(x.status)}</td>
    <td>${esc(x.outcome) || '—'}</td>
    <td>${IS_ENTRY_DESK ? `<div class="actions" style="justify-content:flex-end">${viewLeadBtn(x.client_id)}</div>` : x._pending ? `<div class="actions" style="justify-content:flex-end"><button type="button" class="btn" onclick="goMarkVisitDone(${x.client_id}, '${x.kind}')" title="Mark Visit Done"><i class="fa-solid fa-calendar-check"></i></button></div>` : '—'}</td>
  </tr>`).join('') : '<tr><td colspan="9" class="empty">No site visits found.</td></tr>';
}
// Opens the Edit Client modal for this lead and, if it's still sitting at the
// pending checkpoint for that visit kind, jumps to that kind's Completed
// checkpoint (Site Visit / Re-Visit) so the details can be filled and saved —
// that save is what actually logs it Completed. Negotiation Visit has no
// separate Completed checkpoint, so it just opens on that stage instead.
function goMarkVisitDone(clientId, kind) {
  openClient(clientId);
  const f = document.getElementById('clientForm');
  const k = VISIT_KINDS[kind || 'site'];
  if (k && isVisitKindStage(k, f.elements.stage.value)) {
    const completedStage = { site: 'Site Visit Completed', revisit: 'Re-Visit Completed' }[kind];
    if (completedStage) setStage(completedStage);
  }
}

// Bookings page: every lead handed over to Post Sales (booking pending) or already in a
// Post Sales stage. Salespeople get a slim status view; admin gets the full booking details.
// Bookings page — second filter depends on the desk:
//   Admin: where the booking is in the whole journey (booking → legal → Accounts → possession).
//   Legal: its own Booking & Legal stages, then "Transferred to Accounts".
//   Sales: the same Booking Status the salesperson sees in the table.
// Each entry: [value, label, test(lead)].
function bookingStatusFilters() {
  const st = c => c.stage || '';
  const isCancelled = c => st(c) === 'Cancelled';
  if (IS_LEGAL) return [
    ['pending', 'Booking Pending (Unit Blocked)', c => st(c) === 'Unit Blocked'],
    ...PIPELINE_STAGES.filter(s => stageGroupKey(s) === 'book').map(s => [s, s === 'Registered' ? 'Registered — transfer pending' : s,
      c => st(c) === s && !(s === 'Registered' && c.accounts_handover_at)]),
    ['accounts', 'Transferred to Accounts', c => leadWithAccounts(c)],
    ['cancelled', 'Cancelled', isCancelled],
  ];
  if (IS_ADMIN) return [
    ['pending', 'Booking pending — with Legal', c => st(c) === 'Unit Blocked'],
    ['legal', 'With Legal — Booking → Registration', c => stageGroupKey(st(c)) === 'book' && !c.accounts_handover_at],
    ['inprocess', 'Booking in process', c => ['Booking Initiated', 'KYC Verification'].includes(st(c))],
    ['confirmed', 'Booking confirmed', c => st(c) === 'Booking Confirmed'],
    ['agreement', 'Agreement in process', c => st(c) === 'Agreement In Process'],
    ['registered', 'Registered — transfer to Accounts pending', c => st(c) === 'Registered' && !c.accounts_handover_at],
    ['construction', 'With Accounts — Construction & Payment', c => leadAccountsHandedOver(c) || stageGroupKey(st(c)) === 'construction'],
    ['possession', 'Possession', c => stageGroupKey(st(c)) === 'possession'],
    ['cancelled', 'Cancelled', isCancelled],
  ];
  return [ // salespeople
    ['pending', 'Awaiting booking', c => !isCancelled(c) && !isPostSalesStage(st(c))],
    ['inprocess', 'Booking in process', c => bookingStatusOf(c).text === 'Booking in process'],
    ['confirmed', 'Booking confirmed', c => bookingStatusOf(c).text === 'Booking Confirmed'],
    ['cancelled', 'Cancelled', isCancelled],
  ];
}
function renderBookings() {
  // + bookings cancelled after the transfer, so the "Cancelled" filter finds them too.
  const all = state.clients.filter(c => leadWithPostSales(c) || +c.bookingamount > 0 || (c.stage === 'Cancelled' && !!c.sales_handover_at));
  // Status filter: options built once for this desk. Villa filter: every villa with a booking, serial order.
  const stSel = document.getElementById('bookingStatusFilter'), vSel = document.getElementById('bookingVillaFilter');
  const filters = bookingStatusFilters();
  if (stSel && !stSel.dataset.ready) {
    stSel.dataset.ready = '1';
    stSel.innerHTML = `<option value="">${IS_LEGAL ? 'Stage (All)' : IS_ADMIN ? 'Booking Progress (All)' : 'Booking Status (All)'}</option>` +
      filters.map(([v, l]) => `<option value="${esc(v)}">${esc(l)}</option>`).join('');
  }
  if (vSel) {
    const cur = vSel.value;
    const serial = v => { const t = String(v), i = t.indexOf(' - '); return i === -1 ? t : t.slice(i + 3); };
    const villas = [...new Set(all.flatMap(c => villaListOf(c.villa)))]
      .sort((x, y) => serial(x).localeCompare(serial(y), undefined, { numeric: true, sensitivity: 'base' }) || x.localeCompare(y));
    vSel.innerHTML = '<option value="">Villa (All)</option>' + villas.map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
    vSel.value = villas.includes(cur) ? cur : '';
  }
  const q = (document.getElementById('bookingSearch')?.value || '').trim().toLowerCase();
  const vf = vSel?.value || '';
  const sf = filters.find(([v]) => v === (stSel?.value || ''));
  const filtered = !!(q || vf || sf);
  const a = all.filter(c => (!q || [c.lead_code, c.id, c.name].some(v => String(v || '').toLowerCase().includes(q)))
    && (!vf || villaListOf(c.villa).includes(vf))
    && (!sf || sf[2](c)));
  const th = (icon, label) => `<th><i class="fa-solid ${icon}"></i> ${label}</th>`;
  const pill = c => { const s = bookingStatusOf(c); return `<span class="ps-pill ${s.cls}">${esc(s.text)}</span>`; };
  const fmtD = d => d ? esc(new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })) : '—';
  const editable = c => CAN_POST_SALES && !(IS_LEGAL && leadWithAccounts(c)); // Legal: read-only once with Accounts
  const view = c => `<td><div class="actions">${callBtn(c)}<button class="btn" title="${editable(c) ? 'Edit lead' : 'View lead'}" onclick="openClient(${c.id})"><i class="fa-solid ${editable(c) ? 'fa-pen-to-square' : 'fa-eye'}"></i></button></div></td>`;
  let head, row;
  if (CAN_POST_SALES) {
    // Admin + Post Sales desks: only the booking essentials, one status per lead.
    head = th('fa-hashtag', 'Lead ID') + th('fa-user', 'Client') + th('fa-house-chimney', 'Villa') +
      th('fa-tag', 'Final Villa Price') + th('fa-money-bill', 'Booking Amount') + th('fa-calendar-check', 'Booking Date') +
      th('fa-circle-info', 'Booking Status') + th('fa-file-signature', 'Agreement') + th('fa-stamp', 'Registration') + th('fa-gear', 'Action');
    row = c => `<tr><td>${esc(c.lead_code || c.id)}</td><td><b>${esc(c.name)}</b></td><td>${esc(c.villa) || '—'}</td>
      <td>${esc(villaPriceText(c)) || '—'}</td><td>${+c.bookingamount ? money(c.bookingamount) + (+c.token_amount ? `<br><small style="color:var(--muted)">− Token ${money(c.token_amount)} = <b>${money(netBookingAmount(c.bookingamount, c.token_amount))}</b></small>` : '') : '—'}</td><td>${fmtD(c.bookingdate)}</td>
      <td>${pill(c)}</td><td>${badge(c.agreement || 'Pending')}</td><td>${badge(c.registration || 'Pending')}</td>${view(c)}</tr>`;
  } else {
    head = th('fa-hashtag', 'Lead ID') + th('fa-user', 'Client') + th('fa-house-chimney', 'Villa') +
      th('fa-calendar-check', 'Booking Date') + th('fa-circle-info', 'Booking Status') + th('fa-gear', 'Action');
    row = c => `<tr><td>${esc(c.lead_code || c.id)}</td><td>${esc(c.name)}</td><td>${esc(c.villa) || '—'}</td>
      <td>${salesBookingDate(c) ? fmtD(salesBookingDate(c)) : 'Not confirmed yet'}</td><td>${pill(c)}</td>${view(c)}</tr>`;
  }
  const cols = CAN_POST_SALES ? 10 : 6;
  document.getElementById('bookingHead').innerHTML = `<tr>${head}</tr>`;
  document.getElementById('bookingTable').innerHTML = a.length ? a.map(row).join('')
    : `<tr><td colspan="${cols}" class="empty">${filtered ? 'No bookings match your search / filters.' : 'No bookings yet.'}</td></tr>`;
}

// Edit button on the Payments / Possession tables (admin + Post Sales desks).
function psOpenCell(c) {
  return `<td><div class="actions">${callBtn(c)}<button class="btn" title="Edit lead" onclick="openClient(${c.id})"><i class="fa-solid fa-pen-to-square"></i></button></div></td>`;
}
// Payments page: every lead with the Accounts team — same pattern as Construction & Payment in Edit
// Client: villa price, Received · Due now (Due / Overdue pill) · Pending (later stages), Construction
// Status, and the Paid % bar at the end. Numbers come from each lead's synced payment columns
// (api/payments.php keeps received / dueamount / duedate / pay_demanded up to date).
// payLeadRows() / payTotals() are shared with the Accounts dashboard so both always show the same figures.
function setPayFilter(v) { const sel = document.getElementById('payFilter'); if (sel) sel.value = v; renderPayments(); }
function payLeadRows() {
  return state.clients.filter(c => c.stage !== 'Cancelled' && (leadWithAccounts(c) || +c.received > 0))
    .map(c => {
      const price = villaPriceNum(c), rec = +c.received || 0, due = +c.dueamount || 0, dem = c.pay_demanded == null || c.pay_demanded === '' ? null : +c.pay_demanded;
      const od = due > 0.5 && !!c.duedate && c.duedate < today();
      const later = dem == null ? Math.max(0, price - rec) : Math.max(0, price - Math.max(dem, rec));
      return { c, price, rec, due, dem, od, later, paid: price > 0 && rec >= price - 0.5 };
    });
}
function payTotals(list) {
  const sum = (arr, k) => arr.reduce((t, x) => t + x[k], 0);
  const odList = list.filter(x => x.od), dueList = list.filter(x => x.due > 0.5);
  const total = sum(list, 'price'), rec = sum(list, 'rec');
  return { count: list.length, total, rec, recPct: total ? Math.round(rec / total * 10000) / 100 : 0,
    due: sum(list, 'due'), dueCount: dueList.length, od: sum(odList, 'due'), odCount: odList.length, later: sum(list, 'later') };
}
// Villa filter: each villa (NAME - SERIAL, e.g. "ALARA 1 - A1-01") with a lead in the payment process,
// in serial order — the same villas and order as Villa Inventory.
function populatePayVillaFilter(list) {
  const sel = document.getElementById('payVillaFilter');
  if (!sel) return;
  const cur = sel.value;
  const serial = v => { const t = String(v), i = t.indexOf(' - '); return i === -1 ? t : t.slice(i + 3); };
  const villas = [...new Set(list.map(x => String(x.c.villa || '').trim()).filter(Boolean))]
    .sort((a, b) => serial(a).localeCompare(serial(b), undefined, { numeric: true, sensitivity: 'base' }) || a.localeCompare(b));
  sel.innerHTML = '<option value="">Villa (All)</option>' + villas.map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
  sel.value = villas.includes(cur) ? cur : '';
}
// Construction Status: the last construction stage reached (label as in Edit Client) + its date.
function payConstructionCell(c, dem) {
  const t = PAY.template.find(x => x.stage === c.stage);
  const muted = x => `<div style="font-size:11px;color:var(--muted)">${x}</div>`;
  const date = c.construction_update_date ? ` <span style="font-size:11px;color:var(--muted)">· ${esc(fmtDay(String(c.construction_update_date).slice(0, 10)))}</span>` : '';
  const g = stageGroupKey(c.stage);
  if (g === 'construction') {
    if (c.stage === 'Construction Customer' && !(dem > 0.5)) return `<b>Not started</b>${muted('Agreement not demanded yet')}`;
    return `<b>${esc((t && PAY_STATUS_LABEL[t.code]) || c.stage)}</b>${date}`;
  }
  // Possession stages: construction is complete (OC / CC received); possession itself is admin's.
  if (g === 'possession') return `<b>${esc(PAY_STATUS_LABEL.possession || 'OC / CC Received')}</b>${date}${muted(esc(c.stage) + ' · with Admin')}`;
  if (c.stage === 'Closed') return '<b>Closed</b>';
  return leadAccountsHandedOver(c) ? `<b>Not started</b>${muted('New from Legal')}` : leadStageCell(c);
}
function renderPayments() {
  const tb = document.getElementById('paymentTable');
  if (!tb) return;
  const list = payLeadRows(), T = payTotals(list);
  psSet('payKTotal', shortInr(T.total)); psSet('payKTotalSub', `${T.count} villa${T.count === 1 ? '' : 's'} with Accounts`);
  psSet('payKRec', shortInr(T.rec)); psSet('payKRecSub', T.total ? `${payPct(T.recPct)} of total` : '—');
  psSet('payKDue', shortInr(T.due)); psSet('payKDueSub', `${T.dueCount} client${T.dueCount === 1 ? '' : 's'} to pay`);
  psSet('payKOd', shortInr(T.od)); psSet('payKOdSub', T.odCount ? `${T.odCount} past the pay-by date` : 'Nothing overdue');
  psSet('payKUp', shortInr(T.later));
  populatePayVillaFilter(list);

  const q = (document.getElementById('paySearch')?.value || '').trim().toLowerCase();
  const qd = q.replace(/\D/g, '');
  const f = document.getElementById('payFilter')?.value || '';
  const vf = document.getElementById('payVillaFilter')?.value || '';
  const rows = list.filter(x => {
    if (f === 'due' && !(x.due > 0.5)) return false;
    if (f === 'overdue' && !x.od) return false;
    if (f === 'paid' && !x.paid) return false;
    if (f === 'notdemanded' && (x.due > 0.5 || x.paid || x.dem == null)) return false;
    if (f === 'noplan' && x.dem != null) return false;
    if (vf && String(x.c.villa || '').trim() !== vf) return false;
    if (!q) return true;
    const c = x.c;
    return [c.lead_code, c.id, c.name, c.co_applicant_name, c.mobile, c.whatsapp, c.email, c.villa].some(v => String(v || '').toLowerCase().includes(q))
      || (qd.length >= 4 && [c.mobile, c.whatsapp].some(v => String(v || '').replace(/\D/g, '').includes(qd)));
  }).sort((a, b) => (b.od - a.od) || (b.due > 0.5) - (a.due > 0.5) || String(a.c.duedate || '9999').localeCompare(String(b.c.duedate || '9999')));

  const muted = t => `<div style="font-size:11px;color:var(--muted)">${t}</div>`;
  const pctOf = (v, p) => p ? payPct(Math.round(v / p * 10000) / 100) : '';
  tb.innerHTML = rows.length ? rows.map(({ c, price, rec, due, dem, od, later, paid }) => {
    const odDays = od ? Math.round((new Date(today()) - new Date(c.duedate)) / 86400000) : 0;
    const dueCell = due > 0.5 ? `<b>${money(due)}</b> ${od ? payPill(`Overdue ${odDays}d`, 'pp-od') : payPill('Due', 'pp-due')}${muted(pctOf(due, price))}`
      : paid ? payPill('Paid', 'pp-paid') : dem == null ? muted('No schedule yet') : muted('Nothing due');
    return `<tr onclick="openLeadSummary(${c.id})" style="cursor:pointer">
      <td>${esc(c.lead_code || c.id)}</td><td><b>${esc(c.name)}</b>${muted(esc(c.mobile) || '')}</td><td>${esc(c.villa) || '—'}</td>
      <td>${price ? money(price) : '—'}</td>
      <td>${rec ? `${money(rec)}${muted(pctOf(rec, price))}` : '—'}</td>
      <td>${dueCell}</td>
      <td>${due > 0.5 && c.duedate ? psFmtD(c.duedate) : '—'}</td>
      <td>${price ? `${money(later)}${muted(pctOf(later, price))}` : '—'}</td>
      <td>${payConstructionCell(c, dem)}</td>
      <td>${payMiniBar(c)}</td>
      <td onclick="event.stopPropagation()"><div class="actions">${callBtn(c, due > 0.5 ? 'Payment due' : '')}<button class="btn" title="Open Construction &amp; Payment" onclick="openClient(${c.id});setTimeout(() => clientTab('construction'), 0)"><i class="fa-solid fa-pen-to-square"></i></button></div></td></tr>`;
  }).join('')
    : `<tr><td colspan="11" class="empty">${list.length ? 'No clients match this search / filter.' : 'No leads with the Accounts team yet.'}</td></tr>`;
}

// Possession page: search (Lead ID / name / mobile / villa) + Villa, Possession Stage and Status filters.
// Status follows the table's columns — an empty value counts as Pending, same as the Edit Client default.
const POSSESSION_STATUS_TESTS = {
  fitout: c => (c.fitout || 'Pending') !== 'Completed',
  inspection: c => (c.inspection || 'Pending') !== 'Done',
  snagging: c => (c.snagging || 'Pending') !== 'Completed',
  posletter: c => (c.posletter || 'Pending') !== 'Issued',
  keysno: c => (c.keys_handed || 'No') !== 'Yes',
  keysyes: c => c.keys_handed === 'Yes',
};
function renderPossession() {
  const all = state.clients.filter(c => c.stage &&
    (isAccountsStage(c.stage) || c.construction === 'Under Construction' || c.construction === 'Completed'));
  const vSel = document.getElementById('possessionVillaFilter');
  if (vSel) {
    const cur = vSel.value;
    const serial = v => { const t = String(v), i = t.indexOf(' - '); return i === -1 ? t : t.slice(i + 3); };
    const villas = [...new Set(all.flatMap(c => villaListOf(c.villa)))]
      .sort((x, y) => serial(x).localeCompare(serial(y), undefined, { numeric: true, sensitivity: 'base' }) || x.localeCompare(y));
    vSel.innerHTML = '<option value="">Villa (All)</option>' + villas.map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
    vSel.value = villas.includes(cur) ? cur : '';
  }
  const q = (document.getElementById('possessionSearch')?.value || '').trim().toLowerCase();
  const qd = q.replace(/\D/g, '');
  const vf = vSel?.value || '';
  const sf = document.getElementById('possessionStageFilter')?.value || '';
  const stf = document.getElementById('possessionStatusFilter')?.value || '';
  const filtered = !!(q || vf || sf || stf);
  let rows = all.filter(c =>
    (!q || [c.lead_code, c.id, c.name, c.co_applicant_name, c.mobile, c.villa].some(v => String(v || '').toLowerCase().includes(q))
      || (qd.length >= 4 && [c.mobile, c.whatsapp].some(v => String(v || '').replace(/\D/g, '').includes(qd))))
    && (!vf || villaListOf(c.villa).includes(vf))
    && (!sf || (sf === 'construction' ? (stageGroupKey(c.stage) === 'construction' || leadAccountsHandedOver(c)) : c.stage === sf))
    && (!stf || !POSSESSION_STATUS_TESTS[stf] || POSSESSION_STATUS_TESTS[stf](c))
  ).map(c => `<tr><td>${esc(c.lead_code || c.id)}</td><td>${esc(c.name)}</td><td>${esc(c.villa) || '—'}</td><td>${esc(c.construction) || '—'}${c.construction_milestone ? `<div style="font-size:11px;color:var(--muted)">${esc(c.construction_milestone)}</div>` : ''}</td>
    <td>${esc(c.fitout) || '—'}</td><td>${esc(c.inspection) || '—'}</td><td>${esc(c.snagging) || '—'}</td>
    <td>${esc(c.posletter) || '—'}</td><td>${esc(c.posdate) || '—'}</td><td>${esc(c.handover) || '—'}</td>
    <td>${badge(c.keys_handed || 'No')}</td>${psOpenCell(c)}</tr>`);
  document.getElementById('possessionTable').innerHTML = rows.join('')
    || `<tr><td colspan="12" class="empty">${filtered ? 'No possession records match your search / filters.' : 'No possession records.'}</td></tr>`;
}

// ---------- User Management (admin only) ----------
async function loadUserMgmt() {
  if (!IS_ADMIN) return;
  const users = await api('admin_users.php');
  if (!users || users.error) return;
  const deskLabel = { entry: 'Entry Desk', broker: 'Broker Desk', owner: 'Owner Desk', legal: 'Post Sales', accounts: 'Post Sales' };
  document.getElementById('userMgmtTable').innerHTML = users.map(u => `<tr>
    <td><b>${esc(u.name)}</b></td><td>${esc(u.email)}</td><td>${esc(ROLE_LABELS[userRoleOf(u)] || u.role)}</td>
    <td>${deskLabel[u.desk] || '—'}</td>
    <td><div class="actions">${u.role === 'it' && !IS_IT ? '<span style="font-size:11px;color:var(--muted)">IT only</span>' : `<button class="btn" onclick='openUserForm(${JSON.stringify(u)})'><i class="fa-solid fa-pen-to-square"></i></button><button class="btn danger" onclick="deleteUser(${u.id}, '${jsAttr(u.name)}')"><i class="fa-solid fa-trash"></i></button>`}</div></td>
  </tr>`).join('') || '<tr><td colspan="5" class="empty">No users yet.</td></tr>';
}
async function deleteUser(id, name) {
  if (!confirm(`Delete the login for "${name}"? This cannot be undone.`)) return;
  const result = await api('admin_users.php', 'DELETE', { id });
  if (result && result.error) { alert(result.error); return; }
  await loadUserMgmt();
  await loadAll(); // removed from Team Overview straight away
}
const ROLE_LABELS = { admin: 'Admin', sales: 'Sales', legal: 'Legal', accounts: 'Accounts', it: 'IT' };
// Older Legal / Accounts logins were saved as role "sales" + desk legal/accounts (before migration_v36).
function userRoleOf(u) { return u.role === 'sales' && ['legal', 'accounts'].includes(u.desk) ? u.desk : u.role; }
// Desk follows the role: Legal / Accounts → their own Post Sales desk, Admin / IT → none,
// Sales → Entry / Broker / Owner (api/admin_users.php enforces the same).
function syncUserDeskField() {
  const f = document.getElementById('userForm');
  const role = f.elements.role.value, desk = f.elements.desk;
  const fixed = { legal: 'legal', accounts: 'accounts', admin: '', it: '' };
  [...desk.options].forEach(o => { o.hidden = ['legal', 'accounts'].includes(o.value) && !(role in fixed); });
  if (role in fixed) { desk.value = fixed[role]; desk.disabled = true; }
  else { desk.disabled = false; if (['legal', 'accounts'].includes(desk.value)) desk.value = ''; }
}
function openUserForm(user) {
  const f = document.getElementById('userForm');
  f.reset();
  document.getElementById('userFormTitle').textContent = user ? 'Edit User' : 'Add User';
  document.getElementById('userPasswordLabel').textContent = user ? 'Password' : 'Password *';
  f.elements.password.required = !user;
  if (user) {
    f.elements.id.value = user.id;
    f.elements.name.value = user.name;
    f.elements.email.value = user.email;
    f.elements.role.value = userRoleOf(user);
    f.elements.desk.value = user.desk || '';
    f.elements.smtp_app_password.placeholder = (user.has_smtp == 1) ? '✓ Saved — leave blank to keep' : '16-character Google App Password';
  } else {
    f.elements.id.value = '';
  }
  const itOpt = f.elements.role.querySelector('option[value="it"]');
  if (itOpt) itOpt.hidden = !IS_IT; // only IT can give the IT role (api/admin_users.php enforces it)
  syncUserDeskField();
  openModal('userFormModal');
}
document.getElementById('userForm').onsubmit = async (e) => {
  e.preventDefault();
  const o = Object.fromEntries(new FormData(e.target).entries());
  const result = await api('admin_users.php', 'POST', o);
  if (result && result.error) { alert(result.error); return; }
  closeModal('userFormModal');
  await loadUserMgmt();
  await loadAll(); // new / edited user shows up in Team Overview (and dropdowns) straight away
};

function renderTeam() {
  if (!IS_ADMIN) return;
  // Every non-admin login shows up here automatically — Sales (Entry / Broker / Owner desks), Legal,
  // Accounts and any user added later from User Management (unknown desk → "No desk").
  const desks = { entry: 'Entry Desk', broker: 'Broker Desk', owner: 'Owner Desk', legal: 'Legal', accounts: 'Accounts' };
  const deskMeta = {
    broker: { color: '#e05200', bg: '#fff1e8', iconBg: '#ffe1cc' },
    owner: { color: '#05357c', bg: '#eaf1ff', iconBg: '#d7e5ff' },
    entry: { color: '#7b2ff7', bg: '#f4ecff', iconBg: '#e4d1fb' },
    legal: { color: '#6d28d9', bg: '#f3edff', iconBg: '#e2d4fb' },
    accounts: { color: '#0e7490', bg: '#e6f6fa', iconBg: '#c9ecf3' },
  };
  const C = state.clients;
  const team = state.users.filter(u => !isAdminRole(u.role));
  // Older Legal / Accounts logins were saved as role "sales" + desk legal/accounts (see userRoleOf).
  const psDesk = u => ['legal', 'accounts'].includes(u.desk) ? u.desk : u.role;
  const salesUsers = team.filter(u => !isPostSalesDesk(u));
  const legalUsers = team.filter(u => isPostSalesDesk(u) && psDesk(u) === 'legal');
  const accUsers = team.filter(u => isPostSalesDesk(u) && psDesk(u) === 'accounts');

  // ---- Sales: per salesperson, from the leads they own ----
  const sentToLegal = c => !!c.sales_handover_at || isPostSalesStage(c.stage);
  const salesRows = salesUsers.map(u => {
    const assigned = C.filter(c => String(c.salesperson_id) === String(u.id));
    const ids = new Set(assigned.map(c => String(c.id)));
    return {
      u, assigned: assigned.length,
      added: C.filter(c => String(c.created_by) === String(u.id)).length,
      hot: assigned.filter(c => c.interest === 'HOT').length,
      pendingFU: state.followups.filter(f => String(f.salesperson_id) === String(u.id) && f.status === 'Pending').length,
      visits: state.visits.filter(v => ids.has(String(v.client_id))).length,
      sent: assigned.filter(sentToLegal).length,
      bookings: assigned.filter(isBookingConfirmed).length,
      cancelled: assigned.filter(c => c.stage === 'Cancelled').length,
    };
  });

  // ---- Legal / Accounts: work isn't owned per lead, so it's counted from each lead's history ("by" = who did it) ----
  const logs = C.map(c => { let l = []; try { l = c.activity_log ? JSON.parse(c.activity_log) : []; } catch (e) { l = []; } return { c, l: Array.isArray(l) ? l : [] }; });
  const tsOf = x => `${x.date || ''} ${x.time || ''}`.trim();
  const movedTo = (x, stage) => x.type === 'Stage Change' && String(x.note || '').startsWith(`Moved to ${stage}`);
  const isDocUpload = x => x.type === 'Document' && /^Document uploaded/.test(x.note || '');
  const legalRows = legalUsers.map(u => {
    const r = { u, leads: 0, initiated: 0, confirmed: 0, registered: 0, sent: 0, docs: 0, cancelled: 0, last: '' };
    logs.forEach(({ c, l }) => {
      const mine = l.filter(x => x.by && x.by === u.name);
      if (!mine.length) return;
      r.leads++;
      if (mine.some(x => movedTo(x, 'Booking Initiated') || x.type === 'Booking Initiated')) r.initiated++;
      if (mine.some(x => movedTo(x, 'Booking Confirmed'))) r.confirmed++;
      if (mine.some(x => movedTo(x, 'Registered'))) r.registered++;
      if (mine.some(x => x.type === 'Stage Change' && /Cancelled/.test(x.note || ''))) r.cancelled++;
      // Sent to Accounts: whoever did the Legal → Accounts transfer (still with Accounts).
      const byCol = c.accounts_handover_by != null && c.accounts_handover_by !== '';
      if (leadWithAccounts(c) && (byCol ? String(c.accounts_handover_by) === String(u.id)
        : mine.some(x => x.type === 'Accounts Handover' && /^Legal completed/.test(x.note || '')))) r.sent++;
      mine.forEach(x => { if (isDocUpload(x)) r.docs++; if (tsOf(x) > r.last) r.last = tsOf(x); });
    });
    return r;
  });
  const accRows = accUsers.map(u => {
    const r = { u, leads: 0, demands: 0, sent: 0, payments: 0, docs: 0, last: '' };
    logs.forEach(({ l }) => {
      const mine = l.filter(x => x.by && x.by === u.name);
      if (!mine.length) return;
      r.leads++;
      mine.forEach(x => {
        const note = String(x.note || '');
        if (/ — Demand #\d+ raised/.test(note)) r.demands++;          // construction status update = its demand
        if (/Status update undone · Demand #\d+ withdrawn/.test(note)) r.demands--;
        if (['Email Sent', 'WhatsApp Sent'].includes(x.type) && /^Payment (demand|reminder)/.test(note)) r.sent++;
        if (x.type === 'Payment Received') r.payments++;
        if (x.type === 'Payment Deleted') r.payments--;
        if (isDocUpload(x)) r.docs++;
        if (tsOf(x) > r.last) r.last = tsOf(x);
      });
    });
    r.demands = Math.max(0, r.demands); r.payments = Math.max(0, r.payments);
    return r;
  });

  // ---- Cards: one per member ----
  // A photo file that's missing on this server (e.g. a local copy) falls back to the icon instead of a broken image.
  const avatar = u => u.photo
    ? `<img src="image/avatars/${esc(u.photo)}" alt="" onerror="this.parentNode.innerHTML='<i class=&quot;fa-solid fa-user&quot;></i>'" style="width:100%;height:100%;object-fit:cover;border-radius:50%">`
    : `<i class="fa-solid fa-user"></i>`;
  const card = (u, desk, num, sub) => {
    const m = deskMeta[desk] || { color: '#475467', bg: '#eef2f6', iconBg: '#dbe2e8' };
    return `<div class="card" style="background:linear-gradient(150deg, ${m.bg} 0%, #fff 60%)">
      <div class="card-icon" style="background:${m.iconBg};color:${m.color};overflow:hidden">${avatar(u)}</div>
      <div class="label">${esc(u.name).toUpperCase()}</div><div class="num" style="color:${m.color}">${num}</div><div class="sub">${esc(sub)}</div></div>`;
  };
  document.getElementById('teamCards').innerHTML =
    salesRows.map(r => r.u.desk === 'entry'
      ? card(r.u, 'entry', r.added, 'Entry Desk · leads added')
      : card(r.u, r.u.desk, r.assigned, `${desks[r.u.desk] || 'No desk'} · ${r.pendingFU} pending follow-ups`)).join('') +
    legalRows.map(r => card(r.u, 'legal', r.leads, 'Legal · leads worked on')).join('') +
    accRows.map(r => card(r.u, 'accounts', r.leads, 'Accounts · leads worked on')).join('');

  // ---- One-line desk status (click a pill to open that list) ----
  const pill = (label, n, go, cls) => `<span class="ps-pill ${cls || ''}${go ? '' : ' np'}"${go ? ` onclick="${go}" title="Open"` : ''}>${label}<b>${n}</b></span>`;
  const salesNow = C.filter(leadWithSalesDesk);
  document.getElementById('teamSalesSum').innerHTML =
    pill('In Sales', salesNow.length, "goToLeads({ stage: '__sales' })", 'ps-blue') +
    pill('Hot', C.filter(c => c.interest === 'HOT').length, "goToLeads({ interest: 'HOT' })") +
    pill('Follow-ups pending', state.followups.filter(f => f.status === 'Pending').length, "goToPage('followups')") +
    pill('Visits scheduled', pendingScheduledVisits().length, "goToPage('visits')") +
    pill('Sent to Legal', C.filter(sentToLegal).length, "goToBookings('')", 'ps-green');
  document.getElementById('teamLegalSum').innerHTML =
    pill('Booking pending', C.filter(leadHandedOver).length, "goToBookings('pending')", 'ps-blue') +
    pill('Booking → Registration', C.filter(c => stageGroupKey(c.stage) === 'book' && !c.accounts_handover_at).length, "goToBookings('legal')") +
    pill('Registered — transfer pending', C.filter(c => c.stage === 'Registered' && !c.accounts_handover_at).length, "goToBookings('registered')") +
    pill('Sent to Accounts', C.filter(leadWithAccounts).length, "goToLeads({ stage: '__accounts' })", 'ps-green') +
    pill('Bookings cancelled', C.filter(c => c.stage === 'Cancelled' && c.sales_handover_at).length, "goToBookings('cancelled')");
  const T = payTotals(payLeadRows());
  document.getElementById('teamAccountsSum').innerHTML =
    pill('With Accounts', C.filter(leadWithAccounts).length, "goToLeads({ stage: '__accounts' })", 'ps-blue') +
    pill('New from Legal', C.filter(leadAccountsHandedOver).length, '') +
    pill('Received', shortInr(T.rec), "setPayFilter('');goToPage('payments')", 'ps-green') +
    pill(`Due now (${T.dueCount})`, shortInr(T.due), "setPayFilter('due');goToPage('payments')") +
    pill(`Overdue (${T.odCount})`, shortInr(T.od), "setPayFilter('overdue');goToPage('payments')", T.odCount ? 'ps-red' : '');

  // ---- Tables ----
  const fmtLast = t => t ? esc(new Date(t.replace(' ', 'T')).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })) : '—';
  document.getElementById('teamTable').innerHTML = salesRows.map(r => `<tr>
    <td><b>${esc(r.u.name)}</b></td><td>${desks[r.u.desk] || 'No desk'}</td>
    <td>${r.assigned}</td><td>${r.added}</td><td>${r.hot}</td>
    <td>${r.pendingFU}</td><td>${r.visits}</td><td>${r.sent}</td><td>${r.bookings}</td><td>${r.cancelled}</td>
  </tr>`).join('') || '<tr><td colspan="10" class="empty">No sales team members yet.</td></tr>';
  document.getElementById('teamLegalTable').innerHTML = legalRows.map(r => `<tr>
    <td><b>${esc(r.u.name)}</b></td><td>${r.leads}</td><td>${r.initiated}</td><td>${r.confirmed}</td><td>${r.registered}</td>
    <td>${r.sent}</td><td>${r.docs}</td><td>${r.cancelled}</td><td>${fmtLast(r.last)}</td>
  </tr>`).join('') || '<tr><td colspan="9" class="empty">No Legal users yet — add them from User Management.</td></tr>';
  document.getElementById('teamAccountsTable').innerHTML = accRows.map(r => `<tr>
    <td><b>${esc(r.u.name)}</b></td><td>${r.leads}</td><td>${r.demands}</td><td>${r.sent}</td><td>${r.payments}</td>
    <td>${r.docs}</td><td>${fmtLast(r.last)}</td>
  </tr>`).join('') || '<tr><td colspan="7" class="empty">No Accounts users yet — add them from User Management.</td></tr>';
}

// ---------- Source Leads (website / social form submissions, staged for review) ----------
// Source Leads: call the submitter straight from the review list. Once assigned, the call goes
// through the real lead (logged in its Lead History); before that there's no lead to log against.
function sourceCallBtn(s) {
  const tel = telNumber(s.mobile);
  if (!tel) return '';
  const lead = s.assigned_lead_id && state.clients.find(c => String(c.id) === String(s.assigned_lead_id));
  const go = lead ? `callLead(${Number(lead.id)}, 'Source Leads')` : `openDialer('${jsAttr(tel)}')`;
  return `<button type="button" class="btn call-btn" title="Call ${esc(s.mobile)}" onclick="event.stopPropagation();${go}"><i class="fa-solid fa-phone"></i></button>`;
}
function renderSourceLeads() {
  if (!document.getElementById('sourceLeadTable')) return;
  const statusFilter = document.getElementById('sourceStatusFilter')?.value ?? 'New';
  const rows = state.sourceLeads.filter(s => !statusFilter || s.status === statusFilter);
  const statusCls = { New: 'warm', Assigned: 'green', Rejected: 'hot' };
  document.getElementById('sourceLeadTable').innerHTML = rows.length ? rows.map(s => `<tr>
    <td>${esc((s.created_at || '').slice(0, 16).replace('T', ' '))}</td>
    <td>${esc(s.site)}</td><td>${esc(s.form_type)}</td>
    <td><b>${esc(s.name) || '—'}</b></td><td>${esc(s.mobile) || '—'}</td><td>${esc(s.email) || '—'}</td>
    <td>${badge(s.category)}</td>
    <td style="max-width:260px;font-size:12px;color:var(--muted)">${esc(s.notes) || '—'}</td>
    <td><span class="badge ${statusCls[s.status] || ''}">${esc(s.status)}${s.status === 'Assigned' && s.assigned_lead_code ? ' (' + esc(s.assigned_lead_code) + ')' : ''}</span></td>
    <td><div class="actions">${sourceCallBtn(s)}${s.status === 'New' ? `
      <button class="btn" onclick="openEditSource(${s.id})"><i class="fa-solid fa-pen-to-square"></i></button>
      <button class="btn primary" onclick="openAssignSource(${s.id}, '${jsAttr(s.name)}', '${jsAttr(s.category)}')"><i class="fa-solid fa-user-gear"></i></button>
      <button class="btn danger" onclick="rejectSourceLead(${s.id})"><i class="fa-solid fa-trash"></i></button>` : sourceCallBtn(s) ? '' : '—'}</div></td>
  </tr>`).join('') : '<tr><td colspan="10" class="empty">No source leads.</td></tr>';
}

function openAssignSource(id, name, category) {
  document.getElementById('assignSourceId').value = id;
  document.getElementById('assignSourceName').textContent = name ? `Confirming lead: ${name}` : '';
  const picker = document.getElementById('assignSourcePickerWrap');
  const select = document.getElementById('assignSourceSelect');
  picker.style.display = '';
  select.innerHTML = '<option value="">Auto (by category)</option>' +
    state.users.filter(u => u.desk === 'broker' || u.desk === 'owner')
      .map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('');
  openModal('assignSourceModal');
}

async function confirmAssignSource() {
  const id = document.getElementById('assignSourceId').value;
  const salesperson_id = document.getElementById('assignSourceSelect').value || undefined;
  const result = await api('source_leads.php', 'POST', { action: 'assign', id, salesperson_id });
  if (result?.error) { alert(result.error); return; }
  closeModal('assignSourceModal');
  await loadAll();
  alert(`Assigned as lead ${result.lead_code}.`);
}

function openEditSource(id) {
  const s = state.sourceLeads.find(x => x.id === id);
  if (!s) return;
  document.getElementById('editSourceId').value = s.id;
  document.getElementById('editSourceName').value = s.name || '';
  document.getElementById('editSourceMobile').value = s.mobile || '';
  document.getElementById('editSourceAltMobile').value = s.alt_mobile || '';
  document.getElementById('editSourceEmail').value = s.email || '';
  document.getElementById('editSourceCity').value = s.city || '';
  document.getElementById('editSourceCategory').value = s.category || '';
  document.getElementById('editSourceBudget').value = s.budget || '';
  document.getElementById('editSourceConfig').value = s.config || '';
  document.getElementById('editSourceNotes').value = s.notes || '';
  openModal('editSourceModal');
}

async function confirmEditSource() {
  const id = document.getElementById('editSourceId').value;
  const result = await api('source_leads.php', 'POST', {
    action: 'edit', id,
    name: document.getElementById('editSourceName').value.trim(),
    mobile: document.getElementById('editSourceMobile').value.trim(),
    alt_mobile: document.getElementById('editSourceAltMobile').value.trim(),
    email: document.getElementById('editSourceEmail').value.trim(),
    city: document.getElementById('editSourceCity').value.trim(),
    category: document.getElementById('editSourceCategory').value,
    budget: document.getElementById('editSourceBudget').value.trim(),
    config: document.getElementById('editSourceConfig').value.trim(),
    notes: document.getElementById('editSourceNotes').value.trim(),
  });
  if (result?.error) { alert(result.error); return; }
  closeModal('editSourceModal');
  await loadAll();
}

async function rejectSourceLead(id) {
  if (!confirm('Delete this source lead? It will not become a CRM lead.')) return;
  const result = await api('source_leads.php', 'DELETE', { id });
  if (result?.error) { alert(result.error); return; }
  await loadAll();
}

function renderAll() {
  renderDashboard(); renderLeads(); renderFollowups(); renderVisits();
  renderBookings(); renderPayments(); renderPossession(); renderTeam(); renderDuplicateAlerts();
  renderSourceLeads(); renderLeadRequests(); renderNotifications();
}

if (!IS_ADMIN) {
  document.getElementById('navTeam')?.remove();
  document.getElementById('navUsers')?.remove();
  document.getElementById('navNotifLog')?.remove();
  if (!IS_POST_SALES) { // Payments / Possession are Post Sales pages
    document.getElementById('navPayments')?.remove();
    document.getElementById('navPossession')?.remove();
  }
}
if (IS_POST_SALES) { // Post Sales desks: no Sales pages
  document.querySelector('.nav [data-page="visits"]')?.remove();
  document.querySelector('.nav [data-page="followups"]')?.remove();
}
if (IS_LEGAL) { // Legal desk: booking & legal only — Payments / Possession belong to Accounts
  document.getElementById('navPayments')?.remove();
  document.getElementById('navPossession')?.remove();
}
if (IS_ACCOUNTS) { // Accounts desk: never sees the booking stage; Possession is handled by admin
  document.getElementById('navBookings')?.remove();
  document.getElementById('navPossession')?.remove();
}
if (!canManageSourceLeads()) {
  document.getElementById('navSourceLeads')?.remove();
}
if (IS_ENTRY_DESK) {
  document.getElementById('navBookings')?.remove();
  document.getElementById('navVillas')?.remove();
}
loadAll();
refreshWeatherIcon();
setInterval(refreshWeatherIcon, 15 * 60 * 1000); // refresh weather every 15 min

// Auto-refresh everything in the background — leads, follow-ups, visits,
// team, source leads, lead-transfer requests/notifications — so a change
// made by someone else (e.g. a lead assigned/transferred to you) shows up
// on its own within ~12s, with no manual page reload needed. Skipped while
// any modal is open so it never overwrites a form you're mid-edit on;
// it'll pick up right on the next tick once you close it.
setInterval(async () => {
  if (document.querySelector('.modal.show')) return;
  await loadAll();
}, 12000);
