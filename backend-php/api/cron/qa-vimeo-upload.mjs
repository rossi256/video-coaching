#!/usr/bin/env node
/**
 * Put a Q&A recording on Vimeo and print the id for the replay data file.
 *
 *   node qa-vimeo-upload.mjs <file.mp4> --session 12 --number 2 [--topic "Pumping, jibes and Tarifa"]
 *   node qa-vimeo-upload.mjs <file.mp4> --session 12 --number 2 --replace 123456789
 *   node qa-vimeo-upload.mjs --existing 123456789 --session 12 --number 2 [<file.mp4>]
 *
 * --existing uploads nothing: it brings a video that is already on Vimeo up to
 * the same title, folder and embed rules, verifies it, and records it in the
 * manifest. Pass the local file too and the durations are compared.
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
 * uploader in admin.php. Account is Vimeo Pro (tricktionarygenius), 4 TiB, so a
 * 500 MB session every month is nothing.
 *
 * Privacy: not listed or playable on vimeo.com, and embeddable ONLY on our own
 * domains. The replays sit behind the email gate on the replay page; with
 * embed=public anybody holding the id and hash could put them on any site.
 *
 * The last line printed is `@@VIMEO {json}` so qa-autobuild.mjs can read the
 * result without scraping the human-readable lines above it. Exit is non-zero
 * if the video does not verify as playable, and a caller must then keep its
 * local copy.
 */
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'

const args = process.argv.slice(2)
const file = args.find(a => !a.startsWith('--') && a.endsWith('.mp4'))
const flag = n => { const i = args.indexOf('--' + n); return i > -1 ? args[i + 1] : null }
const sessionId  = flag('session')
const replaceId  = flag('replace')
const existingId = flag('existing')
let   number     = flag('number')
const topic      = flag('topic')

const title = flag('title')
const note  = flag('description')

// Where the Q&A replays may be embedded. Anywhere else Vimeo answers 403.
const DOMAINS = ['events.tricktionary.com', 'coaching.tricktionary.com', 'tricktionary.com']
const FOLDER  = 'Live Q&A Recordings'
// One row per session: number, Vimeo id, date, duration. The replay data files
// carry the same ids, but per month and mixed in with chapters and FAQ.
const MANIFEST = path.resolve(import.meta.dirname, '../../../docs/qa-recordings.json')
const REPLAY_DATA = path.resolve(import.meta.dirname, '../../../../events-site/live-qa/replay/data')

/**
 * The replay number counts the LIBRARY, not every session ever held: the same
 * rule qa-autobuild.mjs uses, so the Vimeo title and the page agree. A month
 * that already has a data file keeps its number.
 */
function libraryNumber(ym) {
  const months = fs.readdirSync(REPLAY_DATA).filter(f => /^20\d\d-\d\d\.json$/.test(f))
  if (months.includes(`${ym}.json`)) return JSON.parse(fs.readFileSync(`${REPLAY_DATA}/${ym}.json`, 'utf8')).number
  return months.length + 1
}

// --session names it from a Q&A row; --title takes the name directly, so the
// same uploader serves Jimmy's Human Optimization library, where the long
// pieces already have vimeo_id slots waiting in library-data.js.
if ((!file && !existingId) || (!sessionId && !title)) {
  console.error('usage: node qa-vimeo-upload.mjs <file.mp4> --session <id> [--number <n>] [--topic "..."] [--replace <vimeo_id>]')
  console.error('       node qa-vimeo-upload.mjs --existing <vimeo_id> --session <id> [--number <n>] [--topic "..."] [<file.mp4>]')
  console.error('       node qa-vimeo-upload.mjs <file.mp4> --title "Name" [--description "..."]')
  process.exit(1)
}
if (file && !fs.existsSync(file)) { console.error(`no such file: ${file}`); process.exit(1) }

/** The token lives in config.local.php on the coaching server, not in a local env. */
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
if (!TOKEN) { console.error('VIMEO_TOKEN is empty: it belongs in api/config.local.php on coaching-server'); process.exit(1) }
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
  if (r.status === 204) return {}
  const j = await r.json().catch(() => ({}))
  if (!r.ok) throw new Error(`Vimeo ${r.status} on ${method} ${pathname}: ${JSON.stringify(j).slice(0, 300)}`)
  return j
}

