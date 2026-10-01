#!/usr/bin/env node
/**
 * Gate a drafted replay before it is allowed to publish itself.
 *
 *   node qa-verify-replay.mjs 2026-10            # verify one month
 *   node qa-verify-replay.mjs 2026-10 --json     # machine readable
 *
 * Exit 0 = safe to publish. Non-zero = do not publish, with named reasons.
 *
 * Why this exists in this shape: the first automatic build passed the only gate
 * there was, "does the video return 200", and would still have published two
 * real defects. It numbered the session "Replay #9" by counting every session
 * ever held against a library of three, and 8 of its 22 chapter timestamps were
 * wrong, one by nine minutes, so clicking a chapter landed the reader somewhere
 * else entirely. A gate that only ever returns yes is not a gate.
 *
 * Every check below is written so it CAN fail, and the suite is exercised
 * against deliberately broken builds (--self-test) so that is not a claim.
 */
import fs from 'node:fs'
import path from 'node:path'

const HERE = import.meta.dirname
const SITE = path.resolve(HERE, '../../../../events-site')
const REPLAY = path.join(SITE, 'live-qa/replay')
const BASE = 'https://events.tricktionary.com'

const args = process.argv.slice(2)
const JSON_OUT = args.includes('--json')
const SELFTEST = args.includes('--self-test')
const SLUG = args.find(a => /^\d{4}-\d{2}$/.test(a))

const results = []
const pass = (name, detail) => results.push({ name, state: 'pass', detail })
const fail = (name, detail) => results.push({ name, state: 'fail', detail })
const warn = (name, detail) => results.push({ name, state: 'warn', detail })

// Phrases that mark a draft as machine written rather than Michi's.
const AI_TELLS = [
  'dive into', 'dive in', 'unpack', 'elevate', 'delve', 'in this article',
  'let us explore', 'key takeaway', 'in summary', 'navigate the', 'seamless',
  'leverage', 'robust', 'comprehensive guide', 'game changer',
]
const PLACEHOLDERS = ['lorem', 'todo', 'tbd', 'xxx', 'undefined', 'null', '[insert', 'placeholder']

/** Pull the transcript so timestamps can be checked against reality. */
function transcriptIndex(date) {
  const f = path.resolve(HERE, `../../../docs/qa-${date}/qa-transcript.txt`)
  if (!fs.existsSync(f)) return null
  const idx = []
  for (const line of fs.readFileSync(f, 'utf8').split('\n')) {
    const m = line.match(/^(\d\d):(\d\d):(\d\d)\s+([^:]{1,40}):\s*(.*)$/)
    if (!m) continue
    idx.push({ t: Number(m[1]) * 3600 + Number(m[2]) * 60 + Number(m[3]), who: m[4], text: m[5] })
  }
  return idx
}

