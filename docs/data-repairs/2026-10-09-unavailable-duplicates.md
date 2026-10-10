# Unavailable duplicate cleanup — 2026-10-09

The read-only audit found 2,991 nondeleted books marked unavailable. None had a directory under the currently mounted book root. Metadata matching found 834 possible pairings with available books. The 106 pairs in the companion CSV were individually reviewed and had close matching titles, authors, durations, and (where recorded) series slots and narrators. All 106 replacement directories existed at deletion time. No selected source record had book-linked user activity in the database audit.

A verified full database backup was created first: `backup_ab5_pre-unavailable-duplicate-cleanup_20261009_181257.sql.gz`.

The 106 source IDs were moved to recoverable application trash with `deleteFiles=false`. Each has `book.json` and `metadata.json`; all metadata records show `files_moved=false`. The replacement records remain active, and all 105 distinct replacement directories exist. No audio files were moved.

After cleanup: 10,445 available books and 2,885 unavailable books remain (13,330 nondeleted records total). The 728 other possible matches and 2,157 records without a candidate were left untouched because edition, narration, duration, or identity was not established strongly enough. Examples deliberately excluded include an unavailable *Warbreaker* credited to James Yaegashi versus an available Alyssa Bresnahan narration, and a *Hunting Party* GraphicAudio path versus a differently labeled available copy.

[Exact source and replacement IDs](2026-10-09-unavailable-duplicates.csv)
