#!/usr/bin/env python3
"""
Pull audience questions out of Instagram comments and DMs.

Every inbound comment is already stored whole in the funnel database
(events_in, kind='comments', raw JSON with the text and the username), whether
or not a keyword rule matched it. So asking people to comment "Question: ..."
needs no new funnel wiring at all: the questions are already being captured,
they just have nobody reading them.

This reads them back out for the prep list. It never writes anything.

  python3 qa-questions.py             # last 14 days, "Question:" prefixed
  python3 qa-questions.py --days 30
  python3 qa-questions.py --all       # also anything ending in a question mark

Default matches a leading "question:" or "frage:". --all also picks up comments
that simply end in a question mark, which is noisier but catches people who
ignore the format.
"""
import argparse
import json
import os
import re
import sqlite3
import sys
from datetime import datetime, timedelta, timezone

DB = os.environ.get("INSTAGRAM_DB_PATH",
                    os.path.expanduser("~/.openclaw/workspace/instagram.db"))

# Our own accounts, so Michi's replies do not come back as audience questions.
OWN = {"wing.tricktionary", "michirossmeier", "loveallsurfallstyle"}

PREFIX = re.compile(r"^\s*(question|frage)\s*[:\-]\s*(.+)$", re.I | re.S)


def parse_comment(raw):
    try:
        v = json.loads(raw).get("value", {})
    except Exception:
        return None
    text = (v.get("text") or "").strip()
    user = (v.get("from") or {}).get("username") or "unknown"
    return (user, text) if text else None


def parse_dm(raw, names, own_ids):
    """DMs carry a sender id, not a username, so resolve it through subscribers."""
    try:
        d = json.loads(raw)
    except Exception:
        return None
    sender = str((d.get("sender") or {}).get("id") or "")
    text = ((d.get("message") or {}).get("text") or "").strip()
    if not text or not sender or sender in own_ids:
        return None            # skip our own outbound messages
    return names.get(sender, sender), text


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--days", type=int, default=14)
    ap.add_argument("--all", action="store_true",
                    help="also include any comment ending in a question mark")
    args = ap.parse_args()

    if not os.path.exists(DB):
        sys.exit(f"funnel database not found at {DB}")

    since = (datetime.now(timezone.utc) - timedelta(days=args.days)).isoformat()
    db = sqlite3.connect(f"file:{DB}?mode=ro", uri=True)

    names = {r[0]: r[1] for r in db.execute(
        "SELECT ig_sender_id, COALESCE(NULLIF(username,''), NULLIF(name,''), ig_sender_id) "
        "FROM subscribers WHERE ig_sender_id IS NOT NULL")}
    own_ids = {r[0] for r in db.execute("SELECT ig_user_id FROM accounts")}

    rows = db.execute(
        "SELECT kind, raw, received_at FROM events_in "
        "WHERE kind IN ('comments','message') AND received_at >= ? ORDER BY received_at",
        (since,),
    ).fetchall()

    hits, seen = [], set()
    for kind, raw, at in rows:
        p = parse_comment(raw) if kind == "comments" else parse_dm(raw, names, own_ids)
        if not p:
            continue
        user, text = p
        if user in OWN:
            continue
        m = PREFIX.match(text)
        if m:
            q = m.group(2).strip()
        elif args.all and text.rstrip().endswith("?"):
            q = text.strip()
        else:
            continue
        key = (user, q.lower())
        if key in seen:
            continue
        seen.add(key)
        hits.append((at[:10], user, q, "comment" if kind == "comments" else "DM"))

    if not hits:
        print(f"No questions in the last {args.days} days."
              + ("" if args.all else "  Try --all for anything ending in '?'."))
        return

    print(f"{len(hits)} question(s) from Instagram, last {args.days} days\n")
    for at, user, q, via in hits:
        print(f"  {at}  @{user}  ({via})")
        for line in q.splitlines():
            print(f"      {line}")
        print()


if __name__ == "__main__":
    main()