async function verify(slug, data) {
  const d = data
  const dur = Number(d.duration_min) * 60

  // ---- 1. shape -----------------------------------------------------------
  for (const k of ['slug', 'number', 'date', 'duration_min', 'title', 'summary', 'chapters']) {
    if (d[k] === undefined || d[k] === null || d[k] === '') fail('shape', `missing ${k}`)
  }
  if (results.some(r => r.state === 'fail')) return
  pass('shape', 'all required fields present')

  // ---- 2. numbering, against the library not the session history ----------
  const files = fs.readdirSync(`${REPLAY}/data`).filter(f => /^20\d\d-\d\d\.json$/.test(f)).sort()
  const expected = files.indexOf(`${slug}.json`) + 1
  if (Number(d.number) !== expected) {
    fail('numbering', `page says #${d.number}, but it is item ${expected} of ${files.length} in the library`)
  } else {
    pass('numbering', `#${d.number} of ${files.length}`)
  }

  // ---- 3. chapters --------------------------------------------------------
  const ch = d.chapters || []
  if (ch.length < 6) fail('chapters', `only ${ch.length}, expected at least 6`)
  else pass('chapters', `${ch.length} chapters`)

  const times = ch.map(c => Number(c.t))
  if (times.some(t => !Number.isFinite(t) || t < 0)) fail('chapter times', 'a timestamp is not a positive number')
  if (times.some(t => t > dur)) {
    const bad = times.filter(t => t > dur)
    fail('chapter times', `${bad.length} beyond the end of a ${d.duration_min} min video (${bad.join(', ')}s)`)
  }
  const sorted = [...times].sort((a, b) => a - b)
  if (JSON.stringify(times) !== JSON.stringify(sorted)) fail('chapter order', 'timestamps are not in order')
  if (new Set(times).size !== times.length) fail('chapter times', 'two chapters share a timestamp')
  // A real session spreads across its hour. Everything bunched in the first
  // quarter means the model gave up partway through the transcript.
  const spread = (Math.max(...times) - Math.min(...times)) / dur
  if (spread < 0.5) fail('chapter spread', `chapters cover only ${Math.round(spread * 100)}% of the session`)
  else pass('chapter spread', `${Math.round(spread * 100)}% of the session covered`)

  // ---- 4. timestamps against the transcript -------------------------------
  const idx = transcriptIndex(d.date)
  if (!idx) {
    warn('transcript check', 'no transcript on disk, timestamps unverified')
  } else {
    // Every chapter should land within a minute of somebody actually speaking.
    let orphans = 0
    for (const t of times) {
      const near = idx.find(l => Math.abs(l.t - t) <= 60)
      if (!near) orphans++
    }
    if (orphans) fail('transcript check', `${orphans} chapter(s) land where nobody is speaking`)
    else pass('transcript check', `all ${times.length} anchored to the transcript`)
  }

  // ---- 5. language --------------------------------------------------------
  const prose = [d.title, d.summary, ...ch.map(c => c.title),
                 ...(d.faq || []).flatMap(f => [f.q, f.a])].join(' ').toLowerCase()
  const tells = AI_TELLS.filter(p => prose.includes(p))
  if (tells.length) fail('voice', `machine phrasing: ${tells.join(', ')}`)
  else pass('voice', 'no AI-tell phrases')

  const ph = PLACEHOLDERS.filter(p => prose.includes(p))
  if (ph.length) fail('placeholders', `unfinished text: ${ph.join(', ')}`)
  else pass('placeholders', 'none')

  const dashes = [d.title, d.summary, ...ch.map(c => c.title)].join(' ').match(/[–—]/g)
  if (dashes) fail('dashes', `${dashes.length} em or en dash, house style is a spaced hyphen`)
  else pass('dashes', 'none')

  if (String(d.title).length > 70) fail('title', `${d.title.length} chars, too long for a card`)
  else pass('title', `"${d.title}"`)

  // ---- 6. FAQ -------------------------------------------------------------
  const faq = d.faq || []
  if (faq.length) {
    const thin = faq.filter(f => String(f.a || '').split(/\s+/).length < 15)
    if (thin.length) fail('faq', `${thin.length} answer(s) under 15 words`)
    else pass('faq', `${faq.length} entries, all substantive`)
    if (faq.some(f => !String(f.q || '').trim())) fail('faq', 'an entry has no question')
  } else {
    warn('faq', 'no FAQ entries')
  }

  // ---- 7. numbers that must agree with each other -------------------------
  if (d.registered && d.attended && Number(d.attended) > Number(d.registered)) {
    fail('attendance', `${d.attended} attended of ${d.registered} registered`)
  } else if (d.attended) {
    pass('attendance', `${d.attended} of ${d.registered}`)
  }

  // ---- 8. the page as published ------------------------------------------
  const url = `${BASE}/live-qa/replay/${slug}/`
  let html = ''
  try {
    const r = await fetch(url + '?cb=' + Date.now())
    if (!r.ok) { fail('page', `${url} returns ${r.status}`); return }
    html = await r.text()
  } catch (e) { fail('page', `could not load: ${e.message}`); return }

  // Count the chapter list only. FAQ timestamps carry data-t as well, so a
  // naive count of those attributes reads 23 chapters plus 6 FAQ as 29
  // chapters and fails a page that is actually correct.
  const chapterBlock = (html.match(/<ul class="chapters"[^>]*>([\s\S]*?)<\/ul>/) || [])[1] || ''
  const onPage = (chapterBlock.match(/data-t="\d+"/g) || []).length
  const faqOnPage = (html.match(/class="qa-t" data-t="\d+"/g) || []).length
  if (onPage !== ch.length) fail('page', `page renders ${onPage} chapters, data has ${ch.length}`)
  else pass('page', `${onPage} chapters and ${faqOnPage} FAQ rendered`)

  if (!html.includes(`Replay #${d.number}`)) fail('page', `page does not show "Replay #${d.number}"`)

  // ---- 9. the video actually plays ---------------------------------------
  const src = (html.match(/<video[^>]+src="([^"]+)"/) || [])[1]
          || (html.match(/<iframe[^>]+src="(https:\/\/player\.vimeo[^"]+)"/) || [])[1]
  if (!src) { fail('player', 'the page has no player'); return }
  try {
    const clean = src.replace(/&amp;/g, '&')
    const r = await fetch(clean, { headers: { Range: 'bytes=0-200' } })
    if (![200, 206].includes(r.status)) fail('player', `video returns ${r.status}`)
    else pass('player', `video ${r.status}`)
  } catch (e) { fail('player', `video unreachable: ${e.message}`) }

  // ---- 10. the images the page and the email depend on --------------------
  if (d.poster) {
    const p = d.poster.startsWith('http') ? d.poster : BASE + d.poster
    const r = await fetch(p, { method: 'GET', headers: { Range: 'bytes=0-100' } }).catch(() => null)
    if (!r || ![200, 206].includes(r.status)) fail('poster', `${d.poster} returns ${r ? r.status : 'nothing'}`)
    else pass('poster', 'present')
  }
  const thumb = `https://coaching.tricktionary.com/video-coaching/static/replay/qa-${d.date}-thumb.jpg`
  const tr = await fetch(thumb, { method: 'GET', headers: { Range: 'bytes=0-100' } }).catch(() => null)
  if (!tr || ![200, 206].includes(tr.status)) {
    // The replay email silently drops its player image when this is missing.
    fail('email thumbnail', 'missing, the replay email would go out without its image')
  } else pass('email thumbnail', 'present')
}

