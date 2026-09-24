#!/usr/bin/env node
/**
 * Pull everything a finished Q&A produced, in one command.
 *
 * Before the Zoom scopes were added on 2 September this had to be done by
 * driving a browser through a passcoded share page. Now it is one call:
 * recording, transcript, chat and the real attendance report.
 *
 *   node qa-postprocess.mjs <session_id> [--out DIR]
 *   node qa-postprocess.mjs 12
 *
 * Writes into projects/video-coaching/docs/qa-YYYY-MM-DD/ by default:
 *   qa-<date>.mp4        the recording
 *   transcript.vtt       Zoom's transcript
 *   qa-transcript.txt    the same, flattened to "time  speaker: text"
 *   chat.txt             in-call chat
 *   attendance.json      who actually attended and for how long
 *   summary.txt          the numbers, for the session data file
 *
 * Read only against Zoom and the coaching database. It never sends anything and
 * never sets replay_url, which is deliberate: setting replay_url is what
 * triggers the replay email to every registrant.
 */
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import os from 'node:os'

const ROOT = '/home/openclaw/.openclaw/workspace'
const ZOOM_MEETING_ID = '86444732437'   // the standing recurring Q&A room

const sessionId = process.argv[2]
if (!sessionId || !/^\d+$/.test(sessionId)) {
  console.error('usage: node qa-postprocess.mjs <session_id> [--out DIR]')
  process.exit(1)
}
const outFlag = process.argv.indexOf('--out')

