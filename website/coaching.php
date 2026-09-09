<?php
/**
 * The coaching sales page: 1:1 call, video review, 3-session pack.
 *
 * Prices and labels are rendered from helpers/coaching-products.php, the same
 * file the checkout charges from, so the page cannot advertise a price Stripe
 * does not take. That mismatch is exactly how the WingCoach page ended up
 * promising "unlimited video uploads" for a product that delivers one round.
 */
define('BASE_PATH', getenv('WINGCOACH_BASE_PATH') ?: '/video-coaching');
require_once __DIR__ . '/api/helpers/coaching-products.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

$order = ['session', 'pack-3'];
$blurb = [
    'session' => [
        'Call or video review, whichever suits. You pick when you book.',
        ['<b>Either</b> 45 minutes live with Michi on Zoom, footage shared',
         '<b>or</b> send your clips and get a personal video reply in 72 hours',
         'One thing you are stuck on, looked at properly',
         'Recording either way, yours to keep'],
        'Decide the format after you buy, not before',
    ],
    'pack-3' => [
        'Because one session rarely finishes the job.',
        ['Three sessions, calls or reviews, any mix',
         'Use them across a whole season',
         'Valid 12 months',
         'Works out at &euro;' . coachingPerSessionEur(COACHING_PRODUCTS['pack-3']) . ' a session'],
        'Best if you are actually trying to change something',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Coaching with Michi Rossmeier | Tricktionary</title>
<meta name="description" content="Personal wingfoil coaching with Michi Rossmeier. A 45 minute 1:1 call on Zoom, a personal video review of your clips, or a pack of three sessions.">
<link rel="canonical" href="https://coaching.tricktionary.com<?= h(BASE_PATH) ?>/coaching">
<link rel="icon" href="<?= h(BASE_PATH) ?>/static/assets/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
  :root{
    --base:#0d1b2e; --base-2:#132640; --card:#16283f;
    --white:#f1f5f9; --dim:#c3cfdb; --grey:#8698a9;
    --teal:#0ea5e9; --teal-2:#38bdf8; --gold:#d4a843; --line:rgba(255,255,255,.09);
  }
  *{margin:0;padding:0;box-sizing:border-box}
  html{background:var(--base);color-scheme:dark;scroll-behavior:smooth}
  body{background:var(--base);color:var(--white);font:16px/1.62 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
  .wrap{max-width:1040px;margin:0 auto;padding:0 20px}
  header{padding:22px 0;border-bottom:1px solid var(--line)}
  header .wrap{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
  .logo{font-weight:800;letter-spacing:.02em;color:var(--white);text-decoration:none;font-size:1.05rem}
  .logo span{color:var(--teal)}
  nav a{color:var(--dim);text-decoration:none;font-size:.92rem;margin-left:18px}
  nav a:hover{color:var(--teal-2)}

  .hero{padding:64px 0 8px;text-align:center}
  .eyebrow{font-size:.74rem;letter-spacing:.16em;text-transform:uppercase;color:var(--teal);font-weight:700}
  h1{font-size:clamp(2rem,5.4vw,3.1rem);line-height:1.08;margin:14px 0 16px;letter-spacing:-.02em;text-wrap:balance}
  .sub{color:var(--dim);font-size:1.06rem;max-width:34rem;margin:0 auto}

  .grid{display:grid;gap:16px;margin:44px 0 0}
  @media(min-width:760px){.grid{grid-template-columns:repeat(2,1fr);align-items:start}}
  .grid{max-width:720px;margin-left:auto;margin-right:auto}
  .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:26px 24px 24px;display:flex;flex-direction:column;height:100%}
  .card.feature{border-color:rgba(14,165,233,.45);box-shadow:0 0 0 1px rgba(14,165,233,.18),0 18px 40px -24px rgba(0,0,0,.9)}
  .tag{display:inline-block;font-size:.66rem;letter-spacing:.13em;text-transform:uppercase;font-weight:800;color:var(--base);background:var(--teal);padding:4px 9px;border-radius:5px;margin-bottom:14px;align-self:flex-start}
  .tag.quiet{background:transparent;color:var(--grey);padding-left:0}
  .card h2{font-size:1.22rem;margin-bottom:4px;letter-spacing:-.01em}
  .lede{color:var(--dim);font-size:.94rem;margin-bottom:18px}
  .price{font-size:2.3rem;font-weight:800;letter-spacing:-.03em;line-height:1}
  .price small{font-size:.86rem;font-weight:500;color:var(--grey);letter-spacing:0;margin-left:6px}
  ul{list-style:none;margin:18px 0 22px}
  li{color:var(--dim);font-size:.93rem;padding-left:22px;position:relative;margin-bottom:8px;line-height:1.5}
  li::before{content:"";position:absolute;left:2px;top:.55em;width:7px;height:7px;border-radius:50%;background:var(--teal);opacity:.85}
  .fit{color:var(--grey);font-size:.85rem;margin:-8px 0 18px;font-style:italic}
  .buy{margin-top:auto;width:100%;border:0;border-radius:50px;padding:14px 24px;font:inherit;font-weight:700;font-size:.95rem;cursor:pointer;background:linear-gradient(135deg,var(--gold),#f0d078);color:#0c1929;transition:transform .2s,box-shadow .2s}
  .buy:hover{transform:translateY(-2px);box-shadow:0 10px 26px -10px rgba(212,168,67,.6)}
  .buy:disabled{opacity:.55;cursor:not-allowed;transform:none;box-shadow:none}
  .buy.ghost{background:transparent;color:var(--white);border:1px solid var(--line)}
  .buy.ghost:hover{border-color:var(--teal);box-shadow:none}
  .err{color:#fca5a5;font-size:.85rem;margin-top:10px;min-height:1.2em}

  .note{border-top:1px solid var(--line);margin-top:56px;padding:26px 0 70px;color:var(--grey);font-size:.9rem}
  .note b{color:var(--dim)}
  .note a{color:var(--teal)}
  .faq{max-width:44rem;margin:46px auto 0}
  .faq h3{font-size:1.28rem;margin-bottom:14px}
  details{border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin-bottom:9px;background:rgba(255,255,255,.02)}
  summary{cursor:pointer;font-weight:600;font-size:.97rem;list-style:none}
  summary::-webkit-details-marker{display:none}
  summary:hover{color:var(--teal-2)}
  details p{color:var(--dim);font-size:.93rem;margin-top:9px}
</style>
</head>
<body>

<header><div class="wrap">
  <a class="logo" href="https://coaching.tricktionary.com/">WING<span>COACH</span></a>
  <nav>
    <a href="<?= h(BASE_PATH) ?>/">Video coaching</a>
    <a href="https://events.tricktionary.com/live-qa/">Free Q&amp;A</a>
    <a href="https://events.tricktionary.com/">Camps</a>
  </nav>
</div></header>

<div class="wrap">
  <section class="hero">
    <p class="eyebrow">Coaching with Michi Rossmeier</p>
    <h1>Get the answer, not another opinion</h1>
    <p class="sub">
      You have watched the videos and read the forum threads. Sometimes what you
      need is one person who has taught this a thousand times, looking at
      <em>your</em> riding and telling you what is actually happening.
    </p>
  </section>

  <section class="grid">
    <?php foreach ($order as $i => $key):
        $p = coachingProduct($key);
        [$lede, $points, $fit] = $blurb[$key];
        $feature = $key === 'session';
    ?>
    <div class="card<?= $feature ? ' feature' : '' ?>">
      <?php if ($feature): ?><span class="tag">Start here</span>
      <?php else: ?><span class="tag quiet">Best value</span><?php endif; ?>

      <h2><?= h($p['label']) ?></h2>
      <p class="lede"><?= h($lede) ?></p>
      <p class="price">&euro;<?= h(coachingPriceEur($p)) ?><?php
        if ($p['credits'] > 1): ?><small>for <?= (int) $p['credits'] ?> sessions</small><?php endif; ?></p>
      <ul><?php foreach ($points as $pt): ?><li><?= $pt ?></li><?php endforeach; ?></ul>
      <p class="fit"><?= h($fit) ?></p>
      <button class="buy<?= $feature ? '' : ' ghost' ?>" data-sku="<?= h($key) ?>">
        <?= $p['credits'] > 1 ? 'Get three sessions' : 'Book a session' ?>
      </button>
      <p class="err" id="err-<?= h($key) ?>"></p>
    </div>
    <?php endforeach; ?>
  </section>

  <section class="faq">
    <h3>Before you buy</h3>
    <details>
      <summary>What happens straight after I pay?</summary>
      <p>You get a private link. On it you pick a call or a video review, say
      what you are working on, and where your clips are. Michi gets that
      the moment you send it. For a call he replies with times; for a review the
      reply lands within 72 hours.</p>
    </details>
    <details>
      <summary>Is one session really enough?</summary>
      <p>For one specific thing, usually yes. That is what it is built for: one
      problem you can name, looked at properly. If you are rebuilding something
      bigger, like switching your whole stance or learning to jump, take the pack
      and spread it across the season.</p>
    </details>
    <details>
      <summary>Call or video review, which should I pick?</summary>
      <p>Take the call if you want to go back and forth, or if the problem is
      hard to put into words. Take the video review if your riding time and
      Michi's do not line up, or if you would rather have something you can
      rewatch on the beach. Same price, and you choose after you buy, so you do
      not have to decide now.</p>
    </details>
    <details>
      <summary>What if I would rather do this in person?</summary>
      <p>Then a camp is better value than any of this. Four coaching days in
      Tenerife are &euro;490, three days at Lake Garda are &euro;499.
      <a href="https://events.tricktionary.com/">Have a look at the camps</a>.</p>
    </details>
    <details>
      <summary>I just want to ask a quick question.</summary>
      <p>Then do not buy anything. Michi runs a free live Q&amp;A on Zoom on the
      first Tuesday of every month, and you can bring whatever you like to it.
      <a href="https://events.tricktionary.com/live-qa/">Save a spot</a>.</p>
    </details>
  </section>

  <p class="note">
    <b>Michi Rossmeier</b> wrote the Wing Tricktionary, coaches on the Duotone
    team, and runs the camps in Tarifa, Lake Garda and Tenerife. Payment is
    handled by Stripe. Questions first? <a href="mailto:rossi@tricktionary.com">rossi@tricktionary.com</a>.
  </p>
</div>

<script>
document.querySelectorAll('.buy').forEach(function (btn) {
  btn.addEventListener('click', async function () {
    var sku = btn.dataset.sku;
    var err = document.getElementById('err-' + sku);
    err.textContent = '';
    var label = btn.textContent;
    btn.disabled = true; btn.textContent = 'Taking you to checkout...';
    try {
      var res = await fetch('<?= h(BASE_PATH) ?>/coaching-checkout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ product: sku })
      });
      var data = await res.json();
      if (data.url) { window.location = data.url; return; }
      err.textContent = data.error || 'Could not start checkout. Try again in a moment.';
    } catch (e) {
      err.textContent = 'Could not reach the server. Try again, or email rossi@tricktionary.com.';
    }
    btn.disabled = false; btn.textContent = label;
  });
});
</script>
</body>
</html>