/** Prove the suite can fail, by feeding it builds that are wrong on purpose. */
async function selfTest(slug) {
  const good = JSON.parse(fs.readFileSync(`${REPLAY}/data/${slug}.json`, 'utf8'))
  const cases = [
    ['wrong number',        d => { d.number = 99 }],
    ['chapter past the end',d => { d.chapters[2].t = d.duration_min * 60 + 500 }],
    ['chapters out of order',d => { const c = d.chapters; [c[1], c[5]] = [c[5], c[1]] }],
    ['duplicate timestamp', d => { d.chapters[3].t = d.chapters[2].t }],
    ['all bunched at the start', d => { d.chapters.forEach((c, i) => { c.t = i * 10 }) }],
    ['AI phrasing',         d => { d.title = 'Let us dive into the Palau' }],
    ['placeholder left in', d => { d.summary = 'TODO write this' }],
    ['em dash',             d => { d.title = 'Palau — and footstraps' }],
    ['thin FAQ answer',     d => { if (d.faq && d.faq[0]) d.faq[0].a = 'Yes.' }],
    ['more attended than registered', d => { d.attended = 999 }],
  ]
  console.log(`\n  self-test against ${slug}, each case must be caught:\n`)
  let missed = 0
  for (const [label, breakIt] of cases) {
    const d = JSON.parse(JSON.stringify(good))
    breakIt(d)
    results.length = 0
    await verify(slug, d)
    const caught = results.some(r => r.state === 'fail')
    console.log(`    ${caught ? 'caught ' : 'MISSED '}  ${label}`)
    if (!caught) missed++
  }
  // And the unmodified build must pass, or the suite is just rejecting everything.
  results.length = 0
  await verify(slug, good)
  const cleanFails = results.filter(r => r.state === 'fail')
  console.log(`    ${cleanFails.length === 0 ? 'passes' : 'FAILS '}   the real build (must pass)`)
  if (cleanFails.length) {
    cleanFails.forEach(r => console.log(`              ${r.name}: ${r.detail}`))
    missed++
  }
  console.log(missed ? `\n  ${missed} problem(s) with the suite itself.\n` : '\n  suite is sound.\n')
  process.exit(missed ? 1 : 0)
}

const main = async () => {
  if (!SLUG) { console.error('usage: node qa-verify-replay.mjs <YYYY-MM> [--json] [--self-test]'); process.exit(2) }
  const f = `${REPLAY}/data/${SLUG}.json`
  if (!fs.existsSync(f)) { console.error(`no data file for ${SLUG}`); process.exit(2) }
  if (SELFTEST) return selfTest(SLUG)

  await verify(SLUG, JSON.parse(fs.readFileSync(f, 'utf8')))
  const fails = results.filter(r => r.state === 'fail')
  const warns = results.filter(r => r.state === 'warn')

  if (JSON_OUT) {
    console.log(JSON.stringify({ ok: fails.length === 0, slug: SLUG, results }, null, 2))
  } else {
    const mark = { pass: '  ok  ', warn: ' warn ', fail: ' FAIL ' }
    console.log('')
    for (const r of results) console.log(`[${mark[r.state]}] ${r.name.padEnd(18)} ${r.detail}`)
    console.log('')
    console.log(fails.length
      ? `${fails.length} problem(s). NOT safe to publish.`
      : warns.length ? `Safe to publish, ${warns.length} thing(s) worth a look.` : 'Safe to publish.')
  }
  process.exit(fails.length ? 1 : 0)
}

main().catch(e => { console.error('verify crashed:', e.message); process.exit(2) })