function env(file) {
  const out = {}
  for (const line of fs.readFileSync(file, 'utf8').split('\n')) {
    const m = line.match(/^([A-Z_]+)=(.*)$/)
    if (m) out[m[1]] = m[2].replace(/^["']|["']$/g, '')
  }
  return out
}

async function zoomToken() {
  const z = env(`${os.homedir()}/.zoom-api.env`)
  const r = await fetch(
    `https://zoom.us/oauth/token?grant_type=account_credentials&account_id=${z.ZOOM_ACCOUNT_ID}`,
    { method: 'POST',
      headers: { Authorization: 'Basic ' + Buffer.from(`${z.ZOOM_CLIENT_ID}:${z.ZOOM_CLIENT_SECRET}`).toString('base64') } })
  const j = await r.json()
  if (!j.access_token) throw new Error('Zoom auth failed: ' + JSON.stringify(j).slice(0, 200))
  return j.access_token
}

/** Ask the coaching DB for the session, via a tiny throwaway script on the server. */
function sessionInfo(id) {
  const php = `<?php
require_once '/home/coaching/public_html/video-coaching/api/config.php';
$s = getDb()->prepare('SELECT id, scheduled_at, title, (SELECT COUNT(*) FROM qa_signups WHERE session_id=?) reg FROM qa_sessions WHERE id=?');
$s->execute([${id}, ${id}]);
echo json_encode($s->fetch(PDO::FETCH_ASSOC));`
  const tmp = `/tmp/qa-sess-${process.pid}.php`
  fs.writeFileSync(tmp, php)
  execFileSync('scp', ['-q', tmp, 'coaching-server:/home/coaching/tmp/'])
  const out = execFileSync('ssh', ['coaching-server',
    `/usr/bin/php8.4 /home/coaching/tmp/${path.basename(tmp)}; rm -f /home/coaching/tmp/${path.basename(tmp)}`],
    { encoding: 'utf8' })
  fs.unlinkSync(tmp)
  return JSON.parse(out.trim())
}

/** Zoom's VTT -> "00:12:34  Speaker: text", one turn per line. */
function vttToText(vtt) {
  const lines = vtt.split(/\r?\n/)
  const turns = []
  for (let i = 0; i < lines.length; i++) {
    const m = lines[i].match(/^(\d\d):(\d\d):(\d\d)\.\d+\s+-->/)
    if (!m) continue
    const body = (lines[i + 1] || '').trim()
    if (!body) continue
    const sp = body.match(/^([^:]{1,40}):\s*(.*)$/)
    const who = sp ? sp[1] : ''
    const txt = sp ? sp[2] : body
    const ts = `${m[1]}:${m[2]}:${m[3]}`
    const last = turns[turns.length - 1]
    if (last && last.who === who) last.txt += ' ' + txt
    else turns.push({ ts, who, txt })
  }
  return turns.map(t => `${t.ts}  ${t.who}: ${t.txt}`).join('\n')
}

const main = async () => {
  const s = sessionInfo(sessionId)
  if (!s) throw new Error(`session ${sessionId} not found`)
  const date = String(s.scheduled_at).slice(0, 10)
  const outDir = outFlag > -1 ? process.argv[outFlag + 1]
                              : `${ROOT}/projects/video-coaching/docs/qa-${date}`
  fs.mkdirSync(outDir, { recursive: true })
  console.log(`session ${s.id}  ${s.scheduled_at}  ${s.reg} registered`)
  console.log(`into ${outDir}\n`)

  const tok = await zoomToken()
  const H = { Authorization: `Bearer ${tok}` }

  // --- attendance -------------------------------------------------------
  // Zoom's recordings list caps the range at about 30 days and silently clamps
  // a wider one instead of erroring, so a 60 day query returns only the recent
  // half. That is how "the August recording is gone" was concluded from a query
  // that could not have returned it. Always ask in windows of 30 days or less.
  const rep = await (await fetch(
    `https://api.zoom.us/v2/report/meetings/${ZOOM_MEETING_ID}/participants?page_size=300`, { headers: H })).json()
  if (rep.code) throw new Error('attendance: ' + rep.message)
  const byName = {}
  for (const p of rep.participants || []) {
    const k = (p.name || p.user_email || '?').trim()
    byName[k] = (byName[k] || 0) + (Number(p.duration) || 0)
  }
  const people = Object.entries(byName)
    .map(([name, secs]) => ({ name, minutes: Math.round(secs / 60) }))
    .sort((a, b) => b.minutes - a.minutes)
  const bots = people.filter(p => /notetaker|otter|fireflies|read\.ai/i.test(p.name))
  const humans = people.filter(p => !bots.includes(p))
  fs.writeFileSync(`${outDir}/attendance.json`, JSON.stringify({ people, bots: bots.map(b => b.name) }, null, 2))
  console.log(`  attendance.json     ${humans.length} people (+${bots.length} bot)`)

  // --- recording files --------------------------------------------------
  const rec = await (await fetch(
    `https://api.zoom.us/v2/meetings/${ZOOM_MEETING_ID}/recordings`, { headers: H })).json()
  if (rec.code) throw new Error('recordings: ' + rec.message)
  if (String(rec.start_time).slice(0, 10) !== date) {
    console.log(`  ! newest recording is ${String(rec.start_time).slice(0,10)}, not ${date}. Check the session id.`)
  }

  const want = { MP4: `qa-${date}.mp4`, TRANSCRIPT: 'transcript.vtt', CHAT: 'chat.txt' }
  for (const f of rec.recording_files || []) {
    const name = want[f.file_type]
    if (!name) continue
    const r = await fetch(f.download_url, { headers: H })
    const buf = Buffer.from(await r.arrayBuffer())
    fs.writeFileSync(`${outDir}/${name}`, buf)
    console.log(`  ${name.padEnd(20)}${(buf.length / 1048576).toFixed(1)} MB`)
  }

  // --- flatten the transcript ------------------------------------------
  const vttPath = `${outDir}/transcript.vtt`
  if (fs.existsSync(vttPath)) {
    const txt = vttToText(fs.readFileSync(vttPath, 'utf8'))
    fs.writeFileSync(`${outDir}/qa-transcript.txt`, txt)
    console.log(`  qa-transcript.txt   ${txt.split('\n').length} turns, ${txt.split(/\s+/).length} words`)
  }

  // --- the numbers for the session data file ----------------------------
  const summary = [
    `session_id     ${s.id}`,
    `date           ${date}`,
    `duration_min   ${rec.duration}`,
    `registered     ${s.reg}`,
    `attended       ${humans.length}`,
    `show_rate      ${Math.round(humans.length / s.reg * 100)}%`,
    `video          https://coaching.tricktionary.com/video-coaching/static/replay/qa-${date}.mp4`,
    '',
    'attended, longest first:',
    ...humans.map(p => `  ${String(p.minutes).padStart(3)} min  ${p.name}`),
    ...(bots.length ? ['', 'bots: ' + bots.map(b => b.name).join(', ')] : []),
  ].join('\n')
  fs.writeFileSync(`${outDir}/summary.txt`, summary + '\n')
  console.log(`  summary.txt\n`)
  console.log(summary.split('\n').slice(0, 7).map(l => '  ' + l).join('\n'))

  console.log(`\nNext:
  1. put the recording on Vimeo (NOT on the web server - a deploy's rsync
     --delete destroyed both earlier replays that were kept there):
     node qa-vimeo-upload.mjs ${outDir}/qa-${date}.mp4 --session ${s.id}
  2. write projects/events-site/live-qa/replay/data/${date.slice(0, 7)}.json,
     including the vimeo_id and vimeo_hash the uploader prints
  3. cd projects/events-site/live-qa/replay && python3 build-replays.py
  4. cd projects/events-site && bash deploy-events.sh site
  5. node qa-preflight.mjs   (confirms the embed really plays)
  6. review the page, THEN set replay_url. That sends the replay email.`)
}

main().catch(e => { console.error('failed:', e.message); process.exit(1) })
