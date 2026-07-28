#!/usr/bin/env python3
"""
Import the 30 LibriVox demo books into the main books database.

This script:
  - Fetches metadata and chapter lists from the LibriVox API
  - Inserts/updates books in the main `books` table with source='local'
  - Creates book directories under BOOK_STORAGE_PATH
  - Syncs authors, genres, narrators, and series
  - Downloads CC0/public-domain cover images (Standard Ebooks or Open Library)
  - Writes librarian.json files in each book directory
  - Updates books.cover_image = 'cover.jpg'

Run standalone or via setup-demo.sh.
"""

import sqlite3
import os
import sys
import json
import html
import time
import urllib.request
import urllib.error
from datetime import datetime

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
SERVER_ROOT = os.path.dirname(SCRIPT_DIR)

# Read from .env
DB_PATH = os.path.join(SERVER_ROOT, 'database', 'database.sqlite')
BOOK_ROOT = '/media/archive/demo-books'

env_file = os.path.join(SERVER_ROOT, '.env')
if os.path.exists(env_file):
    for line in open(env_file):
        line = line.strip()
        if line.startswith('BOOK_STORAGE_PATH='):
            BOOK_ROOT = line.split('=', 1)[1].strip().strip('"').strip("'")
        elif line.startswith('DB_DATABASE='):
            DB_PATH = line.split('=', 1)[1].strip().strip('"').strip("'")

LIBRIVOX_API = 'https://librivox.org/api/feed/audiobooks/'

FIXTURES_DIR = os.path.join(SCRIPT_DIR, 'fixtures')

# librivox_id -> fixture filename. A fixture supplies hand-curated enrichment
# (description, notes, characters) that gets merged into a full LibrarianMetadata-shaped
# librarian.json, so a fresh demo install already has rich per-book content on first
# download instead of the bare-bones metadata the other demo books get.
LIBRARIAN_JSON_FIXTURES = {
    2591: 'great_expectations_librarian.json',
}

# ---------------------------------------------------------------------------
# The 30 demo books: (librivox_id, title, directory_path, author_name)
# directory_path is relative to BOOK_STORAGE_PATH
# ---------------------------------------------------------------------------

DEMO_BOOKS = [
    (2452,  'Study in Scarlet',                    'Sir_Arthur_Conan_Doyle/Sherlock_Holmes/Study_in_Scarlet',              'Sir Arthur Conan Doyle'),
    (966,   'Sign of the Four',                    'Sir_Arthur_Conan_Doyle/Sherlock_Holmes/Sign_of_the_Four',             'Sir Arthur Conan Doyle'),
    (901,   'Hound of the Baskervilles',           'Sir_Arthur_Conan_Doyle/Sherlock_Holmes/Hound_of_the_Baskervilles',    'Sir Arthur Conan Doyle'),
    (253,   'Pride and Prejudice',                 'Jane_Austen/Pride_and_Prejudice',                                     'Jane Austen'),
    (620,   'Sense and Sensibility',               'Jane_Austen/Sense_and_Sensibility',                                   'Jane Austen'),
    (86,    'Emma',                                'Jane_Austen/Emma',                                                    'Jane Austen'),
    (59,    'Adventures of Huckleberry Finn',      'Mark_Twain/Adventures_of_Huckleberry_Finn',                           'Mark Twain'),
    (448,   'Adventures of Tom Sawyer',            'Mark_Twain/Adventures_of_Tom_Sawyer',                                 'Mark Twain'),
    (714,   'Around the World in Eighty Days',     'Jules_Verne/Around_the_World_in_Eighty_Days',                         'Jules Verne'),
    (665,   'Twenty Thousand Leagues Under the Sea','Jules_Verne/Twenty_Thousand_Leagues_Under_the_Sea',                  'Jules Verne'),
    (436,   'War of the Worlds',                   'H_G_Wells/War_of_the_Worlds',                                         'H. G. Wells'),
    (817,   'Time Machine',                        'H_G_Wells/Time_Machine',                                              'H. G. Wells'),
    (158,   'Call of the Wild',                    'Jack_London/Call_of_the_Wild',                                        'Jack London'),
    (3106,  'Sea Wolf',                            'Jack_London/Sea_Wolf',                                                'Jack London'),
    (449,   'Treasure Island',                     'Robert_Louis_Stevenson/Treasure_Island',                              'Robert Louis Stevenson'),
    (417,   'Strange Case of Dr. Jekyll and Mr. Hyde', 'Robert_Louis_Stevenson/Strange_Case_of_Dr_Jekyll_and_Mr_Hyde',   'Robert Louis Stevenson'),
    (120,   'Three Musketeers',                    'Alexandre_Dumas/Three_Musketeers/Three_Musketeers',                   'Alexandre Dumas'),
    (57,    'Twenty Years After',                  'Alexandre_Dumas/Three_Musketeers/Twenty_Years_After',                 'Alexandre Dumas'),
    (271,   'Dracula',                             'Bram_Stoker/Dracula',                                                 'Bram Stoker'),
    (381,   'Frankenstein, or The Modern Prometheus','Mary_Wollstonecraft_Shelley/Frankenstein_or_The_Modern_Prometheus', 'Mary Wollstonecraft Shelley'),
    (365,   'Picture of Dorian Gray',              'Oscar_Wilde/Picture_of_Dorian_Gray',                                  'Oscar Wilde'),
    (200,   "Alice's Adventures in Wonderland",    'Lewis_Carroll/Alice_in_Wonderland/Alices_Adventures_in_Wonderland',   'Lewis Carroll'),
    (443,   'Through the Looking-Glass',           'Lewis_Carroll/Alice_in_Wonderland/Through_the_Looking-Glass',         'Lewis Carroll'),
    (753,   'Moby Dick, or the Whale',             'Herman_Melville/Moby_Dick_or_the_Whale',                              'Herman Melville'),
    (696,   'Robinson Crusoe',                     'Daniel_Defoe/Robinson_Crusoe',                                        'Daniel Defoe'),
    (332,   'Wonderful Wizard of Oz',              'L_Frank_Baum/Wonderful_Wizard_of_Oz',                                 'L. Frank Baum'),
    (549,   'Walden',                              'Henry_David_Thoreau/Walden',                                          'Henry David Thoreau'),
    (1095,  'Meditations',                         'Marcus_Aurelius/Meditations',                                         'Marcus Aurelius'),
    (2591,  'Great Expectations',                  'Charles_Dickens/Great_Expectations',                                  'Charles Dickens'),
    (510,   'Tale of Two Cities',                  'Charles_Dickens/Tale_of_Two_Cities',                                  'Charles Dickens'),
]

