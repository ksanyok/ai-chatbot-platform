<?php
require_once __DIR__.'/../inc/auth.php';
// Lightweight i18n for login (session only until user authenticates)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['ui_lang'])) {
    $_SESSION['ui_lang'] = in_array($_POST['ui_lang'], ['en','ru'], true) ? $_POST['ui_lang'] : 'en';
}
$ui_lang = $_SESSION['ui_lang'] ?? 'en';
// Expanded localization dictionary
$L = [
  'en' => [
    'title' => 'Sign in • Chatbot Admin',
    'heading' => 'Sign in',
    'subheading' => 'Sign in to continue',
    'email' => 'Email',
    'password' => 'Password',
    'submit' => 'Sign in',
    'language' => 'Language',
    'en' => 'English',
    'ru' => 'Russian',
    'remember' => 'Remember me',
    'forgot' => 'Forgot password?',
    'terms' => 'Terms',
    'privacy' => 'Privacy',
    'legal.notice' => 'By continuing, you agree to the {terms} & {privacy}.',
    'promo.title' => 'Chatbot Admin',
    'promo.desc' => 'AI console for bot training, conversation history, and Telegram / Meta integrations. No fluff — just the tools you need.',
    'error.bad' => 'Invalid email or password'
  ],
  'ru' => [
    'title' => 'Вход • Панель Чат-бота',
    'heading' => 'Вход',
    'subheading' => 'Войди, чтобы продолжить',
    'email' => 'Email',
    'password' => 'Пароль',
    'submit' => 'Войти',
    'language' => 'Язык',
    'en' => 'Английский',
    'ru' => 'Русский',
    'remember' => 'Запомнить меня',
    'forgot' => 'Забыли пароль?',
    'terms' => 'Условия',
    'privacy' => 'Конфиденциальность',
    'legal.notice' => 'Продолжая, вы соглашаетесь с {terms} и {privacy}.',
    'promo.title' => 'Chatbot Admin',
    'promo.desc' => 'AI‑панель для обучения бота, истории диалогов и интеграций Telegram / Meta. Без лишнего шума — только нужные инструменты.',
    'error.bad' => 'Неверный логин или пароль'
  ]
];
function L($k){global $L,$ui_lang; return $L[$ui_lang][$k] ?? ($L['en'][$k] ?? $k);} 

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $ok = login($_POST['email']??'', $_POST['password']??'');
    if ($ok) { header('Location: /admin/dashboard.php'); exit; }
    $error = L('error.bad');
}
?>
<!doctype html>
<html lang="<?=htmlspecialchars($ui_lang)?>">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars(L('title')) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    theme: {
      extend: {
        fontFamily: { sans: ['Inter','ui-sans-serif','system-ui','Segoe UI','Roboto','Helvetica Neue','Arial','Noto Sans','Apple Color Emoji','Segoe UI Emoji'] }
      }
    }
  };
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  .glass{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);box-shadow:0 10px 30px rgba(0,0,0,.3)}
</style>
</head>
<body class="min-h-screen text-gray-100 bg-gradient-to-br from-slate-900 via-slate-950 to-slate-900 font-sans">
  <div class="min-h-screen grid md:grid-cols-2">
    <!-- Left promo side -->
    <section class="hidden md:flex relative items-center justify-center p-12 overflow-hidden">
      <div class="absolute -top-20 -left-20 h-96 w-96 rounded-full bg-indigo-600/30 blur-3xl"></div>
      <div class="absolute -bottom-24 -right-24 h-[28rem] w-[28rem] rounded-full bg-purple-600/20 blur-3xl"></div>
      <div class="relative z-10 max-w-md">
        <div class="flex items-center space-x-3 mb-4">
          <div class="h-12 w-12 rounded-xl bg-white/10 flex items-center justify-center">
            <span class="text-2xl">🤖</span>
          </div>
          <h1 class="text-3xl font-bold"><?= htmlspecialchars(L('promo.title')) ?></h1>
        </div>
        <p class="text-slate-300 text-lg leading-relaxed">
          <?= htmlspecialchars(L('promo.desc')) ?>
        </p>
      </div>
    </section>

    <!-- Right auth side -->
    <section class="flex items-center justify-center p-6 md:p-12">
      <form method="post" class="glass rounded-2xl p-8 w-full max-w-md">
        <div class="flex items-center justify-between mb-6">
          <div>
            <h2 class="text-2xl font-bold"><?= htmlspecialchars(L('heading')) ?></h2>
            <p class="text-sm text-slate-300 mt-1"><?= htmlspecialchars(L('subheading')) ?></p>
          </div>
          <div>
            <select name="ui_lang" onchange="this.form.submit()" class="bg-gray-800/70 border border-white/10 text-sm rounded px-2 py-1 focus:outline-none focus:ring-2 focus:ring-indigo-500">
              <option value="en" <?= $ui_lang==='en'?'selected':'' ?>><?= htmlspecialchars(L('en')) ?></option>
              <option value="ru" <?= $ui_lang==='ru'?'selected':'' ?>><?= htmlspecialchars(L('ru')) ?></option>
            </select>
          </div>
        </div>

        <?php if (!empty($error)): ?>
          <div class="mb-4 text-red-400 text-sm flex items-start space-x-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 flex-shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.72-1.36 3.485 0l6.518 11.595c.75 1.336-.213 3.006-1.742 3.006H3.48c-1.53 0-2.492-1.67-1.743-3.006L8.257 3.1zM11 14a1 1 0 10-2 0 1 1 0 002 0zm-1-2a1 1 0 01-1-1V8a1 1 0 112 0v3a1 1 0 01-1 1z" clip-rule="evenodd"/></svg>
            <span><?= htmlspecialchars(L('error.bad')) ?></span>
          </div>
        <?php endif; ?>

        <label class="block text-sm mb-1" for="email"><?= htmlspecialchars(L('email')) ?></label>
        <input id="email" name="email" type="email" autocomplete="email" placeholder="<?= htmlspecialchars(L('email')) ?>" required class="w-full mb-4 p-3 bg-gray-800/60 border border-white/10 rounded focus:ring-2 focus:ring-indigo-500">

        <label class="block text-sm mb-1" for="password"><?= htmlspecialchars(L('password')) ?></label>
        <div class="relative mb-6">
          <input id="password" name="password" type="password" autocomplete="current-password" placeholder="<?= htmlspecialchars(L('password')) ?>" required class="w-full p-3 pr-12 bg-gray-800/60 border border-white/10 rounded focus:ring-2 focus:ring-indigo-500">
          <button type="button" id="pwToggle" class="absolute inset-y-0 right-0 px-3 text-slate-300 hover:text-white" aria-label="toggle password">👁️</button>
        </div>

        <div class="flex items-center justify-between mb-6 text-sm">
          <label class="inline-flex items-center space-x-2">
            <input type="checkbox" name="remember" class="h-4 w-4 rounded border-white/10 bg-gray-800/60">
            <span><?= htmlspecialchars(L('remember')) ?></span>
          </label>
          <a href="#" class="text-indigo-400 hover:text-indigo-300"><?= htmlspecialchars(L('forgot')) ?></a>
        </div>

        <button id="submitBtn" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3 rounded transition flex items-center justify-center">
          <svg id="btnSpinner" class="hidden animate-spin h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle class="opacity-25" cx="12" cy="12" r="10" stroke-width="4"/><path class="opacity-75" d="M4 12a8 8 0 018-8" stroke-width="4" stroke-linecap="round"/></svg>
          <?= htmlspecialchars(L('submit')) ?>
        </button>

        <p class="text-xs text-slate-400 mt-4">
          <?= str_replace(
                ['{terms}','{privacy}'],
                [
                  '<a href="/terms" class="underline hover:no-underline">'.htmlspecialchars(L('terms')).'</a>',
                  '<a href="/privacy" class="underline hover:no-underline">'.htmlspecialchars(L('privacy')).'</a>'
                ],
                L('legal.notice')
          ) ?>
        </p>
      </form>
    </section>
  </div>

  <script>
    // toggle password visibility
    (function(){
      var t=document.getElementById('pwToggle');
      var p=document.getElementById('password');
      if(t&&p){t.addEventListener('click',function(){p.type = p.type==='password'?'text':'password';});}
    })();
    // submit button spinner
    (function(){
      var f=document.querySelector('form[method="post"]');
      if(!f) return;
      var b=document.getElementById('submitBtn');
      var s=document.getElementById('btnSpinner');
      f.addEventListener('submit',function(){ if(b){b.setAttribute('disabled','disabled');} if(s){s.classList.remove('hidden');} });
    })();
  </script>
</body>
</html>