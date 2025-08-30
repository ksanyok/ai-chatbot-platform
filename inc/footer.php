</main>
<?php
// Load current application version and check for updates
// The version is defined in inc/version.php.  We also attempt to fetch
// the remote version from the GitHub repository to detect if a newer
// release is available.  If a newer version exists, a link will appear
// in the footer inviting the administrator to run the update script.
include_once __DIR__ . '/inc/version.php';
$localVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
$latestVersion = $localVersion;
$updateAvailable = false;
try {
    // Fetch remote version file from GitHub
    $remoteContent = @file_get_contents('https://raw.githubusercontent.com/ksanyok/ai-chatbot-platform/main/inc/version.php');
    if ($remoteContent && preg_match("/APP_VERSION\s*=\s*['\"]([\d\.]+)['\"]/i", $remoteContent, $m)) {
        $latestVersion = $m[1];
        $updateAvailable = version_compare($latestVersion, $localVersion, '>');
    }
} catch (Exception $e) {
    // Fail silently if unable to fetch remote version
}
?>
<footer id="app-footer" class="mt-12 relative overflow-hidden text-white/90">
  <!-- Background layer: soft gradient + subtle grid -->
  <div class="absolute inset-0 -z-10 bg-gradient-to-br from-indigo-900/40 via-purple-900/30 to-fuchsia-900/30"></div>
  <div class="absolute inset-0 -z-10 opacity-[0.08]" style="background-image:radial-gradient(1px 1px at 10px 10px, #fff 1px, transparent 1px);background-size:22px 22px"></div>

  <div class="container mx-auto px-4 py-8">
    <div class="grid gap-8 md:grid-cols-[auto,1fr,auto] items-center">

      <!-- Brand / Animated logo -->
      <div class="flex items-center gap-4">
        <div class="relative w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-700 shadow-lg shadow-emerald-900/40 grid place-items-center">
          <span class="font-black text-lg tracking-tight">BRS</span>
          <span class="absolute inset-0 rounded-xl ring-1 ring-white/10"></span>
        </div>
        <div class="leading-tight">
          <div class="font-mono text-xl sm:text-2xl" id="brsType" aria-label="BuyReadySite"></div>
        </div>
      </div>

      <!-- Center: version + credits -->
      <div class="text-center md:text-left">
        <!-- Bump version when releasing new updates -->
        <p class="text-sm opacity-80">
          v<?= htmlspecialchars($localVersion) ?> • Developed by
          <a href="https://BuyReadySite.com" class="underline decoration-emerald-400/50 hover:text-emerald-300" target="_blank" rel="noopener">BuyReadySite.com</a>
          <?php if ($updateAvailable): ?>
            <span class="ml-2 text-xs text-emerald-300">
              <a href="/update.php" class="underline decoration-emerald-400/50 hover:text-emerald-200" title="Новая версия доступна">Обновить до v<?= htmlspecialchars($latestVersion) ?></a>
            </span>
          <?php endif; ?>
        </p>
        <p class="text-xs opacity-70"><?= htmlspecialchars(t('footer.updated')) ?> 28 июля 2025</p>
      </div>

      <!-- Plugins promo -->
      <div class="md:justify-self-end">
        <div class="flex flex-wrap gap-2 text-[13px]">
          <a href="https://aiseo.buyreadysite.com/" target="_blank" rel="noopener" class="group inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-white/5 border border-white/10 hover:bg-white/10 hover:border-emerald-400/40 transition">
            <span class="w-2 h-2 rounded-full bg-emerald-400 group-hover:animate-ping"></span>
            <span class="font-medium">AI SEO AutoOptimize Pro</span>
          </a>
          <a href="https://aiwizard.buyreadysite.com/" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-white/5 border border-white/10 hover:bg-white/10 transition">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
            <span>AI Content Wizard</span>
          </a>
          <a href="/support" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-white/5 border border-white/10 hover:bg-white/10 transition">
            <i class="fa-regular fa-life-ring"></i>
            <span><?= htmlspecialchars(t('footer.support')) ?></span>
          </a>
        </div>
      </div>
    </div>

    <!-- bottom line -->
    <div class="mt-6 pt-6 border-t border-white/10 text-xs text-white/60 flex flex-wrap items-center gap-3 justify-between">
      <div>© <?= date('Y') ?> BuyReadySite — All rights reserved.</div>
      <div class="opacity-80">Made with ❤ for WordPress developers</div>
    </div>
  </div>

  <!-- Animated typing: subtle "matrix" decode effect for BuyReadySite -->
  <style>
    #brsType{min-height:1.4em; letter-spacing:.06em; background:linear-gradient(90deg,#baf7d0,#7fffd4,#baf7d0); -webkit-background-clip:text; background-clip:text; color:transparent; text-shadow:0 0 8px rgba(16,185,129,.25)}
    #brsType::after{content:"|"; margin-left:2px; opacity:.7; animation:blink 1.2s steps(1) infinite}
    @keyframes blink{50%{opacity:0}}
    @media (prefers-reduced-motion: reduce){#brsType::after{animation:none; opacity:.6}}
  </style>
  <script>
    (function(){
      const el = document.getElementById('brsType'); if(!el) return;
      const target = (el.getAttribute('aria-label')||'BuyReadySite');
      const glyphs = '01▮░▒▓█ABCDEFGHJKLMNPQRSTUVWXYZ';
      const STEP_MS = 140;     // typing speed per char
      const PAUSE_MS = 5200;   // pause before repeating
      let reveal = -1, timer = null;

      function frame(){
        reveal++;
        if (reveal >= target.length) {
          el.textContent = target; // show clean text
          clearInterval(timer);
          // soft pause, then restart
          setTimeout(start, PAUSE_MS);
          return;
        }
        let out = '';
        for (let i=0;i<target.length;i++){
          out += (i <= reveal) ? target[i] : glyphs[(Math.random()*glyphs.length)|0];
        }
        el.textContent = out;
      }

      function start(){
        // respect reduced-motion
        try {
          if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            el.textContent = target; return;
          }
        } catch(e){}
        reveal = -1;
        if (timer) clearInterval(timer);
        timer = setInterval(frame, STEP_MS);
      }

      start();
    })();
  </script>
</footer>
</body>
</html>