# Genre assignments keyed by librivox_id
BOOK_GENRES = {
    2452:  ['Detective Fiction'],
    966:   ['Detective Fiction'],
    901:   ['Action & Adventure Fiction', 'Detective Fiction'],
    253:   ['Romance'],
    620:   ['Romance'],
    86:    ['Humorous Fiction', 'Romance'],
    59:    ["Children's Fiction"],
    448:   ["Children's Fiction"],
    714:   ['Action & Adventure Fiction', 'Travel Fiction'],
    665:   ['Action & Adventure Fiction'],
    436:   ['Science Fiction'],
    817:   ['Science Fiction'],
    158:   ['Action & Adventure Fiction', 'Nature & Animal Fiction'],
    3106:  ['Action & Adventure Fiction', 'General Fiction', 'Nautical & Marine Fiction', 'Romance'],
    449:   ['Action & Adventure Fiction'],
    417:   ['Horror & Supernatural Fiction'],
    120:   ['Action & Adventure Fiction', 'Romance'],
    57:    ['Action & Adventure Fiction'],
    271:   ['Horror & Supernatural Fiction'],
    381:   ['Science Fiction'],
    365:   ['Horror & Supernatural Fiction'],
    200:   ["Children's Fiction", 'Fantastic Fiction'],
    443:   ['Action & Adventure'],
    753:   ['Action & Adventure Fiction', 'Nautical & Marine Fiction'],
    696:   ['Action & Adventure Fiction'],
    332:   ['General'],
    549:   ['Modern', 'Nature'],
    1095:  ['Ancient', 'Classics (Greek & Latin Antiquity)'],
    2591:  ['General Fiction', 'Literary Fiction'],
    510:   ['Historical Fiction'],
}

# Series assignments: librivox_id -> (series_name, series_number)
BOOK_SERIES = {
    2452: ('Sherlock Holmes', 1),
    966:  ('Sherlock Holmes', 2),
    901:  ('Sherlock Holmes', 3),
    120:  ("D'Artagnan Romances", 1),
    57:   ("D'Artagnan Romances", 2),
    200:  ("Alice's Adventures", 1),
    443:  ("Alice's Adventures", 2),
}