/** Restrict embedding to DOMAINS. The whitelist only exists once embed=whitelist is set. */
async function lockToOurDomains(videoId) {
  await api('PATCH', `/videos/${videoId}`, { privacy: { view: 'disable', embed: 'whitelist', download: false, add: false } })
  for (const d of DOMAINS) {
    await api('PUT', `/videos/${videoId}/privacy/domains/${d}`, { allow_hd: true })
  }
  const got = await api('GET', `/videos/${videoId}/privacy/domains?per_page=50`)
  const have = (got.data || []).map(x => x.domain)
  const missing = DOMAINS.filter(d => !have.includes(d))
  if (missing.length) throw new Error(`embed whitelist is missing ${missing.join(', ')}`)
  console.log(`  embed limited to ${have.join(', ')}`)
}

async function putInFolder(videoId) {
  const list = await api('GET', '/me/projects?per_page=100&fields=uri,name')
  let folder = (list.data || []).find(p => p.name === FOLDER)
  if (!folder) folder = await api('POST', '/me/projects', { name: FOLDER })
  const folderId = folder.uri.split('/').pop()
  await api('PUT', `/me/projects/${folderId}/videos/${videoId}`)
  console.log(`  in folder "${FOLDER}" (${folderId})`)
}

function localSeconds(f) {
  try {
    return Number(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration',
      '-of', 'default=nw=1:nk=1', f], { encoding: 'utf8' }).trim())
  } catch { return null }
}

/**
 * "Uploaded" is not "plays". Each check here can come back negative, and the
 * last one is run in both directions so a whitelist that lets everything
 * through fails as loudly as one that blocks our own page.
 */
async function verifyPlayable(videoId, localFile, wantWhitelist) {
  const problems = []
  const v = await api('GET', `/videos/${videoId}?fields=status,is_playable,transcode.status,duration,play,player_embed_url`)
  if (v.transcode?.status !== 'complete') problems.push(`transcode is "${v.transcode?.status}"`)
  if (v.status !== 'available') problems.push(`status is "${v.status}"`)
  if (!v.is_playable) problems.push('Vimeo says it is not playable')

  const hls = v.play?.hls?.link
  if (!hls) problems.push('no HLS stream')
  else {
    const r = await fetch(hls)
    const body = await r.text()
    if (!r.ok || !body.startsWith('#EXTM3U')) problems.push(`HLS manifest answered ${r.status}`)
  }

  if (localFile) {
    const local = localSeconds(localFile)
    if (local == null) problems.push('could not read the local duration')
    else if (Math.abs(local - v.duration) > 3) problems.push(`Vimeo has ${v.duration}s, the local file ${Math.round(local)}s`)
    else console.log(`  duration       ${v.duration}s on Vimeo, ${Math.round(local)}s locally`)
  }

  const embed = v.player_embed_url
  const asFrom = async ref => (await fetch(embed, ref ? { headers: { Referer: ref } } : {})).status
  const ours = await asFrom('https://events.tricktionary.com/live-qa/replay/')
  if (ours !== 200) problems.push(`the embed answers ${ours} on events.tricktionary.com`)
  if (wantWhitelist) {
    const foreign = await asFrom('https://example.org/')
    if (foreign !== 403) problems.push(`the embed answers ${foreign} on a foreign site, expected 403`)
    else console.log('  embed plays on events.tricktionary.com, refused (403) elsewhere')
  }

  if (problems.length) throw new Error('not verified: ' + problems.join('; '))
  console.log('  verified: transcoded, playable, HLS stream up')
  return v
}

function recordInManifest(row) {
  const rows = fs.existsSync(MANIFEST) ? JSON.parse(fs.readFileSync(MANIFEST, 'utf8')) : []
  const kept = rows.filter(r => r.number !== row.number && r.vimeo_id !== row.vimeo_id)
  kept.push(row)
  kept.sort((a, b) => a.number - b.number)
  fs.writeFileSync(MANIFEST, JSON.stringify(kept, null, 2) + '\n')
  console.log(`  manifest       ${path.relative(process.cwd(), MANIFEST)} (#${row.number})`)
}

