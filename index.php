<?php
session_start();
// This page's HTML has the logged-in user's identity baked directly into
// it (name/role/id in an inline script below). If any layer between the
// server and the browser caches this response — the hosting account's
// server-side page cache, a CDN, or even an aggressive browser/proxy cache
// on a shared network — a page generated for one person's session can get
// served back to someone else, which looks exactly like "auto-logged in
// as the other person". These headers tell every such layer never to
// cache this response, so it is always freshly generated per request.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (empty($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}
$currentName = htmlspecialchars($_SESSION['name']);
$currentRole = htmlspecialchars($_SESSION['role']);
$currentPhoto = $_SESSION['photo'] ?? null;
// Post Sales (Booking & Legal → Possession) is admin-only; salespeople get read-only summary tabs instead.
$isAdmin = in_array($_SESSION['role'] ?? '', ['admin', 'it'], true); // IT has full admin access
// Entry Desk: view-only on Site Visits / Follow-ups, no Bookings / Villa Inventory / Post Sales.
$isEntryDesk = !$isAdmin && ($_SESSION['desk'] ?? '') === 'entry';
// Post Sales team desks (Legal / Accounts): only leads transferred to Post Sales, only the
// Post Sales tabs (Booking & Legal → Possession). Admin sees and handles both sides.
$isPostSales = !$isAdmin && in_array($_SESSION['desk'] ?? '', ['legal', 'accounts'], true);
$canPostSales = $isAdmin || $isPostSales;
$postSalesDeskLabel = ($_SESSION['desk'] ?? '') === 'accounts' ? 'Accounts' : 'Legal';
// Post Sales split (migration_v35): Legal desk = Booking & Legal only; Accounts desk =
// Construction & Payment + Possession, only for leads Legal has transferred at Registered.
$isLegalDesk = $isPostSales && ($_SESSION['desk'] ?? '') === 'legal';
$isAccountsDesk = $isPostSales && ($_SESSION['desk'] ?? '') === 'accounts';
$showLegalTabs = $isAdmin || $isLegalDesk;       // Booking & Legal sections
$showAccountsTabs = $isAdmin || $isAccountsDesk; // Construction & Payment sections
// Possession (Possession Due → Handover Completed) is handled by admin only; the Accounts desk
// gets a read-only Possession status tab instead (same as the Legal / Sales read-only tabs).
require __DIR__ . '/config/site.php';
require __DIR__ . '/api/legal_docs_lib.php';
require __DIR__ . '/api/payment_lib.php';

// Document upload/download block for a Booking & Legal checkpoint — filled in
// by renderLegalDocs() (app.js) from the rules in api/legal_docs_lib.php.
function legal_docs_block($stage) { ?>
            <div class="attempt-log compact legal-docs" data-doc-stage="<?= htmlspecialchars($stage) ?>"></div>
<?php }

// Calendar-invite (email) + WhatsApp message blocks shared by Site Visit Scheduled,
// Re-Visit Scheduled and Negotiation Visit. $kind = site|revisit|negotiation (see VISIT_KINDS in app.js).
function visit_share_block($kind, $idp, $waField) { ?>
            <div class="attempt-log compact" id="<?= $idp ?>ShareBlock">
              <div class="al-title"><i class="fa-solid fa-envelope-circle-check"></i> Calendar Invite (Email)</div>
              <div class="wa-row">
                <button type="button" class="btn btn-sm" id="<?= $idp ?>InviteBtn" onclick="sendVisitInvite(false, null, '<?= $kind ?>')"><i class="fa-solid fa-paper-plane"></i> Send Calendar Invite</button>
                <label class="wa-hint" style="display:flex;align-items:center;gap:6px"><input type="checkbox" id="<?= $idp ?>InviteAuto" checked style="width:auto"> Auto-send when I save (only if date/time changed)</label>
              </div>
              <div class="wa-hint" id="<?= $idp ?>InviteStatus" style="margin-top:6px"></div>
              <div class="wa-row" style="margin-top:6px">
                <button type="button" class="btn btn-sm" id="<?= $idp ?>RescheduleBtn" onclick="sendRescheduledInvite('<?= $kind ?>')" title="Google Calendar's &quot;Propose a new time&quot; reply lands only in your own Gmail, never here. Update the date/time above to what the client proposed, then click this — it emails the client an updated invite and notifies the sales team."><i class="fa-solid fa-calendar-days"></i> Client Proposed New Time</button>
              </div>
            </div>

            <div class="attempt-log compact">
              <div class="al-title"><i class="fa-brands fa-whatsapp"></i> WhatsApp Visit Details</div>
              <div class="field">
                <textarea id="<?= $idp ?>WhatsappMsg" rows="6" oninput="this.dataset.auto=''"></textarea>
              </div>
              <div class="wa-row">
                <select name="<?= $waField ?>" onchange="onVisitWhatsappSentChange(this, '<?= $kind ?>')">
                  <option value="">Sent? Not set</option>
                  <option>No</option>
                  <option>Yes</option>
                </select>
                <button type="button" class="btn btn-sm" onclick="copyVisitWhatsapp('<?= $kind ?>')"><i class="fa-solid fa-copy"></i> Copy</button>
                <button type="button" class="btn btn-sm" onclick="openVisitWhatsapp('<?= $kind ?>')"><i class="fa-brands fa-whatsapp"></i> Open WhatsApp</button>
                <button type="button" class="btn btn-sm" onclick="fillVisitWhatsapp(true, '<?= $kind ?>')"><i class="fa-solid fa-rotate"></i> Regenerate</button>
              </div>
            </div>
<?php }

// Follow-up detail fields shared by Site Visit Follow-up, Post Site Visit Follow-up and Re-Visit Follow-up.
// $p = column prefix (sv | psv | rv) — field names are {$p}_followup_date / _mode / _note, {$p}_refollow, {$p}_refollow_date.
function followup_fields($p) { ?>
            <div class="grid">
              <div class="field"><label>Follow-up Date</label><input name="<?= $p ?>_followup_date" type="date"></div>
              <div class="field"><label>Mode</label><div class="mode-call"><select name="<?= $p ?>_followup_mode" onchange="updateModeCallBtns()">
                  <option value="">Not set</option>
                  <option>Call</option>
                  <option>WhatsApp</option>
                  <option>Email</option>
                  <option>Meeting</option>
                </select><button type="button" class="btn call-btn hidden" title="Call the client" onclick="callOpenLead(this.closest('.stage-section')?.dataset.stage || 'Follow-up')"><i class="fa-solid fa-phone"></i></button></div></div>
              <div class="field"><label>Re-follow-up Required?</label><select name="<?= $p ?>_refollow" onchange="updateStageRefollowUI('<?= $p ?>')">
                  <option value="">Not set</option>
                  <option>No</option>
                  <option>Yes</option>
                </select></div>
              <div class="field hidden" id="<?= $p ?>RefollowDateWrap"><label>Re-follow-up Date</label><input name="<?= $p ?>_refollow_date" type="date"></div>
            </div>
            <div class="field"><label>Discussion Note</label><textarea name="<?= $p ?>_followup_note"></textarea></div>
<?php }