# Cover image URLs (CC0 / public domain)
COVER_URLS = {
    2452: 'https://standardebooks.org/ebooks/arthur-conan-doyle/a-study-in-scarlet/downloads/cover.jpg',
    966:  'https://standardebooks.org/ebooks/arthur-conan-doyle/the-sign-of-the-four/downloads/cover.jpg',
    901:  'https://standardebooks.org/ebooks/arthur-conan-doyle/the-hound-of-the-baskervilles/downloads/cover.jpg',
    253:  'https://standardebooks.org/ebooks/jane-austen/pride-and-prejudice/downloads/cover.jpg',
    620:  'https://standardebooks.org/ebooks/jane-austen/sense-and-sensibility/downloads/cover.jpg',
    86:   'https://standardebooks.org/ebooks/jane-austen/emma/downloads/cover.jpg',
    59:   'https://standardebooks.org/ebooks/mark-twain/the-adventures-of-huckleberry-finn/downloads/cover.jpg',
    448:  'https://standardebooks.org/ebooks/mark-twain/the-adventures-of-tom-sawyer/downloads/cover.jpg',
    714:  'https://standardebooks.org/ebooks/jules-verne/around-the-world-in-eighty-days/george-makepeace-towle/downloads/cover.jpg',
    665:  'https://covers.openlibrary.org/b/id/6573517-L.jpg',  # SE edition access-restricted
    436:  'https://standardebooks.org/ebooks/h-g-wells/the-war-of-the-worlds/downloads/cover.jpg',
    817:  'https://standardebooks.org/ebooks/h-g-wells/the-time-machine/downloads/cover.jpg',
    158:  'https://standardebooks.org/ebooks/jack-london/the-call-of-the-wild/downloads/cover.jpg',
    3106: 'https://standardebooks.org/ebooks/jack-london/the-sea-wolf/downloads/cover.jpg',
    449:  'https://standardebooks.org/ebooks/robert-louis-stevenson/treasure-island/downloads/cover.jpg',
    417:  'https://standardebooks.org/ebooks/robert-louis-stevenson/the-strange-case-of-dr-jekyll-and-mr-hyde/downloads/cover.jpg',
    120:  'https://standardebooks.org/ebooks/alexandre-dumas/the-three-musketeers/william-robson/downloads/cover.jpg',
    57:   'https://standardebooks.org/ebooks/alexandre-dumas/twenty-years-after/estes-and-lauriat/downloads/cover.jpg',
    271:  'https://standardebooks.org/ebooks/bram-stoker/dracula/downloads/cover.jpg',
    381:  'https://standardebooks.org/ebooks/mary-shelley/frankenstein/downloads/cover.jpg',
    365:  'https://standardebooks.org/ebooks/oscar-wilde/the-picture-of-dorian-gray/downloads/cover.jpg',
    200:  'https://standardebooks.org/ebooks/lewis-carroll/alices-adventures-in-wonderland/john-tenniel/downloads/cover.jpg',
    443:  'https://standardebooks.org/ebooks/lewis-carroll/through-the-looking-glass/john-tenniel/downloads/cover.jpg',
    753:  'https://standardebooks.org/ebooks/herman-melville/moby-dick/downloads/cover.jpg',
    696:  'https://standardebooks.org/ebooks/daniel-defoe/the-life-and-adventures-of-robinson-crusoe/downloads/cover.jpg',
    332:  'https://standardebooks.org/ebooks/l-frank-baum/the-wonderful-wizard-of-oz/downloads/cover.jpg',
    549:  'https://standardebooks.org/ebooks/henry-david-thoreau/walden/downloads/cover.jpg',
    1095: 'https://standardebooks.org/ebooks/marcus-aurelius/meditations/george-long/downloads/cover.jpg',
    2591: 'https://standardebooks.org/ebooks/charles-dickens/great-expectations/downloads/cover.jpg',
    510:  'https://standardebooks.org/ebooks/charles-dickens/a-tale-of-two-cities/downloads/cover.jpg',
}


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def http_get(url, timeout=30):
    req = urllib.request.Request(url, headers={
        'User-Agent': 'AudiobookLibrarian-DemoSetup/1.0 (contact demo@audiobooklibrarian.com)'
    })
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return resp.read()


def fetch_librivox_book(librivox_id):
    url = f'{LIBRIVOX_API}?id={librivox_id}&format=json&extended=1'
    data = http_get(url)
    result = json.loads(data)
    books = result.get('books', [])
    return books[0] if books else None


