#!/usr/bin/env node
/**
 * Pre-flight for a Q&A session. Run it before every one.
 *
 *   node qa-preflight.mjs            # the next session
 *   node qa-preflight.mjs 16         # a specific session id
 *   node qa-preflight.mjs --json     # for a cron or a dashboard
 *
 * Exits non-zero if anything is wrong, so it can gate a send.
 *
 * Every check here exists because that exact thing has already gone wrong at
 * least once, which is the only reason to write a check. Borrowed wholesale
 * from human-optimization-erp, where each unit test is a regression test for a
 * bug Michi found by hand. The failures this encodes:
 *
 *   24 Aug   qa-offers.json promoted a Lake Garda camp that had finished ten
 *            days earlier. It went to 33 people.
 *   07 Sep   the campaign pack still said "Tuesday, September 1" six days after
 *            that call, while claiming it always showed the current session.
 *   Aug-Sep  the August replay video 404'd for a month; 34 people had the link.
 *   02 Sep   the September replay page inherited August's unlock slug.
 *   09 Sep   Stripe rejected customer_email: null, which would have failed
 *            every purchase. Every earlier check had passed an email.
 *
 * The rule those share: a check that cannot come back negative tells you
 * nothing. Each check below can fail.
 */
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'

const ROOT = '/home/openclaw/.openclaw/workspace'
const BASE = 'https://coaching.tricktionary.com/video-coaching'
const EVENTS = 'https://events.tricktionary.com'
const ZOOM_MEETING_ID = '86444732437'

const args = process.argv.slice(2)
const asJson = args.includes('--json')
const wantId = args.find(a => /^\d+$/.test(a))

const results = []
const ok   = (name, detail) => results.push({ name, state: 'ok', detail })
const warn = (name, detail) => results.push({ name, state: 'warn', detail })
const fail = (name, detail) => results.push({ name, state: 'fail', detail })

/** Run a read-only query on the coaching DB, via the server. */
function sql(query) {
  // Flatten first. JSON.stringify turns a multi-line template literal into a
  // literal backslash-n, which the remote shell passes through unchanged inside
  // double quotes, and MariaDB then rejects as a syntax error.
  query = query.replace(/\s+/g, ' ').trim()
  const tmp = `/tmp/qa-preflight-${process.pid}.php`
  fs.writeFileSync(tmp, `<?php
require_once '/home/coaching/public_html/video-coaching/api/config.php';
$sql = $argv[1];
if (!preg_match('/^SELECT/i', trim($sql))) { fwrite(STDERR, "read-only\\n"); exit(1); }
echo json_encode(getDb()->query($sql)->fetchAll(PDO::FETCH_ASSOC));`)
  execFileSync('scp', ['-q', tmp, 'coaching-server:/home/coaching/tmp/'])
  const name = tmp.split('/').pop()
  const out = execFileSync('ssh', ['coaching-server',
    `/usr/bin/php8.4 /home/coaching/tmp/${name} ${JSON.stringify(query)}; rm -f /home/coaching/tmp/${name}`],
    { encoding: 'utf8', maxBuffer: 8 << 20 })
  fs.unlinkSync(tmp)
  return JSON.parse(out.trim() || '[]')
}

async function head(url) {
  try {
    const r = await fetch(url, { method: 'GET', headers: { 'User-Agent': 'qa-preflight/1.0' } })
    return r.status
  } catch { return 0 }
}

async function text(url) {
  try {
    const r = await fetch(url, { headers: { 'User-Agent': 'qa-preflight/1.0' } })
    return r.ok ? await r.text() : ''
  } catch { return '' }
}

