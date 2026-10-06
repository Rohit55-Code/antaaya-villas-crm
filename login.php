<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (!empty($_SESSION['user_id'])) {
  header('Location: index.php');
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#0b1712">
  <title>Login — Antaaya Villas CRM</title>
  <link rel="icon" type="image/png" href="image/AVL White SVG .svg">
  <link rel="apple-touch-icon" sizes="180x180" href="image/AVL White SVG .svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;1,600&family=Montserrat:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      font-family: 'Montserrat', Inter, Segoe UI, Arial, sans-serif;
      background: #0d0e0d;
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      position: relative;
      overflow: hidden;
    }

    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background-image: url('image/last-page.jpg');
      background-size: cover;
      background-position: center;
      transform: scale(1.06);
      z-index: 0;
    }

    body::after {
      content: '';
      position: fixed;
      inset: 0;
      background:
        radial-gradient(120% 90% at 50% 100%, rgba(0, 0, 0, .88) 0%, rgba(0, 0, 0, .55) 45%, rgba(0, 0, 0, .25) 100%),
        linear-gradient(180deg, rgba(6, 12, 9, .75) 0%, rgba(6, 12, 9, .35) 40%, rgba(6, 12, 9, .82) 100%);
      z-index: 0;
    }

    .box {
      position: relative;
      background: rgba(255, 255, 255, .07);
      backdrop-filter: blur(22px) saturate(160%);
      -webkit-backdrop-filter: blur(22px) saturate(160%);
      border: 1px solid rgba(255, 255, 255, .18);
      border-radius: 18px;
      box-shadow: 0 20px 60px rgba(0, 0, 0, .45), inset 0 1px 0 rgba(255, 255, 255, .15);
      padding: 40px 34px;
      width: 100%;
      max-width: 370px;
      z-index: 1;
    }

    h1 {
      font-family: 'Cormorant Garamond', serif;
      font-style: none;
      font-weight: 600;
      text-align: center;
      font-size: 28px;
      letter-spacing: .3px;
      margin: 0 0 4px;
      color: #fff;
    }

    .brand-mark {
      height: 45px;
      width: auto;
      vertical-align: middle;
      margin-left: 6px;
      position: relative;
      top: -2px;
    }

    p {
      text-align: center;
      color: rgba(255, 255, 255, .65);
      font-size: 12px;
      letter-spacing: .5px;
      text-transform: uppercase;
      margin: 0 0 26px
    }

    .field {
      margin-bottom: 16px
    }

    label {
      display: block;
      font-size: 11px;
      font-weight: 600;
      letter-spacing: .4px;
      text-transform: uppercase;
      margin-bottom: 6px;
      color: rgba(255, 255, 255, .75)
    }

    input {
      width: 100%;
      padding: 8px 13px;
      background: rgba(255, 255, 255, .08);
      border: 1px solid rgba(255, 255, 255, .2);
      color: #fff;
      outline: none !important;
      border-radius: 8px;
      font-size: 14px;
      font-family: inherit;
      box-sizing: border-box;
      transition: border-color .15s, background .15s;
    }

    input::placeholder {
      color: rgba(255, 255, 255, .35)
    }

    input:focus {
      background: rgba(255, 255, 255, .13);
      border-color: #8dc63f;
    }

    button {
      width: 100%;
      padding: 12px;
      margin-top: 6px;
      background: linear-gradient(135deg, #8dc63f 0%, #76ca09 100%);
      color: #0d1a08;
      border: 0;
      outline: none !important;
      border-radius: 8px;
      font-weight: 700;
      letter-spacing: .3px;
      cursor: pointer;
      font-size: 14px;
      box-shadow: 0 8px 20px rgba(118, 202, 9, .35);
      transition: transform .12s, box-shadow .12s;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }

    button:hover {
      transform: translateY(-1px);
    }

    .err {
      color: #ff8a80;
      background: rgba(180, 35, 24, .18);
      border: 1px solid rgba(255, 138, 128, .3);
      padding: 8px 10px;
      border-radius: 6px;
      font-size: 12px;
      margin-bottom: 14px;
      display: none
    }

    .pwd-wrap {
      position: relative
    }

    .pwd-wrap input {
      padding-right: 44px
    }

    .pwd-toggle {
      position: absolute;
      right: 8px;
      top: 0;
      bottom: 0;
      margin: auto 0;
      width: 26px;
      height: 26px;
      padding: 0;
      background: transparent !important;
      border: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      color: rgba(255, 255, 255, .55);
      box-shadow: none;
      transition: color .15s;
    }

    .pwd-toggle:hover {
      transform: none !important;
      color: rgba(100, 100, 100, 0.74);
    }
  </style>
  <link rel="stylesheet" href="assets/css/responsive.css">
</head>

<body class="login-page">
  <div class="box">
    <h1><img src="image/Akruti White Background Logo.svg" alt="Antaaya Villas" class="brand-mark">  | <img src="image/Antaaya Villa Lonavala Logo.png" alt="Antaaya Villas" class="brand-mark"></h1>
    <p><i class="fa-solid fa-users-gear"></i> Sales CRM</p>
    <div class="err" id="err"></div>
    <form id="loginForm">
      <div class="field"><label>Email</label><input name="email" type="email" required></div>
      <div class="field">
        <label>Password</label>
        <div class="pwd-wrap">
          <input name="password" type="password" required id="loginPassword">
          <button type="button" class="pwd-toggle" onclick="togglePwd('loginPassword', this)" aria-label="Show password">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z" />
              <circle cx="12" cy="12" r="3" />
            </svg>
          </button>
        </div>
      </div>
      <button type="submit"><i class="fa-solid fa-right-to-bracket"></i> Log In</button>
    </form>
  </div>
  <script>
    const EYE_OPEN = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>';
    const EYE_CLOSED = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a20.3 20.3 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a20.5 20.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    function togglePwd(inputId, btn) {
      const input = document.getElementById(inputId);
      const showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      btn.innerHTML = showing ? EYE_OPEN : EYE_CLOSED;
      btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    }
    document.getElementById('loginForm').onsubmit = async (e) => {
      e.preventDefault();
      const f = new FormData(e.target);
      const res = await fetch('api/login.php', {
        method: 'POST',
        body: JSON.stringify({
          email: f.get('email'),
          password: f.get('password')
        })
      });
      const data = await res.json();
      const err = document.getElementById('err');
      if (data.success) {
        window.location.href = 'index.php';
      } else {
        err.textContent = data.error || 'Login failed';
        err.style.display = 'block';
      }
    };
  </script>
</body>

</html>