// Dashboard blocks, one per desk. Each desk sees its own; admin / IT get all three behind the
// Sales | Legal | Accounts switch. $admin = rendered for admin (card links go to admin's pages).
function dash_legal_block($admin) { ?>
        <!-- Legal desk dashboard — filled by renderLegalDashboard() in app.js -->
        <div class="cards">
          <div class="card c-total dash-link" onclick="<?= $admin ? "goToBookings('')" : 'goToLeads({})' ?>" title="<?= $admin ? 'Open Bookings' : 'Open Manage Leads' ?>">
            <div class="card-icon"><i class="fa-solid fa-right-left"></i></div>
            <div class="label">TRANSFERRED LEADS</div>
            <div class="num" id="psTransferred">0</div>
            <div class="sub" id="psTransferredSub">From the sales team</div>
          </div>
          <div class="card c-hot dash-link" onclick="<?= $admin ? "goToBookings('pending')" : "goToLeads({ stage: 'Unit Blocked' })" ?>" title="View leads waiting for booking">
            <div class="card-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="label">BOOKING PENDING</div>
            <div class="num" id="psPending">0</div>
            <div class="sub">Awaiting Booking Initiated</div>
          </div>
          <div class="card c-visits dash-link" onclick="<?= $admin ? "goToBookings('legal')" : "goToPage('bookings')" ?>" title="Open Bookings">
            <div class="card-icon"><i class="fa-solid fa-file-contract"></i></div>
            <div class="label">BOOKING &amp; LEGAL</div>
            <div class="num" id="psLegal">0</div>
            <div class="sub">Booking → Registration</div>
          </div>
          <div class="card c-ps-pay dash-link" onclick="<?= $admin ? "goToBookings('registered')" : "goToLeads({ stage: 'Registered' })" ?>" title="View registered leads">
            <div class="card-icon"><i class="fa-solid fa-stamp"></i></div>
            <div class="label">READY FOR ACCOUNTS</div>
            <div class="num" id="psReady">0</div>
            <div class="sub">Registered — transfer pending</div>
          </div>
          <div class="card c-ps-acc dash-link" onclick="<?= $admin ? "switchDashDesk('accounts')" : "goToLeads({ stage: '__accounts' })" ?>" title="<?= $admin ? 'Open the Accounts view' : 'View leads transferred to Accounts' ?>">
            <div class="card-icon"><i class="fa-solid fa-calculator"></i></div>
            <div class="label">TRANSFERRED TO ACCOUNTS</div>
            <div class="num" id="psAccounts">0</div>
            <div class="sub">Legal completed</div>
          </div>
        </div>
        <div class="panel">
          <h3><i class="fa-solid fa-hourglass-half"></i> Booking Pending — Newly Transferred</h3>
          <div id="psDashPending"></div>
        </div>
        <div class="panel">
          <h3><i class="fa-solid fa-users"></i> Recent Legal Desk Leads</h3>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><i class="fa-solid fa-hashtag"></i> Lead</th>
                  <th><i class="fa-solid fa-user"></i> Client</th>
                  <th><i class="fa-solid fa-phone"></i> Contact</th>
                  <th><i class="fa-solid fa-house-chimney"></i> Villa</th>
                  <th><i class="fa-solid fa-layer-group"></i> Stage</th>
                  <th><i class="fa-solid fa-circle-info"></i> Booking Status</th>
                  <th><i class="fa-solid fa-user-tie"></i> Sales Person</th>
                  <th style="text-align:right"><i class="fa-solid fa-gear"></i> Action</th>
                </tr>
              </thead>
              <tbody id="psDashLeads"></tbody>
            </table>
          </div>
        </div>
<?php }
function dash_accounts_block($admin) { ?>
        <!-- Accounts desk dashboard — payments only, same figures as the Payments page (renderAccountsDashboard() in app.js) -->
        <div class="cards">
          <div class="card c-total dash-link" onclick="<?= $admin ? "setPayFilter('');goToPage('payments')" : 'goToLeads({})' ?>" title="<?= $admin ? 'Open Payments' : 'Open Manage Leads' ?>">
            <div class="card-icon"><i class="fa-solid fa-right-left"></i></div>
            <div class="label">LEADS WITH ACCOUNTS</div>
            <div class="num" id="acTransferred">0</div>
            <div class="sub" id="acTransferredSub">From the legal team</div>
          </div>
          <div class="card c-bookings dash-link" onclick="goToPage('payments')" title="Open Payments">
            <div class="card-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="label">RECEIVED</div>
            <div class="num" id="acRec">₹0</div>
            <div class="sub" id="acRecSub">0% of villa value</div>
          </div>
          <div class="card c-hot dash-link" onclick="setPayFilter('due');goToPage('payments')" title="Show clients with a payment due">
            <div class="card-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="label">DUE NOW</div>
            <div class="num" id="acDue">₹0</div>
            <div class="sub" id="acDueSub">Demanded, not yet paid</div>
          </div>
          <div class="card c-cancelled dash-link" onclick="setPayFilter('overdue');goToPage('payments')" title="Show overdue payments">
            <div class="card-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="label">OVERDUE</div>
            <div class="num" id="acOd">₹0</div>
            <div class="sub" id="acOdSub">Past the pay-by date</div>
          </div>
          <div class="card c-ps-acc dash-link" onclick="setPayFilter('');goToPage('payments')" title="Open Payments">
            <div class="card-icon"><i class="fa-solid fa-calendar-plus"></i></div>
            <div class="label">PENDING (LATER STAGES)</div>
            <div class="num" id="acUp">₹0</div>
            <div class="sub" id="acUpSub">Not demanded yet</div>
          </div>
        </div>
        <!-- Full width: every client with a payment due, oldest pay-by date first -->
        <div class="panel">
          <h3><i class="fa-solid fa-calendar-days"></i> Payment Due — Oldest First</h3>
          <div id="acDashDues"></div>
        </div>
        <div class="panel" style="margin-top:18px">
          <h3><i class="fa-solid fa-users"></i> Recent Accounts Desk Leads</h3>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><i class="fa-solid fa-hashtag"></i> Lead ID</th>
                  <th><i class="fa-solid fa-user"></i> Client</th>
                  <th><i class="fa-solid fa-house-chimney"></i> Villa</th>
                  <th><i class="fa-solid fa-person-digging"></i> Construction Status</th>
                  <th><i class="fa-solid fa-circle-check"></i> Received</th>
                  <th><i class="fa-solid fa-hourglass-half"></i> Due Now</th>
                  <th><i class="fa-solid fa-chart-simple"></i> Paid</th>
                  <th style="text-align:right"><i class="fa-solid fa-gear"></i> Action</th>
                </tr>
              </thead>
              <tbody id="acDashLeads"></tbody>
            </table>
          </div>
        </div>
<?php }
function dash_sales_block($admin) { ?>
        <div class="cards">
          <div class="card c-total dash-link" onclick="openTotalLeadsBreakdown()" title="Click to view breakdown by category">
            <div class="card-icon"><i class="fa-solid fa-users"></i></div>
            <div class="label">TOTAL LEADS</div>
            <div class="num" id="kLeads">0</div>
            <div class="sub">All enquiries</div>
          </div>
          <div class="card c-hot dash-link" onclick="goToLeads({ interest: 'HOT' })" title="View Hot leads in Manage Leads">
            <div class="card-icon"><i class="fa-solid fa-fire"></i></div>
            <div class="label">HOT LEADS</div>
            <div class="num" id="kHot">0</div>
            <div class="sub">Priority follow-up</div>
          </div>
          <div class="card c-visits dash-link" onclick="goToPage('visits')" title="Open Site Visits">
            <div class="card-icon"><i class="fa-solid fa-calendar-check"></i></div>
            <div class="label">SITE VISITS</div>
            <div class="num" id="kVisits">0</div>
            <div class="sub">Completed / planned</div>
          </div>
          <div class="card c-bookings dash-link" onclick="goToPage('bookings')" title="Open Bookings">
            <div class="card-icon"><i class="fa-solid fa-house-circle-check"></i></div>
            <div class="label">BOOKINGS</div>
            <div class="num" id="kBookings">0</div>
            <div class="sub">Confirmed bookings</div>
          </div>
          <div class="card c-cancelled dash-link" onclick="goToLeads({ stage: 'Cancelled' })" title="View Cancelled leads in Manage Leads">
            <div class="card-icon"><i class="fa-solid fa-circle-xmark"></i></div>
            <div class="label">CANCELLED LEADS</div>
            <div class="num" id="kCancelled">0</div>
            <div class="sub">Marked as cancelled</div>
          </div>
        </div>
        <div class="dash-row">
          <div class="panel">
            <h3><i class="fa-solid fa-location-dot"></i> Today's / Upcoming Site Visits</h3>
            <div id="dashSiteVisits"></div>
          </div>
          <div class="panel">
            <h3><i class="fa-solid fa-calendar-days"></i> Today's / Upcoming Follow-ups</h3>
            <div id="dashFollowups"></div>
          </div>
        </div>
        <div class="panel" style="margin-top:18px">
          <h3><i class="fa-solid fa-users"></i> Recent Leads</h3>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><i class="fa-solid fa-hashtag"></i> Lead</th>
                  <th><i class="fa-solid fa-user"></i> Client</th>
                  <th><i class="fa-solid fa-phone"></i> Contact</th>
                  <th><i class="fa-solid fa-layer-group"></i> Stage</th>
                  <th><i class="fa-solid fa-heart"></i> Interest</th>
                  <th><i class="fa-solid fa-calendar-days"></i> Next Follow-up</th>
                  <th><i class="fa-solid fa-user-tie"></i> Sales Person</th>
                  <th style="text-align:right"><i class="fa-solid fa-gear"></i> Action</th>
                </tr>
              </thead>
              <tbody id="dashLeads"></tbody>
            </table>
          </div>
        </div>
<?php }
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#0b1712">
  <title>CRM | Antaaya Villas</title>
  <link rel="icon" type="image/png" href="image/AVL White SVG .svg">
  <link rel="apple-touch-icon" sizes="180x180" href="image/AVL White SVG .svg">
  <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;1,600&family=Montserrat:wght@400;500;600;700;800&display=swap">

  <style>
    :root {
      --bg: #faf7f2b6;
      --card: #fff;
      --ink: #1f2328;
      --muted: #6b7280;
      --line: #e5e7eb;
      --accent: #1f5c45;
      --accent2: #173f31;
      --danger: #df190b;
      --warn: #b54708;
      --blue: #175cd3;
      --brand-green: #76ca09;
      --brand-green2: #8dc63f;
      --side-bg1: #010202;
      --side-bg2: #0b1712
    }

    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      font-family: 'Montserrat', Inter, Segoe UI, Arial, sans-serif;
      background: var(--bg);
      color: var(--ink)
    }

    .app {
      display: flex;
      min-height: 100vh
    }

    .sidebar {
      width: 350px;
      background: linear-gradient(160deg, var(--side-bg1) 0%, var(--side-bg2) 100%);
      color: #fff;
      padding: 22px 14px;
      position: fixed;
      inset: 0 auto 0 0;
      display: flex;
      flex-direction: column;
      overflow-y: auto;
      box-shadow: 8px 0 15px rgba(0, 0, 0, .35), 28px 0 90px rgba(0, 0, 0, .18), 2px 0 0 rgba(255, 255, 255, .04) inset;
      z-index: 2;
    }

    .sidebar::before {
      content: '';
      position: absolute;
      inset: 0;
      background-image: url('image/last-page.jpg');
      background-size: cover;
      background-position: center;
      filter: blur(8px);
      z-index: -2;
    }

    .sidebar::after {
      content: '';
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, 0.78);
      z-index: -1;
    }

    .brand-wrap {
      padding: 2px 12px 18px;
      border-bottom: 1px solid rgba(255, 255, 255, .08);
      margin-bottom: 12px;
    }

    .brand-logos {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 14px;
    }

    .brand-logo {
      max-height: 44px;
      width: auto;
      object-fit: contain;
    }

    .brand-divider {
      width: 1px;
      height: 36px;
      background: rgba(255, 255, 255, .25);
      flex-shrink: 0;
    }

    .brand-sub {
      display: block;
      text-align: center;
      font-family: 'Montserrat', sans-serif;
      font-style: normal;
      font-size: 12px;
      font-weight: 600;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      color: var(--brand-green2);
      margin-top: 10px;
    }

    .nav button {
      width: 100%;
      border: 0;
      border-left: 3px solid transparent;
      outline: none !important;
      background: transparent;
      color: rgba(255, 255, 255, 0.85);
      text-align: left;
      padding: 12px 14px;
      border-radius: 8px;
      margin: 2px 0;
      cursor: pointer;
      font-size: 13.5px;
      font-weight: 500;
      transition: background .15s, color .15s, border-color .15s;

      /* Icon + text alignment */
      display: flex;
      align-items: center;
      gap: 12px;
    }

    /* All icons same size and alignment */
    .nav button i {
      width: 20px;
      min-width: 20px;
      height: 20px;
      font-size: 15px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: rgba(255, 255, 255, 0.69);
      transition: color .15s;
    }

    .nav button:hover {
      background: rgba(255, 255, 255, .05);
      color: #fff
    }

    .nav button.active {
      background: linear-gradient(90deg, rgba(118, 202, 9, .18) 0%, rgba(118, 202, 9, .04) 100%);
      border-left-color: var(--brand-green);
      color: #fff;
      font-weight: 600;
    }

    .nav button.active i,
    .nav button:hover i {
      color: var(--brand-green2);
    }

    .main {
      margin-left: 350px;
      width: calc(100% - 350px);
      padding: 26px
    }

    .top {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 22px
    }

    .top h1 {
      font-family: 'Cormorant Garamond', serif;
      font-style: none;
      font-weight: 700;
      margin: 0;
      font-size: 32px;
      color: var(--accent2)
    }

    .top p {
      margin: 5px 0 0;
      color: var(--muted);
      font-size: 13px
    }

    .btn {
      border: 1px solid var(--line);
      background: #fff;
      padding: 10px 14px;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 600;
      box-shadow: 0 1px 2px rgba(16, 24, 20, .04), 0 2px 6px rgba(16, 24, 20, .05);
      transition: box-shadow .15s, transform .15s;
    }

    .btn:hover {
      box-shadow: 0 4px 12px rgba(16, 24, 20, .1);
      transform: translateY(-1px);
    }

    .btn.primary {
      background: linear-gradient(135deg, var(--brand-green2) 0%, var(--brand-green) 100%);
      color: #0d1a08;
      border-color: transparent;
      font-weight: 700;
      box-shadow: 0 4px 14px rgba(118, 202, 9, .3);
    }

    .btn.primary:hover {
      box-shadow: 0 10px 26px rgba(118, 202, 9, .4);
      transform: translateY(-1px);
    }

    .btn.danger {
      color: var(--danger)
    }

    /* Call button (tel: link) — same look as the other action buttons (black icon) */
    .mode-call { display: flex; gap: 8px; align-items: center }
    .mode-call select { flex: 1; min-width: 0 }
    .mode-call .btn.call-btn { padding: 9px 12px; flex: none }
    .ls-call { border: 1px solid var(--line); background: #fff; color: inherit; border-radius: 6px; padding: 2px 7px; font-size: 11px; cursor: pointer; margin-left: 4px; vertical-align: 1px }
    .ls-call:hover { box-shadow: 0 2px 6px rgba(16, 24, 20, .1) }

    .cards {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 14px;
      margin-bottom: 22px
    }

    .card {
      background: var(--card);
      border: none;
      border-top: none;
      border-radius: 12px;
      padding: 17px;
      box-shadow: 0 1px 2px rgba(16, 24, 20, .04), 0 8px 20px rgba(16, 24, 20, .06);
      transition: box-shadow .18s, transform .18s;
    }

    .card:hover {
      box-shadow: 0 4px 10px rgba(16, 24, 20, .06), 0 14px 30px rgba(16, 24, 20, .1);
      transform: translateY(-2px);
    }

    .card.c-total {
      border-top-color: #475467
    }

    .card.c-hot {
      border-top-color: #e05200
    }

    .card.c-hot .num {
      color: #e05200
    }

    .card.c-visits {
      border-top-color: #05357c
    }

    .card.c-visits .num {
      color: #05357c
    }

    .card.c-bookings {
      border-top-color: #16940a
    }

    .card.c-bookings .num {
      color: #16940a
    }

    .card.c-cancelled {
      border-top-color: #cf180b
    }

    .card.c-cancelled .num {
      color: #cf180b
    }

    /* Icon-in-circle style used on every KPI card + the table/panel headings,
       so the same visual language (icon chip + heading) repeats everywhere. */
    .card-icon {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 17px;
      margin-bottom: 10px;
    }

    .card.c-total { background: linear-gradient(150deg, #eef2f6 0%, #fff 60%); }
    .card.c-hot { background: linear-gradient(150deg, #fff1e8 0%, #fff 60%); }
    .card.c-visits { background: linear-gradient(150deg, #eaf1ff 0%, #fff 60%); }
    .card.c-bookings { background: linear-gradient(150deg, #e7f4ed 0%, #fff 60%); }
    .card.c-cancelled { background: linear-gradient(150deg, #fdecec 0%, #fff 60%); }

    .card.c-total .card-icon { background: #dbe2e8; color: #475467; }
    .card.c-hot .card-icon { background: #ffe1cc; color: #e05200; }
    .card.c-visits .card-icon { background: #d7e5ff; color: #05357c; }
    .card.c-bookings .card-icon { background: #d1eeda; color: #16940a; }
    .card.c-cancelled .card-icon { background: #fad2ce; color: #cf180b; }
    .card.c-ps-pay { background: linear-gradient(150deg, #f4ecff 0%, #fff 60%); }
    .card.c-ps-pay .card-icon { background: #e4d1fb; color: #7b2ff7; }
    .card.c-ps-pay .num { color: #7b2ff7; }
    .card.c-ps-acc { background: linear-gradient(150deg, #e3f5f8 0%, #fff 60%); }
    .card.c-ps-acc .card-icon { background: #c9ecf2; color: #0e7490; }
    .card.c-ps-acc .num { color: #0e7490; }

    .top h1, .panel > h3 {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .top h1 i, .panel > h3 i {
      color: inherit;
      font-size: .78em;
    }

    .greeting-line {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
      font-weight: 600;
      color: var(--muted);
      margin-bottom: 2px;
    }
    #greetingName { margin: 0; }

    /* Notification bell */
    .notif-wrap { position: relative; }
    .notif-btn {
      position: relative;
      width: 42px;
      height: 42px;
      padding: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      font-size: 17px;
    }
    .notif-count {
      position: absolute;
      top: -4px;
      right: -4px;
      background: var(--danger, #cf180b);
      color: #fff;
      font-size: 10px;
      font-weight: 700;
      min-width: 17px;
      height: 17px;
      border-radius: 9px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 3px;
    }
    .notif-panel {
      position: absolute;
      top: 52px;
      right: 0;
      width: 380px;
      max-height: 420px;
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 12px;
      box-shadow: 0 10px 30px rgba(16, 24, 20, .15);
      z-index: 60;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }
    .notif-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 14px;
      border-bottom: 1px solid var(--line);
      font-weight: 700;
      flex-shrink: 0;
    }
    .notif-head button {
      background: none;
      border: none;
      color: var(--brand-green2);
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
    }
    .notif-list { overflow-y: auto; }
    .notif-item {
      display: flex;
      gap: 10px;
      padding: 12px 14px;
      border-bottom: 1px solid #f1f3f2;
      font-size: 12.5px;
      align-items: flex-start;
    }
    .notif-item i.notif-ico { margin-top: 2px; }
    .notif-item .notif-body { flex: 1; }
    .notif-item .notif-actions { display: flex; gap: 6px; margin-top: 6px; }
    .notif-item .notif-actions button { font-size: 11px; padding: 5px 10px; }
    .notif-item .notif-time {
      display: block;
      margin-top: 4px;
      font-size: 10.5px;
      font-weight: 600;
      color: var(--muted);
    }
    .notif-item .notif-x {
      background: none; border: none; cursor: pointer; color: var(--muted);
      font-size: 13px; padding: 2px;
    }
    .notif-empty { padding: 30px 14px; text-align: center; color: var(--muted); font-size: 12.5px; }

    /* Sidebar pending follow-up badge */
    .nav-badge {
      margin-left: auto;
      background: var(--brand-green2);
      color: #0d1a08;
      font-size: 10.5px;
      font-weight: 800;
      min-width: 18px;
      height: 18px;
      border-radius: 9px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 5px;
    }

    .card.c-desk-broker {
      border-top-color: #3d3d3d
    }

    .card.c-desk-broker .num {
      color: #16940a
    }

    .card.c-desk-owner {
      border-top-color: #3d3d3d
    }

    .card.c-desk-owner .num {
      color: #16940a
    }

    .card.c-desk-legal { border-top-color: #7b2ff7 }
    .card.c-desk-accounts { border-top-color: #0e7490 }
    .card.c-desk-entry {
      border-top-color: #3d3d3d
    }

    .card.c-desk-entry .num {
      color: #16940a
    }

    .card .label {
      font-size: 12px;
      color: var(--muted)
    }

    .card .num {
      font-size: 25px;
      font-weight: 800;
      margin-top: 7px
    }

    .card .sub {
      font-size: 11px;
      color: var(--muted);
      margin-top: 4px
    }

    .panel {
      background: #fff;
      border: 1px solid rgba(31, 35, 40, .05);
      border-radius: 12px;
      padding: 18px;
      margin-bottom: 18px;
      box-shadow: 0 1px 2px rgba(16, 24, 20, .04), 0 10px 26px rgba(16, 24, 20, .06);
    }

    .dash-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 18px;
    }

    .dash-row .panel {
      margin-bottom: 0;
      min-width: 0;
    }

    @media (max-width: 900px) {
      .dash-row {
        grid-template-columns: 1fr;
      }
    }

    .panel h3 {
      margin: 0 0 15px;
      font-size: 16px;
      font-weight: 700;
      color: var(--ink)
    }

    .toolbar {
      display: flex;
      flex-direction: column;
      gap: 9px;
      margin-bottom: 15px
    }

    .toolbar-row {
      display: flex;
      gap: 9px;
      flex-wrap: wrap
    }

    .toolbar-row select {
      flex: 1;
      min-width: 160px
    }

    .toolbar input,
    .toolbar select {
      min-width: 160px
    }

    .search-box {
      position: relative
    }

    .search-box input {
      padding-right: 34px
    }

    .search-box i {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #9aa5a1;
      font-size: 13px;
      pointer-events: none
    }

    input,
    select,
    textarea {
      width: 100%;
      padding: 9px 10px;
      border: 1px solid #d5d9dd;
      outline: none !important;
      border-radius: 7px;
      background: #fff;
      font: inherit;
      font-size: 13px;
      box-shadow: inset 0 1px 2px rgba(16, 24, 20, .03);
      transition: border-color .15s, box-shadow .15s;
    }

    input[type="checkbox"],
    input[type="radio"] {
      width: 16px;
      flex: none;
      box-shadow: none;
      padding: 0
    }

    input:focus,
    select:focus,
    textarea:focus {
      border-color: var(--brand-green);
      box-shadow: 0 0 0 3px rgba(118, 202, 9, .12);
    }

    textarea {
      min-height: 80px;
      resize: vertical
    }

    .field {
      margin-bottom: 12px
    }

    .field label {
      display: block;
      font-size: 12px;
      font-weight: 700;
      margin-bottom: 5px;
      color: #374151
    }

    /* Rupee-prefixed amount field (Indian comma grouping, e.g. 1,00,000) */
    .amt-field {
      position: relative
    }

    .amt-field i {
      position: absolute;
      left: 11px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--muted);
      font-size: 12px;
      pointer-events: none
    }

    .amt-field input {
      padding-left: 27px
    }

    /* KYC Verification — status panel. Soft tint, even border; colour follows data-status. */
    .kyc-panel { --kc: #9a6a1f; --kbg: #fdfaf3; --kbd: #efe3c8; --kbar: #d9a441; --ktint: #f8eed8;
      border: 1px solid var(--kbd); background: var(--kbg); border-radius: 10px; padding: 12px 14px 4px; margin: 0 0 14px }
    .kyc-panel[data-status="Submitted"] { --kc: #3c5f8f; --kbg: #f6f9fd; --kbd: #dbe5f2; --kbar: #7d9cc9; --ktint: #e6eef8 }
    .kyc-panel[data-status="Verified"] { --kc: #2f6b4c; --kbg: #f4faf6; --kbd: #d5eadc; --kbar: #5fa47f; --ktint: #e2f1e8 }
    .kyc-panel[data-status="Rejected"] { --kc: #9b3b34; --kbg: #fdf6f5; --kbd: #f1d9d6; --kbar: #d27b72; --ktint: #f8e5e2 }
    .kyc-panel-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px }
    .kyc-panel-head > i { font-size: 16px; color: var(--kc); width: 32px; height: 32px; border-radius: 50%; background: var(--ktint); display: grid; place-items: center; flex: none }
    .kyc-panel-title { flex: 1; font-size: 13.5px; font-weight: 700; color: var(--ink) }
    .kyc-panel-title small { display: block; font-size: 11px; font-weight: 500; color: var(--muted); margin-top: 1px }
    .kyc-pill { font-size: 11px; font-weight: 700; letter-spacing: .2px; color: var(--kc); background: var(--ktint); border: 1px solid var(--kbd); border-radius: 999px; padding: 3px 11px; white-space: nowrap }
    .kyc-pill::before { content: ''; display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: var(--kbar); margin-right: 6px; vertical-align: 1px }
    .kyc-panel select, .kyc-panel input { border-color: var(--kbd); background: #fff; font-weight: 600 }
    .kyc-panel select:focus, .kyc-panel input:focus { border-color: var(--kbar) }
    .kyc-panel-msg { grid-column: span 2; align-self: center; font-size: 12px; font-weight: 500; color: var(--kc) }
    .kyc-panel-msg i { margin-right: 4px; opacity: .85 }
    .kyc-panel .req-star { color: #c2410c }
    @media (max-width: 720px) { .kyc-panel-msg { grid-column: 1 / -1 } }

    .amt-hint { font-size: 11px; color: var(--muted); margin-top: 4px; min-height: 0 }
    .amt-hint:empty { display: none }
    .amt-tag { font-size: 10px; font-weight: 700; color: #176b48; background: #e7f4ed; border-radius: 6px; padding: 1px 6px; margin-left: 4px; vertical-align: 1px }

    .grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 13px
    }

    .grid-3 {
      grid-template-columns: repeat(3, 1fr)
    }

    .grid-2 {
      grid-template-columns: repeat(2, 1fr)
    }

    .table-wrap {
      overflow-x: auto;
      overflow-y: hidden;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: thin;
      scrollbar-color: #9aa5a1 #eef0ef;
      padding-bottom: 4px
    }

    .table-wrap::-webkit-scrollbar {
      height: 10px
    }

    .table-wrap::-webkit-scrollbar-track {
      background: #eef0ef;
      border-radius: 6px
    }

    .table-wrap::-webkit-scrollbar-thumb {
      background: #9aa5a1;
      border-radius: 6px
    }

    .table-wrap::-webkit-scrollbar-thumb:hover {
      background: #7c8884
    }

    #leadsTableWrap {
      max-height: 64vh;
      overflow-y: auto;
      scrollbar-width: none;
      -ms-overflow-style: none;
    }

    #leadsTableWrap::-webkit-scrollbar {
      width: 0;
      height: 0;
      background: transparent;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 12px;
      min-width: 950px
    }

    th,
    td {
      text-align: left;
      padding: 11px 9px;
      border-bottom: 1px solid var(--line);
      white-space: nowrap
    }

    th {
      background: #fafbfa;
      color: #55625c;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .4px;
      border-bottom: 1px solid var(--line);
      position: sticky;
      top: 0;
      z-index: 3
    }

    th i {
      color: inherit;
      margin-right: 5px;
      font-size: 10px;
    }

    /* Pin the Action column so its buttons are never scrolled out of
       reach / clipped, regardless of how wide the row content gets. */
    th:last-child,
    td:last-child {
      position: sticky;
      right: 0;
      background: #fff;
      box-shadow: -8px 0 8px -8px rgba(0, 0, 0, .15)
    }

    th:last-child {
      background: #fafafa;
      z-index: 4
    }

    td:last-child {
      z-index: 1
    }

    tr:hover td {
      background: #fbfcfb
    }

    tr:hover td:last-child {
      background: #fbfcfb
    }

    .badge {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 20px;
      font-size: 10px;
      font-weight: 700;
      background: #eef2f1;
      color: #33443d
    }

    .hot {
      background: #fdecec;
      color: #b42318
    }

    .warm {
      background: #fff3df;
      color: #b54708
    }

    .cold {
      background: #eef2f6;
      color: #475467
    }

    .green {
      background: #e7f4ed;
      color: #176b48
    }

    .blue {
      background: #eaf1ff;
      color: #175cd3
    }

    .stage-new {
      background: #eef2f6;
      color: #475467
    }

    .stage-visit {
      background: #eaf1ff;
      color: #175cd3
    }

    .stage-engage {
      background: #fff3df;
      color: #b54708
    }

    .stage-book {
      background: #e7f4ed;
      color: #176b48
    }

    .stage-possession {
      background: #e6f7f5;
      color: #0f766e
    }

    .stage-construction {
      background: #f3eefe;
      color: #6d28d9
    }

    .stage-closed {
      background: #f2f4f7;
      color: #667085
    }

    .stage-cancel {
      background: #fdecec;
      color: #b42318
    }

    .stage-badge {
      font-size: 11px;
      font-weight: 800;
      padding: 5px 10px;
      letter-spacing: .2px
    }

    .stage-tracker {
      margin: 2px 0 14px
    }

    .st-row {
      display: flex;
      align-items: center;
      gap: 0
    }

    .st-step {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      min-width: 74px
    }

    .st-dot {
      width: 14px;
      height: 14px;
      border-radius: 50%;
      background: #e4e7ec;
      border: 2px solid #e4e7ec
    }

    .st-step.st-done .st-dot {
      background: #176b48;
      border-color: #176b48
    }

    .st-step.st-current .st-dot {
      background: #fff;
      border-color: #175cd3;
      box-shadow: 0 0 0 3px #eaf1ff
    }

    .st-step.st-cancel .st-dot {
      background: #b42318;
      border-color: #b42318
    }

    .st-label {
      font-size: 10px;
      font-weight: 700;
      color: var(--muted);
      text-align: center
    }

    .st-step.st-current .st-label {
      color: #175cd3
    }

    .st-line {
      flex: 1;
      height: 1.5px;
      background: #e4e7ec;
      margin-bottom: 18px
    }

    .st-current-label {
      margin-top: 8px;
      font-size: 14px;
      font-weight: 600;
      color: var(--muted);
      display: flex;
      align-items: center;
      gap: 10px;
      white-space: nowrap;
    }

    .stage-select {
      font: inherit;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: .2px;
      padding: 5px 10px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
    }

    .stage-select:disabled {
      opacity: .7;
      cursor: default
    }

    .stage-select.stage-new {
      background: #eef2f6;
      color: #475467
    }

    .stage-select.stage-visit {
      background: #eaf1ff;
      color: #175cd3
    }

    .stage-select.stage-engage {
      background: #fff3df;
      color: #b54708
    }

    .stage-select.stage-book {
      background: #e7f4ed;
      color: #176b48
    }

    .stage-select.stage-possession {
      background: #e6f7f5;
      color: #0f766e
    }

    .stage-select.stage-construction {
      background: #f3eefe;
      color: #6d28d9
    }

    .stage-select.stage-closed {
      background: #f2f4f7;
      color: #667085
    }

    .stage-select.stage-cancel {
      background: #fdecec;
      color: #b42318
    }

    /* ---- Lead summary strip (top of Edit Client) ---- */
    .lead-summary {
      position: relative;
      display: flex;
      flex-wrap: wrap;
      gap: 12px 24px;
      background: #f8faf9;
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 10px 46px 10px 14px;
      margin-bottom: 10px;
    }

    .lead-summary .ls-item {
      min-width: 110px
    }

    .lead-summary .ls-label {
      font-size: 10px;
      font-weight: 800;
      letter-spacing: .3px;
      color: var(--muted);
      text-transform: uppercase
    }

    .lead-summary .ls-value {
      font-size: 14px;
      font-weight: 700;
      color: #1b2521;
      margin-top: 2px;
      word-break: break-word
    }

    .ls-edit-btn {
      position: absolute;
      right: 10px;
      bottom: 10px;
      width: 30px;
      height: 30px;
      border-radius: 50%;
      border: 1px solid var(--line);
      background: #fff;
      color: var(--accent);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 1px 3px rgba(0, 0, 0, .08)
    }

    .ls-edit-btn:hover {
      background: var(--accent);
      color: #fff
    }

    .lead-basic-edit {
      border: 1px dashed var(--line);
      border-radius: 10px;
      padding: 14px 16px 4px;
      margin: -4px 0 14px;
      background: #fcfdfd
    }

    /* ---- Liquid lead-stage progress bar ---- */
    .liquid-bar {
      margin: 4px 0 16px
    }

    .liquid-track-row {
      display: flex;
      align-items: center;
      gap: 0
    }

    .liquid-step {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 6px;
      min-width: 70px
    }

    .liquid-dot {
      width: 15px;
      height: 15px;
      border-radius: 50%;
      background: #e4e7ec;
      border: 2px solid #e4e7ec;
      z-index: 1;
      transition: all .3s
    }

    .liquid-step.ls-done .liquid-dot {
      background: #176b48;
      border-color: #176b48
    }

    .liquid-step.ls-current .liquid-dot {
      background: #fff;
      border-color: #175cd3;
      box-shadow: 0 0 0 4px #eaf1ff
    }

    .liquid-step.ls-locked .liquid-dot {
      background: #f2f4f7;
      border-color: #e4e7ec
    }

    .liquid-label {
      font-size: 10px;
      font-weight: 700;
      color: var(--muted);
      text-align: center
    }

    .liquid-step.ls-current .liquid-label {
      color: #175cd3
    }

    .liquid-step.ls-done .liquid-label {
      color: #176b48
    }

    .liquid-line-wrap {
      flex: 1;
      height: 8px;
      background: #eef1f0;
      border-radius: 6px;
      overflow: hidden;
      margin: 0 -2px 20px;
      position: relative
    }

    .liquid-line-fill {
      height: 100%;
      border-radius: 6px;
      background: linear-gradient(90deg, #21b573, #176b48);
      transition: width .6s cubic-bezier(.4, 0, .2, 1);
      width: 0%
    }

    .liquid-cur-label {
      margin-top: 12px;
      font-size: 13px;
      font-weight: 700;
      color: var(--muted);
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px
    }

    .liquid-cur-label b {
      color: #175cd3
    }

    .stage-jump {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-left: auto;
      padding-left: 16px;
      font-weight: 500
    }

    .stage-jump select {
      font-size: 12px;
      padding: 4px 6px;
      border: 1px solid var(--line);
      border-radius: 6px;
      max-width: 220px
    }

    .btn.btn-sm {
      padding: 4px 12px;
      font-size: 12px
    }

    /* ---- Per-stage checklist chips (inside each pipeline tab) ---- */
    .stage-checklist {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin: 0 0 16px
    }

    .chip {
      border: 1px solid var(--line);
      background: #fff;
      color: #344054;
      font-size: 12px;
      font-weight: 700;
      padding: 7px 12px;
      border-radius: 20px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 6px;
      transition: all .15s
    }

    .chip .chip-n {
      font-size: 10px;
      opacity: .6
    }

    .chip.chip-done {
      background: #e7f4ed;
      border-color: #bfe6d2;
      color: #176b48
    }

    .chip.chip-current {
      background: #eaf1ff;
      border-color: #175cd3;
      color: #175cd3;
      box-shadow: 0 0 0 2px #eaf1ff
    }

    .chip.chip-locked {
      background: #f9fafb;
      color: #98a2b3;
      cursor: not-allowed;
      opacity: .8
    }

    .chip:not(.chip-locked):hover {
      transform: translateY(-1px)
    }

    /* ---- Next-step guidance panel ---- */
    .next-step-panel {
      display: flex;
      gap: 14px;
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 12px 16px;
      margin: 4px 0 6px;
      background: #f7fbf9
    }

    .next-step-panel .ns-col {
      flex: 1;
      min-width: 0
    }

    .next-step-panel .ns-label {
      font-size: 10px;
      font-weight: 800;
      letter-spacing: .3px;
      text-transform: uppercase;
      color: var(--muted)
    }

    .next-step-panel .ns-text {
      font-size: 13px;
      font-weight: 700;
      margin-top: 3px
    }

    .next-step-panel .ns-col.ns-done .ns-text {
      color: #176b48
    }

    .next-step-panel .ns-col.ns-next .ns-text {
      color: #175cd3
    }

    .hidden {
      display: none !important
    }

    .actions {
      display: flex;
      gap: 7px
    }

    .empty {
      padding: 35px;
      text-align: center;
      color: var(--muted)
    }

    .modal {
      position: fixed;
      inset: 0 0 0 350px;
      background: rgba(0, 0, 0, .45);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      z-index: 10
    }

    @media (max-width: 900px) {
      .modal {
        inset: 0
      }
    }

    .modal.show {
      display: flex
    }

    .modal-box {
      background: #fff;
      width: min(900px, 100%);
      max-height: 92vh;
      overflow: auto;
      border-radius: 12px;
      padding: 22px
    }

    /* Dashboard cards that jump to a page / filtered list */
    .card.dash-link { cursor: pointer; transition: transform .15s ease, box-shadow .15s ease }
    .card.dash-link:hover { transform: translateY(-3px); box-shadow: 0 10px 24px rgba(16, 24, 20, .10) }

    /* Floating modal (Leads by Category): no white sheet — heading + cards sit over the dimmed dashboard */
    .modal-box.modal-float { background: transparent; box-shadow: none; padding: 0; overflow: visible }
    .modal-box.modal-float .modal-head { margin-bottom: 16px }
    .modal-box.modal-float .modal-head h2 { color: #fff; font-size: 24px; text-shadow: 0 2px 10px rgba(0, 0, 0, .35) }
    .modal-box.modal-float .close { background: rgba(255, 255, 255, .92); box-shadow: 0 4px 14px rgba(0, 0, 0, .25) }
    .modal-box.modal-float .card { box-shadow: 0 14px 34px rgba(0, 0, 0, .28); transition: transform .15s ease, box-shadow .15s ease }
    .modal-box.modal-float .card:hover { transform: translateY(-4px); box-shadow: 0 18px 40px rgba(0, 0, 0, .34) }
    #totalLeadsModal { backdrop-filter: blur(3px) }

    #clientModal .modal-box {
      width: min(1180px, 97vw);
      height: min(820px, 88vh);
      max-height: 88vh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      padding: 16px 18px
    }

    #clientModal .modal-head,
    #clientModal #leadSummary,
    #clientModal #basicEdit {
      flex: 0 0 auto
    }

    #clientForm {
      display: flex;
      flex-direction: column;
      flex: 1 1 auto;
      min-height: 0
    }

    .modal-mid-scroll {
      flex: 1 1 auto;
      min-height: 0;
      overflow-y: auto;
      overflow-x: hidden;
      padding-right: 6px;
      margin-right: -6px
    }

    .client-modal-footer {
      flex: 0 0 auto;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 9px;
      padding-top: 10px;
      margin-top: 8px;
      border-top: 1px solid var(--line)
    }

    .ctab {
      min-height: 0
    }


    /* ---- Villa picker (type-or-select combo; Villas Shortlisted uses chips) ---- */
    .vpick { position: relative }
    .vpick .vp-caret { position: absolute; right: 10px; top: 13px; font-size: 11px; color: var(--muted); pointer-events: none }
    .vpick > input:not([type=hidden]) { padding-right: 28px }
    .vpick .vp-box { display: flex; flex-wrap: wrap; align-items: center; gap: 5px; min-height: 38px; padding: 4px 28px 4px 6px; border: 1px solid #d5d9dd; border-radius: 7px; background: #fff; cursor: text; box-shadow: inset 0 1px 2px rgba(16, 24, 20, .03) }
    .vpick.open .vp-box, .vpick.open > input:not([type=hidden]) { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(31, 92, 69, .12) }
    .vpick .vp-box .vp-input { flex: 1 1 90px; width: auto; min-width: 90px; border: 0; box-shadow: none; padding: 5px 4px; background: transparent }
    .vpick .vp-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 4px 3px 9px; border-radius: 999px; background: #e8f3ee; color: var(--accent2); font-size: 12px; font-weight: 600; white-space: nowrap }
    .vpick .vp-chip button { border: 0; background: rgba(31, 92, 69, .14); color: var(--accent2); width: 17px; height: 17px; border-radius: 50%; font-size: 12px; line-height: 1; cursor: pointer; padding: 0 }
    .vpick .vp-chip button:hover { background: var(--danger); color: #fff }
    .vpick .vp-box.disabled { background: #f4f5f6; cursor: not-allowed }
    .vpick .vp-box.disabled .vp-chip button { display: none }
    .vpick .vp-panel { display: none; position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 60; max-height: 250px; overflow-y: auto; background: #fff; border: 1px solid var(--line); border-radius: 10px; box-shadow: 0 12px 28px rgba(16, 24, 20, .16); padding: 4px }
    .vpick.open .vp-panel { display: block }
    .vpick .vp-opt { display: flex; align-items: center; gap: 9px; padding: 7px 9px; border-radius: 7px; font-size: 13px; cursor: pointer }
    .vpick .vp-opt:hover, .vpick .vp-opt.active { background: #f1f6f3 }
    .vpick .vp-opt .vp-name { flex: 1 }
    .vpick .vp-opt .vp-name em { font-style: normal; color: var(--muted); margin-left: 2px }
    .vpick .vp-opt .vp-check { width: 15px; height: 15px; border: 1.5px solid #c3c9cf; border-radius: 4px; flex: none; display: flex; align-items: center; justify-content: center; font-size: 9px; color: #fff }
    .vpick .vp-opt.sel .vp-check { background: var(--accent); border-color: var(--accent) }
    .vpick .vp-opt.sel .vp-check::after { content: "\2713" }
    .vpick .vp-st { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #e6f6ea; color: #15803d }
    .vpick .vp-st[data-s=blocked], .vpick .vp-st[data-s=negotiation] { background: #fff1e0; color: var(--warn) }
    .vpick .vp-st[data-s=booked], .vpick .vp-st[data-s=sold] { background: #fde8e6; color: var(--danger) }
    .vpick .vp-empty { padding: 10px; font-size: 12px; color: var(--muted); text-align: center }

    .stage-section {
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 10px 14px;
      margin-bottom: 10px;
      transition: opacity .15s ease
    }

    .stage-section .ss-head {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 700;
      font-size: 13px;
      margin-bottom: 12px;
      color: var(--ink)
    }

    .stage-section .ss-lock-badge {
      display: none;
      margin-left: auto;
      align-items: center;
      gap: 5px;
      font-size: 11px;
      font-weight: 600;
      color: var(--warn);
      background: rgba(180, 83, 9, .08);
      padding: 3px 9px;
      border-radius: 20px
    }

    .stage-section.ss-locked {
      background: #f7f7f8;
      opacity: .6
    }

    .stage-section.ss-hidden {
      display: none
    }

    .stage-section.ss-locked .ss-lock-badge {
      display: inline-flex
    }

    .stage-section.ss-current {
      border-color: var(--line)
    }

    .stage-section.ss-done .ss-head i.ss-status {
      color: #1a7f37
    }

    /* ---- Attempt log (Contact Attempted stage) ---- */
    .attempt-log {
      border-top: 1px dashed var(--line);
      margin-top: 16px;
      padding-top: 14px
    }

    .attempt-log .al-title {
      font-size: 11px;
      font-weight: 800;
      letter-spacing: .3px;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      gap: 6px
    }

    .attempt-log .al-add {
      display: flex;
      gap: 8px;
      align-items: flex-start;
      margin-bottom: 12px
    }

    .attempt-log .al-add textarea {
      flex: 1;
      min-height: 44px
    }

    .attempt-log-list {
      max-height: 220px;
      overflow-y: auto
    }

    .attempt-log-list.compact {
      max-height: 130px
    }

    .attempt-log.compact .attempt-log-item {
      padding: 7px 2px
    }

    .attempt-log-item {
      display: flex;
      gap: 10px;
      padding: 10px 2px;
      border-bottom: 1px solid #f1f3f2;
      align-items: flex-start
    }

    .attempt-log-item:last-child {
      border-bottom: none
    }

    .attempt-log-item .ali-ico {
      width: 26px;
      height: 26px;
      border-radius: 50%;
      background: rgba(31, 92, 69, .08);
      color: var(--accent);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      flex: none
    }

    .attempt-log-item .ali-body {
      flex: 1;
      min-width: 0
    }

    .attempt-log-item .ali-note {
      font-size: 13px;
      color: var(--ink);
      word-break: break-word
    }

    .attempt-log-item .ali-meta {
      font-size: 10.5px;
      font-weight: 700;
      color: var(--muted);
      margin-top: 3px;
      letter-spacing: .2px
    }

    .attempt-log-empty {
      padding: 12px 2px;
      text-align: center;
      color: var(--muted);
      font-size: 12.5px
    }

    .attempt-log.compact {
      margin-top: 10px;
      padding-top: 10px
    }

    /* ---- Lead Summary overlay ---- */
    /* Fixed-height frame: never taller than 92vh and no outer scrollbar — header, details,
       stage and footer always stay visible; only the Lead/Sales History list scrolls. */
    .ls-box {
      padding: 0;
      position: relative;
      max-height: 92vh;
      overflow: hidden;
      display: flex;
      flex-direction: column
    }

    #leadSummaryBody {
      display: flex;
      flex-direction: column;
      flex: 1 1 auto;
      min-height: 0
    }

    .ls-close {
      position: absolute;
      top: 16px;
      right: 16px;
      z-index: 2
    }

    .ls-header {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 16px 50px 12px 24px;
      border-bottom: 1px solid var(--line);
      flex: none
    }

    .ls-avatar {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      background: rgba(31, 92, 69, .08);
      color: var(--accent);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 19px;
      flex: none
    }

    .ls-name {
      font-size: 18px;
      font-weight: 800;
      color: var(--ink);
      margin: 0;
      line-height: 1.25
    }

    .ls-sub {
      font-size: 12.5px;
      color: var(--muted);
      margin-top: 2px;
      letter-spacing: .2px
    }

    .ls-body {
      padding: 14px 24px 14px;
      display: flex;
      flex-direction: column;
      flex: 1 1 auto;
      min-height: 0;
      overflow: hidden
    }

    .ls-body > * {
      flex: none
    }

    .ls-list {
      display: flex;
      flex-wrap: wrap;
      gap: 10px 28px;
      background: #f8faf9;
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 10px 14px;
      margin-bottom: 10px
    }

    .ls-list .lsum-item {
      min-width: 120px
    }

    .ls-list .lsum-item label {
      display: block;
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .3px;
      color: var(--muted);
      margin-bottom: 3px
    }

    .ls-list .lsum-item div {
      font-size: 13.5px;
      color: #1b2521;
      font-weight: 700
    }

    /* Lead details: four to a row — contact · source & owner · requirement (Purpose, Configuration,
       Budget, Added By show here; Manage Leads leaves them out of its table on laptops) */
    .ls-list.ls-facts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px 18px }
    .ls-facts .lsum-item { min-width: 0 }
    .ls-facts .lsum-item.lsum-wide { grid-column: span 2 }
    .ls-facts .lsum-item > div { overflow-wrap: anywhere }
    .ls-facts .lsum-item small { display: block; font-size: 11px; font-weight: 600; color: var(--muted); margin-top: 1px }
    @media (max-width: 640px) { .ls-list.ls-facts { grid-template-columns: repeat(2, minmax(0, 1fr)) } }

    .ls-stagebar {
      display: flex;
      align-items: center;
      gap: 18px;
      padding: 10px 14px;
      border: 1px solid var(--line);
      border-radius: 12px;
      margin-bottom: 10px;
      background: #fff
    }

    .ls-stagebar .ls-stage-col {
      flex: 1
    }

    .ls-stagebar .lbl {
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .3px;
      color: var(--muted);
      margin-bottom: 5px
    }

    .ls-stage-arrow {
      color: var(--muted);
      font-size: 16px;
      flex: none
    }

    .ls-history-title {
      font-size: 11px;
      font-weight: 800;
      letter-spacing: .3px;
      text-transform: uppercase;
      color: var(--muted);
      margin: 4px 0 6px;
      display: flex;
      align-items: center;
      gap: 6px
    }

    /* Admin / IT lead summary: one card per desk (Sales / Legal / Accounts) — same border as the other summary
       cards; the heading takes the history colours below. 4-column grid so every card lines up. Transfer info sits in the header. */
    .ls-desk { background: #f8faf9; border: 1px solid var(--line); border-radius: 10px; padding: 10px 14px; margin-bottom: 10px }
    .ls-desk-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 7px; font-size: 10.5px; font-weight: 800; letter-spacing: .3px; text-transform: uppercase }
    .ls-desk-head span { display: inline-flex; align-items: center; gap: 6px }
    .ls-desk-head em { font-style: normal; font-size: 11px; font-weight: 600; letter-spacing: 0; text-transform: none; color: var(--muted); text-align: right }
    .ls-desk-sales .ls-desk-head span { color: #05357c }
    .ls-desk-legal .ls-desk-head span { color: #6d28d9 }
    .ls-desk-accounts .ls-desk-head span { color: #0e7490 }
    .ls-desk-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 9px 18px }
    .ls-desk-grid .lsum-item label { display: block; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .3px; color: var(--muted); margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
    .ls-desk-grid .lsum-item > div { font-size: 13px; color: #1b2521; font-weight: 700; overflow-wrap: anywhere }
    .ls-desk-grid .lsum-item small { display: block; font-size: 11px; font-weight: 600; color: var(--muted); margin-top: 1px }
    @media (max-width: 640px) { .ls-desk-grid { grid-template-columns: repeat(2, minmax(0, 1fr)) } }

    /* Admin / IT full history: Sales / Legal / Accounts sub-headings (renderHistorySections grouped) */
    .ls-hist-phase { position: sticky; top: 0; z-index: 1; display: flex; align-items: center; gap: 6px; margin: 6px 0 2px; padding: 6px 10px;
      border-radius: 8px; font-size: 11px; font-weight: 800; letter-spacing: .3px; text-transform: uppercase; background: #eef2f6; color: #475467 }
    .ls-hist-phase span { margin-left: auto; font-size: 10.5px; font-weight: 700; background: #fff; border-radius: 999px; padding: 1px 8px }
    .ls-hist-phase.ls-hist-sales { background: #eaf1ff; color: #05357c }
    .ls-hist-phase.ls-hist-legal { background: #f3edff; color: #6d28d9 }
    .ls-hist-phase.ls-hist-accounts { background: #e3f5f8; color: #0e7490 }

    .ls-body > .ls-history-list {
      flex: 1 1 auto;
      min-height: 90px;
      max-height: none; /* override .attempt-log-list's 220px cap — the frame decides the height */
      overflow-y: auto;
      border-top: 1px dashed var(--line);
      padding-top: 4px;
      margin-bottom: 12px
    }

    .ls-footer {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      border-top: 1px solid var(--line);
      padding-top: 12px
    }

    .attempt-log.compact .field {
      margin-bottom: 6px
    }

    .wa-bypass-toggle {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      color: var(--muted);
      margin-bottom: 12px;
      cursor: pointer;
      max-width: 100%
    }

    .wa-bypass-toggle input[type="checkbox"] {
      width: 16px;
      height: 16px;
      flex: none;
      margin: 0
    }

    .wa-row {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap
    }

    .wa-row select {
      width: auto;
      min-width: 130px
    }

    .wa-row .wa-hint {
      font-size: 11px;
      color: var(--muted)
    }

    /* ---- Sales / Post Sales phase switch (Edit Client) ---- */
    .phase-switch { display: inline-flex; gap: 4px; padding: 4px; background: #f2f4f7; border-radius: 10px; margin: 0 0 10px }
    .phase-switch button { border: 0; background: transparent; padding: 7px 16px; border-radius: 8px; font-size: 12.5px; font-weight: 700; color: var(--muted); cursor: pointer; display: inline-flex; align-items: center; gap: 6px }
    .phase-switch button.active { background: #fff; color: var(--accent); box-shadow: 0 1px 3px rgba(16, 24, 40, .12) }
    /* Construction & Payment inner tabs */
    .pay-subtabs { display: flex; width: max-content; max-width: 100%; margin: 0 0 12px }
    .pay-sub-n:empty { display: none }
    .pay-sub-n { font-size: 10px; font-weight: 800; background: #176b48; color: #fff; border-radius: 999px; padding: 0 6px; line-height: 16px }
    .tabs .tab.phase-hidden { display: none }

    /* ---- Unit Selection: final villa(s) + per-villa offered price ---- */
    .fv-list { border: 1px solid #d5d9dd; border-radius: 7px; background: #fff; padding: 4px 10px; min-height: 38px }
    .fv-list.disabled { background: #f4f5f6 }
    .fv-row { display: flex; align-items: center; gap: 10px; padding: 6px 0; border-bottom: 1px dashed var(--line) }
    .fv-row:last-child { border-bottom: 0 }
    .fv-row label { display: flex; align-items: center; gap: 8px; flex: 1 1 auto; margin: 0; font-size: 13px; font-weight: 600; color: var(--ink); cursor: pointer }
    .fv-row label input { width: auto; margin: 0 }
    .fv-row .fv-price { flex: 0 0 220px; max-width: 45% }
    .fv-row .fv-price[disabled] { visibility: hidden }
    .fv-empty { font-size: 12px; color: var(--muted); padding: 8px 0 }
    .fv-count { font-size: 11px; color: var(--accent); font-weight: 700; margin-top: 6px }

    /* ---- Unit Blocked: Complete Sales & Transfer to Post Sales ---- */
    .handover-box:empty { display: none }
    .handover-box { margin-top: 6px; padding: 12px 14px; border-radius: 10px; border: 1px dashed #bfe6d2; background: #f3faf6; display: flex; align-items: center; gap: 14px; flex-wrap: wrap }
    .handover-box .ho-text { flex: 1 1 260px; font-size: 12px; color: var(--muted) }
    .handover-box .ho-text b { display: block; font-size: 13px; color: var(--ink); margin-bottom: 2px }
    .handover-box.ho-done { border-style: solid; background: #e7f4ed }
    .handover-box.ho-done .ho-text b { color: #176b48 }
    .btn.ho-btn { background: #176b48; border-color: #176b48; color: #fff; font-weight: 700 }
    .btn.ho-btn:hover { background: #125a3c }
    .btn.ho-btn[disabled] { opacity: .5; cursor: not-allowed }
    #markCancelledBtn[disabled] { opacity: .45; cursor: not-allowed }
    .liquid-cur-label .ho-tag { font-size: 11px; font-weight: 700; color: #176b48; background: #e7f4ed; border-radius: 999px; padding: 3px 10px }

    /* ---- Salesperson read-only Post Sales summary ---- */
    .ps-summary { border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px }
    .ps-summary .ps-head { font-weight: 700; font-size: 13px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px }
    .ps-summary .ps-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px }
    .ps-summary .ps-item label { display: block; font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .3px; margin-bottom: 4px }
    .ps-summary .ps-item div { font-size: 14px; font-weight: 600; color: var(--ink) }
    .ps-summary .ps-note { font-size: 11px; color: var(--muted); margin-top: 12px }
    .ps-pill { display: inline-block; font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 999px; background: #f2f4f7; color: #475467 }
    .ps-pill.ps-green { background: #e6f6ea; color: #15803d }
    .ps-pill.ps-blue { background: #eaf1ff; color: #175cd3 }
    .ps-pill.ps-red { background: #fdecec; color: #b42318 }
    /* Admin / IT dashboard: Sales | Legal | Accounts switch (same look as Edit Client's phase switch) */
    .dash-desk-switch { margin: 0 0 16px }
    .dash-desk-switch .ds-count { font-size: 11px; font-weight: 800; min-width: 20px; padding: 1px 7px; border-radius: 999px; background: #e4e7ec; color: #475467; text-align: center }
    .dash-desk-switch button.active .ds-count { background: #e7f4ed; color: var(--accent) }
    /* Team Overview: one-line desk status under each desk heading */
    .desk-sum { display: flex; flex-wrap: wrap; gap: 6px; margin: -6px 0 14px }
    .desk-sum .ps-pill { font-size: 11.5px; cursor: pointer }
    .desk-sum .ps-pill b { font-weight: 800; margin-left: 3px }
    .desk-sum .ps-pill.np { cursor: default }

    /* ---- Booking & Legal documents ---- */
    .legal-docs .ld-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px }
    .legal-docs .ld-top .al-title { margin: 0 }
    .legal-docs .ld-count { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 999px; background: #fff1e0; color: var(--warn) }
    .legal-docs .ld-count.ok { background: #e6f6ea; color: #15803d }
    .legal-docs .ld-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; margin-bottom: 6px; flex-wrap: wrap }
    .legal-docs .ld-row.ld-missing { border-style: dashed }
    .legal-docs .ld-name { font-size: 12.5px; font-weight: 600; color: var(--ink); min-width: 210px; flex: 1; display: flex; align-items: center; gap: 6px }
    .legal-docs .ld-req { font-size: 10px; font-weight: 700; color: var(--danger); background: #fde8e6; padding: 1px 7px; border-radius: 999px }
    .legal-docs .ld-opt { font-size: 10px; font-weight: 600; color: var(--muted); background: #f2f4f7; padding: 1px 7px; border-radius: 999px }
    .legal-docs .ld-files { display: flex; flex-wrap: wrap; gap: 6px; flex: 2 }
    .legal-docs .ld-file { display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px; background: #f5f7f6; border: 1px solid var(--line); border-radius: 6px; padding: 3px 8px; max-width: 260px }
    .legal-docs .ld-file a { color: var(--accent); text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
    .legal-docs .ld-file button { border: 0; background: none; cursor: pointer; color: var(--muted); padding: 0 2px }
    .legal-docs .ld-file button:hover { color: var(--danger) }
    .legal-docs .ld-none { font-size: 11.5px; color: var(--muted) }
    .legal-docs .ld-hint { font-size: 11px; color: var(--warn); margin-top: 6px }
    /* Construction & Payment documents: one folder per construction stage (payDocsStripHtml in app.js) */
    .legal-docs .ld-stage-name { text-transform: none; letter-spacing: 0; color: var(--ink) }
    .legal-docs .ld-stages { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 10px }
    .legal-docs .ld-stg { border: 1px solid var(--line); background: #fff; border-radius: 999px; padding: 4px 11px; font-size: 11.5px; font-weight: 600; color: #475467; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 5px }
    .legal-docs .ld-stg:hover { border-color: #9ed8b2 }
    .legal-docs .ld-stg small { font-weight: 500; color: var(--muted) }
    .legal-docs .ld-stg.on { background: #e9f6ef; border-color: #176b48; color: #176b48 }
    .legal-docs .ld-stg-n { font-size: 10px; font-weight: 800; background: #176b48; color: #fff; border-radius: 999px; padding: 0 6px; line-height: 16px }

    .modal-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 15px
    }

    .modal-head h2 {
      margin: 0;
      font-size: 20px
    }

    .close {
      border: 0;
      background: #f1f3f4;
      border-radius: 50%;
      width: 32px;
      height: 32px;
      cursor: pointer
    }

    .tabs {
      display: flex;
      gap: 5px;
      border-bottom: 1px solid var(--line);
      margin-bottom: 18px;
      overflow: auto
    }

    .tab {
      border: 0;
      background: transparent;
      padding: 10px 13px;
      cursor: pointer;
      color: var(--muted);
      white-space: nowrap
    }

    .tab.active {
      color: var(--accent);
      font-weight: 700;
      border-bottom: 2px solid var(--accent)
    }

    .userbar {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 14px 12px 6px;
      margin-top: auto;
      border-top: 1px solid rgba(255, 255, 255, .08);
      font-size: 12px;
      color: #cbd5d0
    }

    .userbar button {
      background: rgba(255, 255, 255, .04);
      border: 1px solid rgba(255, 255, 255, .14);
      color: #e6efe9;
      border-radius: 20px;
      padding: 7px 14px;
      cursor: pointer;
      font-size: 11px;
      font-weight: 600;
      letter-spacing: .3px;
      transition: background .15s, border-color .15s;
    }

    .userbar button:hover {
      background: rgba(180, 35, 24, .25);
      border-color: rgba(180, 35, 24, .5);
    }

    .avatar-wrap {
      position: relative;
      width: 40px;
      height: 40px;
      flex-shrink: 0;
      border-radius: 50%;
      cursor: pointer;
      overflow: hidden;
      background: rgba(255, 255, 255, .08);
      border: 1px solid rgba(255, 255, 255, .18);
    }

    .avatar-wrap img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .avatar-wrap #avatarIcon {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 24px;
      color: #cbd5d0;
    }

    /* ---- Photo viewer (WhatsApp-style full view of your own photo) ---- */
    .photo-viewer {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .8);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 999;
    }

    .photo-viewer.hidden {
      display: none;
    }

    .viewer-inner {
      position: relative;
      width: 320px;
      height: 320px;
    }

    .viewer-inner img {
      width: 100%;
      height: 100%;
      border-radius: 50%;
      object-fit: cover;
      box-shadow: 0 20px 60px rgba(0, 0, 0, .5);
    }

    .viewer-edit {
      position: absolute;
      right: 8px;
      bottom: 8px;
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: var(--accent);
      color: #fff;
      border: 3px solid #fff;
      cursor: pointer;
      font-size: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 12px rgba(0, 0, 0, .3);
    }

    /* ---- Crop / adjust-photo modal (matches app's light modal design) ---- */
    .crop-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .8);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 999;
      padding: 20px;
    }

    .crop-overlay.hidden {
      display: none;
    }

    .crop-box {
      background: linear-gradient(160deg, #ffffff 0%, #f6f8f6 100%);
      border: 1px solid rgba(0, 0, 0, .05);
      border-radius: 20px;
      padding: 24px;
      text-align: center;
      width: min(380px, 100%);
      box-shadow: 0 25px 70px rgba(0, 0, 0, .35), 0 2px 0 rgba(255, 255, 255, .6) inset;
    }

    .crop-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 18px;
      padding-bottom: 14px;
      border-bottom: 1px solid var(--line);
    }

    .crop-head h3 {
      margin: 0;
      font-size: 16px;
      font-weight: 700;
      color: var(--accent2);
    }

    .crop-toolbar {
      display: flex;
      gap: 8px;
    }

    .crop-toolbar button {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      border: 1px solid var(--line);
      background: #fff;
      cursor: pointer;
      color: var(--accent2);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0;
      box-shadow: 0 1px 3px rgba(16, 24, 20, .08);
      transition: box-shadow .15s, transform .15s, background .15s;
    }

    .crop-toolbar button:hover {
      background: #f1f3f4;
      box-shadow: 0 3px 8px rgba(16, 24, 20, .14);
      transform: translateY(-1px);
    }

    .crop-toolbar .crop-delete {
      color: var(--danger);
      background: #fff;
      border-color: var(--line);
    }

    .crop-toolbar .crop-delete:hover {
      background: #f1f3f4;
    }

    .crop-toolbar .crop-delete.hidden {
      display: none;
    }

    .crop-square {
      width: 280px;
      height: 280px;
      border-radius: 18px;
      overflow: hidden;
      background: #16191c;
      margin: 0 auto;
      cursor: grab;
      position: relative;
      box-shadow: 0 0 0 1px rgba(0, 0, 0, .06) inset;
    }

    .crop-square:active {
      cursor: grabbing;
    }

    .crop-square img {
      position: absolute;
      top: 0;
      left: 0;
      user-select: none;
      -webkit-user-drag: none;
      pointer-events: none;
    }

    .crop-mask {
      position: absolute;
      top: 50%;
      left: 50%;
      width: 260px;
      height: 260px;
      transform: translate(-50%, -50%);
      border-radius: 50%;
      box-shadow: 0 0 0 9999px rgba(0, 0, 0, .55);
      pointer-events: none;
    }

    .crop-controls {
      margin-top: 16px;
      display: flex;
      align-items: center;
      gap: 10px;
      color: var(--muted);
      font-size: 12px;
    }

    .crop-controls input[type="range"] {
      flex: 1;
      accent-color: var(--brand-green);
    }

    .crop-actions {
      margin-top: 18px;
      display: flex;
      gap: 10px;
      justify-content: center;
    }

    .crop-actions .btn {
      width: auto;
    }

    @media(max-width:1000px) {
      .cards {
        grid-template-columns: repeat(2, 1fr)
      }

      .grid,
      .grid-3,
      .grid-2 {
        grid-template-columns: repeat(2, 1fr)
      }
    }

    @media(max-width:700px) {
      .sidebar {
        width: 68px
      }

      .brand-wrap {
        padding: 14px 4px 20px;
      }

      .brand-logos {
        gap: 6px;
      }

      .brand-logo {
        max-height: 28px;
      }

      .brand-divider {
        height: 22px;
      }

      .brand-sub {
        font-size: 0;
      }

      .nav span {
        display: none
      }

      .main {
        margin-left: 68px;
        width: calc(100% - 68px);
        padding: 15px
      }

      .cards {
        grid-template-columns: 1fr
      }

      .grid,
      .grid-3,
      .grid-2 {
        grid-template-columns: 1fr
      }
    }

    /* ---- Accounts: Construction & Payment screen (renderPayTracker in app.js) ---- */
    .pay-tracker:empty { display: none }
    .pay-card { border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; margin: 0 0 12px; background: #fff }
    .pay-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 12px }
    .pay-cards > .pay-card { margin: 0 0 12px }
    .pay-two { display: grid; grid-template-columns: 1fr; gap: 16px }
    .pay-cards > *, .pay-two > * { min-width: 0 }
    @media (max-width: 900px) { .pay-cards { grid-template-columns: 1fr } }
    .pc-title { font-size: 11px; font-weight: 800; letter-spacing: .3px; text-transform: uppercase; color: var(--muted); display: flex; align-items: center; gap: 7px; margin-bottom: 10px }
    .pc-title > i { color: #176b48 }
    .pc-title .pay-pill { text-transform: none; letter-spacing: 0; margin-left: auto }
    .pc-cur { font-size: 15px; color: var(--ink); margin-bottom: 12px }
    .pc-amt { font-size: 14px; margin-bottom: 4px }
    .pc-amt b { font-size: 22px; color: var(--ink) }
    .pc-actions { margin: 12px 0 6px }
    /* Bottom note (WhatsApp app / Web) — see crmToast() in app.js */
    .crm-toast { position: fixed; left: 50%; bottom: 22px; transform: translate(-50%, 20px); opacity: 0; pointer-events: none; z-index: 10050;
      display: flex; align-items: center; gap: 10px; max-width: min(640px, calc(100vw - 32px)); background: #1b2521; color: #fff;
      padding: 10px 12px 10px 16px; border-radius: 12px; box-shadow: 0 12px 32px rgba(0, 0, 0, .25); font-size: 13px; transition: opacity .2s, transform .2s }
    .crm-toast.show { opacity: 1; transform: translate(-50%, 0); pointer-events: auto }
    .crm-toast > i { color: #25d366; font-size: 18px; flex: none }
    .crm-toast span { flex: 1; line-height: 1.4 }
    .crm-toast .btn { flex: none; white-space: nowrap }
    .crm-toast-x { flex: none; background: none; border: 0; color: #c9d1cd; font-size: 18px; cursor: pointer; padding: 0 4px }
    .pay-dlog .attempt-log-list.compact { max-height: 190px } /* Demand Log: ~4 lines, then scrolls */
    .pay-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px }
    .pay-title { font-size: 13px; color: var(--muted); display: flex; align-items: center; gap: 8px; flex: 1 1 auto; flex-wrap: wrap }
    .pay-title > i { color: #176b48 }
    .pay-title b { font-size: 17px; color: var(--ink) }
    .pay-muted { color: var(--muted); font-size: 12px; font-weight: 500 }
    .pay-bar { display: flex; height: 16px; border-radius: 999px; overflow: hidden; background: #eceff1 }
    .pay-bar .pb-seg { height: 100%; display: flex; align-items: center; justify-content: center; font-size: 10.5px; font-weight: 800; color: #fff; white-space: nowrap; overflow: hidden }
    .pay-bar .pb-rec { background: #16a34a }
    .pay-bar .pb-od { background: #dc2626 }
    .pay-bar .pb-due { background: #f59e0b }
    /* Possession tab: colour key under the bar (percentages only) */
    .pp-keys { display: flex; flex-wrap: wrap; gap: 6px 16px; margin-top: 8px; font-size: 11.5px; font-weight: 700; color: #475467 }
    .pp-key { display: inline-flex; align-items: center; gap: 6px }
    .pp-key i { width: 9px; height: 9px; border-radius: 50% }
    .pp-key i.pb-rec { background: #16a34a } .pp-key i.pb-od { background: #dc2626 } .pp-key i.pb-due { background: #f59e0b } .pp-key i.pb-up { background: #d0d5dd }
    .pay-tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 12px }
    .pt-tile { border-radius: 10px; padding: 9px 12px; background: #f8faf9; border: 1px solid #d8dde3 }
    .pt-tile span { display: block; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .3px; color: var(--muted) }
    .pt-tile b { display: block; font-size: 17px; color: var(--ink) }
    .pt-tile small { font-size: 11.5px; font-weight: 600; color: #475467 }
    .pt-tile.t-rec { border-color: #9ed8b2 }
    .pt-tile.t-due { border-color: #f3c677 }
    .pt-tile.t-od { border-color: #f1a3a3; background: #fef2f2 }
    .pt-tile.t-od b { color: #b42318 }
    @media (max-width: 720px) { .pay-tiles { grid-template-columns: 1fr } }
    .pay-status-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 10px }
    .pay-status-grid .field:first-child { grid-column: 1 / -1 }
    .pay-preview { font-size: 12.5px; background: #f6fbf8; border: 1px dashed #cfe7da; border-radius: 8px; padding: 8px 10px; margin: 0 0 10px; line-height: 1.5 }
    .pay-preview .pay-total { font-size: 14px; color: #176b48 }
    .pay-warn { font-size: 12.5px; color: var(--warn); background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; padding: 8px 10px }
    .pay-tw { overflow-x: auto }
    .pay-table { width: 100%; border-collapse: collapse; font-size: 12.5px }
    .pay-table th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .3px; color: var(--muted); padding: 6px 8px; border-bottom: 1px solid var(--line); background: #fafbfa; white-space: nowrap }
    .pay-table td { padding: 7px 8px; border-bottom: 1px solid #f0f2f1; vertical-align: middle }
    .pay-table .num { text-align: right; white-space: nowrap }
    .pay-table tr.pt-cur td { background: #f3faf6 }
    .pay-table tfoot td { font-weight: 800; border-top: 1px solid var(--line); border-bottom: 0 }
    .pay-table small { color: var(--muted); font-size: 11px }
    .pay-pill { display: inline-block; font-size: 10.5px; font-weight: 800; padding: 2px 8px; border-radius: 999px; white-space: nowrap; background: #f2f4f7; color: #667085 }
    .pay-pill.pp-paid { background: #dcfce7; color: #166534 }
    .pay-pill.pp-part { background: #e0ecff; color: #1d4ed8 }
    .pay-pill.pp-due { background: #fef3c7; color: #92400e }
    .pay-pill.pp-od { background: #fee2e2; color: #991b1b }
    .pay-act { display: flex; gap: 4px; justify-content: flex-end }
    .pay-act a, .pay-act button { border: 1px solid var(--line); background: #fff; border-radius: 6px; padding: 3px 7px; cursor: pointer; color: var(--muted); font-size: 11px; text-decoration: none; line-height: 1.4 }
    .pay-act a:hover { color: var(--accent) }
    .pay-act button:hover { color: var(--danger) }
    .pay-form { margin: 0 0 12px; border: 1px solid #cfe7da; background: #f6fbf8; border-radius: 12px; padding: 12px 14px }
    .pay-form h4 { margin: 0 0 10px; font-size: 13px; display: flex; align-items: center; gap: 7px }
    .pay-form .grid { grid-template-columns: repeat(5, 1fr) }
    @media (max-width: 900px) { .pay-form .grid { grid-template-columns: 1fr 1fr } }
    .pay-form .pf-note { font-size: 12px; background: #fff; border: 1px dashed #cfe7da; border-radius: 8px; padding: 8px 10px; margin: 0 0 10px }
    .pay-form .pf-note:empty { display: none }
    .pay-form .pf-actions { display: flex; gap: 8px; justify-content: flex-end }
    .pay-form .field label small { font-weight: 500; color: var(--muted) }
    .pay-form input[type=file] { padding: 6px }
    .pay-card a.btn { text-decoration: none; color: inherit }
    .pay-sec-note { font-size: 11.5px; color: var(--muted); margin: -4px 0 10px }
    .pay-demand:empty { display: none }
    .pay-demand { border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; margin: 4px 0 10px; background: #fcfdfc }
    .pd-hint { font-size: 11.5px; color: var(--muted); margin: 2px 0 0 }
    .pd-ok { font-size: 12.5px; color: #166534; font-weight: 700 }
    .pd-due-now { font-size: 12.5px; font-weight: 800; color: var(--warn) }
    .pd-due-now.od { color: #b42318 }
    .pay-link { background: none; border: 0; color: var(--muted); font-size: 11px; text-decoration: underline; cursor: pointer; padding: 0; margin-left: 8px }
    .pay-link:hover { color: var(--danger) }
    .pay-mini { display: inline-flex; align-items: center; gap: 7px; min-width: 120px }
    .pay-mini .pm-bar { flex: 1; min-width: 60px; height: 7px; border-radius: 999px; background: #eceff1; overflow: hidden; display: flex }
    .pay-mini .pm-bar i { display: block; height: 100% }
    .pay-mini .pm-txt { font-size: 11px; font-weight: 800; color: var(--ink); min-width: 44px; text-align: right }
  </style>
  <!-- Laptop / tablet / phone layouts (after the desktop styles above, so it only adapts them) -->
  <link rel="stylesheet" href="assets/css/responsive.css">
</head>

<body>
  <!-- Phone / tablet app bar, menu backdrop and phone bottom navigation — hidden on laptop and
       desktop (assets/css/responsive.css); menu + bottom nav behaviour in assets/js/responsive.js -->
  <header class="rs-appbar" id="rsAppbar">
    <button type="button" class="rs-icon-btn" id="rsMenuBtn" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
    <div class="rs-brand">
      <img src="image/Akruti White Background Logo.svg" alt="Akruti">
      <span class="rs-brand-div"></span>
      <img src="image/Antaaya Villa Lonavala Logo.png" alt="Antaaya Villas">
    </div>
    <div class="rs-appbar-end" id="rsAppbarEnd">
      <!-- The notification bell is moved in here, just before the profile photo (responsive.js).
           Profile photo = the same photo as the sidebar's (kept in sync by responsive.js); tapping it shows
           who is logged in, the photo option and Logout — the sidebar's user bar, which is hidden here. -->
      <div class="rs-me" id="rsMe">
        <button type="button" class="rs-me-btn" id="rsMeBtn" aria-label="My profile" aria-haspopup="true" aria-expanded="false" aria-controls="rsMePanel">
          <?php if ($currentPhoto): ?><img src="<?= htmlspecialchars($currentPhoto) ?>" alt=""><?php else: ?><i class="fa-solid fa-circle-user"></i><?php endif; ?>
        </button>
        <div class="rs-me-panel" id="rsMePanel" hidden>
          <div class="rs-me-head">
            <span class="rs-me-pic"><?php if ($currentPhoto): ?><img src="<?= htmlspecialchars($currentPhoto) ?>" alt=""><?php else: ?><i class="fa-solid fa-circle-user"></i><?php endif; ?></span>
            <div class="rs-me-who"><b><?= $currentName ?></b><span><?= $isPostSales ? $postSalesDeskLabel : ($currentRole === 'it' ? 'IT' : $currentRole) ?></span></div>
          </div>
          <button type="button" class="rs-me-act" data-act="photo"><i class="fa-solid fa-image"></i><span><?= $currentPhoto ? 'View / change photo' : 'Add profile photo' ?></span></button>
          <button type="button" class="rs-me-act rs-me-logout" data-act="logout"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></button>
        </div>
      </div>
    </div>
  </header>
  <div class="rs-scrim" id="rsScrim"></div>
  <nav class="rs-bottomnav" id="rsBottomNav" aria-label="Main menu"></nav>

  <div class="app">
    <aside class="sidebar">
      <div class="brand-wrap">
        <div class="brand-logos">
          <img src="image/Akruti White Background Logo.svg" alt="AVL Antaaya" class="brand-logo">
          <span class="brand-divider"></span>
          <img src="image/Antaaya Villa Lonavala Logo.png" alt="Sales CRM" class="brand-logo">
        </div>
        <small class="brand-sub"><i class="fa-solid fa-users-gear"></i> <?= $isPostSales ? 'Post Sales · ' . $postSalesDeskLabel : 'Sales CRM' ?></small>
      </div>
      <div class="nav">
        <button class="active" data-page="dashboard">
          <i class="fa-solid fa-chart-pie"></i>
          <span>Dashboard</span>
        </button>

        <button data-page="leads">
          <i class="fa-solid fa-users"></i>
          <span>Manage Leads</span>
        </button>

        <button data-page="sourceleads" id="navSourceLeads">
          <i class="fa-solid fa-globe"></i>
          <span>Source Leads</span>
        </button>

        <button data-page="visits">
          <i class="fa-solid fa-location-dot"></i>
          <span>Site Visits</span>
          <span class="nav-badge" id="visitBadge" style="display:none">0</span>
        </button>

        <button data-page="followups">
          <i class="fa-solid fa-rotate"></i>
          <span>Follow-ups</span>
          <span class="nav-badge" id="followupBadge" style="display:none">0</span>
        </button>

        <button data-page="bookings" id="navBookings">
          <i class="fa-solid fa-house-circle-check"></i>
          <span>Bookings</span>
        </button>

        <button data-page="villas" id="navVillas">
          <i class="fa-solid fa-city"></i>
          <span>Villa Inventory</span>
        </button>

        <button data-page="payments" id="navPayments">
          <i class="fa-solid fa-indian-rupee-sign"></i>
          <span>Payments</span>
        </button>

        <button data-page="possession" id="navPossession">
          <i class="fa-solid fa-key"></i>
          <span>Possession</span>
        </button>

        <button data-page="team" id="navTeam">
          <i class="fa-solid fa-user-group"></i>
          <span>Team Overview</span>
        </button>

        <button data-page="usermgmt" id="navUsers">
          <i class="fa-solid fa-user-gear"></i>
          <span>User Management</span>
        </button>

        <button data-page="notiflog" id="navNotifLog">
          <i class="fa-solid fa-bell"></i>
          <span>Notification Log</span>
        </button>
      </div>
      <div class="userbar">
        <div class="avatar-wrap" id="avatarWrap" title="View / update photo">
          <?php if ($currentPhoto): ?>
            <img src="<?= htmlspecialchars($currentPhoto) ?>" id="avatarImg" alt="Profile photo">
          <?php else: ?>
            <i class="fa-solid fa-circle-user" id="avatarIcon"></i>
          <?php endif; ?>
        </div>
        <input type="file" id="avatarInput" accept="image/png,image/jpeg,image/webp" hidden onchange="onAvatarFileSelected(this.files[0])">
        <div style="flex:1"><b><?= $currentName ?></b><br><span style="text-transform:capitalize"><?= $isPostSales ? $postSalesDeskLabel : ($currentRole === 'it' ? 'IT' : $currentRole) ?></span></div>
        <button onclick="logout()"><i class="fa-solid fa-right-from-bracket"></i> Logout </button>
      </div>
    </aside>

    <main class="main">
      <section id="dashboard" class="page">
        <div class="top">
          <div>
            <div class="greeting-line"><i id="greetingIcon" class="fa-solid fa-sun"></i><span id="greetingText">Good Morning</span></div>
            <h1 id="greetingName">there</h1>
            <p><?= $isAccountsDesk ? "Here's where every lead from the legal team stands in construction and payments."
              : ($isLegalDesk ? "Here's where every lead from the sales team stands in booking and legal."
              : ($isAdmin ? "Here's what's happening across Sales, Legal and Accounts today."
              : "Here's what's happening with your sales journey today.")) ?></p>
          </div>
          <div style="display:flex;gap:12px;align-items:center">
            <div class="notif-wrap">
              <button class="btn notif-btn" id="notifBtn" onclick="toggleNotifPanel()" title="Notifications">
                <i class="fa-solid fa-bell"></i>
                <span class="notif-count" id="notifCount" style="display:none">0</span>
              </button>
              <div class="notif-panel" id="notifPanel" style="display:none">
                <div class="notif-head">
                  <span><i class="fa-solid fa-bell"></i> Notifications</span>
                  <button onclick="clearAllNotifs()">Clear all</button>
                </div>
                <div class="notif-list" id="notifList"></div>
              </div>
            </div>
            <?php if (!$isPostSales): ?><button class="btn primary" onclick="openQuickAdd()"><i class="fa-solid fa-user-plus"></i> Add Lead</button><?php endif; ?>
          </div>
        </div>
        <div id="duplicateAlerts"></div>
        <?php if ($isLegalDesk): dash_legal_block(false);
        elseif ($isAccountsDesk): dash_accounts_block(false);
        elseif ($isAdmin): ?>
        <!-- Admin / IT: one dashboard per desk — Sales, Legal, Accounts (switchDashDesk() in app.js). Counts = leads with that desk now. -->
        <div class="phase-switch dash-desk-switch" id="dashDeskSwitch">
          <button type="button" data-desk="sales" title="Leads with the Sales team now" class="active" onclick="switchDashDesk('sales')"><i class="fa-solid fa-user-tie"></i> Sales <span class="ds-count" id="dsSales">0</span></button>
          <button type="button" data-desk="legal" title="Leads with the Legal desk now (booking pending → registered)" onclick="switchDashDesk('legal')"><i class="fa-solid fa-file-contract"></i> Legal <span class="ds-count" id="dsLegal">0</span></button>
          <button type="button" data-desk="accounts" title="Leads with the Accounts desk now" onclick="switchDashDesk('accounts')"><i class="fa-solid fa-calculator"></i> Accounts <span class="ds-count" id="dsAccounts">0</span></button>
        </div>
        <div class="dash-desk" data-desk="sales"><?php dash_sales_block(true); ?></div>
        <div class="dash-desk hidden" data-desk="legal"><?php dash_legal_block(true); ?></div>
        <div class="dash-desk hidden" data-desk="accounts"><?php dash_accounts_block(true); ?></div>
        <?php else: dash_sales_block(false); endif; ?>
      </section>

      <section id="leads" class="page hidden">
        <div class="top">
          <div>
            <h1><i class="fa-solid fa-users"></i> Manage Leads</h1>
            <p><?= $isAccountsDesk ? 'Leads transferred by the legal team — construction and payments (possession is handled by admin).'
              : ($isLegalDesk ? 'Leads transferred by sales — booking and legal, then handed to Accounts at Registered.'
              : "Every lead's ongoing follow-up, sales progress and possession status.") ?></p>
          </div><?php if (!$isPostSales): ?><button class="btn primary" onclick="openQuickAdd()"><i class="fa-solid fa-user-plus"></i> Add Lead</button><?php endif; ?>
        </div>
        <div class="panel">
          <div class="toolbar">
            <div class="search-box"><input id="leadSearch" placeholder="Search name, mobile, Lead ID or broker name..." oninput="renderLeads()"><i class="fa-solid fa-magnifying-glass"></i></div>
            <div class="toolbar-row">
              <select id="stageFilter" onchange="renderLeads()">
                <option value="">All Clients</option>
              </select>
              <?php if (!$isLegalDesk): /* Lead Type / Added By are sales filters — not on the Legal desk */ ?>
              <select id="interestFilter" onchange="renderLeads()">
                <option value="">Lead Type</option>
                <option>HOT</option>
                <option>WARM</option>
                <option>COLD</option>
              </select>
            </div>
            <div class="toolbar-row">
              <select id="addedByFilter" onchange="renderLeads()">
                <option value="">Added By (Anyone)</option>
              </select>
              <?php endif; ?>
              <select id="categoryFilter" onchange="renderLeads()">
                <option value="">Category (Any)</option>
                <option value="Broker">Broker</option>
                <option value="Broker Reference">Broker Reference</option>
                <option value="Owner">Owner</option>
              </select>
            </div>
          </div>
          <div class="table-wrap" id="leadsTableWrap">
            <table>
              <thead id="leadHead">
                <tr>
                  <th><i class="fa-solid fa-hashtag"></i> Lead ID</th>
                  <th><i class="fa-solid fa-user"></i> Client</th>
                  <th><i class="fa-solid fa-phone"></i> Mobile</th>
                  <th><i class="fa-solid fa-tags"></i> Category</th>
                  <th><i class="fa-solid fa-globe"></i> Source</th>
                  <th><i class="fa-solid fa-bullseye"></i> Purpose</th>
                  <th><i class="fa-solid fa-house"></i> Configuration</th>
                  <th><i class="fa-solid fa-indian-rupee-sign"></i> Budget</th>
                  <th><i class="fa-solid fa-layer-group"></i> Stage</th>
                  <th><i class="fa-solid fa-heart"></i> Interest</th>
                  <th><i class="fa-solid fa-calendar-days"></i> Next Follow-up</th>
                  <th><i class="fa-solid fa-user-tie"></i> Sales Person</th>
                  <th><i class="fa-solid fa-user-plus"></i> Added By</th>
                  <th><i class="fa-solid fa-gear"></i> Action</th>
                </tr>
              </thead>
              <tbody id="leadTable"></tbody>
            </table>
          </div>
        </div>
      </section>

      <section id="sourceleads" class="page hidden">
        <div class="top">
          <div>
            <h1><i class="fa-solid fa-globe"></i> Source Leads</h1>
            <p>Website &amp; social media form submissions, awaiting review before they enter the CRM.</p>
          </div>
        </div>
        <div class="panel">
          <div class="toolbar">
            <select id="sourceStatusFilter" onchange="renderSourceLeads()">
              <option value="New">New (Awaiting Review)</option>
              <option value="Assigned">Assigned</option>
              <option value="Rejected">Rejected</option>
              <option value="">All</option>
            </select>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><i class="fa-solid fa-inbox"></i> Received</th>
                  <th><i class="fa-solid fa-location-dot"></i> Site</th>
                  <th><i class="fa-solid fa-file-lines"></i> Form</th>
                  <th><i class="fa-solid fa-user"></i> Name</th>
                  <th><i class="fa-solid fa-phone"></i> Contact</th>
                  <th><i class="fa-solid fa-envelope"></i> Email</th>
                  <th><i class="fa-solid fa-tags"></i> Category</th>
                  <th><i class="fa-solid fa-note-sticky"></i> Notes</th>
                  <th><i class="fa-solid fa-circle-info"></i> Status</th>
                  <th><i class="fa-solid fa-gear"></i> Action</th>
                </tr>
              </thead>
              <tbody id="sourceLeadTable"></tbody>
            </table>
          </div>
        </div>
      </section>

      <section id="followups" class="page hidden">
        <div class="top">
          <div>
            <h1><i class="fa-solid fa-rotate"></i> Follow-ups</h1>
            <p>Never miss the next client action.</p>
          </div><?php if (!$isEntryDesk): ?><button class="btn primary" onclick="openFollowup()"><i class="fa-solid fa-calendar-plus"></i> Add Follow-up</button><?php endif; ?>
        </div>
        <div class="panel">
          <div class="toolbar">
            <div class="search-box"><input id="fuSearch" placeholder="Search client..." oninput="renderFollowups()"><i class="fa-solid fa-magnifying-glass"></i></div><div class="toolbar-row"><select id="fuStatus" onchange="renderFollowups()">
              <option value="">All Status</option>
              <option>Pending</option>
              <option>Completed</option>
            </select><select id="fuTypeFilter" onchange="renderFollowups()">
              <option value="">All Follow-up Types</option>
              <option value="After Scheduled Site Visit">Site Visit Follow-up</option>
              <option value="Post Site Visit Follow-up">Post Site Visit Follow-up</option>
              <option value="Re-Visit Follow-up">Re-Visit Follow-up</option>
              <option value="Negotiation Visit Follow-up">Negotiation Visit Follow-up</option>
              <option value="Villa Blocked Follow-up">Villa Blocked Follow-up</option>
            </select></div>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><i class="fa-solid fa-calendar"></i> Follow-up Date</th>
                  <th><i class="fa-solid fa-user"></i> Client</th>
                  <th><i class="fa-solid fa-tag"></i> Type</th>
                  <th><i class="fa-solid fa-comments"></i> Mode</th>
                  <th><i class="fa-solid fa-rotate"></i> Re-follow-up</th>
                  <th><i class="fa-solid fa-circle-info"></i> Status</th>
                  <th style="text-align:right"><i class="fa-solid fa-gear"></i> Action</th>
                </tr>
              </thead>
              <tbody id="fuTable"></tbody>
            </table>
          </div>
        </div>
      </section>

      <section id="visits" class="page hidden">
        <div class="top">
          <div>
            <h1><i class="fa-solid fa-location-dot"></i> Site Visits</h1>
            <p>Plan, track and record every site visit.</p>
          </div><?php if (!$isEntryDesk): ?><button class="btn primary" onclick="openVisit()"><i class="fa-solid fa-map-location-dot"></i> Schedule Site Visit</button><?php endif; ?>
        </div>
        <div class="panel">
          <div class="toolbar">
            <div class="search-box"><input id="visitSearch" placeholder="Search client or visit type..." oninput="renderVisits()"><i class="fa-solid fa-magnifying-glass"></i></div>
            <div class="toolbar-row">
              <select id="visitTypeFilter" onchange="renderVisits()">
                <option value="">All Visit Types</option>
                <option value="site">Site Visit</option>
                <option value="revisit">Re-Visit</option>
                <option value="negotiation">Negotiation Visit</option>
              </select>
              <select id="visitStatusFilter" onchange="renderVisits()">
                <option value="">All Status</option>
                <option>Scheduled</option>
                <option>Completed</option>
              </select>
              <input type="date" id="visitDateFilter" onchange="renderVisits()" title="Filter by visit date" style="flex:1;min-width:160px">
            </div>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><i class="fa-solid fa-user"></i> Client</th>
                  <th><i class="fa-solid fa-tag"></i> Visit Type</th>
                  <th><i class="fa-solid fa-calendar-day"></i> Visit Date</th>
                  <th><i class="fa-solid fa-clock"></i> Time</th>
                  <th><i class="fa-solid fa-user-group"></i> Visitors</th>
                  <th><i class="fa-solid fa-house-chimney"></i> Villa Shown</th>
                  <th><i class="fa-solid fa-circle-info"></i> Status</th>
                  <th><i class="fa-solid fa-flag-checkered"></i> Outcome</th>
                  <th style="text-align:right"><i class="fa-solid fa-check"></i> Action</th>
                </tr>
              </thead>
              <tbody id="visitTable"></tbody>
            </table>
          </div>
        </div>
      </section>

      <section id="bookings" class="page hidden">
        <div class="top">
          <div>
            <h1><i class="fa-solid fa-house-circle-check"></i> Bookings</h1>
            <p>Track confirmed sales and documentation.</p>
          </div>
        </div>
        <div class="panel">
          <div class="toolbar">
            <div class="search-box"><input id="bookingSearch" placeholder="Search by Lead ID or client name..." oninput="renderBookings()"><i class="fa-solid fa-magnifying-glass"></i></div>
            <div class="toolbar-row">
              <!-- Both filled by renderBookings() in app.js: every villa with a booking, and a status filter that depends on the desk -->
              <select id="bookingVillaFilter" onchange="renderBookings()">
                <option value="">Villa (All)</option>
              </select>
              <select id="bookingStatusFilter" onchange="renderBookings()">
                <option value="">Status (All)</option>
              </select>
            </div>
          </div>
          <div class="table-wrap">
            <table>
              <thead id="bookingHead"></thead><!-- columns set per role in renderBookings() -->
              <tbody id="bookingTable"></tbody>
            </table>
          </div>
        </div>
      </section>

      <section id="villas" class="page hidden">
        <div class="top">
          <div>
            <h1><i class="fa-solid fa-city"></i> Villa Inventory</h1>
            <p>Live availability across all villas — kept in sync with the public configurator.<?= $isPostSales ? ' View only — updates automatically as your leads move stage.' : '' ?></p>
          </div>
        </div>
        <div class="panel" style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap">
          <button class="btn" data-villafilter="all" onclick="filterVillas('all',this)">All (<span id="vCountAll">0</span>)</button>
          <button class="btn" data-villafilter="available" onclick="filterVillas('available',this)">Available (<span id="vCountAvailable">0</span>)</button>
          <button class="btn" data-villafilter="negotiation" onclick="filterVillas('negotiation',this)">Negotiation (<span id="vCountNegotiation">0</span>)</button>
          <button class="btn" data-villafilter="blocked" onclick="filterVillas('blocked',this)">Blocked (<span id="vCountBlocked">0</span>)</button>
          <button class="btn" data-villafilter="booked" onclick="filterVillas('booked',this)">Booked (<span id="vCountBooked">0</span>)</button>
          <button class="btn" data-villafilter="sold" onclick="filterVillas('sold',this)">Sold (<span id="vCountSold">0</span>)</button>
        </div>
        <div class="panel">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><i class="fa-solid fa-house-chimney"></i> Villa Name</th>
                  <th><i class="fa-solid fa-hashtag"></i> Serial</th>
                  <th><i class="fa-solid fa-layer-group"></i> Type</th>
                  <th><i class="fa-solid fa-circle-info"></i> Status</th>
                  <th><i class="fa-solid fa-user"></i> Linked Lead</th>
                  <th id="villaAdminCol"><i class="fa-solid fa-gear"></i> Update</th>
                </tr>
              </thead>
              <tbody id="villaTable"></tbody>
            </table>
          </div>
        </div>
      </section>


      <section id="payments" class="page hidden">
        <div class="top">
          <div>
            <h1><i class="fa-solid fa-indian-rupee-sign"></i> Payments</h1>
            <p>Construction-linked demands — what's received, what's due now and what's still to come for every villa.</p>
          </div>
        </div>
        <!-- Filled by renderPayments() in app.js from each lead's synced payment columns -->
        <div class="cards">
          <div class="card c-total">
            <div class="card-icon"><i class="fa-solid fa-sack-dollar"></i></div>
            <div class="label">TOTAL VILLA VALUE</div>
            <div class="num" id="payKTotal">₹0</div>
            <div class="sub" id="payKTotalSub">0 villas</div>
          </div>
          <div class="card c-bookings">
            <div class="card-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="label">RECEIVED</div>
            <div class="num" id="payKRec">₹0</div>
            <div class="sub" id="payKRecSub">0% of total</div>
          </div>
          <div class="card c-hot dash-link" onclick="setPayFilter('due')" title="Show clients with a payment due">
            <div class="card-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="label">DUE NOW</div>
            <div class="num" id="payKDue">₹0</div>
            <div class="sub" id="payKDueSub">Demanded, not yet paid</div>
          </div>
          <div class="card c-cancelled dash-link" onclick="setPayFilter('overdue')" title="Show overdue payments">
            <div class="card-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="label">OVERDUE</div>
            <div class="num" id="payKOd">₹0</div>
            <div class="sub" id="payKOdSub">Past the pay-by date</div>
          </div>
          <div class="card c-ps-acc">
            <div class="card-icon"><i class="fa-solid fa-calendar-plus"></i></div>
            <div class="label">PENDING (LATER STAGES)</div>
            <div class="num" id="payKUp">₹0</div>
            <div class="sub">Not demanded yet</div>
          </div>
        </div>
        <div class="panel">
          <div class="toolbar">
            <div class="search-box"><input id="paySearch" placeholder="Search lead — name, mobile, Lead ID, email or villa..." oninput="renderPayments()"><i class="fa-solid fa-magnifying-glass"></i></div>
            <div class="toolbar-row">
              <select id="payFilter" onchange="renderPayments()">
                <option value="">Payment Status (All)</option>
                <option value="due">Payment due</option>
                <option value="overdue">Overdue</option>
                <option value="paid">Fully paid</option>
                <option value="notdemanded">Nothing due now</option>
                <option value="noplan">No payment schedule yet</option>
              </select>
              <!-- Filled by populatePayVillaFilter() in app.js: every villa (NAME - SERIAL) with a lead in the payment process -->
              <select id="payVillaFilter" onchange="renderPayments()">
                <option value="">Villa (All)</option>
              </select>
            </div>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><i class="fa-solid fa-hashtag"></i> Lead ID</th>
                  <th><i class="fa-solid fa-user"></i> Client</th>
                  <th><i class="fa-solid fa-house-chimney"></i> Villa</th>
                  <th><i class="fa-solid fa-tag"></i> Villa Price</th>
                  <th><i class="fa-solid fa-circle-check"></i> Received</th>
                  <th><i class="fa-solid fa-hourglass-half"></i> Due Now</th>
                  <th><i class="fa-solid fa-calendar-days"></i> Pay By</th>
                  <th><i class="fa-solid fa-calendar-plus"></i> Pending (Later Stages)</th>
                  <th><i class="fa-solid fa-person-digging"></i> Construction Status</th>
                  <th><i class="fa-solid fa-chart-simple"></i> Paid</th>
                  <th><i class="fa-solid fa-gear"></i> Action</th>
                </tr>
              </thead>
              <tbody id="paymentTable"></tbody>
            </table>
          </div>
        </div>
      </section>

      <section id="possession" class="page hidden">
        <div class="top">
          <div>
            <h1><i class="fa-solid fa-key"></i> Possession &amp; Handover</h1>
            <p>Track the final customer journey.</p>
          </div>
        </div>
        <div class="panel">
          <div class="toolbar">
            <div class="search-box"><input id="possessionSearch" placeholder="Search by Lead ID, client name, mobile or villa..." oninput="renderPossession()"><i class="fa-solid fa-magnifying-glass"></i></div>
            <div class="toolbar-row">
              <!-- Villa: filled by renderPossession() in app.js — every villa in this list, serial order -->
              <select id="possessionVillaFilter" onchange="renderPossession()">
                <option value="">Villa (All)</option>
              </select>
              <select id="possessionStageFilter" onchange="renderPossession()">
                <option value="">Possession Stage (All)</option>
                <option value="construction">Under Construction</option>
                <option value="Possession Due">Possession Due</option>
                <option value="Possession Offered">Possession Offered</option>
                <option value="Handover Completed">Handover Completed</option>
              </select>
              <!-- Matches the table's status columns: Fit-out / Inspection / Snagging / Possession Letter / Keys -->
              <select id="possessionStatusFilter" onchange="renderPossession()">
                <option value="">Status (All)</option>
                <option value="fitout">Fit-out pending</option>
                <option value="inspection">Inspection pending</option>
                <option value="snagging">Snagging pending</option>
                <option value="posletter">Possession letter pending</option>
                <option value="keysno">Keys not handed over</option>
                <option value="keysyes">Keys handed over</option>
              </select>
            </div>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><i class="fa-solid fa-hashtag"></i> Lead ID</th>
                  <th><i class="fa-solid fa-user"></i> Client</th>
                  <th><i class="fa-solid fa-house-chimney"></i> Villa</th>
                  <th><i class="fa-solid fa-trowel-bricks"></i> Construction</th>
                  <th><i class="fa-solid fa-screwdriver-wrench"></i> Fit-out</th>
                  <th><i class="fa-solid fa-magnifying-glass"></i> Inspection</th>
                  <th><i class="fa-solid fa-list-check"></i> Snagging</th>
                  <th><i class="fa-solid fa-file-lines"></i> Possession Letter</th>
                  <th><i class="fa-solid fa-calendar-check"></i> Possession Date</th>
                  <th><i class="fa-solid fa-handshake"></i> Handover</th>
                  <th><i class="fa-solid fa-key"></i> Keys</th>
                  <th><i class="fa-solid fa-gear"></i> Action</th>
                </tr>
              </thead>
              <tbody id="possessionTable"></tbody>
            </table>
          </div>
        </div>
      </section>

      <section id="notiflog" class="page hidden">
        <div class="top">
          <div>
            <h1><i class="fa-solid fa-bell"></i> Notification Log</h1>
            <p>Full history of every notification — lead assignment/reassignment, Source Lead activity, transfers to Post Sales / Accounts and booking cancellations — never cleared, admin-only.</p>
          </div>
        </div>
        <div class="panel">
          <div class="toolbar-row" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
            <div class="field"><label>Type</label><select id="notifLogType" onchange="renderNotifLog()">
                <option value="">All Types</option>
                <option value="assigned">Lead Assigned</option>
                <option value="reassigned">Lead Reassigned</option>
                <option value="source_new">New Source Lead</option>
                <option value="source_assigned">Source Lead Assigned</option>
                <option value="source_rejected">Source Lead Rejected</option>
                <option value="reschedule_proposed">Client Proposed New Time</option>
                <option value="post_sales_transfer">Transferred to Post Sales</option>
                <option value="accounts_transfer">Transferred to Accounts</option>
                <option value="booking_cancelled">Booking Cancelled</option>
              </select></div>
            <div class="field"><label>Date</label><input type="date" id="notifLogDate" onchange="renderNotifLog()"></div>
            <div class="field"><label>Salesperson</label><select id="notifLogSalesperson" onchange="renderNotifLog()"><option value="">All</option></select></div>
            <div class="field" style="align-self:flex-end"><button type="button" class="btn" onclick="document.getElementById('notifLogType').value='';document.getElementById('notifLogDate').value='';document.getElementById('notifLogSalesperson').value='';renderNotifLog()">Clear filters</button></div>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><i class="fa-solid fa-clock"></i> Date/Time</th>
                  <th><i class="fa-solid fa-arrow-right-from-bracket"></i> From</th>
                  <th><i class="fa-solid fa-arrow-right-to-bracket"></i> To (Salesperson)</th>
                  <th><i class="fa-solid fa-id-card"></i> Lead</th>
                  <th><i class="fa-solid fa-tag"></i> Type</th>
                  <th><i class="fa-solid fa-trash"></i> Action</th>
                </tr>
              </thead>
              <tbody id="notifLogTable"></tbody>
            </table>
          </div>
        </div>
      </section>

      <section id="team" class="page hidden">
        <div class="top">
          <div>
            <h1><i class="fa-solid fa-user-group"></i> Team Overview</h1>
            <p>Workload and activity across every desk — Sales, Legal and Accounts. New users added in User Management show up here automatically.</p>
          </div>
        </div>
        <div class="cards" id="teamCards"></div>
        <!-- Each desk: a one-line status of its queue (click a pill to open that list), then each member's work.
             Legal / Accounts work isn't owned per lead, so it's counted from each lead's history (who did what). -->
        <div class="panel">
          <h3><i class="fa-solid fa-user-tie"></i> Sales Team</h3>
          <div class="desk-sum" id="teamSalesSum"></div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><i class="fa-solid fa-user-tie"></i> Salesperson</th>
                  <th><i class="fa-solid fa-briefcase"></i> Desk</th>
                  <th><i class="fa-solid fa-user-check"></i> Leads Assigned</th>
                  <th><i class="fa-solid fa-user-plus"></i> Leads They Added</th>
                  <th><i class="fa-solid fa-fire"></i> Hot Leads</th>
                  <th><i class="fa-solid fa-rotate"></i> Pending Follow-ups</th>
                  <th><i class="fa-solid fa-location-dot"></i> Site Visits</th>
                  <th><i class="fa-solid fa-right-left"></i> Sent to Legal</th>
                  <th><i class="fa-solid fa-house-circle-check"></i> Bookings</th>
                  <th><i class="fa-solid fa-circle-xmark"></i> Cancelled</th>
                </tr>
              </thead>
              <tbody id="teamTable"></tbody>
            </table>
          </div>
        </div>
        <div class="panel" style="margin-top:18px">
          <h3><i class="fa-solid fa-file-contract"></i> Legal Team</h3>
          <div class="desk-sum" id="teamLegalSum"></div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><i class="fa-solid fa-user-tie"></i> Team Member</th>
                  <th><i class="fa-solid fa-folder-open"></i> Leads Worked On</th>
                  <th><i class="fa-solid fa-file-invoice-dollar"></i> Booking Initiated</th>
                  <th><i class="fa-solid fa-circle-check"></i> Booking Confirmed</th>
                  <th><i class="fa-solid fa-stamp"></i> Registered</th>
                  <th><i class="fa-solid fa-right-left"></i> Sent to Accounts</th>
                  <th><i class="fa-solid fa-file-arrow-up"></i> Documents</th>
                  <th><i class="fa-solid fa-circle-xmark"></i> Cancelled</th>
                  <th><i class="fa-solid fa-clock"></i> Last Activity</th>
                </tr>
              </thead>
              <tbody id="teamLegalTable"></tbody>
            </table>
          </div>
        </div>
        <div class="panel" style="margin-top:18px">
          <h3><i class="fa-solid fa-calculator"></i> Accounts Team</h3>
          <div class="desk-sum" id="teamAccountsSum"></div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><i class="fa-solid fa-user-tie"></i> Team Member</th>
                  <th><i class="fa-solid fa-folder-open"></i> Leads Worked On</th>
                  <th><i class="fa-solid fa-person-digging"></i> Demands Raised</th>
                  <th><i class="fa-solid fa-paper-plane"></i> Demands / Reminders Sent</th>
                  <th><i class="fa-solid fa-circle-check"></i> Payments Logged</th>
                  <th><i class="fa-solid fa-file-arrow-up"></i> Documents</th>
                  <th><i class="fa-solid fa-clock"></i> Last Activity</th>
                </tr>
              </thead>
              <tbody id="teamAccountsTable"></tbody>
            </table>
          </div>
        </div>
      </section>

      <section id="usermgmt" class="page hidden">
        <div class="top">
          <div>
            <h1><i class="fa-solid fa-user-gear"></i> User Management</h1>
            <p>Add sales accounts, change details, or reset passwords.</p>
          </div><button class="btn primary" onclick="openUserForm()"><i class="fa-solid fa-user-plus"></i> Add User</button>
        </div>
        <div class="panel">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><i class="fa-solid fa-user"></i> Name</th>
                  <th><i class="fa-solid fa-envelope"></i> Email</th>
                  <th><i class="fa-solid fa-user-shield"></i> Role</th>
                  <th><i class="fa-solid fa-briefcase"></i> Desk</th>
                  <th><i class="fa-solid fa-gear"></i> Action</th>
                </tr>
              </thead>
              <tbody id="userMgmtTable"></tbody>
            </table>
          </div>
        </div>
      </section>
    </main>
  </div>

  <!-- Add/Edit User -->
  <div class="modal" id="userFormModal">
    <div class="modal-box" style="max-width:480px">
      <div class="modal-head">
        <h2 id="userFormTitle">Add User</h2><button class="close" onclick="closeModal('userFormModal')">×</button>
      </div>
      <form id="userForm">
        <input type="hidden" name="id">
        <div class="field"><label>Full Name *</label><input name="name" required></div>
        <div class="field"><label>Email *</label><input name="email" type="email" required></div>
        <div class="field"><label>Role *</label><select name="role" onchange="syncUserDeskField()">
            <option value="sales">Sales</option>
            <option value="legal">Legal</option>
            <option value="accounts">Accounts</option>
            <option value="it">IT</option>
            <option value="admin">Admin</option>
          </select></div>
        <!-- Desk follows the role (syncUserDeskField in app.js): only Sales picks a desk. -->
        <div class="field"><label>Desk</label><select name="desk">
            <option value="">None</option>
            <option value="entry">Entry Desk</option>
            <option value="broker">Broker Desk</option>
            <option value="owner">Owner Desk</option>
            <option value="legal">Legal Desk (Post Sales)</option>
            <option value="accounts">Accounts Desk (Post Sales)</option>
          </select></div>
        <div class="field">
          <label id="userPasswordLabel">Password *</label>
          <div style="position:relative">
            <input name="password" type="password" id="userPasswordInput" placeholder="Leave blank to keep current password" style="padding-right:62px">
            <button type="button" onclick="togglePwd('userPasswordInput', this)" aria-label="Show password" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);width:26px;height:26px;padding:0;background:transparent;border:0;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#6b7280"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a20.3 20.3 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a20.3 20.3 0 0 1-3.22 4.44M14.12 14.12a3 3 0 1 1-4.24-4.24" />
                <path d="M1 1l22 22" />
              </svg>
            </button>
          </div>
        </div>
        <div class="field">
          <label>Gmail App Password <span style="font-weight:400;color:var(--muted)">(sends this user's site-visit invites from their Gmail)</span></label>
          <input name="smtp_app_password" type="password" id="userSmtpInput" autocomplete="new-password" placeholder="16-character Google App Password">
        </div>
        <div style="text-align:right"><button type="button" class="btn" onclick="closeModal('userFormModal')">Cancel</button> <button class="btn primary">Save User</button></div>
      </form>
    </div>
  </div>

  <!-- Reassign Lead -->
  <div class="modal" id="reassignModal">
    <div class="modal-box" style="max-width:420px">
      <div class="modal-head">
        <h2>Reassign Lead</h2><button class="close" onclick="closeModal('reassignModal')">×</button>
      </div>
      <input type="hidden" id="reassignClientId">
      <p style="font-size:13px;color:var(--muted);margin-top:-8px">Reassigning <b id="reassignClientName"></b> — all existing notes, stage, and progress stay exactly as they are, only the assigned salesperson changes.</p>
      <div class="field"><label>Assign to</label><select id="reassignSelect"></select></div>
      <div style="text-align:right"><button type="button" class="btn" onclick="closeModal('reassignModal')">Cancel</button> <button class="btn primary" onclick="confirmReassign()">Confirm Reassign</button></div>
    </div>
  </div>

  <!-- Total Leads Breakdown -->
  <div class="modal" id="totalLeadsModal" onclick="if (event.target === this) closeModal('totalLeadsModal')">
    <div class="modal-box modal-float" style="max-width:920px;width:95%">
      <div class="modal-head">
        <h2>Leads by Category</h2><button class="close" onclick="closeModal('totalLeadsModal')">×</button>
      </div>
      <div id="totalLeadsBreakdown"></div>
    </div>
  </div>

  <!-- Assign Source Lead -->
  <div class="modal" id="assignSourceModal">
    <div class="modal-box" style="max-width:420px">
      <div class="modal-head">
        <h2>Confirm &amp; Assign Lead</h2><button class="close" onclick="closeModal('assignSourceModal')">×</button>
      </div>
      <input type="hidden" id="assignSourceId">
      <p style="font-size:13px;color:var(--muted);margin-top:-8px">This creates a real lead in the CRM with its own Lead ID and hands it to a salesperson. <b id="assignSourceName"></b></p>
      <div class="field" id="assignSourcePickerWrap"><label>Assign to</label><select id="assignSourceSelect"></select></div>
      <div style="text-align:right"><button type="button" class="btn" onclick="closeModal('assignSourceModal')">Cancel</button> <button class="btn primary" onclick="confirmAssignSource()">Confirm &amp; Assign</button></div>
    </div>
  </div>

  <!-- Edit Source Lead -->
  <div class="modal" id="editSourceModal">
    <div class="modal-box" style="max-width:460px">
      <div class="modal-head">
        <h2>Edit Source Lead</h2><button class="close" onclick="closeModal('editSourceModal')">×</button>
      </div>
      <input type="hidden" id="editSourceId">
      <div class="form-row">
        <div class="field"><label>Name</label><input id="editSourceName"></div>
        <div class="field"><label>Contact</label><input id="editSourceMobile"></div>
      </div>
      <div class="form-row">
        <div class="field"><label>Alt. Contact</label><input id="editSourceAltMobile"></div>
        <div class="field"><label>Email</label><input id="editSourceEmail"></div>
      </div>
      <div class="form-row">
        <div class="field"><label>City</label><input id="editSourceCity"></div>
        <div class="field"><label>Category</label>
          <select id="editSourceCategory">
            <option value="">—</option>
            <option value="Broker">Broker</option>
            <option value="Owner">Owner</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="field"><label>Budget</label><input id="editSourceBudget"></div>
        <div class="field"><label>Config</label><input id="editSourceConfig"></div>
      </div>
      <div class="field"><label>Notes</label><textarea id="editSourceNotes" rows="2"></textarea></div>
      <div style="text-align:right"><button type="button" class="btn" onclick="closeModal('editSourceModal')">Cancel</button> <button class="btn primary" onclick="confirmEditSource()">Save</button></div>
    </div>
  </div>

  <!-- Quick Add: role picker for admin -->
  <div class="modal" id="quickPickerModal">
    <div class="modal-box" style="max-width:480px">
      <div class="modal-head">
        <h2>Add Lead</h2><button class="close" onclick="closeModal('quickPickerModal')">×</button>
      </div>
      <p style="color:var(--muted);font-size:13px;margin-top:-8px">Which form does this lead come from?</p>
      <div style="display:flex;flex-direction:column;gap:9px;margin-top:14px">
        <button class="btn" style="text-align:left;padding:14px" onclick="closeModal('quickPickerModal');openQuickForm('enquiry')">📋 Enquiry — new walk-in / call enquiry</button>
        <button class="btn" style="text-align:left;padding:14px" onclick="closeModal('quickPickerModal');openQuickForm('broker')">🤝 Broker Entry — a broker visit</button>
        <button class="btn" style="text-align:left;padding:14px" onclick="closeModal('quickPickerModal');openQuickForm('newlead')">🆕 New Lead — client details only, no site visit yet</button>
        <button class="btn" style="text-align:left;padding:14px" onclick="closeModal('quickPickerModal');openQuickForm('walkin')">🏡 Walk-in Site Visit — client came straight to site (Site Visit Completed)</button>
        <button class="btn" style="text-align:left;padding:14px" onclick="closeModal('quickPickerModal');openClient()">📝 Full Manual Entry — every field, pick any salesperson yourself</button>
      </div>
    </div>
  </div>

  <!-- Quick Add: Enquiry -->
  <div class="modal" id="quickEnquiryModal">
    <div class="modal-box" style="max-width:700px">
      <div class="modal-head">
        <h2>Add Lead — Enquiry</h2><button class="close" onclick="closeModal('quickEnquiryModal')">×</button>
      </div>
      <form id="quickEnquiryForm">
        <div class="grid grid-2">
          <div class="field"><label>Client Name *</label><input name="clientName" required></div>
          <div class="field"><label>Contact Number *</label><input name="clientContact" required></div>
          <div class="field"><label>Email ID</label><input name="email" type="email"></div>
          <div class="field"><label>Location</label><input name="clientLocation"></div>
          <div class="field"><label>Source</label><select name="source">
              <option>Walk-in</option>
              <option>Broker</option>
              <option>Digital Marketing</option>
              <option>Reference</option>
              <option>Hoarding</option>
              <option>Website</option>
              <option value="Other">Other</option>
            </select></div>
          <div class="field"><label>Source (Other)</label><input name="sourceOther"></div>
          <div class="field"><label>Referred By</label><input name="referredBy"></div>
          <div class="field"><label>Client Type *</label><select name="clientType" required>
              <option value="Investor">Investor</option>
              <option value="Broker">Broker</option>
              <option value="Other">Other</option>
            </select></div>
          <div class="field"><label>Villa Type</label><select name="villaType">
              <option>5 BHK</option>
              <option>8 BHK</option>
              <option value="Other">Other</option>
            </select></div>
          <div class="field"><label>Villa Type (Other)</label><input name="villaTypeOther"></div>
          <div class="field"><label>Investment Purpose</label><input name="investmentPurpose"></div>
          <div class="field"><label>Budget</label><input name="budget" placeholder="e.g. ₹2.50 Cr"></div>
          <div class="field"><label>Lead Status</label><select name="leadStatus">
              <option>Hot</option>
              <option>Warm</option>
              <option>Cold</option>
              <option>Site Visit Scheduled</option>
              <option>Cancelled</option>
              <option value="Other">Other</option>
            </select></div>
        </div>
        <div class="field"><label>Enquiry Details</label><textarea name="enquiryDetails"></textarea></div>
        <div style="text-align:right"><button type="button" class="btn" onclick="closeModal('quickEnquiryModal')">Cancel</button> <button class="btn primary">Save Lead</button></div>
      </form>
    </div>
  </div>

  <!-- Quick Add: Broker -->
  <div class="modal" id="quickBrokerModal">
    <div class="modal-box" style="max-width:700px">
      <div class="modal-head">
        <h2>Add Lead — Broker Entry</h2><button class="close" onclick="closeModal('quickBrokerModal')">×</button>
      </div>
      <form id="quickBrokerForm">
        <div class="grid grid-2">
          <div class="field"><label>Broker Name *</label><input name="brokerName" required></div>
          <div class="field"><label>Broker Contact *</label><input name="contact" required></div>
          <div class="field"><label>Email</label><input name="email" type="email"></div>
          <div class="field"><label>Occupation</label><input name="occupation" placeholder="e.g. Real Estate Consultant"></div>
          <div class="field"><label>Company</label><input name="company"></div>
          <div class="field"><label>Visit Type</label><select name="visitType">
              <option>New Lead</option>
              <option>First Visit</option>
              <option>Follow-up Visit</option>
              <option value="Other">Other</option>
            </select></div>
          <div class="field"><label>Reference</label><select name="reference">
              <option>Walk-in</option>
              <option>Digital Marketing</option>
              <option>Website</option>
              <option value="Other">Other</option>
            </select></div>
          <div class="field"><label>Referred By</label><input name="referredBy"></div>
          <div class="field"><label>RERA Registered</label><select name="reraRegistered">
              <option>Yes</option>
              <option>No</option>
            </select></div>
          <div class="field"><label>RERA Number</label><input name="reraNumber"></div>
        </div>
        <p style="font-size:12px;font-weight:700;margin:16px 0 6px;color:var(--muted)">CLIENT BROUGHT — entirely optional, leave blank to just log this broker's visit</p>
        <div class="grid grid-2">
          <div class="field"><label>Client Name</label><input name="clientName"></div>
          <div class="field"><label>Client Contact</label><input name="clientContact"></div>
        </div>
        <div class="grid grid-2">
          <div class="field"><label>Client Budget</label><input name="clientBudget" placeholder="e.g. ₹1.5 Cr – ₹2 Cr"></div>
          <div class="field"><label>Interest Level</label><select name="interest">
              <option value="Hot">Hot</option>
              <option value="Warm" selected>Warm</option>
              <option value="Cold">Cold</option>
            </select></div>
        </div>
        <div class="field"><label>Visit Feedback</label><textarea name="feedback"></textarea></div>
        <div style="text-align:right"><button type="button" class="btn" onclick="closeModal('quickBrokerModal')">Cancel</button> <button class="btn primary">Save Entry</button></div>
      </form>
    </div>
  </div>

  <!-- Quick Add: New Lead (client details only — starts at New Lead) -->
  <div class="modal" id="quickNewLeadModal">
    <div class="modal-box" style="max-width:760px">
      <div class="modal-head">
        <h2>Add Lead — New Lead</h2><button class="close" onclick="closeModal('quickNewLeadModal')">×</button>
      </div>
      <form id="quickNewLeadForm">
        <input type="hidden" name="visit_purpose" value="new">
        <div class="grid grid-2">
          <div class="field"><label>Client Name *</label><input name="client_name" required></div>
          <div class="field"><label>Contact Number *</label><input name="client_contact" required></div>
          <div class="field"><label>Alternate Number</label><input name="client_alt_contact"></div>
          <div class="field"><label>Email</label><input name="client_email" type="email"></div>
          <div class="field"><label>City</label><input name="client_city"></div>
          <div class="field"><label>Source</label><select name="source">
              <option>Walk-in</option>
              <option>Website</option>
              <option>Hoarding</option>
              <option>Digital Marketing</option>
              <option>Reference</option>
              <option value="Other">Other</option>
            </select></div>
          <div class="field"><label>Reference</label><input name="reference"></div>
          <div class="field"><label>Requirement</label><select name="requirement">
              <option>5 BHK</option>
              <option>8 BHK</option>
              <option>Not Decided</option>
              <option value="Other">Other</option>
            </select></div>
          <div class="field"><label>Purpose</label><select name="purpose">
              <option>Investment</option>
              <option>Weekend Home</option>
              <option>Self Use</option>
              <option>Second Home</option>
              <option>Retirement Home</option>
              <option>Rental Income</option>
              <option value="Other">Other</option>
            </select></div>
          <div class="field"><label>Budget</label><input name="budget" placeholder="e.g. ₹2.50 Cr"></div>
          <div class="field"><label>Lead Status</label><select name="lead_status">
              <option>Hot</option>
              <option selected>Warm</option>
              <option>Cold</option>
            </select></div>
        </div>
        <div class="field"><label>Notes</label><textarea name="feedback"></textarea></div>
        <p style="font-size:11px;color:var(--muted);margin-top:-4px">Starts at <b>New Lead</b>. Added by a Broker/Owner desk salesperson → stays on their desk; otherwise the Owner desk.</p>
        <div style="text-align:right"><button type="button" class="btn" onclick="closeModal('quickNewLeadModal')">Cancel</button> <button class="btn primary">Save Lead</button></div>
      </form>
    </div>
  </div>

  <!-- Quick Add: Walk-in Site Visit — lead created directly at Site Visit Completed
       (see the sitevisit / visit_purpose=done branch in api/public_intake.php) -->
  <div class="modal" id="quickWalkinModal">
    <div class="modal-box" style="max-width:760px">
      <div class="modal-head">
        <h2>Add Lead — Walk-in Site Visit</h2><button class="close" onclick="closeModal('quickWalkinModal')">×</button>
      </div>
      <form id="quickWalkinForm">
        <input type="hidden" name="visit_purpose" value="done">
        <p style="font-size:12px;color:var(--muted);margin:-6px 0 12px">For a client who came straight to site. The lead is added directly at <b>Site Visit Completed</b> — Contact Attempted, Contacted, Site Visit Scheduled and Site Visit Follow-up are skipped, Qualified is auto-marked Yes, and the brochure is set to be sent after the visit.</p>
        <p style="font-size:12px;font-weight:700;margin:0 0 6px;color:var(--muted)">CLIENT DETAILS</p>
        <div class="grid grid-2">
          <div class="field"><label>Client Name *</label><input name="client_name" required></div>
          <div class="field"><label>Contact Number *</label><input name="client_contact" required></div>
          <div class="field"><label>Alternate Number</label><input name="client_alt_contact"></div>
          <div class="field"><label>Email</label><input name="client_email" type="email"></div>
          <div class="field"><label>City</label><input name="client_city"></div>
          <div class="field"><label>Source</label><select name="source">
              <option>Walk-in</option>
              <option>Website</option>
              <option>Hoarding</option>
              <option>Digital Marketing</option>
              <option>Reference</option>
              <option value="Other">Other</option>
            </select></div>
          <div class="field"><label>Reference</label><input name="reference"></div>
          <div class="field"><label>Requirement</label><select name="requirement">
              <option>5 BHK</option>
              <option>8 BHK</option>
              <option>Not Decided</option>
              <option value="Other">Other</option>
            </select></div>
          <div class="field"><label>Purpose</label><select name="purpose">
              <option>Investment</option>
              <option>Weekend Home</option>
              <option>Self Use</option>
              <option>Second Home</option>
              <option>Retirement Home</option>
              <option>Rental Income</option>
              <option value="Other">Other</option>
            </select></div>
          <div class="field"><label>Budget</label><input name="budget" placeholder="e.g. ₹2.50 Cr"></div>
          <div class="field"><label>Financing</label><select name="finance">
              <option value="">Not set</option>
              <option>Self Funded</option>
              <option>Loan</option>
              <option>Part Loan</option>
              <option>Not Decided</option>
            </select></div>
        </div>
        <p style="font-size:12px;font-weight:700;margin:16px 0 6px;color:var(--muted)">SITE VISIT COMPLETED</p>
        <div class="grid grid-2">
          <div class="field"><label>Visit Date *</label><input name="date" type="date" required></div>
          <div class="field"><label>Visit Time *</label><input name="time_in" type="time" required></div>
          <div class="field"><label>Number of Visitors</label><input name="visitors" type="number" min="1" value="1"></div>
          <div class="field"><label>Villa Shown</label><div class="vpick" data-mode="single" data-scope="all"><input name="villa_shown" autocomplete="off" placeholder="Select or type a villa"></div></div>
          <div class="field"><label>Visit Outcome</label><select name="visit_outcome">
              <option value="">Not set</option>
              <option>Interested</option>
              <option>Needs Follow-up</option>
              <option>Re-visit Requested</option>
              <option>Not Interested</option>
            </select></div>
          <div class="field"><label>Interest Level</label><select name="lead_status">
              <option value="HOT">HOT</option>
              <option value="WARM" selected>WARM</option>
              <option value="COLD">COLD</option>
            </select></div>
        </div>
        <div class="field"><label>Post-Visit Feedback / WhatsApp Note</label><textarea name="feedback"></textarea></div>
        <p style="font-size:11px;color:var(--muted);margin-top:-4px">Added by a Broker/Owner desk salesperson → stays on their desk; otherwise the Owner desk. The lead opens at Site Visit Completed after saving.</p>
        <div style="text-align:right"><button type="button" class="btn" onclick="closeModal('quickWalkinModal')">Cancel</button> <button class="btn primary">Save Walk-in Visit</button></div>
      </form>
    </div>
  </div>

  <!-- Client Modal -->
  <div class="modal" id="clientModal">
    <div class="modal-box">
      <div class="modal-head">
        <h2 id="clientTitle">Add New Lead</h2><button class="close" onclick="closeModal('clientModal')">×</button>
      </div>
      <form id="clientForm">
        <input type="hidden" name="id">
        <input type="hidden" name="stage" id="stageInput" value="New Lead">
        <input type="hidden" name="visit_status" id="visitStatusInput" value="Not Scheduled">
        <input type="hidden" name="agreement" id="agreementInput" value="Pending">
        <input type="hidden" name="registration" id="registrationInput" value="Pending">

        <!-- Basic details summary, always visible on top -->
        <div id="leadSummary" class="lead-summary">
          <button type="button" class="ls-edit-btn" title="Edit basic details" onclick="toggleBasicEdit()"><i class="fa-solid fa-pen"></i></button>
        </div>
        <div id="basicEdit" class="lead-basic-edit hidden">
          <div class="grid">
            <div class="field"><label>Lead ID</label><input name="lead_code" placeholder="Auto-generated" readonly></div>
            <div class="field"><label>Client Name *</label><input name="name" required></div>
            <div class="field"><label>Mobile Number *</label><input name="mobile" required></div>
            <div class="field"><label>Alternate Number</label><input name="alt"></div>
            <div class="field"><label>WhatsApp Number</label><input name="whatsapp"></div>
            <div class="field"><label>Email ID</label><input name="email" type="email"></div>
            <div class="field"><label>City</label><input name="city"></div>
            <div class="field"><label>Lead Source</label><select name="source">
                <option>Calling</option>
                <option>Website</option>
                <option>Broker</option>
                <option>Reference</option>
                <option>Walk-in</option>
                <option>Digital Marketing</option>
                <option>Hoarding</option>
                <option>Other</option>
              </select></div>
            <div class="field"><label>Lead Sub-Source</label><input name="subsource"></div>
            <div class="field"><label>Category</label><select name="category">
                <option value="">Not set</option>
                <option value="Broker">Broker</option>
                <option value="Broker Reference">Broker Reference</option>
                <option value="Owner">Owner</option>
              </select></div>
            <div class="field"><label>Sales Person</label><select name="salesperson_id" id="salespersonSelect"></select></div>
            <div class="field"><label>Created Date</label><input name="created" type="date"></div>
          </div>
          <p style="font-size:12px;font-weight:700;margin:16px 0 6px;color:var(--muted)">BROKER DETAILS — fill in if this lead came through a broker (leave blank if none)</p>
          <div class="grid">
            <div class="field"><label>Broker Name</label><input name="broker_name"></div>
            <div class="field"><label>Broker Contact</label><input name="broker_contact"></div>
            <div class="field"><label>Broker Email</label><input name="broker_email" type="email"></div>
          </div>
          <p style="font-size:11px;color:var(--muted);margin:-4px 0 10px">Category updates automatically: broker details alone → Broker, broker details with the client's own Name/Mobile above → Broker Reference.</p>
          <div style="display:flex;justify-content:flex-end">
            <button type="button" class="btn btn-sm primary" onclick="saveBasicDetails()">Save</button>
          </div>
        </div>

        <div id="clientMidScroll" class="modal-mid-scroll">
        <!-- Liquid lead-stage progress bar -->
        <div id="stageTracker" class="liquid-bar"></div>

        <!-- Sales / Post Sales split. Admin switches between both phases;
             salespeople only get the Sales tabs + two read-only summary tabs. -->
        <?php if ($isAdmin): ?>
        <div class="phase-switch" id="phaseSwitch">
          <button type="button" data-phase="sales" class="active" onclick="switchPhase('sales')"><i class="fa-solid fa-user-tie"></i> Sales</button>
          <button type="button" data-phase="post" onclick="switchPhase('post')"><i class="fa-solid fa-file-contract"></i> Post Sales</button>
        </div>
        <?php endif; ?>

        <!-- Pipeline tabs, one per stage group -->
        <div class="tabs">
          <?php if (!$isPostSales): ?>
          <button type="button" class="tab active" data-phase="sales" onclick="clientTab('new',this)">New Lead</button>
          <button type="button" class="tab" data-phase="sales" onclick="clientTab('visit',this)">Site Visit</button>
          <button type="button" class="tab" data-phase="sales" onclick="clientTab('engage',this)">Follow-up / Negotiation</button>
          <?php endif; ?>
          <?php if ($canPostSales): ?>
          <?php if ($showLegalTabs): ?><button type="button" class="tab" data-phase="post" onclick="clientTab('book',this)">Booking &amp; Legal</button><?php endif; ?>
          <?php if ($showAccountsTabs): ?>
          <button type="button" class="tab" data-phase="post" onclick="clientTab('construction',this)">Construction &amp; Payment</button>
          <?php if ($isAdmin): ?>
          <button type="button" class="tab" data-phase="post" onclick="clientTab('possession',this)">Possession</button>
          <?php else: ?>
          <!-- Accounts desk: Possession is handled by admin — read-only status (renderAccountsSummaryTabs in app.js) -->
          <button type="button" class="tab" data-phase="post" onclick="clientTab('acctpos',this)">Possession</button>
          <?php endif; ?>
          <?php endif; /* Legal desk: Booking & Legal only — Construction, payments & possession are Accounts' details */ ?>
          <?php elseif (!$isEntryDesk): ?>
          <button type="button" class="tab" data-phase="sales" onclick="clientTab('salesbook',this)">Booking</button>
          <button type="button" class="tab" data-phase="sales" onclick="clientTab('salespos',this)">Possession</button>
          <?php endif; ?>
        </div>
        <input type="hidden" name="activity_log" id="activityLogField">
        <!-- "1" = Complete Sales & Transfer to Post Sales on this save (see renderSalesHandover in app.js) -->
        <input type="hidden" name="sales_handover" id="salesHandoverField" value="">
        <!-- "1" = Legal Completed & Transfer to Accounts on this save (see renderAccountsHandover in app.js) -->
        <input type="hidden" name="accounts_handover" id="accountsHandoverField" value="">
        <!-- Canonical Interest Level value — kept OUTSIDE any stage-section
             so it's never disabled/omitted from submission just because
             that section happens to be locked. The visible dropdowns in
             New Lead / Contacted / Site Visit Completed are pure mirrors
             (no name attribute) kept in sync via syncInterest(). -->
        <input type="hidden" name="interest" id="interestCanonical" value="WARM">
        <!-- Brochure Sent canonical fields — kept outside the stage-section
             so the Site Visit Completed "bypass" copy (for brochures sent
             after the visit) can mirror the exact same values. -->
        <input type="hidden" name="brochure_date" id="brochureDateCanonical">
        <input type="hidden" name="brochure_mode" id="brochureModeCanonical">
        <input type="hidden" name="brochure_whatsapp_message" id="brochureMsgCanonical">
        <input type="hidden" name="brochure_whatsapp_sent" id="brochureSentCanonical">
        <input type="hidden" name="brochure_bypass" id="brochureBypassCanonical" value="No">

        <div id="ct-new" class="ctab">
          <div id="chk-new" class="stage-checklist"></div>

          <div class="stage-section" data-stage="New Lead">
            <div class="ss-head"><i class="fa-regular fa-circle-dot ss-status"></i> New Lead <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <p style="font-size:12px;color:var(--muted);margin:0 0 10px">Lead captured. Fill in the sections below as you move through each contact step — each unlocks once you reach it.</p>
            <div class="field" style="max-width:220px">
              <label>Interest Level</label>
              <select data-interest-mirror onchange="syncInterest(this.value)">
                <option>HOT</option>
                <option>WARM</option>
                <option>COLD</option>
              </select>
            </div>
          </div>

          <div class="stage-section" data-stage="Contact Attempted">
            <div class="ss-head"><i class="fa-solid fa-phone ss-status"></i> Contact Attempted <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <div class="grid">
              <div class="field"><label>First Call Date</label><input name="firstcall" type="date"></div>
              <div class="field"><label>Attempt Time</label><input name="attempt_time" type="time"></div>
              <div class="field"><label>Attempt Result</label><div class="mode-call"><select name="call_attempt_result" onchange="onAttemptResultChange(this)">
                  <option value="">Not set</option>
                  <option>No Answer</option>
                  <option>Switched Off</option>
                  <option>Busy</option>
                  <option>Call Back Requested</option>
                  <option>Connected</option>
                </select><button type="button" class="btn call-btn" data-always title="Call the client" onclick="callOpenLead('Contact Attempted')"><i class="fa-solid fa-phone"></i></button></div></div>
            </div>
            <p style="font-size:10.5px;color:var(--muted);margin:4px 0 0"><i class="fa-solid fa-phone"></i> opens your phone's dialer with the client's number. Attempt Result auto-fills First Call Date &amp; Attempt Time on pick — "Connected" moves this lead to Contacted.</p>

            <div id="activityLogPanel" class="attempt-log compact">
              <div class="al-title"><i class="fa-solid fa-note-sticky"></i> Attempt Log</div>
              <div class="al-add">
                <textarea id="manualNoteInput" rows="2" placeholder="Add a note about this contact attempt…"></textarea>
                <button type="button" class="btn btn-sm" onclick="addManualNote()">Add Note</button>
              </div>
              <div id="activityLogList" class="attempt-log-list compact"></div>
            </div>
          </div>

          <div class="stage-section" data-stage="Contacted">
            <div class="ss-head"><i class="fa-solid fa-comments ss-status"></i> Contacted <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <div class="grid">
              <div class="field"><label>Last Contact Date</label><input name="lastcontact" type="date"></div>
              <div class="field"><label>Contacted Mode</label><div class="mode-call"><select name="fumode" onchange="updateModeCallBtns()">
                  <option value="Call" selected>Call</option>
                  <option>WhatsApp</option>
                  <option>Email</option>
                  <option>Meeting</option>
                </select><button type="button" class="btn call-btn hidden" title="Call the client" onclick="callOpenLead('Contacted')"><i class="fa-solid fa-phone"></i></button></div></div>
              <div class="field"><label>Interest Level</label><select data-interest-mirror onchange="syncInterest(this.value)">
                  <option>HOT</option>
                  <option>WARM</option>
                  <option>COLD</option>
                </select></div>
            </div>
            <div class="field"><label>Last Discussion / Notes</label><textarea name="notes"></textarea></div>
          </div>

          <div class="stage-section" data-stage="Qualified">
            <div class="ss-head"><i class="fa-solid fa-check-double ss-status"></i> Qualified <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <div class="grid">
              <div class="field"><label>Budget Confirmed?</label><select name="budget_confirmed" onchange="checkQualifiedComplete()">
                  <option value="">Not set</option>
                  <option>Yes</option>
                  <option>No</option>
                  <option>Partially</option>
                </select></div>
              <div class="field"><label>Decision Maker?</label><select name="decision_maker" onchange="checkQualifiedComplete()">
                  <option value="">Not set</option>
                  <option>Yes</option>
                  <option>No</option>
                </select></div>
            </div>
          </div>

          <div class="stage-section" data-stage="Requirement Understood">
            <div class="ss-head"><i class="fa-solid fa-clipboard-list ss-status"></i> Requirement Understood <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <div class="grid">
              <div class="field"><label>Purpose of Purchase</label><select name="purpose">
                  <option>Investment</option>
                  <option>Second Home</option>
                  <option>Weekend Home</option>
                  <option>Self Use</option>
                  <option>Retirement Home</option>
                  <option>Rental Income</option>
                  <option>Other</option>
                </select></div>
              <div class="field"><label>Preferred Configuration</label><select name="config">
                  <option>5 BHK</option>
                  <option>8 BHK</option>
                  <option>Not Decided</option>
                  <option value="Other">Other</option>
                </select></div>
              <div class="field"><label>Budget</label><input name="budget" placeholder="e.g. ₹2.50 Cr"></div>
              <div class="field"><label>Financing</label><select name="finance">
                  <option>Self Funded</option>
                  <option>Loan</option>
                  <option>Part Loan</option>
                  <option>Not Decided</option>
                </select></div>
            </div>

            <div class="attempt-log compact" id="reqWhatsappBlock">
              <div class="al-title"><i class="fa-brands fa-whatsapp"></i> Post-Contact WhatsApp Greeting</div>
              <div class="field">
                <label>Greeting Message</label>
                <textarea name="req_whatsapp_message" id="reqWhatsappMsg" rows="3"></textarea>
              </div>
              <div class="wa-row">
                <select name="req_whatsapp_sent" onchange="onWhatsappSentChange(this)">
                  <option value="">Sent? Not set</option>
                  <option>No</option>
                  <option>Yes</option>
                </select>
                <button type="button" class="btn btn-sm" onclick="copyWhatsappMessage()"><i class="fa-solid fa-copy"></i> Copy</button>
                <button type="button" class="btn btn-sm" onclick="openWhatsappFrom('reqWhatsappMsg')"><i class="fa-brands fa-whatsapp"></i> Open WhatsApp</button>
                <button type="button" class="btn btn-sm" onclick="regenerateReqWhatsapp()"><i class="fa-solid fa-rotate"></i> Regenerate</button>
                <span class="wa-hint">Locks "Brochure Sent" until marked <b>Yes</b>.</span>
              </div>
            </div>
          </div>

          <div class="stage-section" data-stage="Brochure Sent">
            <div class="ss-head"><i class="fa-solid fa-file-arrow-up ss-status"></i> Brochure Sent <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <label class="wa-bypass-toggle" for="brochureBypassChk">
              <input type="checkbox" id="brochureBypassChk" onchange="toggleBrochureBypass(this.checked)">
              <span>Not sent yet — I'll send it after the site visit instead</span>
            </label>
            <div class="grid">
              <div class="field"><label>Brochure Sent Date</label><input type="date" data-mirror="brochure_date" oninput="mirrorSync('brochure_date', this.value)"></div>
              <div class="field"><label>Sent Via</label><select data-mirror="brochure_mode" onchange="mirrorSync('brochure_mode', this.value); fillBrochureWhatsappGreetingDefault()">
                  <option value="">Not set</option>
                  <option>WhatsApp</option>
                  <option>Email</option>
                  <option>Printed</option>
                  <option>In-person</option>
                </select></div>
            </div>

            <div class="attempt-log compact" id="brochureWhatsappBlock">
              <div class="al-title"><i class="fa-brands fa-whatsapp"></i> Post-Brochure-Sent WhatsApp Greeting</div>
              <div class="field">
                <label>Greeting Message</label>
                <textarea data-mirror="brochure_whatsapp_message" id="brochureWhatsappMsg1" rows="3" oninput="mirrorSync('brochure_whatsapp_message', this.value); clearBrochureAuto()"></textarea>
              </div>
              <div class="wa-row">
                <select data-mirror="brochure_whatsapp_sent" onchange="mirrorSync('brochure_whatsapp_sent', this.value); onBrochureWhatsappSentChange(this)">
                  <option value="">Sent? Not set</option>
                  <option>No</option>
                  <option>Yes</option>
                </select>
                <button type="button" class="btn btn-sm" onclick="copyBrochureWhatsappMessage('brochureWhatsappMsg1')"><i class="fa-solid fa-copy"></i> Copy</button>
                <button type="button" class="btn btn-sm" onclick="openWhatsappFrom('brochureWhatsappMsg1')"><i class="fa-brands fa-whatsapp"></i> Open WhatsApp</button>
                <button type="button" class="btn btn-sm" onclick="regenerateBrochureWhatsapp()"><i class="fa-solid fa-rotate"></i> Regenerate</button>
              </div>
            </div>
          </div>
        </div>

        <div id="ct-visit" class="ctab hidden">
          <div id="chk-visit" class="stage-checklist"></div>

          <div class="stage-section" data-stage="Site Visit Scheduled">
            <div class="ss-head"><i class="fa-solid fa-calendar-check ss-status"></i> Site Visit Scheduled <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <div class="grid">
              <div class="field"><label>Visit Date</label><input name="visit_date" type="date"></div>
              <div class="field"><label>Visit Time</label><input name="visit_time" type="time"></div>
              <div class="field"><label>Pickup Required?</label><select name="visit_pickup">
                  <option value="">Not set</option>
                  <option>Yes</option>
                  <option>No</option>
                </select></div>
            </div>

            <?php visit_share_block('site', 'visit', 'visit_whatsapp_sent'); ?>
          </div>

          <div class="stage-section" data-stage="Site Visit Follow-up">
            <div class="ss-head"><i class="fa-solid fa-phone-volume ss-status"></i> Site Visit Follow-up <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <p style="font-size:11px;color:var(--muted);margin:0 0 10px">Follow up with the client after the visit is scheduled. Saved as a "Site Visit Follow-up" — it shows on the Dashboard and Follow-ups page.</p>
            <?php followup_fields('sv'); ?>
          </div>

          <div class="stage-section" data-stage="Site Visit Completed">
            <div class="ss-head"><i class="fa-solid fa-flag-checkered ss-status"></i> Site Visit Completed <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <div class="grid">
              <div class="field"><label>Villa Shown</label><div class="vpick" data-mode="single" data-scope="all"><input name="visit_villa" autocomplete="off" placeholder="Select or type a villa"></div></div>
              <div class="field"><label>Number of Visitors</label><input name="visit_visitors" type="number" min="1" value="1"></div>
              <div class="field"><label>Visit Outcome</label><select name="visit_outcome">
                  <option value="">Not set</option>
                  <option>Interested</option>
                  <option>Needs Follow-up</option>
                  <option>Re-visit Requested</option>
                  <option>Not Interested</option>
                </select></div>
              <div class="field"><label>Interest Level</label><select data-interest-mirror onchange="syncInterest(this.value)">
                  <option>HOT</option>
                  <option>WARM</option>
                  <option>COLD</option>
                </select></div>
            </div>
            <div class="field"><label>Post-Visit Feedback / WhatsApp Note</label><textarea name="visit_feedback"></textarea></div>
            <p style="font-size:11px;color:var(--muted);margin:6px 0 0">Marking "Site Visit Completed" below also logs this on the Site Visits tab automatically.</p>

            <div id="brochureInVisitBlock" class="attempt-log compact hidden">
              <div class="al-title"><i class="fa-solid fa-file-arrow-up"></i> Brochure Sent (after the site visit)</div>
              <div class="grid grid-2">
                <div class="field"><label>Brochure Sent Date</label><input type="date" data-mirror="brochure_date" oninput="mirrorSync('brochure_date', this.value)"></div>
                <div class="field"><label>Sent Via</label><select data-mirror="brochure_mode" onchange="mirrorSync('brochure_mode', this.value); fillBrochureWhatsappGreetingDefault()">
                    <option value="">Not set</option>
                    <option>WhatsApp</option>
                    <option>Email</option>
                    <option>Printed</option>
                    <option>In-person</option>
                  </select></div>
              </div>
              <div class="field">
                <label>Greeting Message</label>
                <textarea data-mirror="brochure_whatsapp_message" id="brochureWhatsappMsg2" rows="3" oninput="mirrorSync('brochure_whatsapp_message', this.value); clearBrochureAuto()"></textarea>
              </div>
              <div class="wa-row">
                <select data-mirror="brochure_whatsapp_sent" onchange="mirrorSync('brochure_whatsapp_sent', this.value); onBrochureWhatsappSentChange(this)">
                  <option value="">Sent? Not set</option>
                  <option>No</option>
                  <option>Yes</option>
                </select>
                <button type="button" class="btn btn-sm" onclick="copyBrochureWhatsappMessage('brochureWhatsappMsg2')"><i class="fa-solid fa-copy"></i> Copy</button>
                <button type="button" class="btn btn-sm" onclick="openWhatsappFrom('brochureWhatsappMsg2')"><i class="fa-brands fa-whatsapp"></i> Open WhatsApp</button>
                <button type="button" class="btn btn-sm" onclick="regenerateBrochureWhatsapp()"><i class="fa-solid fa-rotate"></i> Regenerate</button>
              </div>
            </div>
          </div>
        </div>

        <div id="ct-engage" class="ctab hidden">
          <div id="chk-engage" class="stage-checklist"></div>

          <div class="stage-section" data-stage="Post Site Visit Follow-up">
            <div class="ss-head"><i class="fa-solid fa-arrows-rotate ss-status"></i> Post Site Visit Follow-up <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <p style="font-size:11px;color:var(--muted);margin:0 0 10px">Follow up with the client after the site visit is completed. Saved as a "Post Site Visit Follow-up" follow-up — it shows on the Dashboard and Follow-ups page.</p>
            <?php followup_fields('psv'); ?>
          </div>

          <div class="stage-section" data-stage="Re-Visit Scheduled">
            <div class="ss-head"><i class="fa-solid fa-calendar-plus ss-status"></i> Re-Visit Scheduled <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <div class="grid">
              <div class="field"><label>Re-visit Date</label><input name="revisit_date" type="date"></div>
              <div class="field"><label>Re-visit Time</label><input name="revisit_time" type="time"></div>
            </div>

            <?php visit_share_block('revisit', 'revisit', 'revisit_whatsapp_sent'); ?>
          </div>

          <div class="stage-section" data-stage="Re-Visit Follow-up">
            <div class="ss-head"><i class="fa-solid fa-phone-volume ss-status"></i> Re-Visit Follow-up <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <p style="font-size:11px;color:var(--muted);margin:0 0 10px">Follow up with the client after the re-visit is scheduled. Saved as a "Re-Visit Follow-up" follow-up — it shows on the Dashboard and Follow-ups page.</p>
            <?php followup_fields('rv'); ?>
          </div>

          <div class="stage-section" data-stage="Re-Visit Completed">
            <div class="ss-head"><i class="fa-solid fa-flag-checkered ss-status"></i> Re-Visit Completed <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <div class="grid">
              <div class="field"><label>Villa Shown</label><div class="vpick" data-mode="single" data-scope="all"><input name="revisit_villa" autocomplete="off" placeholder="Select or type a villa"></div></div>
              <div class="field"><label>Number of Visitors</label><input name="revisit_visitors" type="number" min="1" value="1"></div>
              <div class="field"><label>Re-visit Outcome</label><select name="revisit_outcome">
                  <option value="">Not set</option>
                  <option>Interested</option>
                  <option>Needs Follow-up</option>
                  <option>Re-visit Requested</option>
                  <option>Not Interested</option>
                </select></div>
              <div class="field"><label>Interest Level</label><select data-interest-mirror onchange="syncInterest(this.value)">
                  <option>HOT</option>
                  <option>WARM</option>
                  <option>COLD</option>
                </select></div>
            </div>
            <div class="field"><label>Post-Visit Feedback / WhatsApp Note</label><textarea name="revisit_feedback"></textarea></div>
          </div>

          <div class="stage-section" data-stage="Negotiation Visit Scheduled">
            <div class="ss-head"><i class="fa-solid fa-handshake ss-status"></i> Negotiation Visit Scheduled <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <p style="font-size:11px;color:var(--muted);margin:0 0 10px">For scheduling the negotiation visit only. Offered price and negotiation notes are captured afterwards in "Negotiation Visit Follow-up".</p>
            <div class="grid">
              <div class="field"><label>Negotiation Visit Date</label><input name="negotiation_visit_date" type="date"></div>
              <div class="field"><label>Negotiation Visit Time</label><input name="negotiation_visit_time" type="time"></div>
            </div>

            <?php visit_share_block('negotiation', 'negotiation', 'negotiation_whatsapp_sent'); ?>
          </div>

          <div class="stage-section" data-stage="Negotiation Visit Follow-up">
            <div class="ss-head"><i class="fa-solid fa-phone-volume ss-status"></i> Negotiation Visit Follow-up <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <p style="font-size:11px;color:var(--muted);margin:0 0 10px">Follow up with the client after the negotiation visit. Saved as a "Negotiation Visit Follow-up" follow-up — it shows on the Dashboard and Follow-ups page.</p>
            <?php followup_fields('nv'); ?>
          </div>

          <div class="stage-section" data-stage="Unit Selection">
            <div class="ss-head"><i class="fa-solid fa-list-check ss-status"></i> Unit Selection <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <p style="font-size:11px;color:var(--muted);margin:0 0 10px">Shortlist a few villas from inventory, then tick the final villa(s) for this client — tick more than one if the client is buying multiple villas — and record the price offered on each.</p>
            <div class="grid">
              <div class="field"><label>Villas Shortlisted</label><div class="vpick" data-mode="multi" data-scope="available" data-placeholder="Type or select villas"><input type="hidden" name="villa_shortlist"></div></div>
              <div class="field" style="grid-column:span 3"><label>Villa(s) Selected (Final) &amp; Offered Price</label>
                <!-- Canonical values: villa = "A, B" (comma list, Villa Inventory syncs on each),
                     villa_offers = JSON {villa: price}, offered_price = readable summary. -->
                <input type="hidden" name="villa">
                <input type="hidden" name="villa_offers">
                <input type="hidden" name="offered_price">
                <div id="finalVillaList" class="fv-list"></div>
              </div>
            </div>
            <div class="field"><label>Discussion Note</label><textarea name="negotiation_notes"></textarea></div>
          </div>

          <div class="stage-section" data-stage="Villa Blocked Follow-up">
            <div class="ss-head"><i class="fa-solid fa-phone-volume ss-status"></i> Villa Blocked Follow-up <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <p style="font-size:11px;color:var(--muted);margin:0 0 10px">Optional — follow up with the client to get the selected villa(s) blocked. Skip it if the client blocks right away. Saved as a "Villa Blocked Follow-up" follow-up — it shows on the Dashboard and Follow-ups page.</p>
            <?php followup_fields('vb'); ?>
          </div>

          <div class="stage-section" data-stage="Unit Blocked">
            <div class="ss-head"><i class="fa-solid fa-lock ss-status"></i> Unit Blocked <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <div class="grid">
              <div class="field"><label>Block Date</label><input name="block_date" type="date"></div>
              <div class="field"><label>Token Amount</label>
                <div class="amt-field">
                  <i class="fa-solid fa-indian-rupee-sign"></i>
                  <input type="text" inputmode="decimal" id="tokenAmountDisplay" placeholder="e.g. 1,00,000"
                    oninput="amtSanitize(this);renderBookingBreakdown()" onfocus="amtEditMode(this)" onblur="amtFormat(this)">
                  <input type="hidden" name="token_amount">
                </div>
              </div>
            </div>
            <div class="field"><label>Note</label><textarea name="block_note" placeholder="Note about blocking this unit"></textarea></div>
            <!-- Sales → Post Sales handover (filled by renderSalesHandover() in app.js) -->
            <div id="salesHandoverBox" class="handover-box"></div>
          </div>
        </div>

        <?php if ($canPostSales): ?>
        <?php if ($showLegalTabs): ?>
        <div id="ct-book" class="ctab hidden">
          <div id="chk-book" class="stage-checklist"></div>

          <div class="stage-section" data-stage="Booking Initiated">
            <div class="ss-head"><i class="fa-solid fa-file-invoice-dollar ss-status"></i> Booking Initiated <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <!-- Final Villa Price drives the rest: Booking Amount = 10% of it and Booking Date
                 = today are auto-filled (both still editable) — see onFinalVillaPriceInput() in app.js.
                 It is also the lead's Final Sale Value (api/clients.php keeps salevalue in sync). -->
            <div class="grid">
              <div class="field"><label>Final Villa Price</label>
                <div class="amt-field">
                  <i class="fa-solid fa-indian-rupee-sign"></i>
                  <input type="text" inputmode="decimal" id="finalVillaPriceDisplay" placeholder="e.g. 2,35,00,000"
                    oninput="onFinalVillaPriceInput(this)" onfocus="amtEditMode(this)" onblur="amtFormat(this)">
                  <input type="hidden" name="final_villa_price">
                </div>
                <div class="amt-hint" id="finalVillaPriceWords"></div>
              </div>
              <div class="field"><label>Booking Amount <span class="amt-tag">Auto 10%</span></label>
                <div class="amt-field">
                  <i class="fa-solid fa-indian-rupee-sign"></i>
                  <input type="text" inputmode="decimal" id="bookingAmountDisplay" placeholder="Auto from villa price"
                    oninput="amtSanitize(this);renderBookingBreakdown()" onfocus="amtEditMode(this)" onblur="amtFormat(this);renderBookingBreakdown()">
                  <input type="hidden" name="bookingamount">
                </div>
              </div>
              <!-- Token (paid at Unit Blocked, entered by Sales) and Net Booking Amount = Booking Amount − Token.
                   Read-only, calculated — see renderBookingBreakdown() in app.js. -->
              <div class="field"><label>Token Amount <span class="amt-tag">From Sales</span></label>
                <div class="amt-field">
                  <i class="fa-solid fa-indian-rupee-sign"></i>
                  <input type="text" id="bookingTokenDisplay" placeholder="No token recorded" readonly data-ro="1" tabindex="-1">
                </div>
                <div class="amt-hint" id="bookingTokenHint"></div>
              </div>
              <div class="field"><label>Net Booking Amount <span class="amt-tag">Auto</span></label>
                <div class="amt-field">
                  <i class="fa-solid fa-indian-rupee-sign"></i>
                  <input type="text" id="bookingNetDisplay" placeholder="Booking amount − token" readonly data-ro="1" tabindex="-1">
                </div>
                <div class="amt-hint" id="bookingNetHint"></div>
              </div>
              <div class="field"><label>Booking Date</label><input name="bookingdate" type="date" oninput="this.dataset.auto=''"></div>
              <div class="field"><label>Payment Mode</label><select name="booking_payment_mode">
                  <option value="">Not set</option>
                  <option>Cheque</option>
                  <option>NEFT / RTGS</option>
                  <option>UPI</option>
                  <option>Demand Draft</option>
                </select></div>
              <div class="field"><label>Cheque / Transaction Ref. No.</label><input name="booking_txn_ref"></div>
            </div>
            <?php legal_docs_block('Booking Initiated'); ?>
          </div>

          <div class="stage-section" data-stage="KYC Verification">
            <div class="ss-head"><i class="fa-solid fa-id-card ss-status"></i> KYC Verification <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <p style="font-size:11px;color:var(--muted);margin:0 0 10px">Buyer KYC for the Agreement for Sale &amp; registration. Required documents change with the applicant type and whether there's a co-applicant.</p>
            <!-- KYC decision — deliberately loud (colour follows the status, see updateKycPanel() in app.js):
                 Booking Confirmed stays locked until KYC Status = Verified. -->
            <div class="kyc-panel" id="kycPanel" data-status="">
              <div class="kyc-panel-head">
                <i class="fa-solid fa-user-shield"></i>
                <div class="kyc-panel-title">KYC Status <small>Legal team — update this after checking the applicant's details &amp; documents below</small></div>
                <span class="kyc-pill" id="kycPill">Not set</span>
              </div>
              <div class="grid">
                <div class="field"><label>KYC Status <span class="req-star">*</span></label><select name="kyc_status" onchange="onKycStatusChange(this)">
                    <option value="">Not set</option>
                    <option>Pending</option>
                    <option>Submitted</option>
                    <option>Verified</option>
                    <option>Rejected</option>
                  </select></div>
                <div class="field"><label>Verified On</label><input name="kyc_verified_date" type="date" onchange="updateKycPanel()"></div>
                <div class="kyc-panel-msg" id="kycPanelMsg"></div>
              </div>
            </div>
            <div class="grid">
              <div class="field"><label>Applicant Type</label><select name="applicant_type" onchange="refreshLegalGate()">
                  <?php foreach (array_keys(LEGAL_KYC_BY_TYPE) as $t): ?><option><?= htmlspecialchars($t) ?></option><?php endforeach; ?>
                </select></div>
              <div class="field"><label>PAN No.</label><input name="applicant_pan" maxlength="10" placeholder="ABCDE1234F" style="text-transform:uppercase"></div>
              <div class="field"><label>Aadhaar (last 4 digits)</label><input name="applicant_aadhaar_last4" maxlength="4" inputmode="numeric" placeholder="1234"></div>
              <div class="field"><label>Date of Birth</label><input name="applicant_dob" type="date"></div>
              <div class="field"><label>Occupation</label><input name="applicant_occupation"></div>
            </div>
            <div class="field"><label>Address (as per KYC)</label><textarea name="applicant_address" rows="2"></textarea></div>
            <p style="font-size:12px;font-weight:700;margin:12px 0 6px;color:var(--muted)">CO-APPLICANT — leave blank if none</p>
            <div class="grid">
              <div class="field"><label>Co-applicant Name</label><input name="co_applicant_name" oninput="refreshLegalGate()"></div>
              <div class="field"><label>Relation</label><select name="co_applicant_relation">
                  <option value="">Not set</option>
                  <option>Spouse</option>
                  <option>Parent</option>
                  <option>Child</option>
                  <option>Sibling</option>
                  <option>Business Partner</option>
                  <option>Other</option>
                </select></div>
              <div class="field"><label>Co-applicant PAN</label><input name="co_applicant_pan" maxlength="10" placeholder="ABCDE1234F" style="text-transform:uppercase"></div>
              <div class="field"><label>Co-applicant Aadhaar (last 4)</label><input name="co_applicant_aadhaar_last4" maxlength="4" inputmode="numeric" placeholder="1234"></div>
            </div>
            <div class="field"><label>KYC Note</label><textarea name="kyc_note" rows="2" placeholder="e.g. name mismatch on PAN vs Aadhaar, re-submitted…"></textarea></div>
            <?php legal_docs_block('KYC Verification'); ?>
          </div>

          <div class="stage-section" data-stage="Booking Confirmed">
            <div class="ss-head"><i class="fa-solid fa-circle-check ss-status"></i> Booking Confirmed <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <div class="grid">
              <div class="field"><label>Confirmation Date</label><input name="confirmation_date" type="date"></div>
            </div>
            <?php legal_docs_block('Booking Confirmed'); ?>
          </div>

          <div class="stage-section" data-stage="Agreement In Process">
            <div class="ss-head"><i class="fa-solid fa-file-signature ss-status"></i> Agreement In Process <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <div class="grid">
              <div class="field"><label>Draft Shared On</label><input name="draft_agreement_date" type="date"></div>
              <div class="field"><label>Agreement Date</label><input name="agreement_date" type="date"></div>
              <!-- Stamp Duty = 6% of Final Villa Price (editable); GST = 5% of the stamp duty (calculated, read-only,
                   not stored); Registration Fee defaults to ₹50,000 (editable) — see syncAgreementFees() in app.js. -->
              <div class="field"><label>Stamp Duty Amount <span class="amt-tag">Auto 6%</span></label>
                <div class="amt-field">
                  <i class="fa-solid fa-indian-rupee-sign"></i>
                  <input type="text" inputmode="decimal" id="stampDutyDisplay" placeholder="Auto from villa price"
                    oninput="amtSanitize(this);this.dataset.auto='';renderAgreementFeeHints()" onfocus="amtEditMode(this)" onblur="amtFormat(this)">
                  <input type="hidden" name="stamp_duty_amount">
                </div>
                <div class="amt-hint" id="stampDutyHint"></div>
              </div>
              <div class="field"><label>GST on Stamp Duty <span class="amt-tag">Auto 5%</span></label>
                <div class="amt-field">
                  <i class="fa-solid fa-indian-rupee-sign"></i>
                  <input type="text" id="stampGstDisplay" placeholder="5% of stamp duty" readonly data-ro="1" tabindex="-1">
                </div>
                <div class="amt-hint" id="stampGstHint"></div>
              </div>
              <div class="field"><label>Registration Fee <span class="amt-tag">Default ₹50,000</span></label>
                <div class="amt-field">
                  <i class="fa-solid fa-indian-rupee-sign"></i>
                  <input type="text" inputmode="decimal" id="registrationFeeDisplay" placeholder="50,000"
                    oninput="amtSanitize(this);this.dataset.auto='';renderAgreementFeeHints()" onfocus="amtEditMode(this)" onblur="amtFormat(this)">
                  <input type="hidden" name="registration_fee">
                </div>
                <div class="amt-hint" id="registrationFeeHint"></div>
              </div>
            </div>
            <div class="field"><label>Agreement Notes</label><textarea name="agreement_notes"></textarea></div>
            <?php legal_docs_block('Agreement In Process'); ?>
          </div>

          <div class="stage-section" data-stage="Registered">
            <div class="ss-head"><i class="fa-solid fa-stamp ss-status"></i> Registered <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <div class="grid">
              <div class="field"><label>Registration Date</label><input name="registration_date" type="date"></div>
              <div class="field"><label>Document / Registration No.</label><input name="registration_doc_no"></div>
              <div class="field"><label>Sub-Registrar Office</label><input name="sub_registrar_office" placeholder="e.g. Maval (Lonavala)"></div>
            </div>
            <?php legal_docs_block('Registered'); ?>
            <!-- Legal → Accounts handover (filled by renderAccountsHandover() in app.js) -->
            <div id="accountsHandoverBox" class="handover-box"></div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($showAccountsTabs): ?>
        <div id="ct-construction" class="ctab hidden">
          <!-- Two inner tabs (paySubTab() in app.js, data from api/payments.php):
               1) Demand & Payment — villa price + 100% bar, Receive Payment, Construction Status (updating it
                  raises that stage's payment demand), the current demand (Letter / Email / WhatsApp),
                  Other Details and the stage-wise Documents.
               2) Schedule & Payments — Payment Schedule table + Payments Received log (receipts). -->
          <div class="phase-switch pay-subtabs" id="paySubTabs">
            <button type="button" data-sub="demand" class="active" onclick="paySubTab('demand')"><i class="fa-solid fa-file-invoice-dollar"></i> Demand &amp; Payment</button>
            <button type="button" data-sub="schedule" onclick="paySubTab('schedule')"><i class="fa-solid fa-list-check"></i> Schedule &amp; Payments <span class="pay-sub-n" id="paySubCount"></span></button>
          </div>
          <div class="pay-sub" data-sub="schedule" hidden>
            <div id="paySchedule" class="pay-tracker"></div>
          </div>
          <div class="pay-sub" data-sub="demand">
          <div id="payTracker" class="pay-tracker"></div>
          <div class="pay-card">
            <div class="pc-title"><i class="fa-solid fa-circle-info"></i> Other Details</div>
            <div class="grid">
              <div class="field"><label>Home Loan</label><select name="loan">
                  <option>N/A</option>
                  <option>Not Applied</option>
                  <option>Applied</option>
                  <option>Sanctioned</option>
                  <option>Disbursed</option>
                </select></div>
              <div class="field"><label>Loan Bank</label><input name="loan_bank"></div>
              <div class="field"><label>Fit-out Status</label><select name="fitout">
                  <option>Pending</option>
                  <option>In Progress</option>
                  <option>Completed</option>
                </select></div>
            </div>
            <!-- Architect certificate / site photos / signed demand letter — one set per construction stage;
                 the stage strip (payDocsStripHtml in app.js) opens the current stage or any earlier one -->
            <div class="attempt-log compact legal-docs" id="payDocs" data-doc-stage="Construction Customer"></div>
          </div>
          </div><!-- /Demand & Payment -->
        </div>

        <?php if ($isAdmin): ?>
        <div id="ct-possession" class="ctab hidden">
          <div id="chk-possession" class="stage-checklist"></div>
          <!-- Construction status + payment progress bar only, no amounts — payPossessionHtml() in app.js -->
          <div id="payTrackerMini" class="pay-tracker compact"></div>

          <div class="stage-section" data-stage="Possession Due">
            <div class="ss-head"><i class="fa-solid fa-hourglass-half ss-status"></i> Possession Due <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <p class="pay-sec-note">The final 5% is raised from Construction &amp; Payment → Construction Status → "OC / CC Received".</p>
            <div class="grid">
              <div class="field"><label>Expected Possession Date</label><input name="expected_possession_date" type="date"></div>
            </div>
            <?php legal_docs_block('Possession Due'); ?>
          </div>

          <div class="stage-section" data-stage="Possession Offered">
            <div class="ss-head"><i class="fa-solid fa-key ss-status"></i> Possession Offered <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <div class="grid">
              <div class="field"><label>Final Inspection</label><select name="inspection">
                  <option>Pending</option>
                  <option>Done</option>
                </select></div>
              <div class="field"><label>Snagging</label><select name="snagging">
                  <option>Pending</option>
                  <option>In Progress</option>
                  <option>Completed</option>
                </select></div>
              <div class="field"><label>Possession Letter</label><select name="posletter">
                  <option>Pending</option>
                  <option>Issued</option>
                </select></div>
              <div class="field"><label>Possession Date</label><input name="posdate" type="date"></div>
            </div>
            <!-- Keys only after full payment: Handover Completed stays locked until the villa price is fully received -->
            <div class="pay-demand" data-pay-code="__handover"></div>
            <?php legal_docs_block('Possession Offered'); ?>
          </div>

          <div class="stage-section" data-stage="Handover Completed">
            <div class="ss-head"><i class="fa-solid fa-house-circle-check ss-status"></i> Handover Completed <span class="ss-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span></div>
            <div class="grid">
              <div class="field"><label>Handover Date</label><input name="handover" type="date"></div>
              <div class="field"><label>Keys Handed Over</label><select name="keys">
                  <option>No</option>
                  <option>Yes</option>
                </select></div>
              <div class="field"><label>Allotment Date</label><input name="allotment_date" type="date"></div>
            </div>
            <?php legal_docs_block('Handover Completed'); ?>
          </div>
        </div>
        <?php else: ?>
        <!-- Accounts desk: Possession is handled by admin — shown read-only. The real Possession
             sections aren't rendered, so their fields are never submitted from here. -->
        <div id="ct-acctpos" class="ctab hidden"><div class="ps-summary" id="acctPosSummary"></div></div>
        <?php endif; ?>
        <?php endif; /* Legal desk: no Accounts tabs — the real Accounts sections aren't rendered, so their fields are never submitted */ ?>
        <?php elseif (!$isEntryDesk): ?>
        <!-- Salesperson view: read-only Post Sales summary (filled by renderSalesSummaryTabs() in app.js).
             The real Post Sales sections aren't rendered at all, so their fields are never submitted. -->
        <div id="ct-salesbook" class="ctab hidden"><div class="ps-summary" id="salesBookSummary"></div></div>
        <div id="ct-salespos" class="ctab hidden"><div class="ps-summary" id="salesPosSummary"></div></div>
        <?php endif; ?>
        </div><!-- /#clientMidScroll -->

        <div id="nextStepPanel" class="next-step-panel"></div>

        <div class="client-modal-footer">
          <div>
            <button type="button" id="markCancelledBtn" class="btn" style="color:#b42318" onclick="markTerminal('Cancelled')">Mark Cancelled</button>
          </div>
          <div><button type="button" class="btn" onclick="closeModal('clientModal')">Cancel</button> <button class="btn primary">Save Client</button></div>
        </div>
      </form>
    </div>
  </div>

  <!-- Lead Summary overlay — quick read-only view opened by clicking a lead
       row anywhere (Manage Leads, Dashboard recent leads). -->
  <div class="modal" id="leadSummaryModal">
    <div class="modal-box ls-box" style="max-width:760px;width:95%">
      <button class="close ls-close" onclick="closeModal('leadSummaryModal')">×</button>
      <div id="leadSummaryBody"></div>
    </div>
  </div>

  <!-- Followup Modal -->
  <div class="modal" id="followModal">
    <div class="modal-box" style="max-width:560px">
      <div class="modal-head">
        <h2>Add Follow-up</h2><button class="close" onclick="closeModal('followModal')">×</button>
      </div>
      <p style="font-size:12px;color:var(--muted);margin:-8px 0 14px">Only leads at "Requirement Understood" or further are listed.</p>
      <form id="followForm">
        <div class="grid grid-2">
          <div class="field"><label>Client *</label><select name="client" id="fuClient" required onchange="onFuClientChange()"></select></div>
          <div class="field"><label>Follow-up Type</label><select name="type" id="fuType" disabled title="Auto-detected from the lead's current stage">
              <option value="">Auto — based on lead stage</option>
              <option value="After Scheduled Site Visit">Site Visit Follow-up</option>
              <option>Post Site Visit Follow-up</option>
              <option>Re-Visit Follow-up</option>
              <option>Negotiation Visit Follow-up</option>
              <option>Villa Blocked Follow-up</option>
            </select></div>
          <div class="field"><label>Date</label><input name="date" type="date" required></div>
          <div class="field"><label>Mode</label><select name="mode">
              <option>Call</option>
              <option>WhatsApp</option>
              <option>Email</option>
              <option>Meeting</option>
            </select></div>
          <div class="field"><label>Re-follow-up Required?</label><select name="refollow" onchange="updateFuRefollowUI()">
              <option>No</option>
              <option>Yes</option>
            </select></div>
          <div class="field hidden" id="fuRefollowDateWrap"><label>Re-follow-up Date</label><input name="refollow_date" type="date"></div>
        </div>
        <div style="text-align:right"><button class="btn primary">Save Follow-up</button></div>
      </form>
    </div>
  </div>

  <!-- Visit Modal -->
  <div class="modal" id="visitModal">
    <div class="modal-box" style="max-width:700px">
      <div class="modal-head">
        <h2>Schedule Site Visit</h2><button class="close" onclick="closeModal('visitModal')">×</button>
      </div>
      <p style="font-size:12px;color:var(--muted);margin:-8px 0 14px">For scheduling an upcoming visit. Only leads at "Requirement Understood" or further are listed. The visit type follows the lead's current stage. Villa shown, outcome and feedback are filled in afterwards from the lead's own Site Visit Completed step.</p>
      <form id="visitForm">
        <div class="grid grid-2">
          <div class="field"><label>Client *</label><select name="client" id="visitClient" required onchange="onVisitClientChange()"></select></div>
          <div class="field"><label>Visit Date</label><input name="date" type="date" required></div>
          <div class="field"><label>Visit Time</label><input name="time" type="time"></div>
          <div class="field"><label>No. of Visitors</label><input name="visitors" type="number" min="1" value="2"></div>
          <div class="field"><label>Visit Type</label><select name="kind" id="visitKind" disabled title="Auto-detected from the lead's current stage">
              <option value="">Auto — based on lead stage</option>
              <option value="site">Site Visit</option>
              <option value="revisit">Re-Visit</option>
              <option value="negotiation">Negotiation Visit</option>
            </select></div>
        </div>
        <div style="text-align:right"><button class="btn primary">Schedule Visit</button></div>
      </form>
    </div>
  </div>

  <div class="photo-viewer hidden" id="photoViewer" onclick="if(event.target===this) closePhotoViewer()">
    <div class="viewer-inner">
      <img id="viewerImg" alt="Profile photo">
      <button type="button" class="viewer-edit" onclick="openEditFromViewer()" title="Edit photo"><i class="fa-solid fa-pen"></i></button>
    </div>
  </div>

  <div class="crop-overlay hidden" id="cropOverlay">
    <div class="crop-box">
      <div class="crop-head">
        <h3>Adjust your photo</h3>
        <div class="crop-toolbar">
          <button type="button" onclick="document.getElementById('avatarInput').click()" title="Add / change photo"><i class="fa-solid fa-image"></i></button>
          <button type="button" class="crop-delete<?= $currentPhoto ? '' : ' hidden' ?>" id="cropDelete" onclick="deleteAvatar(event)" title="Delete photo"><i class="fa-solid fa-trash"></i></button>
        </div>
      </div>
      <div class="crop-square" id="cropCircle">
        <img id="cropImg" alt="">
        <div class="crop-mask"></div>
      </div>
      <div class="crop-controls">
        <i class="fa-solid fa-magnifying-glass-minus"></i>
        <input type="range" id="cropZoom" min="100" max="300" value="100">
        <i class="fa-solid fa-magnifying-glass-plus"></i>
      </div>
      <div class="crop-actions">
        <button type="button" class="btn" onclick="closeCropper()">Cancel</button>
        <button type="button" class="btn primary" onclick="saveCroppedAvatar()"><i class="fa-solid fa-check"></i> Save</button>
      </div>
    </div>
  </div>

  <input type="file" id="legalDocInput" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" style="display:none" onchange="uploadLegalDoc(this)">
  <script>
    window.SITE_LOCATION = <?= json_encode(VISIT_LOCATION) ?>;
    window.LEGAL_DOCS = <?= json_encode(legal_docs_js_config()) ?>;
    window.PAY_CONFIG = <?= json_encode(pay_js_config()) ?>;
    window.CURRENT_USER = {
      id: <?= (int)$_SESSION['user_id'] ?>,
      name: <?= json_encode($_SESSION['name']) ?>,
      role: <?= json_encode($_SESSION['role']) ?>,
      desk: <?= json_encode($_SESSION['desk'] ?? null) ?>,
      photo: <?= json_encode($_SESSION['photo'] ?? null) ?>
    };
  </script>
  <script src="assets/js/app.js"></script>
  <script src="assets/js/responsive.js"></script>
</body>

</html>