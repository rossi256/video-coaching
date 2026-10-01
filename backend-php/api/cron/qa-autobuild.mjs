#!/usr/bin/env node
/**
 * Build a finished session's replay by itself, then open a veto window.
 *
 * Everything before a Q&A ran itself; everything after waited on somebody
 * remembering. This closes that. It runs the whole mechanical chain, drafts the
 * judgement bits from the recording, verifies the page actually plays, and then
 * arms a timer. Silence publishes it. One tap stops it for good.
 *
 *   node qa-autobuild.mjs            # the next finished session that needs it
 *   node qa-autobuild.mjs --dry      # say what it would do, change nothing
 *   node qa-autobuild.mjs 15 --force # redo one, even if already built
 *
 * It never sets replay_url itself. Publishing is the timer's job, in
 * qa-lifecycle.php, so there is exactly one place that can mail the registrants.
 *
 * Safety: the preflight gate must pass before the window opens. If the video
 * does not play, this stops and nudges instead of publishing a dead page.
 */
import { execFileSync, execSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import os from 'node:os'

const HERE = import.meta.dirname
const VC   = path.resolve(HERE, '../../..')            // projects/video-coaching
const SITE = path.resolve(VC, '../events-site')
const REPLAY = path.join(SITE, 'live-qa/replay')

const args  = process.argv.slice(2)
const DRY   = args.includes('--dry')
const FORCE = args.includes('--force')
const WANT  = args.find(a => /^\d+$/.test(a))
// How long Michi gets to read it before it goes out.
const VETO_HOURS = Number(process.env.QA_VETO_HOURS || 2)

const log = (...m) => console.log(' ', ...m)

function sql(query) {
  query = query.replace(/\s+/g, ' ').trim()
  const tmp = `/tmp/qa-autobuild-${process.pid}.php`
  fs.writeFileSync(tmp, `<?php
require_once '/home/coaching/public_html/video-coaching/api/config.php';
$sql = $argv[1];
if (!preg_match('/^SELECT/i', trim($sql))) { fwrite(STDERR, "read-only\\n"); exit(1); }
echo json_encode(getDb()->query($sql)->fetchAll(PDO::FETCH_ASSOC));`)
  execFileSync('scp', ['-q', tmp, 'coaching-server:/home/coaching/tmp/'])
  const n = path.basename(tmp)
  const out = execFileSync('ssh', ['coaching-server',
    `/usr/bin/php8.4 /home/coaching/tmp/${n} ${JSON.stringify(query)}; rm -f /home/coaching/tmp/${n}`],
    { encoding: 'utf8', maxBuffer: 16 << 20 })
  fs.unlinkSync(tmp)
  return JSON.parse(out.trim() || '[]')
}

/** Run a small PHP snippet on the coaching server (for writes and mail). */
function remotePhp(code) {
  const tmp = `/tmp/qa-ab-${process.pid}-${Math.abs(code.length)}.php`
  fs.writeFileSync(tmp, `<?php
require_once '/home/coaching/public_html/video-coaching/api/config.php';
require_once '/home/coaching/public_html/video-coaching/api/helpers/email.php';
${code}`)
  execFileSync('scp', ['-q', tmp, 'coaching-server:/home/coaching/tmp/'])
  const n = path.basename(tmp)
  const out = execFileSync('ssh', ['coaching-server',
    `/usr/bin/php8.4 /home/coaching/tmp/${n}; rm -f /home/coaching/tmp/${n}`], { encoding: 'utf8' })
  fs.unlinkSync(tmp)
  return out.trim()
}

/** Ask Claude through the Forge gateway, which runs on the Max subscription. */
async function draft(transcript, session) {
  const keyPath = `${os.homedir()}/.qa-gateway-key`
  let key = process.env.QA_GATEWAY_KEY || ''
  if (!key && fs.existsSync(keyPath)) key = fs.readFileSync(keyPath, 'utf8').trim()
  if (!key) {
    key = execFileSync('ssh', ['ari-server',
      `python3 -c "import json;print(json.load(open('/home/ari/podcast-copilot.json')).get('gatewayKey',''))"`],
      { encoding: 'utf8' }).trim()
  }
  if (!key) throw new Error('no gateway key')

  const prompt = `You are preparing the replay page for a monthly live wingfoil Q&A hosted by Michi Rossmeier (Tricktionary).

Below is an automatic transcript of the session. It is machine generated from a call in mixed English and German, so it is rough: names are mangled, German is often garbled, and some lines are nonsense. Work only from what is clearly legible. Never invent a topic you cannot see in the text.

Return ONLY valid JSON, no prose around it, in this exact shape:
{
  "title": "short page title, 4 to 8 words, concrete, no colon",
  "summary": "one sentence naming the main things covered, under 200 characters",
  "chapters": [ { "t": <seconds, integer>, "quote": "<6 to 12 words copied EXACTLY from the transcript at that moment>", "title": "what was covered, plain statement, under 70 chars" } ],
  "faq": [ { "t": <seconds>, "quote": "<6 to 12 words copied EXACTLY from the transcript>", "q": "the question as a rider would ask it", "a": "Michi's answer in 2 to 4 sentences, his words not yours" } ]
}

The "quote" is not decoration. Every timestamp is checked against it: the quote
must appear verbatim in the transcript, and the timestamp is corrected to wherever
the quote actually is. An entry whose quote cannot be found is thrown away. So
copy the words exactly, do not tidy them, and do not quote a line you are not
looking at.

Rules:
- 12 to 25 chapters, in time order, first one at t=0 for the arrivals.
- 4 to 8 FAQ entries, only where a real question was actually answered.
- Timestamps must be seconds from the start and must match where it is discussed.
- British English. No em dashes. Do not use the words "dive into", "unpack", "elevate".
- If a section is too garbled to summarise honestly, leave it out rather than guessing.

Session date: ${session.scheduled_at}. Duration: ${session.duration_minutes} minutes.

TRANSCRIPT:
${transcript.slice(0, 190000)}`

  const res = await fetch('https://forge.tricktionary.com/claude/v1/messages', {
    method: 'POST',
    headers: { 'x-api-key': key, 'content-type': 'application/json' },
    body: JSON.stringify({
      model: 'claude-sonnet-5',
      max_tokens: 8000,
      messages: [{ role: 'user', content: prompt }],
    }),
  })
  if (!res.ok) throw new Error(`gateway ${res.status}: ${(await res.text()).slice(0, 200)}`)
  const j = await res.json()
  const text = (j.content || []).map(c => c.text || '').join('')
  const m = text.match(/\{[\s\S]*\}/)
  if (!m) throw new Error('no JSON in the model reply')
  return JSON.parse(m[0])
}

/**
 * Trust the model's topics, never its timestamps.
 *
 * On the first real run the drafted topics were all genuinely in the session,
 * but the times were out by minutes: a chapter called "back loop technique" at
 * 46:02 landed in the middle of a footstraps question. A wrong timestamp is
 * worse than no chapter, because the reader clicks it and lands on the wrong
 * thing.
 *
 * So every entry has to carry a verbatim quote. We find that quote in the
 * transcript and move the timestamp to where it really is. Anything we cannot
 * find is dropped rather than published on trust.
 */
function anchorToTranscript(entries, transcript, label) {
  // "HH:MM:SS  Speaker: text" -> [seconds, normalised text]
  const index = []
  for (const line of transcript.split('\n')) {
    const m = line.match(/^(\d\d):(\d\d):(\d\d)\s+([^:]{1,40}):\s*(.*)$/)
    if (!m) continue
    index.push([Number(m[1]) * 3600 + Number(m[2]) * 60 + Number(m[3]),
                m[5].toLowerCase().replace(/[^a-z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim(),
                m[4]])
  }
  const norm = s => String(s || '').toLowerCase().replace(/[^a-z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim()

  const kept = []
  let moved = 0, dropped = 0
  for (const e of entries) {
    const q = norm(e.quote)
    if (!q || q.split(' ').length < 4) { dropped++; continue }
    // exact containment first, then a looser word-overlap pass
    let hit = index.find(([, txt]) => txt.includes(q))
    if (!hit) {
      const words = q.split(' ')
      let best = null, bestScore = 0
      for (const row of index) {
        const score = words.filter(w => w.length > 3 && row[1].includes(w)).length
        if (score > bestScore) { bestScore = score; best = row }
      }
      if (bestScore >= Math.max(4, Math.ceil(words.length * 0.6))) hit = best
    }
    if (!hit) { dropped++; continue }
    if (Math.abs(hit[0] - Number(e.t)) > 20) moved++
    // Keep the quote and who said it. The gate re-finds the quote near the
    // timestamp, which is a near-deterministic check; without it the gate can
    // only ask whether anybody was speaking within 60s, which 89% of random
    // timestamps satisfy. It also lets the gate refuse a line attributed to
    // Michi that is actually a participant asking a question.
    kept.push({ ...e, t: hit[0], src_quote: String(e.quote).slice(0, 120), src_speaker: hit[2] })
  }
  log(`${label}: ${kept.length} kept, ${moved} retimed, ${dropped} dropped as unverifiable`)
  kept.sort((a, b) => a.t - b.t)
  // Two entries anchored to the same line become two chapters on the same
  // second, which reads as a duplicate. Keep the first.
  const seen = new Set()
  const unique = kept.filter(e => (seen.has(e.t) ? false : seen.add(e.t)))
  if (unique.length !== kept.length) log(`${label}: ${kept.length - unique.length} merged onto a shared timestamp`)
  return unique
}



const main = async () => {
  // A finished session with no replay, old enough that the recording exists.
  const rows = WANT
    ? sql(`SELECT * FROM qa_sessions WHERE id = ${Number(WANT)}`)
    : sql(`SELECT * FROM qa_sessions
            WHERE status = 'past' AND replay_url IS NULL AND publish_held = 0
              AND autobuilt_at IS NULL
              AND scheduled_at < NOW() - INTERVAL 2 HOUR
              AND scheduled_at > NOW() - INTERVAL 14 DAY
            ORDER BY scheduled_at DESC LIMIT 1`)
  if (!rows.length) { log('nothing to build'); return }
  const s = rows[0]
  if (s.autobuilt_at && !FORCE) { log(`session ${s.id} already built at ${s.autobuilt_at}`); return }
  if (s.replay_url && !FORCE)   { log(`session ${s.id} already published`); return }

  const date = String(s.scheduled_at).slice(0, 10)
  const ym   = date.slice(0, 7)
  log(`session ${s.id}  ${s.scheduled_at}`)
  if (DRY) { log('[dry] would build and arm a veto window'); return }

  const outDir = `${VC}/docs/qa-${date}`

  // 1. everything off Zoom
  log('pulling from Zoom...')
  execSync(`node ${HERE}/qa-postprocess.mjs ${s.id}`, { stdio: 'inherit' })

  // 2. the recording has to be reachable before anything else is worth doing
  const mp4 = `${outDir}/qa-${date}.mp4`
  if (!fs.existsSync(mp4)) throw new Error('no MP4 was downloaded, stopping')
  log('uploading the recording...')
  execFileSync('ssh', ['coaching-server',
    'mkdir -p /home/coaching/public_html/video-coaching/static/replay'])
  execFileSync('scp', ['-q', mp4,
    'coaching-server:/home/coaching/public_html/video-coaching/static/replay/'])

  // 3. poster and the email thumbnail
  log('rendering poster and thumbnail...')
  // Replay numbers count the LIBRARY, not every session ever held. There have
  // been 9 past sessions but the library starts at August 2026, so counting
  // sessions labelled tonight's page "Replay #9" against a shelf of three.
  const existing = fs.readdirSync(`${REPLAY}/data`).filter(f => /^20\d\d-\d\d\.json$/.test(f))
  const already = existing.find(f => f === `${ym}.json`)
  const num = already
    ? JSON.parse(fs.readFileSync(`${REPLAY}/data/${ym}.json`, 'utf8')).number
    : existing.length + 1
  execSync(`python3 ${HERE}/qa-make-poster.py --number ${num} --date ${date} --minutes ${s.duration_minutes}`, { stdio: 'inherit' })
  const dur = `${Math.floor(s.duration_minutes / 60)}:${String(s.duration_minutes % 60).padStart(2, '0')}:00`
  execSync(`python3 ${HERE}/qa-make-thumb.py ${date} --at 2400 --duration "${dur}" --upload`, { stdio: 'inherit' })

  // 4. the judgement bits, drafted from the recording
  log('drafting chapters and FAQ...')
  const transcript = fs.readFileSync(`${outDir}/qa-transcript.txt`, 'utf8')
  const d = await draft(transcript, s)
  d.chapters = anchorToTranscript(d.chapters, transcript, 'chapters')
  d.faq      = anchorToTranscript(d.faq, transcript, 'faq')
  if (d.chapters.length < 6) throw new Error(`only ${d.chapters.length} chapters survived verification, not publishing`)
  if (d.chapters[0].t > 120) d.chapters.unshift({ t: 0, title: 'Arrivals, who is on and from where' })
  const att = JSON.parse(fs.readFileSync(`${outDir}/attendance.json`, 'utf8'))
  const reg = Number(sql(`SELECT COUNT(*) n FROM qa_signups WHERE session_id = ${s.id}`)[0].n)

  const data = {
    slug: ym, number: num, date,
    duration_min: Number(s.duration_minutes),
    registered: reg,
    attended: att.attended ?? (att.people.length - (att.bots || []).length),
    title: d.title,
    summary: d.summary,
    video: `https://coaching.tricktionary.com/video-coaching/static/replay/qa-${date}.mp4`,
    poster: `/live-qa/replay/img/qa-${date}-poster.jpg`,
    chapters: d.chapters,
    faq: d.faq,
  }
  fs.writeFileSync(`${REPLAY}/data/${ym}.json`, JSON.stringify(data, null, 2) + '\n')
  log(`data/${ym}.json  ${d.chapters.length} chapters, ${d.faq.length} FAQ`)

  // 5. build and deploy
  log('building and deploying...')
  execSync('python3 build-replays.py', { cwd: REPLAY, stdio: 'inherit' })
  execSync('bash deploy-events.sh site', { cwd: SITE, stdio: 'pipe' })

  // 6. THE GATE. Nothing arms a publish timer until this passes.
  //
  // It used to be a single check, "does the video return 200". That passed on
  // the first automatic build which was nonetheless numbered Replay #9 against
  // a library of three and had 8 of 22 chapter timestamps wrong. A gate that
  // can only say yes is not a gate, so this is now a suite that is itself
  // exercised against deliberately broken builds (--self-test).
  log('verifying...')
  const pageUrl = `https://events.tricktionary.com/live-qa/replay/${ym}/`
  let verdict
  try {
    const out = execSync(`node ${HERE}/qa-verify-replay.mjs ${ym} --json`, { encoding: 'utf8' })
    verdict = JSON.parse(out)
  } catch (e) {
    // Non-zero exit still prints the JSON, which is the useful part.
    try { verdict = JSON.parse(e.stdout || '{}') } catch { verdict = { ok: false, results: [] } }
  }
  const failed = (verdict.results || []).filter(r => r.state === 'fail')
  if (!verdict.ok) {
    log(`verification FAILED, not publishing:`)
    failed.forEach(r => log(`   ${r.name}: ${r.detail}`))
    // Leave autobuilt_at set so it does not loop, and let the daily nudge chase
    // it. A half-built replay that nobody is told about is the worse outcome.
    remotePhp(`
$db = getDb();
$db->prepare("UPDATE qa_sessions SET autobuilt_at = NOW(), publish_after = NULL WHERE id = ?")
   ->execute([${s.id}]);
echo "marked";`)
    throw new Error(`${failed.length} check(s) failed: ` + failed.map(r => r.name).join(', '))
  }
  log(`verification passed, ${(verdict.results || []).length} checks`)

  // 7. arm the window. qa-lifecycle.php does the actual publish.
  const publishAt = new Date(Date.now() + VETO_HOURS * 3600_000)
  const stamp = publishAt.toISOString().slice(0, 19).replace('T', ' ')
  remotePhp(`
$db = getDb();
$db->prepare("UPDATE qa_sessions SET autobuilt_at = NOW(), publish_after = ? WHERE id = ?")
   ->execute([${JSON.stringify(stamp)}, ${s.id}]);
$s = $db->query("SELECT * FROM qa_sessions WHERE id = ${s.id}")->fetch(PDO::FETCH_ASSOC);
sendQaReadyToPublish($s, ${JSON.stringify(pageUrl)}, ${reg}, ${JSON.stringify(stamp)});
echo "armed";`)
  log(`armed. publishes ${stamp} unless held.`)
}

main().catch(e => { console.error('autobuild failed:', e.message); process.exit(1) })
