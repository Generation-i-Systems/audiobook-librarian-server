#!/usr/bin/env python3
"""
After downloading LibriVox zips into /media/archive/demo-books/, run this script
to update chapter file_name fields in the database to point to local MP3 files.

Usage:
    python3 scripts/update_chapter_paths.py [--dry-run]
"""

import sqlite3
import os
import sys
import re

DB = os.path.join(os.path.dirname(__file__), '../database/database.sqlite')
DRY_RUN = '--dry-run' in sys.argv

conn = sqlite3.connect(DB)
c = conn.cursor()

books = c.execute("""
    SELECT id, title, directory_path FROM books
    WHERE directory_path IS NOT NULL AND directory_exists = 1
    ORDER BY id
""").fetchall()

updated = skipped = missing = 0

for book_id, title, dir_path in books:
    if not dir_path or not os.path.isdir(dir_path):
        print(f"  SKIP (no dir): [{book_id}] {title}")
        skipped += 1
        continue

    # Get all MP3 files in the directory, sorted
    mp3_files = sorted(
        f for f in os.listdir(dir_path)
        if f.lower().endswith('.mp3')
    )

    if not mp3_files:
        print(f"  SKIP (no mp3s): [{book_id}] {title}")
        skipped += 1
        continue

    chapters = c.execute("""
        SELECT id, chapter_number, file_name, title
        FROM chapters WHERE book_id = ?
        ORDER BY chapter_number
    """, (book_id,)).fetchall()

    print(f"\n  [{book_id}] {title} — {len(mp3_files)} mp3s, {len(chapters)} chapters")

    for ch_id, ch_num, old_file_name, ch_title in chapters:
        # Try to match by extracting the base filename from the listen_url/file_name
        old_base = os.path.basename(old_file_name or '').lower()
        old_base = re.sub(r'\?.*$', '', old_base)  # strip query strings

        # Find best match: exact name match first, then by chapter number position
        matched = None
        for mp3 in mp3_files:
            if mp3.lower() == old_base:
                matched = mp3
                break

        # Fall back: match by chapter number ordering
        if not matched and ch_num > 0 and ch_num <= len(mp3_files):
            matched = mp3_files[ch_num - 1]
        elif not matched and mp3_files:
            matched = mp3_files[min(ch_num, len(mp3_files)) - 1]

        if matched:
            local_path = os.path.join(dir_path, matched)
            relative_path = os.path.relpath(local_path, dir_path)
            new_file_name = matched
            if not DRY_RUN:
                c.execute("UPDATE chapters SET file_name = ? WHERE id = ?", (new_file_name, ch_id))
            print(f"    ch{ch_num:02d}: {old_base[:30]:30s} -> {new_file_name}")
            updated += 1
        else:
            print(f"    ch{ch_num:02d}: NO MATCH for {old_base[:40]}")
            missing += 1

if not DRY_RUN:
    conn.commit()
    print(f"\nDone. Updated: {updated}, Skipped books: {skipped}, Missing: {missing}")
else:
    print(f"\nDry run. Would update: {updated}, Skip books: {skipped}, Missing: {missing}")

conn.close()
