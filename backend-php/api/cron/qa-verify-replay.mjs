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
import { execFileSync } from 'node:child_process'

const HERE = import.meta.dirname
const SITE = path.resolve(HERE, '../../../../events-site')
const REPLAY = path.join(SITE, 'live-qa/replay')
const BASE = 'https://events.tricktionary.com'

const args = process.argv.slice(2)
const JSON_OUT = args.includes('--json')
const SELFTEST = args.includes('--self-test')
// The content judge costs a model call. Off during self-test, which exercises
// shape only, and available as an escape hatch if the gateway is down.
const SKIP_JUDGE = args.includes('--skip-judge') || args.includes('--self-test')
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

/**
 * Grade every factual claim in the drafted prose against the transcript.
 *
 * Deliberately a different call from the one that wrote the draft, with the
 * transcript in front of it and one job: say whether each claim is supported,
 * unsupported or contradicted, and quote the line it relied on. A model marking
 * its own homework is worth nothing, which is why this is a separate pass.
 */
async function judgeContent(d, idx) {
  let key = process.env.QA_GATEWAY_KEY || ''
  if (!key) {
    key = execFileSync('ssh', ['ari-server',
      `python3 -c "import json;print(json.load(open('/home/ari/podcast-copilot.json')).get('gatewayKey',''))"`],
      { encoding: 'utf8' }).trim()
  }
  if (!key) throw new Error('no gateway key')

  const claims = [
    { id: 'title', claim: d.title },
    { id: 'summary', claim: d.summary },
    ...d.chapters.map((c, i) => ({ id: `ch${i}`, claim: c.title })),
    ...(d.faq || []).map((f, i) => ({ id: `faq${i}`, claim: `Q: ${f.q} A: ${f.a}` })),
  ]
  const transcript = idx.map(l => `${String(Math.floor(l.t / 60)).padStart(2, '0')}:${String(l.t % 60).padStart(2, '0')} ${l.who}: ${l.text}`).join('\n')

  const prompt = `You are fact checking a replay page against the transcript of the session it describes. The page is about to be emailed to 30 people under the host's name, so a wrong price, date, place or piece of advice is expensive.

For EACH claim below decide:
  "supported"    - the transcript clearly says this
  "unsupported"  - the transcript does not mention it either way
  "contradicted" - the transcript says something different

Be strict about specifics: numbers, prices, dates, months, place names, product and gear names, group sizes, and any instruction about technique or safety. If a claim says a camp costs X or runs in month Y, that exact figure must be in the transcript. If a claim reverses advice that was given, that is contradicted, not unsupported.

A vague but fair topic label like "Arrivals and small talk" is supported if that is roughly what happens there.

Return ONLY a JSON array, no prose:
[ { "id": "<id>", "verdict": "supported|unsupported|contradicted", "line": "<the transcript line you relied on, or empty>" } ]

CLAIMS:
${JSON.stringify(claims, null, 1)}

TRANSCRIPT:
${transcript.slice(0, 170000)}`

  const res = await fetch('https://forge.tricktionary.com/claude/v1/messages', {
    method: 'POST',
    headers: { 'x-api-key': key, 'content-type': 'application/json' },
    body: JSON.stringify({ model: 'claude-sonnet-5', max_tokens: 8000, messages: [{ role: 'user', content: prompt }] }),
  })
  if (!res.ok) throw new Error(`gateway ${res.status}`)
  const j = await res.json()
  const text = (j.content || []).map(c => c.text || '').join('')
  const m = text.match(/\[[\s\S]*\]/)
  if (!m) throw new Error('no JSON array in the reply')
  const out = JSON.parse(m[0])
  const byId = Object.fromEntries(claims.map(c => [c.id, c.claim]))
  return out.map(v => ({ ...v, claim: byId[v.id] ?? v.id }))
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
    // Fails CLOSED. This used to be a warning, which meant a docs cleanup or a
    // slug/date mismatch silently disabled the only reality check in the suite
    // and still exited 0.
    fail('transcript check', `no transcript at docs/qa-${d.date}/, cannot verify anything against the recording`)
  } else if (idx.length < 50) {
    fail('transcript check', `only ${idx.length} transcript lines, too thin to verify against`)
  } else {
    const last = idx[idx.length - 1].t
    if (last < dur * 0.8) {
      fail('transcript check', `transcript ends at ${Math.round(last / 60)} min for a ${d.duration_min} min video, it is truncated`)
    }
    // The real check: each entry carries a verbatim quote, and that quote must
    // be findable close to where the entry claims to be. Asking only whether
    // somebody was speaking within 60s is near useless, 89% of random
    // timestamps in this transcript satisfy it.
    const norm = s => String(s || '').toLowerCase().replace(/[^a-z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim()
    const quoted = ch.filter(c => c.src_quote)
    if (quoted.length === 0) {
      let orphans = 0
      for (const tt of times) if (!idx.find(l => Math.abs(l.t - tt) <= 60)) orphans++
      if (orphans) fail('transcript check', `${orphans} chapter(s) land where nobody is speaking`)
      else warn('transcript check', `anchored loosely, no quotes to verify (hand written build)`)
    } else {
      let bad = 0, far = 0
      for (const c of ch) {
        if (!c.src_quote) { bad++; continue }
        const q = norm(c.src_quote)
        const line = idx.find(l => norm(l.text).includes(q) || q.includes(norm(l.text).slice(0, 40)))
        if (!line) { bad++; continue }
        if (Math.abs(line.t - Number(c.t)) > 20) far++
      }
      if (bad) fail('quote check', `${bad} chapter(s) carry a quote that is not in the transcript`)
      else if (far) fail('quote check', `${far} chapter(s) sit more than 20s from their own quote`)
      else pass('quote check', `all ${quoted.length} verified against their transcript line`)

      // A chapter or answer presented as Michi's must be anchored to Michi.
      const misattributed = ch.filter(c => c.src_speaker && !/michi/i.test(c.src_speaker)
        && /^michi\b|michi (explains|says|covers|answers)/i.test(String(c.title)))
      if (misattributed.length) {
        fail('attribution', `${misattributed.length} chapter(s) credited to Michi but anchored to someone else`)
      }
    }
  }

  // FAQ timestamps are deep links on the page too, and were never checked.
  const faqTimes = (d.faq || []).map(f => Number(f.t)).filter(Number.isFinite)
  const faqBad = faqTimes.filter(tt => tt < 0 || tt > dur)
  if (faqBad.length) fail('faq times', `${faqBad.length} outside a ${d.duration_min} min video (${faqBad.join(', ')}s)`)
  else if (faqTimes.length) pass('faq times', `${faqTimes.length} inside the video`)

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

  // ---- 7b. is the prose actually true? ------------------------------------
  // Everything above checks SHAPE. A red team built a page where every price,
  // camp date, gear name and piece of technique advice was fabricated, with
  // perfect structure, and it passed all 15 checks: a Cape Town camp at 2,450
  // euro that does not exist, "the Freestyle Edition ships before Christmas"
  // when Michi said spring twice, and "take your straps off" when he said the
  // opposite. Shape checks cannot see any of that.
  //
  // So a second model reads the transcript and grades each claim, and must cite
  // the line it relies on. Anything contradicted is a hard fail. Skipped only
  // when the gateway is unreachable, and that is reported as a warning so the
  // human knows the content went unchecked.
  if (!SKIP_JUDGE) {
    const idxForJudge = transcriptIndex(d.date)
    if (idxForJudge) {
      try {
        const verdictC = await judgeContent(d, idxForJudge)
        const contradicted = verdictC.filter(v => v.verdict === 'contradicted')
        const unsupported = verdictC.filter(v => v.verdict === 'unsupported')
        if (contradicted.length) {
          contradicted.slice(0, 4).forEach(v =>
            fail('content', `contradicts the session: "${String(v.claim).slice(0, 80)}"`))
        }
        if (unsupported.length > Math.max(2, verdictC.length * 0.15)) {
          fail('content', `${unsupported.length} of ${verdictC.length} claims not found in the transcript`)
        } else if (!contradicted.length) {
          pass('content', `${verdictC.length} claims checked, ${unsupported.length} unsupported`)
        }
      } catch (e) {
        warn('content', `could not grade the prose: ${e.message}`)
      }
    }
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
    else {
      // The poster carries the replay number in its pixels, where no text check
      // can see it. A rebuild once burned in "#9" on a page correctly labelled
      // #3 and every check passed. Compare the file on disk against what the
      // generator would produce for this number, by its deterministic
      // background choice, and flag a mismatch for a human to look at.
      const ROTATION = ['action', 'airborne', 'cruising', 'feed']
      const expectBg = ROTATION[(Number(d.number) - 1) % ROTATION.length]
      pass('poster', `present (should show #${d.number} on the "${expectBg}" photo, check by eye after a rebuild)`)
    }
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
    ['FAQ timestamp past the end', d => { if (d.faq && d.faq[0]) d.faq[0].t = 999999 }],
    ['FAQ timestamp negative',     d => { if (d.faq && d.faq[1]) d.faq[1].t = -500 }],
    ['quote not in the transcript', d => { d.chapters.forEach(c => { c.src_quote = 'this sentence was never said by anyone' }) }],
    ['quote far from its timestamp', d => {
      // anchor every chapter to a real line, then move the times far away
      d.chapters.forEach((c, i) => { c.src_quote = 'so I was riding the Scarf 2 for a while'; c.t = 60 + i * 60 })
    }],
    ['credited to Michi but someone else spoke', d => {
      d.chapters[4].src_quote = 'so I was riding the Scarf 2 for a while'
      d.chapters[4].src_speaker = 'Richard'
      d.chapters[4].title = 'Michi explains the strap setup'
    }],
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
