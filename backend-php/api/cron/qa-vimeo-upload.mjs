#!/usr/bin/env node
/**
 * Put a Q&A recording on Vimeo and print the id for the replay data file.
 *
 *   node qa-vimeo-upload.mjs <file.mp4> --session 12
 *   node qa-vimeo-upload.mjs <file.mp4> --session 12 --replace 123456789
 *
 * Why Vimeo and not the web server: the recordings are 250 to 500 MB and were
 * living under website/static/replay/ on the server only. The website deploy
 * rsyncs with --delete, so both the August and September replays were silently
 * destroyed, leaving a dead player for the 58 people who had been emailed the
 * links. Big media does not belong in a directory a deploy can reach.
 *
 * Vimeo also gives adaptive streaming, so a rider on a phone at the beach gets a
 * bitrate that works instead of a 500 MB progressive download.
 *
 * Uses the same account and the same tus approach as the WingCoach reply
 * uploader in admin.php. Account is Vimeo Pro, ~3.8 TB free, so a 500 MB session
 * every month is nothing.
 *
 * Privacy matches the reply videos: not listed or playable on vimeo.com, but
 * embeddable, because the replay page is the front door and it is meant to be
 * found.
 */
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'

const args = process.argv.slice(2)
const file = args.find(a => !a.startsWith('--') && a.endsWith('.mp4'))
const flag = n => { const i = args.indexOf('--' + n); return i > -1 ? args[i + 1] : null }
const sessionId = flag('session')
const replaceId = flag('replace')

const title = flag('title')
const note  = flag('description')

// --session names it from a Q&A row; --title takes the name directly, so the
// same uploader serves Jimmy's Human Optimization library, where the long
// pieces already have vimeo_id slots waiting in library-data.js.
if (!file || (!sessionId && !title)) {
  console.error('usage: node qa-vimeo-upload.mjs <file.mp4> --session <id> [--replace <vimeo_id>]')
  console.error('       node qa-vimeo-upload.mjs <file.mp4> --title "Name" [--description "..."]')
  process.exit(1)
}
if (!fs.existsSync(file)) { console.error(`no such file: ${file}`); process.exit(1) }

/** The token lives in config.php on the coaching server, not in a local env. */
function vimeoToken() {
  return execFileSync('ssh', ['coaching-server',
    `/usr/bin/php8.4 -r 'require "/home/coaching/public_html/video-coaching/api/config.php"; echo VIMEO_TOKEN;'`],
    { encoding: 'utf8' }).trim()
}

function sessionInfo(id) {
  const tmp = `/tmp/qa-vimeo-${process.pid}.php`
  fs.writeFileSync(tmp, `<?php
require_once '/home/coaching/public_html/video-coaching/api/config.php';
$s = getDb()->prepare('SELECT id, scheduled_at, title FROM qa_sessions WHERE id = ?');
$s->execute([${Number(id)}]);
echo json_encode($s->fetch(PDO::FETCH_ASSOC));`)
  execFileSync('scp', ['-q', tmp, 'coaching-server:/home/coaching/tmp/'])
  const name = path.basename(tmp)
  const out = execFileSync('ssh', ['coaching-server',
    `/usr/bin/php8.4 /home/coaching/tmp/${name}; rm -f /home/coaching/tmp/${name}`], { encoding: 'utf8' })
  fs.unlinkSync(tmp)
  return JSON.parse(out.trim())
}

const TOKEN = vimeoToken()
const H = {
  Authorization: `Bearer ${TOKEN}`,
  Accept: 'application/vnd.vimeo.*+json;version=3.4',
}

async function api(method, pathname, body) {
  const r = await fetch('https://api.vimeo.com' + pathname, {
    method,
    headers: body ? { ...H, 'Content-Type': 'application/json' } : H,
    body: body ? JSON.stringify(body) : undefined,
  })
  const j = await r.json().catch(() => ({}))
  if (!r.ok) throw new Error(`Vimeo ${r.status} on ${method} ${pathname}: ${JSON.stringify(j).slice(0, 300)}`)
  return j
}

