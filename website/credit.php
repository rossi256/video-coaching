<?php
/**
 * The buyer's private page: how many sessions they have, and how to book one.
 *
 * Reached two ways:
 *   /credit?t=TOKEN                     the link in their email, the durable one
 *   /credit?paid=1&session_id=cs_...    straight off Stripe, before the webhook
 *                                       has necessarily landed
 *
 * The second case is the awkward one. Stripe redirects the buyer back
 * immediately, while the webhook that creates their credits arrives a moment
 * later over a separate connection. Showing "link not valid" to someone who has
 * just paid is the worst possible first screen, so that case polls briefly
 * instead of guessing.
 */
define('BASE_PATH', getenv('WINGCOACH_BASE_PATH') ?: '/video-coaching');
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/helpers/coaching-products.php';

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES); }

$db        = getDb();
$token     = trim($_GET['t'] ?? '');
$sessionId = trim($_GET['session_id'] ?? '');
$justPaid  = isset($_GET['paid']);

$credit = $token !== '' ? creditByToken($db, $token) : null;

// Came back from Stripe without a token: find the purchase by session id.
if (!$credit && $sessionId !== '') {
    $s = $db->prepare('SELECT * FROM coaching_credits WHERE stripe_session_id = ?');
    $s->execute([$sessionId]);
    $credit = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($credit) {
        // Put them on the durable link so a bookmark or a refresh still works.
        header('Location: ' . BASE_PATH . '/credit?t=' . $credit['token']);
        exit;
    }
}

$balance = $credit ? creditBalance($db, (int) $credit['id']) : null;
$product = $credit ? coachingProduct($credit['product']) : null;

