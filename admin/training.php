<?php
require_once __DIR__.'/../inc/auth.php';
require_login();
require_once __DIR__.'/../inc/header.php';

// Подсчёт стоимости обучения
$stmt = db()->query("SELECT SUM(total_cost) AS cost FROM trainings WHERE status='running'");
$running = $stmt->fetch();
$cost = isset($running['cost']) ? $running['cost'] : 0;
?>
<div class="container mx-auto py-6 max-w-5xl">
    <h2 class="text-2xl font-bold mb-4 text-indigo-400"><?= htmlspecialchars(t('training.title')) ?></h2>
    <p class="mb-4 text-gray-400"><?= htmlspecialchars(t('training.cost')) ?>: $<?=number_format($cost, 2)?></p>
    <!-- Встраиваем форму из scripts/ingest.php -->
    <?php
    // Embedded ingest UI with endpoint awareness
    if (!defined('EMBEDDED_INGEST')) define('EMBEDDED_INGEST', true);
    $endpoint = '';

    // Ensure Composer autoloader is available for embedded scripts (robust search)
    $autoloadPaths = [];
    $base = __DIR__;
    for ($i = 0; $i < 7; $i++) {
        $autoloadPaths[] = $base . str_repeat('/..', $i) . '/vendor/autoload.php';
    }
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $autoloadPaths[] = rtrim(realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT'], '/') . '/vendor/autoload.php';
    }
    $autoloadPaths[] = __DIR__ . '/../vendor/autoload.php';
    $autoloadFound = false;
    foreach ($autoloadPaths as $p) {
        if (!$p) continue;
        $p = str_replace('/./', '/', $p);
        $rp = realpath($p) ?: $p;
        if (file_exists($rp)) {
            @require_once $rp;
            $autoloadFound = true;
            break;
        }
    }
    // Last-resort: try one level up from project (handles some chroot setups)
    if (!$autoloadFound) {
        $maybe = dirname(__DIR__, 1) . '/vendor/autoload.php';
        if (file_exists($maybe)) { @require_once $maybe; $autoloadFound = true; }
    }
    // Log diagnostic info for admins (non-fatal)
    @file_put_contents(__DIR__ . '/../ingest.log', '[' . date('c') . '] admin/training.php autoload_found=' . ($autoloadFound ? '1' : '0') . '; tried=' . json_encode($autoloadPaths) . PHP_EOL, FILE_APPEND);

    if (file_exists(__DIR__.'/../scripts/ingest.php')) {
        $endpoint = dirname($_SERVER['SCRIPT_NAME']).'/../scripts/ingest.php';
        if (!defined('INGEST_ENDPOINT')) define('INGEST_ENDPOINT', $endpoint);
        include __DIR__.'/../scripts/ingest.php';
    } elseif (file_exists(__DIR__.'/../ingest.php')) {
        $endpoint = dirname($_SERVER['SCRIPT_NAME']).'/../ingest.php';
        if (!defined('INGEST_ENDPOINT')) define('INGEST_ENDPOINT', $endpoint);
        include __DIR__.'/../ingest.php';
    } else {
        echo '<div class="text-red-400">Ingest script not found.</div>';
    }
    ?>
</div>
<script>
  function preview(){
    fetch(ENDPOINT, {method:'POST', body: fd({ajax:'1', action:'preview', urls: elUrls.value})})
      .then(r=>r.text()).then(txt=>{ let d; try{ d=JSON.parse(txt); } catch(e){ console.error('Preview JSON parse failed:', txt); alert('Server returned invalid response for preview. See console for details.'); return; }
        if(!d||!d.ok) return;
        rawList = d.list||[]; totalRaw = d.total||rawList.length;
        previewInfo.classList.remove('hidden');
        previewInfo.textContent = `${d.total||rawList.length} URLs found`;
        toStep2.classList.remove('opacity-60','cursor-not-allowed');
        wrapPrev.classList.remove('hidden');
        recount(applyFilters(rawList));
      });
  }

  function poll(tid){
    fetch(ENDPOINT + '?progress=1&training_id=' + encodeURIComponent(tid))
      .then(r=>r.text()).then(txt=>{ let d; try{ d=JSON.parse(txt); } catch(e){ console.error('Progress JSON parse failed:', txt); alert('Server returned invalid response for progress. See console for details.'); return; }
        if(!d||!d.status) return;
        const pct = d.total_pages ? (d.processed_pages/d.total_pages*100) : 0;
        if (bar) bar.style.width = pct.toFixed(1) + '%';
        if (progTxt) progTxt.textContent = `${d.processed_pages||0} <?= htmlspecialchars(t('of')) ?> ${d.total_pages||0} (${pct.toFixed(1)}%)`;
        if(d.status==='running') setTimeout(()=>poll(tid), 3000);
      });
  }

  function start(){
    const mode = (document.querySelector('input[name="procMode]:checked')||{value:'smart'}).value;
    progWrap && progWrap.classList.remove('hidden');
    fetch(ENDPOINT, {method:'POST', body: fd({ajax:'1', action:'start', urls: elUrls.value, exclusions: elExcl.value, mode})})
      .then(r=>r.text()).then(txt=>{ let d; try{ d=JSON.parse(txt); } catch(e){ console.error('Start JSON parse failed:', txt); alert('Server returned invalid response when starting training. See console for details.'); return; } if(d&&d.ok&&d.tid){ poll(d.tid); refreshStats(); } });
  }

  function refreshStats(){
    fetch(ENDPOINT, {method:'POST', body: fd({ajax:'1', action:'stats'})})
      .then(r=>r.text())
      .then(txt=>{ let d; try { d = JSON.parse(txt); } catch(e){ console.error('Stats JSON parse failed:', txt); alert('Server returned invalid response for stats. See console for details.'); return; }
        if(!d||!d.ok) return; const rows=d.rows||[];
        statsBody.innerHTML = rows.map(x=>`<tr>
            <td class="py-2 pr-4">${x.url||'—'}</td>
            <td class="py-2 pr-4 text-right">${x.trainings||0}</td>
            <td class="py-2 pr-4 text-right">${x.total_pages||0}</td>
            <td class="py-2 pr-4 text-right">${x.processed||0}</td>
            <td class="py-2 text-left">${x.last_trained||'—'}</td>
          </tr>`).join('');
      });
  }

  function loadSummary(){
    fetch(ENDPOINT, {method:'POST', body: fd({ajax:'1', action:'summary'})})
      .then(r=>r.text())
      .then(txt=>{ let d; try { d = JSON.parse(txt); } catch(e){ console.error('Summary JSON parse failed:', txt); alert('Server returned invalid response for summary. See console for details.'); return; }
        if(!d||!d.ok) return; sumSites.textContent=d.sites||0; sumPages.textContent=d.trained||0; sumRunning.textContent=d.running||0; });
  }
</script>
<?php require_once __DIR__.'/../inc/footer.php'; ?>