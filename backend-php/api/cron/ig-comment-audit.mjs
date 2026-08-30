// Read-only audit: ask Instagram itself for the comments on recent media and
// compare them against handled_comments.
//
// The local events_in table is NOT a complete record. On 2026-08-30, 18 of 53
// handled comments had no stored raw event, so a scan of the local store alone
// cannot prove nothing was missed. This asks the source of truth instead.
//
// Run from /home/openclaw/apps/instagram so the libsql import resolves:
//   cd ~/apps/instagram && node <path-to-this-file>
//
// Flags any comment that matches a live keyword rule but was never handled.
// Sends nothing and writes nothing.
import { createClient } from '@libsql/client'
const c = createClient({ url: 'file:/home/openclaw/.openclaw/workspace/instagram.db' })
const GRAPH = 'https://graph.instagram.com/v21.0'

const accts = (await c.execute(
  "SELECT id, username, ig_user_id, access_token FROM accounts WHERE id LIKE 'instagram:%' AND username IN ('wing.tricktionary','michirossmeier','loveallsurfallstyle')"
)).rows

const handled = new Set((await c.execute('SELECT comment_id FROM handled_comments')).rows.map(r => r.comment_id))
const rules = (await c.execute("SELECT keyword, match_type FROM keyword_rules WHERE status='live'")).rows

const norm = s => s.toLowerCase().normalize('NFKD').replace(/[^\p{L}\p{N}\s]/gu, ' ')
const match = (t, k, m) => {
  const T = norm(t), K = norm(k).trim(); if (!K) return false
  if (m === 'exact') return T.trim() === K
  if (m === 'word') { const w = new Set(T.split(/\s+/).filter(Boolean)); return K.split(/\s+/).every(x => w.has(x)) }
  return T.includes(K)
}
const fires = t => rules.some(r => match(t, r.keyword, r.match_type))
const OWN = new Set(accts.map(a => a.username))

for (const a of accts) {
  const mres = await fetch(`${GRAPH}/${a.ig_user_id}/media?fields=id,caption,timestamp&limit=6&access_token=${encodeURIComponent(a.access_token)}`)
  const media = (await mres.json()).data || []
  console.log(`\n@${a.username}  (${media.length} recent posts)`)
  for (const m of media) {
    const cres = await fetch(`${GRAPH}/${m.id}/comments?fields=id,text,username,timestamp&limit=50&access_token=${encodeURIComponent(a.access_token)}`)
    const j = await cres.json()
    if (j.error) { console.log(`  ${m.id}: ${j.error.message}`); continue }
    const cs = (j.data || []).filter(x => !OWN.has(x.username))
    if (!cs.length) continue
    const cap = (m.caption || '').split('\n')[0].slice(0, 46)
    console.log(`  post ${m.timestamp?.slice(0,10)} "${cap}"  ${cs.length} comment(s)`)
    for (const x of cs) {
      const f = fires(x.text || ''), h = handled.has(x.id)
      const flag = f && !h ? '  *** MATCHES A RULE BUT WAS NOT HANDLED ***' : (f ? '  (handled)' : '')
      console.log(`     ${x.timestamp?.slice(0,10)} @${x.username}: ${(x.text||'').slice(0,60)}${flag}`)
    }
  }
}