const main = async () => {
  const size = fs.statSync(file).size
  let name, description, date = null

  if (sessionId) {
    const s = sessionInfo(sessionId)
    if (!s) throw new Error(`session ${sessionId} not found`)
    date = String(s.scheduled_at).slice(0, 10)
    const pretty = new Date(date + 'T12:00:00').toLocaleDateString('en-GB',
      { day: 'numeric', month: 'long', year: 'numeric' })
    console.log(`session ${s.id}  ${date}`)
    name = `Live Q&A with Michi Rossmeier - ${pretty}`
    description =
      `The full recording of the monthly Tricktionary live Q&A with Michi Rossmeier, ${pretty}. ` +
      `Wing technique, gear, and questions from riders. ` +
      `Chapters and the written answers are on the replay page: ` +
      `https://events.tricktionary.com/live-qa/replay/${date.slice(0, 7)}/`
  } else {
    name = title
    description = note || ''
    console.log(`title   ${name}`)
  }
  console.log(`file    ${path.basename(file)}  ${(size / 1048576).toFixed(0)} MB\n`)

  let videoId = replaceId
  let uploadLink

  if (replaceId) {
    // Replacing keeps the id, so every page and email already pointing at it
    // keeps working.
    console.log(`  replacing the source of existing video ${replaceId}`)
    const v = await api('POST', `/videos/${replaceId}/versions`, {
      file_name: path.basename(file),
      upload: { approach: 'tus', size },
    })
    uploadLink = v.upload?.upload_link
  } else {
    const v = await api('POST', '/me/videos', {
      upload: { approach: 'tus', size },
      name,
      description,
      // Not findable on vimeo.com, but embeddable on our page. Same shape the
      // WingCoach reply videos use.
      privacy: { view: 'disable', embed: 'public', download: false, add: false },
      content_rating: ['safe'],
    })
    videoId = String(v.uri || '').replace(/\D/g, '')
    uploadLink = v.upload?.upload_link
  }
  if (!videoId || !uploadLink) throw new Error('Vimeo returned no upload link')

  // ---- tus upload, in chunks so a 500 MB file is not one fragile request ----
  const CHUNK = 32 * 1024 * 1024
  const fd = fs.openSync(file, 'r')
  let offset = 0
  let lastPct = -1
  try {
    while (offset < size) {
      const len = Math.min(CHUNK, size - offset)
      const buf = Buffer.allocUnsafe(len)
      fs.readSync(fd, buf, 0, len, offset)
      const r = await fetch(uploadLink, {
        method: 'PATCH',
        headers: {
          'Tus-Resumable': '1.0.0',
          'Upload-Offset': String(offset),
          'Content-Type': 'application/offset+octet-stream',
        },
        body: buf,
      })
      if (!r.ok) throw new Error(`tus PATCH ${r.status} at offset ${offset}: ${(await r.text()).slice(0, 200)}`)
      const next = Number(r.headers.get('upload-offset'))
      if (!Number.isFinite(next) || next <= offset) {
        throw new Error(`tus did not advance past ${offset} (got ${r.headers.get('upload-offset')})`)
      }
      offset = next
      const pct = Math.floor(offset / size * 100)
      if (pct >= lastPct + 10) { process.stdout.write(`  uploaded ${pct}%\n`); lastPct = pct }
    }
  } finally {
    fs.closeSync(fd)
  }
  console.log(`  upload complete (${offset} of ${size} bytes)\n`)

  // ---- wait for the transcode, else the first viewer gets the low rendition --
  process.stdout.write('  transcoding')
  for (let i = 0; i < 120; i++) {
    const v = await api('GET', `/videos/${videoId}?fields=transcode.status`)
    const st = v.transcode?.status
    if (st === 'complete') { console.log('  complete\n'); break }
    if (st === 'error') throw new Error('Vimeo transcode failed')
    process.stdout.write('.')
    await new Promise(r => setTimeout(r, 10000))
  }

  // White-label the player. Without this the viewer sees the Vimeo logo plus
  // like / share / embed buttons, which makes a replay on our own page read as
  // somebody else's video, and hands anyone the code to re-embed it past the
  // email gate.
  await api('PATCH', `/videos/${videoId}`, {
    embed: {
      logos: { vimeo: false },
      buttons: { watchlater: false, share: false, embed: false, like: false,
                 fullscreen: true, scaling: false },
      title: { name: 'hide', owner: 'hide', portrait: 'hide' },
      color: '0ea5e9',
    },
  })
  console.log('  player white-labelled (no Vimeo logo, no share or embed buttons)')

  const meta = await api('GET', `/videos/${videoId}?fields=link,player_embed_url,duration,privacy,embed.logos`)
  // With view=disable the embed only plays when the URL carries the privacy
  // hash, so it has to travel with the id into the data file. An iframe built
  // from the id alone renders a "private video" box.
  const hash = (meta.player_embed_url || '').match(/[?&]h=([a-z0-9]+)/i)?.[1] || ''
  console.log(`  vimeo id       ${videoId}`)
  console.log(`  privacy hash   ${hash || '(none - the video is public)'}`)
  console.log(`  duration       ${Math.round((meta.duration || 0) / 60)} min`)
  console.log(`  privacy        view=${meta.privacy?.view}, embed=${meta.privacy?.embed}`)
  if (date) {
    console.log(`
Next:
  1. add to projects/events-site/live-qa/replay/data/${date.slice(0, 7)}.json:
       "vimeo_id": "${videoId}",
       "vimeo_hash": "${hash}"
     Leave "video" in place as a fallback until the page is confirmed working.
  2. cd projects/events-site/live-qa/replay && python3 build-replays.py
  3. cd projects/events-site && bash deploy-events.sh site`)
  } else {
    console.log(`
Next: set these on the piece, e.g. in Jimmy's library-data.js:
       vimeo_id: "${videoId}", vimeo_hash: "${hash}"`)
  }
}

main().catch(e => { console.error('\nfailed:', e.message); process.exit(1) })