def get_or_create_genre(c, name):
    row = c.execute('SELECT id FROM genres WHERE name = ?', (name,)).fetchone()
    if row:
        return row[0]
    now = datetime.utcnow().isoformat()
    c.execute('INSERT INTO genres (name, created_at, updated_at) VALUES (?, ?, ?)', (name, now, now))
    return c.lastrowid


def get_or_create_author(c, name):
    row = c.execute('SELECT id FROM authors WHERE name = ?', (name,)).fetchone()
    if row:
        return row[0]
    now = datetime.utcnow().isoformat()
    c.execute('INSERT INTO authors (name, created_at, updated_at) VALUES (?, ?, ?)', (name, now, now))
    return c.lastrowid


def get_or_create_series(c, name):
    row = c.execute('SELECT id FROM series WHERE name = ?', (name,)).fetchone()
    if row:
        return row[0]
    now = datetime.utcnow().isoformat()
    c.execute("INSERT INTO series (name, is_collection, created_at, updated_at) VALUES (?, 0, ?, ?)", (name, now, now))
    return c.lastrowid


def upsert_book(c, librivox_id, title, description, language, duration, audio_file_count, directory_path, librivox_info):
    now = datetime.utcnow().isoformat()
    row = c.execute('SELECT id FROM books WHERE librivox_id = ?', (str(librivox_id),)).fetchone()
    if row:
        book_id = row[0]
        c.execute("""
            UPDATE books SET
                title = ?, description = ?, language = ?, duration = ?,
                audio_file_count = ?, directory_path = ?, directory_exists = 1,
                source = 'local', librivox_info = ?, updated_at = ?
            WHERE id = ?
        """, (title, description, language, duration, audio_file_count,
              directory_path, json.dumps(librivox_info), now, book_id))
        return book_id, False
    else:
        c.execute("""
            INSERT INTO books
                (title, description, language, duration, audio_file_count,
                 directory_path, directory_exists, source, librivox_id, librivox_info,
                 needs_review, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, 'local', ?, ?, 0, ?, ?)
        """, (title, description, language, duration, audio_file_count,
              directory_path, str(librivox_id), json.dumps(librivox_info), now, now))
        return c.lastrowid, True


def sync_chapters(c, book_id, sections):
    c.execute('DELETE FROM chapters WHERE book_id = ?', (book_id,))
    now = datetime.utcnow().isoformat()
    for section in sections:
        listen_url = section.get('listen_url', '')
        file_name = os.path.basename(listen_url) if listen_url else ''
        duration_secs = int(section.get('playtime', 0) or 0)
        c.execute("""
            INSERT INTO chapters
                (book_id, chapter_number, title, file_name, listen_url, duration, size_bytes, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)
        """, (
            book_id,
            int(section.get('section_number', 0) or 0),
            section.get('title', ''),
            file_name,
            listen_url,
            duration_secs,
            now, now,
        ))


def sync_genres(c, book_id, genre_names):
    c.execute('DELETE FROM book_genre WHERE book_id = ?', (book_id,))
    for name in genre_names:
        genre_id = get_or_create_genre(c, name)
        c.execute('INSERT OR IGNORE INTO book_genre (book_id, genre_id) VALUES (?, ?)', (book_id, genre_id))


def sync_author(c, book_id, author_name):
    c.execute('DELETE FROM author_book WHERE book_id = ?', (book_id,))
    author_id = get_or_create_author(c, author_name)
    c.execute('INSERT OR IGNORE INTO author_book (author_id, book_id) VALUES (?, ?)', (author_id, book_id))


def sync_series(c, book_id, series_name, series_number):
    series_id = get_or_create_series(c, series_name)
    c.execute('DELETE FROM book_series WHERE book_id = ?', (book_id,))
    c.execute('INSERT INTO book_series (book_id, series_id, series_number) VALUES (?, ?, ?)',
              (book_id, series_id, series_number))


def download_file(url, dest_path, timeout=300):
    req = urllib.request.Request(url, headers={
        'User-Agent': 'AudiobookLibrarian-DemoSetup/1.0 (contact demo@audiobooklibrarian.com)'
    })
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        with open(dest_path, 'wb') as f:
            while True:
                chunk = resp.read(65536)
                if not chunk:
                    break
                f.write(chunk)
    return os.path.getsize(dest_path)


def download_cover(url, dest_path):
    data = http_get(url, timeout=30)
    with open(dest_path, 'wb') as f:
        f.write(data)
    return len(data)


def sections_to_chapters(sections):
    chapters = []
    for s in sections:
        listen_url = s.get('listen_url', '')
        filename = os.path.basename(listen_url) if listen_url else ''
        chapters.append({
            'title':    s.get('title', ''),
            'file':     filename,
            'start':    None,
            'duration': int(s.get('playtime', 0) or 0),
        })
    return chapters