function env(file) {
  const out = {}
  for (const line of fs.readFileSync(file, 'utf8').split('\n')) {
    const m = line.match(/^([A-Z_]+)=(.*)$/)
    if (m) out[m[1]] = m[2].replace(/^["']|["']$/g, '')
  }
  return out
}

const MONTHS = ['January','February','March','April','May','June','July',
                'August','September','October','November','December']

const main = async () => {
  // ---- which session -----------------------------------------------------
  const rows = wantId
    ? sql(`SELECT * FROM qa_sessions WHERE id = ${Number(wantId)}`)
    : sql(`SELECT * FROM qa_sessions WHERE status = 'upcoming' ORDER BY scheduled_at LIMIT 1`)
  if (!rows.length) {
    fail('session', 'no upcoming session found')
    return report(null)
  }
  const s = rows[0]
  const when = new Date(s.scheduled_at.replace(' ', 'T'))
  const days = Math.round((when - new Date()) / 86400000)
  ok('session', `#${s.id} ${s.scheduled_at} (${days} days away)`)

  // ---- 1. will the invite actually fire, by the cron's own query? --------
  // Asking with the same BETWEEN window the lifecycle uses, not a convenient
  // one. A manual check against a different query proves nothing about a
  // scheduled job.
  if (s.invite_email_sent_at) {
    ok('invite', `already sent ${s.invite_email_sent_at}`)
  } else {
    const fires = sql(
      `SELECT DATE_SUB(scheduled_at, INTERVAL 8 DAY) AS opens,
              DATE_SUB(scheduled_at, INTERVAL 6 DAY) AS closes,
              (NOW() BETWEEN DATE_SUB(scheduled_at, INTERVAL 8 DAY)
                         AND DATE_SUB(scheduled_at, INTERVAL 6 DAY)) AS in_window
         FROM qa_sessions WHERE id = ${s.id}`)[0]
    const sel = sql(
      `SELECT id FROM qa_sessions
        WHERE status = 'upcoming' AND invite_email_sent_at IS NULL
          AND scheduled_at BETWEEN '${fires.opens}' + INTERVAL 6 DAY
                               AND '${fires.opens}' + INTERVAL 8 DAY`)
    if (!sel.some(r => Number(r.id) === Number(s.id))) {
      fail('invite window', `the lifecycle query does not select session ${s.id}`)
    } else if (Number(fires.in_window)) {
      warn('invite window', `open now, closes ${fires.closes} - it fires within the hour`)
    } else {
      ok('invite window', `opens ${fires.opens}, cron runs hourly at :05`)
    }
  }

  // ---- 2. audience -------------------------------------------------------
  const aud = sql(
    `SELECT COUNT(*) AS n FROM qa_audience a
      WHERE a.unsubscribed = 0
        AND LOWER(a.email) COLLATE utf8mb4_unicode_ci
            NOT IN (SELECT LOWER(email) FROM qa_signups WHERE session_id = ${s.id})`)[0].n
  const junk = sql(
    `SELECT COUNT(*) AS n FROM qa_audience
      WHERE unsubscribed = 0 AND (email LIKE '%@example.com' OR email NOT LIKE '%@%.%')`)[0].n
  const reg = sql(`SELECT COUNT(*) AS n FROM qa_signups WHERE session_id = ${s.id}`)[0].n
  ok('audience', `${aud} will be invited, ${reg} already registered`)
  if (Number(junk) > 0) fail('audience junk', `${junk} unsuppressed address(es) will bounce`)
  else ok('audience junk', 'no test or malformed addresses will be mailed')

  // ---- 3. the offers block, which has gone stale before ------------------
  let offers = null
  try {
    offers = JSON.parse(
      execFileSync('ssh', ['coaching-server',
        'cat /home/coaching/public_html/video-coaching/api/qa-offers.json'], { encoding: 'utf8' }))
  } catch (e) {
    fail('qa-offers.json', 'could not read or parse it: ' + e.message)
  }
  if (offers) {
    const stale = []
    for (const o of offers) {
      const status = await head(o.url)
      if (status !== 200) stale.push(`${o.title}: URL returns ${status}`)
      // Any month named in the blurb that is already behind us.
      for (const m of MONTHS) {
        if (!new RegExp(`\\b${m}|\\b${m.slice(0, 3)}\\b`, 'i').test(o.text)) continue
        const mi = MONTHS.indexOf(m)
        const guess = new Date(when.getFullYear(), mi, 28)
        if (guess < new Date(Date.now() - 86400000 * 7) && mi !== when.getMonth()) {
          stale.push(`${o.title}: mentions ${m}, which is behind us`)
        }
      }
    }
    if (stale.length) fail('qa-offers.json', stale.join('; '))
    else ok('qa-offers.json', `${offers.length} offer(s), all live and none past-dated`)
  }

  // ---- 4. the campaign pack, which silently served last month ------------
  const packHtml = await text(`${BASE}/static/campaign/`)
  const wantDate = `${when.toLocaleDateString('en-GB', { weekday: 'long' })}, ${MONTHS[when.getMonth()]} ${when.getDate()}`
  if (!packHtml) {
    fail('campaign pack', 'the page did not load')
  } else if (!packHtml.includes(wantDate)) {
    const got = (packHtml.match(/Live Q&amp;A - ([^<,]+, [A-Za-z]+ \d+)/) || [])[1] || 'unknown'
    fail('campaign pack', `shows "${got}", this session is "${wantDate}". Run qa-campaign-build.py --deploy`)
  } else {
    const slug = when.toLocaleDateString('en-US', { month: 'short' }).toLowerCase() + when.getDate()
    const img = await head(`${BASE}/static/campaign/qa-${slug}-story.jpg`)
    if (img !== 200) fail('campaign images', `qa-${slug}-story.jpg returns ${img}`)
    else ok('campaign pack', `${wantDate}, images present`)
  }

  // ---- 5. what the public site reads ------------------------------------
  let api = []
  try { api = JSON.parse(await text(`${BASE}/api/qa-sessions`)) } catch {}
  if (!api.length) fail('public sessions API', 'returned nothing; the site would show no session')
  else if (Number(api[0].id) !== Number(s.id) && !wantId) {
    fail('public sessions API', `the site shows session ${api[0].id}, not ${s.id}`)
  } else ok('public sessions API', `site shows #${api[0].id}, ${api[0].scheduled_at}`)

  // ---- 6. the Zoom room --------------------------------------------------
  try {
    const z = env(`${os.homedir()}/.zoom-api.env`)
    const tok = await (await fetch(
      `https://zoom.us/oauth/token?grant_type=account_credentials&account_id=${z.ZOOM_ACCOUNT_ID}`,
      { method: 'POST', headers: { Authorization: 'Basic ' + Buffer.from(`${z.ZOOM_CLIENT_ID}:${z.ZOOM_CLIENT_SECRET}`).toString('base64') } })).json()
    if (!tok.access_token) throw new Error('auth failed')
    const m = await (await fetch(`https://api.zoom.us/v2/meetings/${ZOOM_MEETING_ID}`,
      { headers: { Authorization: `Bearer ${tok.access_token}` } })).json()
    if (m.code) throw new Error(m.message)
    const rec = m.settings?.auto_recording
    if (rec !== 'cloud') fail('zoom', `auto_recording is "${rec}", not "cloud" - the call would not be recorded`)
    else ok('zoom', `room live, auto recording on${m.settings?.join_before_host ? '' : ', start it yourself a few minutes early'}`)
    if (!String(s.meeting_link || '').includes(ZOOM_MEETING_ID)) {
      fail('zoom link', `the session row points at a different meeting than ${ZOOM_MEETING_ID}`)
    }
  } catch (e) {
    warn('zoom', 'could not check: ' + e.message)
  }

  // ---- 7. the crons that do all of this unattended ----------------------
  try {
    const ct = execFileSync('ssh', ['coaching-server', 'crontab -l'], { encoding: 'utf8' })
    const needed = ['qa-lifecycle.php', 'qa-reminders.php']
    const missing = needed.filter(n => !ct.includes(n))
    if (missing.length) fail('crons', `not scheduled: ${missing.join(', ')}`)
    else ok('crons', 'lifecycle and reminders both scheduled')
  } catch (e) {
    warn('crons', 'could not read the crontab: ' + e.message)
  }

  // ---- 8. the last session's replay, which 404'd for a month -------------
  const past = sql(
    `SELECT id, scheduled_at, replay_url FROM qa_sessions
      WHERE status = 'past' AND replay_url IS NOT NULL
      ORDER BY scheduled_at DESC LIMIT 1`)[0]
  if (!past) {
    ok('last replay', 'no published replay yet')
  } else {
    const d = past.scheduled_at.slice(0, 10)
    const url = `${EVENTS}/live-qa/replay/${d.slice(0, 7)}/`
    const html = await text(url)
    if (!html) {
      fail('last replay page', `${url} did not load`)
    } else {
      // Read the src the page actually uses rather than rebuilding it by
      // convention. A URL guessed from the date can pass while the real one is
      // broken, or fail while the page is fine, which is how a cache-busted
      // video URL made this check lie about a page that worked.
      // The page carries a Vimeo iframe or, for older sessions, a self-hosted
      // <video>. Check whichever is actually there. Vimeo's oEmbed endpoint is
      // the honest test for an embed: it answers only if the video exists and
      // is embeddable, so a deleted or privacy-locked video fails here.
      const iframe = (html.match(/<iframe[^>]+src="(https:\/\/player\.vimeo\.com\/[^"]+)"/) || [])[1]
      const video  = (html.match(/<video[^>]+src="([^"]+)"/) || [])[1]
      if (iframe) {
        // Ask Vimeo directly rather than via oEmbed. oEmbed answers 200 even for
        // a wrong privacy hash, so it cannot tell a working embed from a broken
        // one; the API reports the transcode state and the privacy settings the
        // embed actually depends on.
        const vid = (iframe.match(/\/video\/(\d+)/) || [])[1]
        try {
          const tokenOut = execFileSync('ssh', ['coaching-server',
            `/usr/bin/php8.4 -r 'require "/home/coaching/public_html/video-coaching/api/config.php"; echo VIMEO_TOKEN;'`],
            { encoding: 'utf8' }).trim()
          const r = await fetch(
            `https://api.vimeo.com/videos/${vid}?fields=name,duration,privacy,transcode.status,player_embed_url`,
            { headers: { Authorization: `Bearer ${tokenOut}`, Accept: 'application/vnd.vimeo.*+json;version=3.4' } })
          if (!r.ok) {
            fail('last replay video', `Vimeo ${r.status} for video ${vid} - the player is dead for everyone who was emailed the link`)
          } else {
            const v = await r.json()
            const wantHash = (v.player_embed_url || '').match(/[?&]h=([a-z0-9]+)/i)?.[1] || ''
            const haveHash = (iframe.match(/[?&]h=([a-z0-9]+)/i) || [])[1] || ''
            if (v.transcode?.status !== 'complete') {
              fail('last replay video', `Vimeo transcode is "${v.transcode?.status}"`)
            } else if (v.privacy?.embed !== 'public') {
              fail('last replay video', `privacy.embed is "${v.privacy?.embed}" - it will not play on our page`)
            } else if (wantHash && haveHash !== wantHash) {
              fail('last replay video', `the page uses privacy hash ${haveHash || '(none)'}, Vimeo expects ${wantHash}`)
            } else {
              ok('last replay', `${d} page live, Vimeo ${vid} ok (${Math.round((v.duration || 0) / 60)} min)`)
            }
          }
        } catch (e) {
          warn('last replay video', 'could not reach the Vimeo API: ' + e.message)
        }
      } else if (video) {
        const vid = await head(video.startsWith('http') ? video : EVENTS + video)
        if (vid !== 200 && vid !== 206) {
          fail('last replay video', `${video} returns ${vid} - the player is dead for everyone who was emailed the link`)
        } else {
          ok('last replay', `${d} page and self-hosted video both live`)
        }
      } else {
        fail('last replay video', 'the page has neither a Vimeo embed nor a <video src>')
      }
    }
  }

  // ---- 9. can someone actually sign up right now? -----------------------
  // Checked against the real endpoint with a deliberately invalid payload: a
  // 4xx proves it is reachable and validating, without creating a signup.
  try {
    const r = await fetch(`${BASE}/api/qa-sessions/${s.id}/signup`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: '', email: '', website: '' }),
    })
    if (r.status >= 500) fail('signup endpoint', `returns ${r.status}`)
    else ok('signup endpoint', `reachable, rejects empty input with ${r.status}`)
  } catch (e) {
    fail('signup endpoint', 'unreachable: ' + e.message)
  }

  report(s)
}

function report(s) {
  const bad = results.filter(r => r.state === 'fail')
  const warns = results.filter(r => r.state === 'warn')

  if (asJson) {
    console.log(JSON.stringify({ ok: bad.length === 0, session: s?.id ?? null, results }, null, 2))
  } else {
    const mark = { ok: '  ok  ', warn: ' warn ', fail: ' FAIL ' }
    console.log('')
    for (const r of results) console.log(`[${mark[r.state]}] ${r.name.padEnd(22)} ${r.detail}`)
    console.log('')
    if (bad.length) console.log(`${bad.length} problem(s) to fix before this session goes out.`)
    else if (warns.length) console.log(`Clear, with ${warns.length} thing(s) worth a look.`)
    else console.log('All clear.')
  }
  process.exit(bad.length ? 1 : 0)
}

main().catch(e => { console.error('preflight crashed:', e.message); process.exit(2) })
