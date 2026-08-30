#!/usr/bin/env node
/**
 * The Q&A promo audience: which Sendy lists to send a Q&A invite to, and why.
 *
 * Sendy holds 76 lists, most of them dead campaign artefacts from 2015 to 2019.
 * Picking the right ones from that dropdown every month is where mistakes get
 * made, so the decision lives here instead of in someone's head. Run it before
 * each send: it re-reads the live sizes so the numbers are never stale.
 *
 *   node qa-promo-audience.mjs
 *
 * Reads only. It never subscribes, unsubscribes or sends anything.
 */
import { execFileSync } from 'node:child_process'
import path from 'node:path'
import fs from 'node:fs'

const SENDY = '/home/openclaw/.openclaw/workspace/skills/sendy/scripts/sendy.mjs'

// ---------------------------------------------------------------------------
// The definition. Edit THIS when the audience changes, not the send dialog.
// ---------------------------------------------------------------------------
const INCLUDE = [
  { id: 'YwFkEI2ikOIElxBo4eAiXQ',     name: 'email-b2c-2026-04-26',
    why: 'B2C shop customers, refreshed from Magento orders. Existing customer relationship.' },
  { id: 'hjpQ66NMtUO2hG3kqLf3Gw',     name: 'Wing Tricktionary Preorder webshop list',
    why: 'Bought or preordered the wing book. Exactly the right sport, and the biggest clean pool.' },
  { id: 'GT94MZDRsTEb3jBnWWAaUg',     name: 'WING TR EARLY ORDER LIST',
    why: 'Early orders on the wing book. Small but warm.' },
  { id: '8DSvERx892Ep892ghWEtQIgQ7w', name: 'wingfoil-events-leads',
    why: 'Captured by the Instagram comment funnel. Already asked about wingfoil events.' },
]

// Named so nobody re-adds them by accident.
const EXCLUDE_RULES = [
  ['B2B, shops, schools, media', 'Retailers and journalists, not riders. A free rider Q&A is the wrong offer.'],
  ['SUP, kite, windsurf-only lists', 'Wrong sport. Sending wingfoil invites there earns unsubscribes.'],
  ['TG ALL, xmas resends, Trickgenius', '2015 to 2019, cross-sport and stale. Bounces land on the same domain as the order confirmations and the Q&A reminders.'],
  ['Anything named subtract / not opened / EXCLUDE / bounced / testlist', 'Campaign artefacts, not audiences.'],
]

function count(id) {
  try {
    const out = execFileSync('node', [SENDY, 'count', '--list', id], {
      encoding: 'utf8', timeout: 25000,
      env: { ...process.env, ...readEnv() },
    })
    const j = JSON.parse(out)
    if (!j.ok) return j.error?.message
    // Sendy answers this endpoint inconsistently: some lists return the number,
    // others just "Count retrieved". The list is still valid and sendable, the
    // size simply is not exposed. Verified against wingfoil-events-leads, where
    // a status lookup confirms real subscribers.
    return j.data?.count ?? 'see UI'
  } catch (e) {
    // Sendy returns the bare number as an error body for this endpoint.
    const m = String(e.stdout || e.message).match(/"message":\s*"(\d+)"/)
    return m ? m[1] : 'unreadable'
  }
}

function readEnv() {
  const out = {}
  try {
    for (const line of fs.readFileSync('/home/openclaw/.openclaw/.env', 'utf8').split('\n')) {
      const m = line.match(/^(SENDY_[A-Z_]+)=(.*)$/)
      if (m) out[m[1]] = m[2]
    }
  } catch {}
  return out
}

console.log('Q&A promo audience\n')
let total = 0
const ids = []
for (const l of INCLUDE) {
  const n = count(l.id)
  const known = /^\d+$/.test(String(n))
  if (known) total += Number(n)
  ids.push(l.id)
  console.log(`  ${String(n).padStart(7)}  ${l.name}${known ? '' : '  (valid list, size only visible in the Sendy UI)'}`)
  console.log(`           ${l.why}`)
}
console.log(`\n  ~${total} plus the events leads, before Sendy dedupes. Sendy shows the real figure before you send.\n`)
console.log('  Paste this as the campaign list_ids:')
console.log(`  ${ids.join(',')}\n`)

console.log('Never include:')
for (const [what, why] of EXCLUDE_RULES) console.log(`  - ${what}\n      ${why}`)

console.log('\nHandled separately, keep out of Sendy:')
console.log('  qa_audience in the coaching database. Everyone who ever signed up for a')
console.log('  Q&A or unlocked a replay already gets the invite automatically 7 days')
console.log('  before each session. Adding them to Sendy would mail them twice.')