$history = [];
if ($credit) {
    $q = $db->prepare('SELECT * FROM credit_redemptions WHERE credit_id = ? ORDER BY id DESC');
    $q->execute([$credit['id']]);
    $history = $q->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Your coaching sessions | Tricktionary</title>
<link rel="icon" href="<?= h(BASE_PATH) ?>/static/assets/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
  :root{--base:#0d1b2e;--card:#16283f;--white:#f1f5f9;--dim:#c3cfdb;--grey:#8698a9;
        --teal:#0ea5e9;--teal-2:#38bdf8;--gold:#d4a843;--line:rgba(255,255,255,.09);--good:#4ade80}
  *{margin:0;padding:0;box-sizing:border-box}
  html{background:var(--base);color-scheme:dark}
  body{background:var(--base);color:var(--white);font:16px/1.62 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
  .wrap{max-width:620px;margin:0 auto;padding:48px 20px 80px}
  .logo{font-weight:800;color:var(--white);text-decoration:none;font-size:1rem;letter-spacing:.02em}
  .logo span{color:var(--teal)}
  h1{font-size:clamp(1.6rem,4.6vw,2.2rem);margin:26px 0 8px;letter-spacing:-.02em;text-wrap:balance}
  .sub{color:var(--dim);margin-bottom:26px}

  .balance{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:22px 24px;margin-bottom:22px}
  .bignum{font-size:2.6rem;font-weight:800;line-height:1;letter-spacing:-.03em}
  .bignum small{font-size:.95rem;font-weight:500;color:var(--grey);letter-spacing:0;margin-left:8px}
  .meta{color:var(--grey);font-size:.86rem;margin-top:8px}

  fieldset{border:0;margin:0 0 18px}
  legend{font-size:.74rem;letter-spacing:.13em;text-transform:uppercase;color:var(--teal);font-weight:700;margin-bottom:10px}
  .kinds{display:grid;gap:9px}
  @media(min-width:520px){.kinds{grid-template-columns:1fr 1fr}}
  .kind{position:relative;display:block;cursor:pointer}
  .kind input{position:absolute;opacity:0;pointer-events:none}
  .kind span{display:block;border:1px solid var(--line);border-radius:12px;padding:14px 16px;background:rgba(255,255,255,.02);transition:border-color .15s,background .15s}
  .kind b{display:block;font-size:.98rem;margin-bottom:2px}
  .kind em{font-style:normal;color:var(--grey);font-size:.85rem;line-height:1.45}
  .kind input:checked + span{border-color:var(--teal);background:rgba(14,165,233,.09)}
  .kind input:focus-visible + span{outline:2px solid var(--teal-2);outline-offset:2px}

  label.f{display:block;font-size:.9rem;color:var(--dim);margin:16px 0 6px}
  textarea,input[type=text]{width:100%;padding:12px 14px;border-radius:10px;border:1px solid var(--line);
    background:rgba(255,255,255,.05);color:var(--white);font:inherit;font-size:.95rem;resize:vertical}
  textarea:focus,input[type=text]:focus{outline:2px solid var(--teal);outline-offset:1px;border-color:transparent}
  .hint{color:var(--grey);font-size:.82rem;margin-top:5px}
  button.go{width:100%;margin-top:20px;border:0;border-radius:50px;padding:14px 28px;font:inherit;font-weight:700;
    font-size:.96rem;cursor:pointer;background:linear-gradient(135deg,var(--gold),#f0d078);color:#0c1929;transition:transform .2s,box-shadow .2s}
  button.go:hover{transform:translateY(-2px);box-shadow:0 10px 26px -10px rgba(212,168,67,.6)}
  button.go:disabled{opacity:.55;cursor:not-allowed;transform:none;box-shadow:none}
  .err{color:#fca5a5;font-size:.88rem;margin-top:12px;min-height:1.2em}

  .done{background:rgba(74,222,128,.09);border:1px solid rgba(74,222,128,.3);border-radius:14px;padding:22px 24px;text-align:center}
  .done h2{font-size:1.25rem;margin-bottom:6px;color:var(--good)}
  .done p{color:var(--dim);font-size:.95rem}

  .hist{margin-top:34px;border-top:1px solid var(--line);padding-top:20px}
  .hist h3{font-size:.74rem;letter-spacing:.13em;text-transform:uppercase;color:var(--grey);font-weight:700;margin-bottom:12px}
  .hrow{display:flex;gap:12px;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--line);font-size:.9rem}
  .hrow:last-child{border-bottom:0}
  .hrow .k{color:var(--white);font-weight:600}
  .hrow .d{color:var(--grey);font-size:.84rem}
  .st{font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;font-weight:700;padding:3px 8px;border-radius:5px;white-space:nowrap;height:fit-content}
  .st-requested{background:rgba(212,168,67,.16);color:var(--gold)}
  .st-scheduled{background:rgba(14,165,233,.16);color:var(--teal-2)}
  .st-delivered{background:rgba(74,222,128,.14);color:var(--good)}

  .empty{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:30px 26px;text-align:center}
  .empty a{color:var(--teal)}
  footer{margin-top:40px;color:var(--grey);font-size:.84rem}
  footer a{color:var(--teal)}
</style>
</head>
<body>
<div class="wrap">
  <a class="logo" href="https://coaching.tricktionary.com/">WING<span>COACH</span></a>

<?php if (!$credit && $justPaid): ?>
  <h1>Payment received</h1>
  <p class="sub">Setting your sessions up now. This takes a few seconds.</p>
  <div class="empty">
    <p style="color:var(--dim)">If this does not move along in a moment, check your
    email: the link is in there too. Nothing is lost either way, and
    <a href="mailto:rossi@tricktionary.com">Michi can look it up</a> from your
    payment.</p>
  </div>
  <script>
    // The webhook that creates the credits arrives on its own connection, a beat
    // after Stripe sends the buyer back here. Poll rather than show an error to
    // someone whose money has already left their account.
    //
    // The attempt count rides in the URL: a page-local counter resets on every
    // reload, so it would never reach its own limit and the page would refresh
    // forever.
    (function () {
      var u = new URL(location.href);
      var n = parseInt(u.searchParams.get('try') || '0', 10) + 1;
      if (n > 12) return;            // ~30s, then leave the message on screen
      u.searchParams.set('try', n);
      setTimeout(function () { location.replace(u.toString()); }, 2500);
    })();
  </script>

<?php elseif (!$credit): ?>
  <h1>That link is not valid</h1>
  <p class="sub">
    It may have been truncated by a mail client. The full link is in the email
    that arrived after your purchase. If you cannot find it,
    <a href="mailto:rossi@tricktionary.com" style="color:var(--teal)">email Michi</a>
    and he will send it again.
  </p>

<?php else:
  $left = $balance['left'];
  $name = trim(explode(' ', (string) $credit['name'])[0] ?? '');
?>
  <h1><?= $name ? 'Hey ' . h($name) : 'Your coaching' ?></h1>
  <p class="sub">Book a session whenever you are ready. No rush.</p>

  <div class="balance">
    <div class="bignum"><?= (int) $left ?><small><?= $left === 1 ? 'session left' : 'sessions left' ?></small></div>
    <p class="meta">
      <?= h($product['label'] ?? $credit['product']) ?>
      <?php if ($balance['used'] > 0): ?> &middot; <?= (int) $balance['used'] ?> used<?php endif; ?>
      <?php if ($credit['expires_at']): ?> &middot; valid until <?= h(date('j F Y', strtotime($credit['expires_at']))) ?><?php endif; ?>
    </p>
  </div>

  <?php if ($balance['expired']): ?>
    <div class="empty">
      <p style="color:var(--dim)">These expired on <?= h(date('j F Y', strtotime($credit['expires_at']))) ?>.
      <a href="mailto:rossi@tricktionary.com">Send Michi a line</a> and he will sort it out.</p>
    </div>
  <?php elseif ($left <= 0): ?>
    <div class="empty">
      <p style="color:var(--dim)">All used. Hope the riding is going better.<br>
      <a href="<?= h(BASE_PATH) ?>/coaching">Grab another session</a> whenever you want one.</p>
    </div>
  <?php else: ?>

  <form id="redeem">
    <fieldset>
      <legend>How do you want it</legend>
      <div class="kinds">
        <label class="kind">
          <input type="radio" name="kind" value="call" checked>
          <span><b>1:1 call, 45 min</b><em>Live on Zoom. Michi replies with times that suit.</em></span>
        </label>
        <label class="kind">
          <input type="radio" name="kind" value="review">
          <span><b>Video review</b><em>He watches your clips and sends a video reply within 72h.</em></span>
        </label>
      </div>
    </fieldset>

    <label class="f" for="msg">What are you working on?</label>
    <textarea id="msg" rows="4" placeholder="The move, what happens when it goes wrong, and what you have already tried."></textarea>
    <p class="hint">The more specific, the more useful the answer. "My back foot comes out on Palau attempts" beats "help with freestyle".</p>

    <label class="f" for="clip">Link to your clips <span style="color:var(--grey)">(optional)</span></label>
    <input type="text" id="clip" placeholder="Google Drive, Dropbox, WeTransfer, YouTube unlisted...">
    <p class="hint">Any link Michi can open. Uploading straight to a shared folder is usually easiest.</p>

    <div id="whenBox">
      <label class="f" for="when">When could you make a call?</label>
      <input type="text" id="when" placeholder="Weekday evenings CEST, or Saturday mornings...">
      <p class="hint">Rough is fine. Michi comes back with two or three concrete times.</p>
    </div>

    <button class="go" type="submit">Send it to Michi</button>
    <p class="err" id="err"></p>
  </form>

  <div class="done" id="done" style="display:none">
    <h2>Sent</h2>
    <p id="doneMsg"></p>
  </div>
  <?php endif; ?>

  <?php if ($history): ?>
  <div class="hist">
    <h3>Your sessions</h3>
    <?php foreach ($history as $r):
      $st = in_array($r['status'], ['requested','scheduled','delivered'], true) ? $r['status'] : 'requested'; ?>
      <div class="hrow">
        <div>
          <div class="k"><?= h(CREDIT_KINDS[$r['kind']] ?? $r['kind']) ?></div>
          <div class="d">
            <?= h(date('j M Y', strtotime($r['created_at']))) ?>
            <?php if ($r['scheduled_at']): ?> &middot; <?= h(date('j M, H:i', strtotime($r['scheduled_at']))) ?><?php endif; ?>
          </div>
          <?php if ($r['zoom_join_url'] && $st !== 'delivered'): ?>
            <div class="d"><a href="<?= h($r['zoom_join_url']) ?>" style="color:var(--teal)">Join the call &rarr;</a></div>
          <?php endif; ?>
          <?php if ($r['reply_url']): ?>
            <div class="d"><a href="<?= h($r['reply_url']) ?>" style="color:var(--teal)">Watch Michi's reply &rarr;</a></div>
          <?php endif; ?>
        </div>
        <span class="st st-<?= h($st) ?>"><?= h($st) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <footer>
    Keep this link, it is how you get back here.
    Anything odd? <a href="mailto:rossi@tricktionary.com">rossi@tricktionary.com</a>.
  </footer>
<?php endif; ?>
</div>

<?php if ($credit && !$balance['expired'] && $balance['left'] > 0): ?>
<script>
var form = document.getElementById('redeem');
var whenBox = document.getElementById('whenBox');

// The "when could you make it" question is meaningless for an async review.
function syncKind() {
  var call = document.querySelector('input[name=kind]:checked').value === 'call';
  whenBox.style.display = call ? '' : 'none';
}
document.querySelectorAll('input[name=kind]').forEach(function (r) {
  r.addEventListener('change', syncKind);
});
syncKind();

form.addEventListener('submit', async function (e) {
  e.preventDefault();
  var btn = form.querySelector('button.go');
  var err = document.getElementById('err');
  var kind = document.querySelector('input[name=kind]:checked').value;
  var msg = document.getElementById('msg').value.trim();
  err.textContent = '';

  if (msg.length < 15) {
    err.textContent = 'Give Michi a sentence or two to work with.';
    document.getElementById('msg').focus();
    return;
  }

  btn.disabled = true; btn.textContent = 'Sending...';
  try {
    var res = await fetch('<?= h(BASE_PATH) ?>/credit-redeem', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        token: <?= json_encode($credit['token']) ?>,
        kind: kind,
        message: msg,
        clip_url: document.getElementById('clip').value.trim(),
        prefer: kind === 'call' ? document.getElementById('when').value.trim() : ''
      })
    });
    var data = await res.json();
    if (data.ok) {
      form.style.display = 'none';
      document.getElementById('doneMsg').textContent = kind === 'call'
        ? 'Michi will come back to you by email with a couple of times.'
        : 'Michi will send your video reply within 72 hours.';
      document.getElementById('done').style.display = 'block';
      setTimeout(function () { location.reload(); }, 2600);
      return;
    }
    err.textContent = data.error || 'That did not go through.';
  } catch (e2) {
    err.textContent = 'Could not reach the server. Try again, or email rossi@tricktionary.com.';
  }
  btn.disabled = false; btn.textContent = 'Send it to Michi';
});
</script>
<?php endif; ?>
</body>
</html>