const main = async () => {
  const size = file ? fs.statSync(file).size : 0
  let name, description, date = null

  if (sessionId) {
    const s = sessionInfo(sessionId)
    if (!s) throw new Error(`session ${sessionId} not found`)
    date = String(s.scheduled_at).slice(0, 10)
    if (!number) number = libraryNumber(date.slice(0, 7))
    const pretty = new Date(date + 'T12:00:00').toLocaleDateString('en-GB',
      { day: 'numeric', month: 'long', year: 'numeric' })
    console.log(`session ${s.id}  #${number}  ${date}`)
    // Zoom titles every one "Wingfoil Q&A with Michi", so the number in the
    // replay library is the only thing that tells them apart.
    name = `Live Q&A #${number} - ${pretty}${topic ? ' - ' + topic : ''}`
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
  if (file) console.log(`file    ${path.basename(file)}  ${(size / 1048576).toFixed(0)} MB\n`)

  let videoId = replaceId || existingId
  let uploadLink

  if (existingId) {
    console.log(`  existing video ${existingId}, nothing to upload`)
    await api('PATCH', `/videos/${existingId}`, { name, description })
  } else if (replaceId) {
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
      // Not findable on vimeo.com. A Q&A session is then narrowed to our
      // domains below; a --title upload keeps the reply-video shape.
      privacy: { view: 'disable', embed: 'public', download: false, add: false },
      content_rating: ['safe'],
    })
    videoId = String(v.uri || '').replace(/\D/g, '')
    uploadLink = v.upload?.upload_link
  }
  if (!videoId || (!existingId && !uploadLink)) throw new Error('Vimeo returned no upload link')

  if (!existingId) {
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
  }

  // ---- wait for the transcode, else the first viewer gets the low rendition --
  // 40 minutes. A 75 minute session has taken up to 25.
  process.stdout.write('  transcoding')
  for (let i = 0; i < 240; i++) {
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

  if (sessionId) {
    await lockToOurDomains(videoId)
    await putInFolder(videoId)
  }

  const verified = await verifyPlayable(videoId, file, Boolean(sessionId))

  const meta = await api('GET', `/videos/${videoId}?fields=link,player_embed_url,duration,privacy,embed.logos,width,height`)
  // With view=disable the embed only plays when the URL carries the privacy
  // hash, so it has to travel with the id into the data file. An iframe built
  // from the id alone renders a "private video" box.
  const hash = (meta.player_embed_url || '').match(/[?&]h=([a-z0-9]+)/i)?.[1] || ''
  console.log(`  vimeo id       ${videoId}`)
  console.log(`  privacy hash   ${hash || '(none - the video is public)'}`)
  // Zoom does not always record 16:9. The August session came out 1920x1028,
  // and in a hardcoded 16:9 box Vimeo letterboxed it with WHITE bars top and
  // bottom. The page sizes its own box from these numbers instead.
  console.log(`  dimensions     ${meta.width}x${meta.height}  (ratio ${(meta.width / meta.height).toFixed(3)}${
    Math.abs(meta.width / meta.height - 16 / 9) > 0.01 ? ', NOT 16:9' : ''})`)
  console.log(`  duration       ${Math.round((meta.duration || 0) / 60)} min`)
  console.log(`  privacy        view=${meta.privacy?.view}, embed=${meta.privacy?.embed}`)

  if (sessionId) {
    recordInManifest({
      number: Number(number), session_id: Number(sessionId), date,
      vimeo_id: videoId, vimeo_hash: hash,
      duration_s: verified.duration, width: meta.width, height: meta.height,
      title: name, verified_at: new Date().toISOString(),
    })
    console.log(`
Next:
  1. add to projects/events-site/live-qa/replay/data/${date.slice(0, 7)}.json:
       "vimeo_id": "${videoId}",
       "vimeo_hash": "${hash}",
       "vimeo_w": ${meta.width}, "vimeo_h": ${meta.height}
  2. cd projects/events-site/live-qa/replay && python3 build-replays.py
  3. cd projects/events-site && bash deploy-events.sh site
  4. delete the local MP4 once the page plays: this run verified the Vimeo copy`)
  } else {
    console.log(`
Next: set these on the piece, e.g. in Jimmy's library-data.js:
       vimeo_id: "${videoId}", vimeo_hash: "${hash}"`)
  }
  console.log('@@VIMEO ' + JSON.stringify({ id: videoId, hash, w: meta.width, h: meta.height, duration: verified.duration }))
}

main().catch(e => { console.error('\nfailed:', e.message); process.exit(1) })