def write_librarian_json(path, title, authors, genres, series_name, series_number, year, description, language, dir_path, chapters=None):
    metadata = {
        'title':         title,
        'author':        authors,
        'narrator':      [],
        'genre':         genres[0] if genres else '',
        'series':        series_name or '',
        'series_number': series_number,
        'year':          int(year) if year else None,
        'description':   html.unescape(description or '').replace('\n', ' ').strip(),
        'language':      language or 'English',
        'isbn':          '',
        'asin':          '',
        'publisher':     '',
        'cover_url':     '',
        'confidence':    100,
        'source_path':   dir_path,
        'chapters':      chapters or [],
    }
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(metadata, f, indent=4, ensure_ascii=False)


def load_json_fixture(name):
    with open(os.path.join(FIXTURES_DIR, name), encoding='utf-8') as f:
        return json.load(f)


def write_enriched_librarian_json(path, book_id, rel_dir, default_title, default_author,
                                   sections, abs_dir, fixture):
    """Write a full LibrarianMetadata-shaped librarian.json for a book that has a
    curated fixture, so the client adopts it as-is on first download instead of
    generating bare metadata from a fresh scan. audioFiles/chapters are computed from
    the live LibriVox sections/downloaded files rather than the fixture, so they always
    match this server's actual directory layout and file set."""
    audio_files = []
    chapters = []
    start_ms = 0
    for i, section in enumerate(sections):
        listen_url = section.get('listen_url', '')
        filename = os.path.basename(listen_url) if listen_url else ''
        dest = os.path.join(abs_dir, filename)
        size_bytes = os.path.getsize(dest) if filename and os.path.exists(dest) else 0
        duration_ms = int(section.get('playtime', 0) or 0) * 1000
        audio_files.append({
            'name': filename,
            'path': f'{rel_dir}/{filename}',
            'durationMs': duration_ms,
            'sizeBytes': size_bytes,
            'trackNumber': i + 1,
            'format': os.path.splitext(filename)[1].lstrip('.') or 'mp3',
        })
        chapters.append({
            'index': i,
            'title': section.get('title') or f'Chapter {i + 1}',
            'startTimeMs': start_ms,
            'durationMs': duration_ms,
            'fileIndex': i,
        })
        start_ms += duration_ms

    now_ms = int(time.time() * 1000)
    cover_dest = os.path.join(abs_dir, 'cover.jpg')
    metadata = {
        'bookId': book_id,
        'version': fixture.get('version', 1),
        'title': fixture.get('title') or default_title,
        'author': fixture.get('author') or [default_author],
        'authorId': None,
        'narrator': fixture.get('narrator', []),
        'series': fixture.get('series'),
        'seriesId': fixture.get('seriesId'),
        'description': fixture.get('description', ''),
        'rating': fixture.get('rating'),
        'publishedYear': fixture.get('publishedYear'),
        'coverUrl': None,
        'localCoverPath': f'{rel_dir}/cover.jpg' if os.path.exists(cover_dest) else None,
        'contentPath': rel_dir,
        'downloadedAt': now_ms,
        'fileSize': sum(af['sizeBytes'] for af in audio_files),
        'audioFiles': audio_files,
        'totalDurationMs': start_ms,
        'chapters': chapters,
        'chaptersExtractedAt': now_ms,
        'progress': None,
        'bookmarks': [],
        'listeningHistory': [],
        'notes': fixture.get('notes', []),
        'characters': fixture.get('characters', []),
        'createdAt': now_ms,
        'lastUpdatedAt': now_ms,
        'lastScannedAt': now_ms,
        'apiId': book_id,
        'isMatchDismissed': False,
        'backendId': None,
        'sourceRemoteId': None,
    }
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(metadata, f, indent=4, ensure_ascii=False)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    print(f'\n=== Demo Book Import ===')
    print(f'DB:        {DB_PATH}')
    print(f'Book root: {BOOK_ROOT}\n')

    conn = sqlite3.connect(DB_PATH)
    c = conn.cursor()

    ok = skipped = failed = 0

    for (librivox_id, title, rel_dir, author_name) in DEMO_BOOKS:
        abs_dir = os.path.join(BOOK_ROOT, rel_dir)
        print(f'[{librivox_id}] {title}')

        # Create directory
        os.makedirs(abs_dir, exist_ok=True)

        # Fetch from LibriVox API
        try:
            api_book = fetch_librivox_book(librivox_id)
            time.sleep(0.3)  # polite rate-limit
        except Exception as e:
            print(f'  ERROR fetching from LibriVox API: {e}')
            failed += 1
            continue

        if not api_book:
            print(f'  ERROR: not found on LibriVox')
            failed += 1
            continue

        description = api_book.get('description', '') or ''
        description = description.strip()
        language = api_book.get('language', 'English') or 'English'
        duration = int(api_book.get('totaltimesecs', 0) or 0)
        sections = api_book.get('sections', []) or []
        audio_file_count = int(api_book.get('num_sections', len(sections)) or len(sections))
        year = api_book.get('copyright_year') or None

        librivox_info = {
            'id':             str(librivox_id),
            'url_zip_file':   api_book.get('url_zip_file'),
            'url_librivox':   api_book.get('url_librivox'),
            'url_iarchive':   api_book.get('url_iarchive'),
            'url_text_source': api_book.get('url_text_source'),
            'imported_at':    datetime.utcnow().isoformat(),
        }

        # Upsert book
        book_id, created = upsert_book(c, librivox_id, title, description, language,
                                       duration, audio_file_count, rel_dir, librivox_info)
        print(f'  {"created" if created else "updated"} book_id={book_id}, {len(sections)} chapters')

        # Sync chapters
        sync_chapters(c, book_id, sections)

        # Sync author
        sync_author(c, book_id, author_name)

        # Sync genres
        genre_names = BOOK_GENRES.get(librivox_id, [])
        sync_genres(c, book_id, genre_names)

        # Sync series if applicable
        if librivox_id in BOOK_SERIES:
            series_name, series_number = BOOK_SERIES[librivox_id]
            sync_series(c, book_id, series_name, series_number)

        conn.commit()

        # Download cover
        cover_url = COVER_URLS.get(librivox_id)
        cover_dest = os.path.join(abs_dir, 'cover.jpg')
        cover_ok = os.path.exists(cover_dest) and os.path.getsize(cover_dest) > 0
        if cover_ok:
            print(f'  cover: skipped (already exists)')
        elif cover_url:
            try:
                size = download_cover(cover_url, cover_dest)
                print(f'  cover: {size // 1024}KB')
                cover_ok = True
            except Exception as e:
                print(f'  WARN: cover download failed: {e}')

        if cover_ok:
            c.execute("UPDATE books SET cover_image = 'cover.jpg' WHERE id = ?", (book_id,))

        # Download audio chapters
        audio_ok = audio_skip = audio_fail = 0
        for section in sections:
            listen_url = section.get('listen_url', '')
            if not listen_url:
                continue
            filename = os.path.basename(listen_url)
            dest = os.path.join(abs_dir, filename)
            if os.path.exists(dest) and os.path.getsize(dest) > 0:
                audio_skip += 1
                continue
            try:
                size = download_file(listen_url, dest)
                audio_ok += 1
            except Exception as e:
                print(f'    WARN: audio download failed ({filename}): {e}')
                audio_fail += 1
            time.sleep(0.1)  # polite rate-limit

        print(f'  audio: {audio_ok} downloaded, {audio_skip} skipped, {audio_fail} failed')

        # Write librarian.json. Books with a curated fixture get a full, enriched
        # metadata file (real audioFiles/chapters plus fixture description/notes/
        # characters); everything else gets the bare-bones hint file.
        librarian_json_path = os.path.join(abs_dir, 'librarian.json')
        fixture_name = LIBRARIAN_JSON_FIXTURES.get(librivox_id)
        if fixture_name:
            fixture = load_json_fixture(fixture_name)
            write_enriched_librarian_json(
                librarian_json_path, book_id, rel_dir, title, author_name,
                sections, abs_dir, fixture,
            )
            print(f'  librarian.json written from fixture ({len(sections)} chapters, '
                  f'{len(fixture.get("notes", []))} notes, {len(fixture.get("characters", []))} characters)')
        else:
            series_name, series_number = BOOK_SERIES.get(librivox_id, (None, None))
            write_librarian_json(
                librarian_json_path,
                title, [author_name], genre_names,
                series_name, series_number, year, description, language, rel_dir,
                chapters=sections_to_chapters(sections),
            )
            print(f'  librarian.json written ({len(sections)} chapters)')

        conn.commit()
        ok += 1

    conn.close()
    print(f'\nDone. Imported/updated: {ok}, Failed: {failed}')


if __name__ == '__main__':
    main()
