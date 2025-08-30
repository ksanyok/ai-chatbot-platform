<?php
ob_start();
require_once __DIR__ . '/../inc/header.php';
$pdo = db();

// ---------- helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tt($key, $fallback){
  if (function_exists('t')) { $v = t($key); if ($v !== $key && $v !== null && $v !== '') return $v; }
  return $fallback;
}

// ---------- schema ensure ----------
$pdo->exec("CREATE TABLE IF NOT EXISTS history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  question TEXT NOT NULL,
  answer TEXT NOT NULL,
  channel VARCHAR(32) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at),
  KEY idx_channel_created (channel, created_at),
  KEY idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// ---------- ajax api ----------
if (($_POST['ajax'] ?? '') === '1') {
  ob_end_clean();
  header('Content-Type: application/json; charset=utf-8');
  $action = $_POST['action'] ?? '';

  // Clear history (with optional filters)
  if ($action === 'clear') {
    $channel = $_POST['channel'] ?? 'all';
    $from = $_POST['from'] ?? '';
    $to   = $_POST['to'] ?? '';
    $where = [];$args = [];
    if ($channel !== 'all') { $where[] = 'channel = ?'; $args[] = $channel; }
    if ($from) { $where[] = 'created_at >= ?'; $args[] = date('Y-m-d 00:00:00', strtotime($from)); }
    if ($to)   { $where[] = 'created_at <= ?'; $args[] = date('Y-m-d 23:59:59', strtotime($to)); }
    $sql = 'DELETE FROM history' . ($where?(' WHERE '.implode(' AND ',$where)) : '');
    $st = $pdo->prepare($sql); $st->execute($args);
    echo json_encode(['ok'=>true,'deleted'=>$st->rowCount()]);
    exit;
  }

  // List sessions
  if ($action === 'list') {
    $channel = $_POST['channel'] ?? 'all';
    $term = trim($_POST['term'] ?? '');
    $from = $_POST['from'] ?? '';
    $to   = $_POST['to'] ?? '';
    $page = max(1,(int)($_POST['page'] ?? 1));
    $per  = min(60, max(12,(int)($_POST['per'] ?? 24)));

    // --- Dynamic column detection (handles custom schemas) ---
    $rows = [];
    $cols = [];
    try { $cols = $pdo->query('SHOW COLUMNS FROM `history`')->fetchAll(PDO::FETCH_COLUMN, 0); } catch (Throwable $e) { $cols = []; }
    $pick = function(array $opts, array $cols){ foreach ($opts as $o) { if (in_array($o, $cols, true)) return $o; } return null; };

    $uf = $pick(['user_id','uid','sender_id','chat_id','from_id','user'], $cols);
    $qf = $pick(['question','request','user_message','message','msg','prompt','text'], $cols);
    $af = $pick(['answer','response','bot_reply','reply','output'], $cols);
    $tf = $pick(['created_at','created','date','timestamp','sent_at','time'], $cols);
    $cf = $pick(['channel','source','driver','platform'], $cols);
    $hasId = in_array('id', $cols, true);
    $debug = ['cols'=>$cols, 'mapped'=>['user'=>$uf,'q'=>$qf,'a'=>$af,'t'=>$tf,'c'=>$cf]];

    // If we couldn't detect mandatory fields, bail with empty list
    if ($uf && $qf && $af && $tf) {
      $w = [];$a2 = [];
      if ($channel !== 'all' && $cf) { $w[] = "`$cf` = ?"; $a2[] = $channel; }
      if ($from) { $w[] = "`$tf` >= ?"; $a2[] = date('Y-m-d 00:00:00', strtotime($from)); }
      if ($to)   { $w[] = "`$tf` <= ?"; $a2[] = date('Y-m-d 23:59:59', strtotime($to)); }
      if ($term !== '') { $w[] = "(`$qf` LIKE ? OR `$af` LIKE ?)"; $a2[]='%'.$term.'%'; $a2[]='%'.$term.'%'; }

      $sql = "SELECT "
           . "`$uf` AS user_id, "
           . "`$qf` AS question, "
           . "`$af` AS answer, "
           . "`$tf` AS created_at, "
           . ($cf ? "COALESCE(`$cf`, '')" : "''") . " AS channel "
           . "FROM `history`"
           . ($w ? (' WHERE '.implode(' AND ', $w)) : '')
           . " ORDER BY `$uf` ASC, `$tf` ASC" . ($hasId ? ", id ASC" : "");
      try { $st=$pdo->prepare($sql); $st->execute($a2); $rows=$st->fetchAll(PDO::FETCH_ASSOC);} catch(Throwable $e){ $rows=[]; }
      $debug['sql_main']=$sql; $debug['rows_main']=count($rows);

      if (!$rows) {
        // Absolute fallback without WHERE
        $sql = "SELECT "
             . "`$uf` AS user_id, "
             . "`$qf` AS question, "
             . "`$af` AS answer, "
             . "`$tf` AS created_at, "
             . ($cf ? "COALESCE(`$cf`, '')" : "''") . " AS channel "
             . "FROM `history` ORDER BY " . ($hasId ? "id ASC" : "`$tf` ASC");
        try { $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e) { $rows = []; }
        $debug['sql_fallback']=$sql; $debug['rows_fallback']=count($rows);
      }
    } else {
      // Last resort: try to pull something and map keys heuristically
      try { $probe = $pdo->query('SELECT * FROM `history` LIMIT 50'); $rows = $probe ? $probe->fetchAll(PDO::FETCH_ASSOC) : []; } catch (Throwable $e) { $rows = []; }
      // Attempt to rename keys to expected ones if possible
      if ($rows) {
        foreach ($rows as &$r) {
          $r['user_id'] = $r['user_id'] ?? ($r['uid'] ?? ($r['sender_id'] ?? ($r['chat_id'] ?? ($r['from_id'] ?? ($r['user'] ?? 0)))));
          $r['question'] = $r['question'] ?? ($r['request'] ?? ($r['user_message'] ?? ($r['message'] ?? ($r['msg'] ?? ($r['prompt'] ?? ($r['text'] ?? ''))))));
          $r['answer']   = $r['answer']   ?? ($r['response'] ?? ($r['bot_reply'] ?? ($r['reply'] ?? ($r['output'] ?? ''))));
          $r['created_at'] = $r['created_at'] ?? ($r['created'] ?? ($r['date'] ?? ($r['timestamp'] ?? ($r['sent_at'] ?? ($r['time'] ?? '')))));
          $r['channel']  = $r['channel'] ?? ($r['source'] ?? ($r['driver'] ?? ($r['platform'] ?? '')));
        }
        unset($r);
      }
    }

    // try load user display names (optional)
    $userNames = [];
    $ids = array_values(array_unique(array_map(fn($r)=>(int)$r['user_id'],$rows)));
    if ($ids) {
      $in = implode(',', array_fill(0,count($ids),'?'));
      try {
        $q = $pdo->prepare('SELECT id, username, first_name, last_name FROM bot_users WHERE id IN ('.$in.')');
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) {
          $nm = trim(($u['first_name']??'').' '.($u['last_name']??''));
          if ($nm==='') $nm = $u['username'] ?? ('ID '.$u['id']);
          $userNames[(int)$u['id']] = $nm;
        }
      } catch(Throwable $e) { /* ignore */ }
    }

    // group into sessions
    $gap = 45*60; $sessions=[]; $cur=null; $counts=['telegram'=>0,'facebook'=>0,'instagram'=>0,'whatsapp'=>0,'web'=>0,'unknown'=>0];
    $mk = function($uid,$from,$to){ return rtrim(strtr(base64_encode(json_encode(['u'=>$uid,'from'=>$from,'to'=>$to])), '+/', '-_'), '='); };
    foreach ($rows as $r) {
      $uid=(int)$r['user_id']; $ts=strtotime($r['created_at']); $ch=$r['channel']?:'unknown';
      $name = $userNames[$uid] ?? ('ID '.$uid);
      if ($cur===null || $cur['user_id']!==$uid || ($ts-$cur['last_ts'])>$gap) {
        if ($cur!==null) { $cur['end_ts']=$cur['last_ts']; $cur['id']=$mk($cur['user_id'],$cur['start_ts'],$cur['end_ts']+1); $sessions[]=$cur; $counts[$cur['channel']] = ($counts[$cur['channel']]??0)+1; }
        $cur=['user_id'=>$uid,'user_name'=>$name,'channel'=>$ch,'start_ts'=>$ts,'last_ts'=>$ts,'count'=>1,'preview'=>mb_substr(trim((string)$r['question']),0,140)];
      } else {
        $cur['last_ts']=$ts; $cur['count']++; if(empty($cur['preview'])) $cur['preview']=mb_substr(trim((string)$r['question']),0,140);
      }
    }
    if ($cur!==null) { $cur['end_ts']=$cur['last_ts']; $cur['id']=$mk($cur['user_id'],$cur['start_ts'],$cur['end_ts']+1); $sessions[]=$cur; $counts[$cur['channel']] = ($counts[$cur['channel']]??0)+1; }

    // Fallback: if for any reason no sessions were built but rows exist — build one session per user
    if (!$sessions && $rows) {
      $byUser = [];
      foreach ($rows as $r) {
        $uid = (int)$r['user_id'];
        $ts = strtotime($r['created_at']);
        $ch = $r['channel'] ?: 'unknown';
        if (!isset($byUser[$uid])) {
          $byUser[$uid] = [
            'user_id'=>$uid,
            'user_name'=>('ID '.$uid),
            'channel'=>$ch,
            'start_ts'=>$ts,
            'last_ts'=>$ts,
            'count'=>1,
            'preview'=>substr(trim((string)$r['question']),0,140)
          ];
        } else {
          $byUser[$uid]['last_ts'] = $ts;
          $byUser[$uid]['count']++;
          if (empty($byUser[$uid]['preview'])) $byUser[$uid]['preview'] = substr(trim((string)$r['question']),0,140);
        }
      }
      foreach ($byUser as $u=>$s) {
        $s['end_ts'] = $s['last_ts'];
        $s['id'] = $mk($s['user_id'],$s['start_ts'],$s['end_ts']+1);
        $sessions[] = $s;
        $counts[$s['channel']] = ($counts[$s['channel']]??0)+1;
      }
    }

    // sort desc, paginate
    usort($sessions, fn($a,$b)=>($b['end_ts']<=>$a['end_ts']));
    $total=count($sessions); $offset=($page-1)*$per; $slice=array_slice($sessions,$offset,$per);
    echo json_encode([
      'ok'=>true,
      'sessions'=>$slice,
      'total'=>$total,
      'has_more'=>($offset+$per<$total),
      'counts'=>$counts,
      '_raw'=>count($rows),
      'debug'=>$debug
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Load a single session messages
  if (isset($_GET['session'])) {
    $meta = json_decode(base64_decode($_GET['session']), true);
    if (!$meta || !isset($meta['u'],$meta['from'],$meta['to'])) { echo json_encode(['ok'=>false]); exit; }
    // dynamic column mapping for messages
    $cols = [];
    try { $cols = $pdo->query('SHOW COLUMNS FROM `history`')->fetchAll(PDO::FETCH_COLUMN, 0); } catch (Throwable $e) { $cols = []; }
    $pick = function(array $opts, array $cols){ foreach ($opts as $o) { if (in_array($o, $cols, true)) return $o; } return null; };
    $uf = $pick(['user_id','uid','sender_id','chat_id','from_id','user'], $cols);
    $qf = $pick(['question','request','user_message','message','msg','prompt','text'], $cols);
    $af = $pick(['answer','response','bot_reply','reply','output'], $cols);
    $tf = $pick(['created_at','created','date','timestamp','sent_at','time'], $cols);
    $hasId = in_array('id', $cols, true);
    if (!$uf || !$qf || !$af || !$tf) { echo json_encode(['ok'=>false,'error'=>'schema']); exit; }
    $sql = "SELECT `$qf` AS question, `$af` AS answer, `$tf` AS created_at FROM `history` WHERE `$uf`=? AND `$tf` BETWEEN ? AND ? ORDER BY `$tf` ASC" . ($hasId ? ", id ASC" : "");
    $st = $pdo->prepare($sql);
    $st->execute([(int)$meta['u'], date('Y-m-d H:i:s',(int)$meta['from']), date('Y-m-d H:i:s',(int)$meta['to'])]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC); $msgs=[];
    foreach($rows as $r){ $msgs[]=['role'=>'user','text'=>$r['question'],'time'=>$r['created_at']]; $msgs[]=['role'=>'bot','text'=>$r['answer'],'time'=>$r['created_at']]; }
    echo json_encode(['ok'=>true,'messages'=>$msgs], JSON_UNESCAPED_UNICODE); exit;
  }

  echo json_encode(['ok'=>false,'error'=>'unknown action']);
  exit;
}

// ---------- page ui ----------
?>
<div class="container mx-auto py-6">
  <div class="rounded-2xl border border-white/10 bg-gradient-to-r from-indigo-900/40 to-purple-900/30 p-6 mb-6">
    <h2 class="text-2xl font-bold text-indigo-200 flex items-center gap-2"><i class="fas fa-comments"></i> <?= h(tt('history.sessions','Conversations')) ?></h2>
    <p class="text-sm text-gray-300/90 mt-1"><?= h(tt('history.sessions.desc','Grouped by chat sessions across channels.')) ?></p>
  </div>

  <div class="flex flex-wrap items-center gap-2 mb-4">
    <span class="text-sm text-gray-400 mr-1"><?= h(tt('filter.by','Filter')) ?>:</span>
    <?php
      $filters = [
        'all'=>tt('channel.all','All'),
        'telegram'=>tt('channel.telegram','Telegram'),
        'facebook'=>tt('channel.facebook','Facebook'),
        'instagram'=>tt('channel.instagram','Instagram'),
        'whatsapp'=>tt('channel.whatsapp','WhatsApp'),
        'web'=>tt('channel.web','Website'),
        'unknown'=>tt('channel.unknown','Unknown'),
      ];
      $icons = [
        'all'=>'fas fa-inbox', 'telegram'=>'fab fa-telegram-plane','facebook'=>'fab fa-facebook-messenger','instagram'=>'fab fa-instagram','whatsapp'=>'fab fa-whatsapp','web'=>'fas fa-globe','unknown'=>'far fa-question-circle'
      ];
      foreach ($filters as $key=>$label): ?>
      <button type="button" data-filter="<?= h($key) ?>" class="filter-chip inline-flex items-center gap-2 px-3 py-1.5 rounded-full ring-1 ring-white/10 text-gray-300 hover:bg-white/5 bg-white/0 data-[active=true]:bg-indigo-600/20 data-[active=true]:text-indigo-200">
        <i class="<?= h($icons[$key]) ?>"></i>
        <span><?= h($label) ?></span>
        <span class="text-xs px-1.5 py-0.5 rounded-full bg-white/10">0</span>
      </button>
    <?php endforeach; ?>

    <div class="ml-auto flex items-center gap-2">
      <input id="search" type="text" placeholder="<?= h(tt('search.placeholder','Search history...')) ?>" class="w-64 px-3 py-2 rounded-lg bg-gray-900/60 text-gray-100 placeholder-gray-400 ring-1 ring-inset ring-white/10 focus:ring-2 focus:ring-indigo-500 outline-none transition" />
      <label class="text-xs text-gray-400"><?= h(tt('date.from','From')) ?></label>
      <input id="dateFrom" type="date" class="px-2 py-1 rounded ring-1 ring-white/10 bg-gray-900/60 text-gray-100" />
      <label class="text-xs text-gray-400"><?= h(tt('date.to','To')) ?></label>
      <input id="dateTo" type="date" class="px-2 py-1 rounded ring-1 ring-white/10 bg-gray-900/60 text-gray-100" />
      <button id="presetToday" class="px-2 py-1 rounded ring-1 ring-white/10 hover:bg-white/5 text-gray-300"><?= h(tt('preset.today','Today')) ?></button>
      <button id="preset7" class="px-2 py-1 rounded ring-1 ring-white/10 hover:bg-white/5 text-gray-300"><?= h(tt('preset.7d','7 days')) ?></button>
      <button id="preset30" class="px-2 py-1 rounded ring-1 ring-white/10 hover:bg-white/5 text-gray-300"><?= h(tt('preset.30d','30 days')) ?></button>
      <button id="applyFilters" class="px-3 py-1.5 rounded bg-indigo-600 hover:bg-indigo-700 text-white"><?= h(tt('apply','Apply')) ?></button>
      <button id="resetFilters" class="px-3 py-1.5 rounded ring-1 ring-white/10 hover:bg-white/5 text-gray-300"><?= h(tt('reset','Reset')) ?></button>
      <button id="clearHistory" class="px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white"><?= h(tt('clear.history','Clear history')) ?></button>
    </div>
  </div>

  <div id="sessionGrid" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4"></div>
  <div id="emptyState" class="hidden text-center text-gray-400 py-20"><?= h(tt('no.sessions','No sessions found')) ?></div>
  <div class="mt-4 flex justify-center">
    <button id="loadMore" class="px-4 py-2 rounded ring-1 ring-white/10 hover:bg-white/5 text-gray-300 hidden"><?= h(tt('load.more','Load more')) ?></button>
  </div>
</div>

<!-- Modal -->
<div id="convModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
  <div class="relative max-w-4xl mx-auto bg-gray-900/95 border border-white/10 rounded-2xl mt-12 h-[75vh] grid grid-rows-[auto,1fr,auto]">
    <div class="p-4 flex items-center justify-between border-b border-white/10">
      <div class="flex items-center gap-2 text-indigo-200">
        <i id="modalIcon" class="fas fa-comments"></i>
        <div id="modalTitle" class="font-semibold">—</div>
      </div>
      <button id="modalClose" class="px-3 py-1.5 rounded-lg ring-1 ring-white/10 hover:bg-white/5 text-gray-300"><i class="fas fa-times"></i> <?= h(tt('close','Close')) ?></button>
    </div>
    <div id="modalBody" class="overflow-y-auto p-4 space-y-3">
      <div id="modalLoading" class="text-gray-400"><?= h(tt('loading','Loading...')) ?></div>
    </div>
    <div class="p-3 border-t border-white/10 flex justify-end">
      <button id="exportTxt" class="px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-gray-200"><i class="far fa-file-alt mr-1"></i><?= h(tt('export.txt','Export .txt')) ?></button>
    </div>
  </div>
</div>

<script>
(function(){
  const grid = document.getElementById('sessionGrid');
  const emptyState = document.getElementById('emptyState');
  const chips = document.querySelectorAll('.filter-chip');
  const search = document.getElementById('search');
  const dateFrom = document.getElementById('dateFrom');
  const dateTo = document.getElementById('dateTo');
  const btnApply = document.getElementById('applyFilters');
  const btnReset = document.getElementById('resetFilters');
  const btnClear = document.getElementById('clearHistory');
  const btnMore  = document.getElementById('loadMore');
  const presetToday = document.getElementById('presetToday');
  const preset7 = document.getElementById('preset7');
  const preset30 = document.getElementById('preset30');

  let state = { channel:'all', term:'', from:'', to:'', page:1, per:24, has_more:false };

  function chIcon(ch){
    switch(ch){ case 'telegram': return 'fab fa-telegram-plane'; case 'facebook': return 'fab fa-facebook-messenger'; case 'instagram': return 'fab fa-instagram'; case 'whatsapp': return 'fab fa-whatsapp'; case 'web': return 'fas fa-globe'; default: return 'far fa-question-circle'; }
  }
  function chLabel(ch){
    switch(ch){
      case 'telegram': return <?= json_encode(tt('channel.telegram','Telegram')) ?>;
      case 'facebook': return <?= json_encode(tt('channel.facebook','Facebook')) ?>;
      case 'instagram': return <?= json_encode(tt('channel.instagram','Instagram')) ?>;
      case 'whatsapp': return <?= json_encode(tt('channel.whatsapp','WhatsApp')) ?>;
      case 'web': return <?= json_encode(tt('channel.web','Website')) ?>;
      default: return <?= json_encode(tt('channel.unknown','Unknown')) ?>;
    }
  }
  function cardHtml(s){
    const d = new Date((s.start_ts||0)*1000);
    const start = d.toISOString().slice(0,16).replace('T',' ');
    return `<div class="session-card bg-gradient-to-br from-white/5 to-white/0 border border-white/10 rounded-xl p-4 hover:border-indigo-400/30 hover:-translate-y-0.5 transition" data-id="${s.id}" data-user="${escapeHtml(s.user_name)}" data-channel="${s.channel}">
      <div class="flex items-start justify-between">
        <div class="flex items-center gap-2">
          <div class="w-9 h-9 rounded-lg bg-white/10 grid place-items-center"><i class="${chIcon(s.channel)} text-indigo-300"></i></div>
          <div>
            <div class="font-semibold text-indigo-200 truncate max-w-[11rem]" title="${escapeHtml(s.user_name)}">${escapeHtml(s.user_name)}</div>
            <div class="text-xs text-gray-400">${chLabel(s.channel)}</div>
          </div>
        </div>
        <div class="text-xs text-gray-400 text-right">
          <div>${start}</div>
          <div><?= h(tt('session.duration','Duration')) ?>: ${new Date((s.end_ts-s.start_ts)*1000).toISOString().substr(11,5)}</div>
        </div>
      </div>
      <div class="mt-3 text-sm text-gray-200 line-clamp-3">${escapeHtml(s.preview||'')}</div>
      <div class="mt-3 flex items-center justify-between text-sm">
        <span class="text-gray-400">${s.count} <?= h(tt('session.messages','messages')) ?></span>
        <button class="view-btn inline-flex items-center gap-2 text-indigo-300 hover:text-indigo-200"><i class="far fa-eye"></i> <?= h(tt('view.details','View')) ?></button>
      </div>
    </div>`;
  }
  function escapeHtml(s){return (s||'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}

  function renderSessions(list, reset){
    if (reset) grid.innerHTML='';
    list.forEach(s=> grid.insertAdjacentHTML('beforeend', cardHtml(s)));
    btnMore.classList.toggle('hidden', !state.has_more);
    emptyState.classList.toggle('hidden', grid.childElementCount>0);
  }
  function updateCounts(counts){
    document.querySelectorAll('.filter-chip').forEach(ch=>{
      const key = ch.getAttribute('data-filter');
      const cnt = (key==='all') ? (counts.__total||0) : (counts[key]||0);
      const badge = ch.querySelector('span:last-child'); if (badge) badge.textContent = cnt;
    });
  }
  function load(reset=false){
    const fd = new FormData();
    fd.append('ajax','1'); fd.append('action','list');
    Object.entries({channel:state.channel, term:state.term, from:state.from, to:state.to, page:state.page, per:state.per}).forEach(([k,v])=>fd.append(k,v));
    fetch('history.php',{method:'POST', body:fd}).then(r=>r.json()).then(d=>{
      console.log('history:list', d._raw, d.total, d.debug);
      state.has_more = !!d.has_more; const list = (d.sessions||[]); const counts = d.counts||{}; counts.__total = d.total||list.length; renderSessions(list, reset); updateCounts(counts);
    }).catch(()=>{ emptyState.classList.remove('hidden'); });
  }

  // filters
  chips.forEach(b=> b.addEventListener('click', ()=>{
    chips.forEach(x=>{ x.classList.remove('active','bg-white/10'); x.dataset.active='false'; });
    b.classList.add('active','bg-white/10'); b.dataset.active='true';
    state.channel = b.getAttribute('data-filter'); state.page=1; load(true);
  }));
  search && search.addEventListener('input', ()=>{ state.term = search.value; state.page=1; load(true); });
  btnMore.addEventListener('click', ()=>{ state.page++; load(false); });
  btnApply.addEventListener('click', ()=>{ state.from=dateFrom.value; state.to=dateTo.value; state.page=1; load(true); });
  btnReset.addEventListener('click', ()=>{ dateFrom.value=''; dateTo.value=''; search.value=''; state={...state, term:'', from:'', to:'', page:1}; chips[0]&&chips[0].click(); load(true); });
  presetToday.addEventListener('click', (e)=>{ e.preventDefault(); const d=new Date(); dateFrom.value=d.toISOString().slice(0,10); dateTo.value=d.toISOString().slice(0,10); btnApply.click(); });
  preset7.addEventListener('click', (e)=>{ e.preventDefault(); const d=new Date(); const d2=new Date(Date.now()-6*86400000); dateFrom.value=d2.toISOString().slice(0,10); dateTo.value=d.toISOString().slice(0,10); btnApply.click(); });
  preset30.addEventListener('click', (e)=>{ e.preventDefault(); const d=new Date(); const d2=new Date(Date.now()-29*86400000); dateFrom.value=d2.toISOString().slice(0,10); dateTo.value=d.toISOString().slice(0,10); btnApply.click(); });

  btnClear.addEventListener('click', (e)=>{ e.preventDefault(); if(!confirm(<?= json_encode(tt('confirm.clear','Delete all messages in the selected range? This cannot be undone.')) ?>)) return; const fd=new FormData(); fd.append('ajax','1'); fd.append('action','clear'); fd.append('channel', state.channel); fd.append('from', dateFrom.value); fd.append('to', dateTo.value); fetch('history.php',{method:'POST', body:fd}).then(r=>r.json()).then(()=>{ state.page=1; load(true); }); });

  // modal logic
  const modal = document.getElementById('convModal');
  const modalBody = document.getElementById('modalBody');
  const modalLoading = document.getElementById('modalLoading');
  const modalTitle = document.getElementById('modalTitle');
  const modalIcon = document.getElementById('modalIcon');
  document.getElementById('modalClose').addEventListener('click', ()=> modal.classList.add('hidden'));
  function openSession(id, user, channel){
    modalTitle.textContent = user; modalIcon.className = chIcon(channel)+' mr-2'; modalBody.innerHTML=''; modalLoading.classList.remove('hidden'); modal.classList.remove('hidden');
    fetch('history.php?session='+encodeURIComponent(id), {method:'POST', body:new URLSearchParams({ajax:'1'})})
      .then(r=>r.json()).then(d=>{ modalLoading.classList.add('hidden'); if(!d||!d.ok){ modalBody.innerHTML='<div class="text-red-400">Load error</div>'; return; } modalBody.innerHTML = d.messages.map(m=>bubble(m.role,m.text,m.time)).join(''); })
      .catch(()=>{ modalLoading.classList.add('hidden'); modalBody.innerHTML='<div class="text-red-400">Load error</div>'; });
  }
  function bubble(role, text, time){ const side = role==='user'?'justify-start':'justify-end'; const bg = role==='user'?'bg-white/10':'bg-indigo-600/80 text-white'; const align = role==='user'?'':'text-right'; const name = role==='user'?'User':'Bot'; return `<div class="flex ${side}"><div class="max-w-[80%] ${bg} rounded-xl px-3 py-2"><div class="text-xs text-gray-300 ${align}">${name} • ${time}</div><div class="whitespace-pre-wrap">${escapeHtml(text)}</div></div></div>`; }

  grid.addEventListener('click', (e)=>{ const btn = e.target.closest('.view-btn'); if(!btn) return; const card = e.target.closest('.session-card'); openSession(card.getAttribute('data-id'), card.getAttribute('data-user'), card.getAttribute('data-channel')); });

  // init
  chips[0] && chips[0].classList.add('active','bg-white/10'); chips[0] && (chips[0].dataset.active='true');
  load(true);
})();
</script>
<?php require_once __DIR__ . '/../inc/footer.php'; ?>