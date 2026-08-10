# AI Prompts Log

This file contains the text of prompts sent to the AI assistant.

---

## 2026-08-08

pm notice run vite build
vite v6.4.3 building for production...
Sass emits deprecation warnings for application and Bootstrap `@import` usage. Please migrate the dependency and stylesheet so production builds remain clean.

---

## 2026-08-10

adding a cover image from the web book edit silently fails

editing https://books.ablibrarian.com/admin/books/14469/edit?search=cannon&sort=recent_desc

---

Call to undefined method App\Auth\DocumentstoreUser::groups() when opening /admin/books/5809

---

update the import script to use more AI by including the previous imports of other books in the same parent directory if they were user modified so that patterns modified by the user may be applied to the other files in the same parent dir (ignoring generic parents). in the edit menu add an item to reverse the title and series and make sure this is something that the AI would notice

---

## 2026-08-03

build a backend-independent Skin Store at www.ablibrarian.com. Phase 1 must provide remote free-skin browsing, previews, downloads, and skin-packaged rules for every client backend; paid skins remain previewable and clearly unavailable until a later Phase 2 commerce release. Keep the self-hosted server gallery proxy only for legacy compatibility.

---

if an email server is configured add support for logging into the web with a onetime password code or email link similar to what the app does. and create a server capabilities endpoint in the api the will allow the client to request login capabilities from the server. Also add an option in the user management to show a QR code and/or send an email that will allow loging in through a onetime login. make sure it works with the deep links in the client for hosted server installs

---

## 2026-07-22

the qr generation on the login page is not working

---

## 2026-07-17

it also seems that series number from enrichment sources is being ignored too

---

something has changed in the import script that has made series number not work very often. For example "/media/lyra_data/download/Magic Eater 5" is not getting a series number even though it is clearly in the directory name

---

## 2026-07-12

make sure https only is documented in the install instructions and docker for the server and for the lite server

---

## 2026-07-12

then update to your suggested flow

---

## 2025-10-16

test that this line gets added to prompts.md

get a count of books in the database

get a count of books in the database that have missing directories

add a filter for api operations that surpresses those books based on a daily rescan of the file system to validate if directories are missing. Also scan for directories that do not have books and build a report page in the app showing both categories. including AI processing to attempt to match them up to see if some of the directories just need to be renamed

yes

on the admin page the pagination is messed up with huge forward and back icons. and the import button doesn't work. There should also be a delete option and a move (rename) options for orphaned directories. Import should go to the regular import flow as if import was selected from the web import from directory flow and returning from that import should put the user back on the same page as before (only hopefully with the imported item removed)

ebook directories should not be conisdered for import. nor should any other directory that does not contain audio files

the import link doesn't work. it just goes to the directory browse screen. Instead it should import using the edit form LIKE what is used on that directory browse screen

rename doesn't work ( it doesn't do anything) it should rename in place for the full directory path (beneath root obviously)

on import no data is prepopulated

rename button still doesn't do anytyhing

the pagination buttons are still ENORMOUS ~300px x ~500px

deleting one row cleared the entire orphaned data

the page is showin AI suggestions now but the only action shown is "rename" and the ai text is showing as white on light grey

the icons are still ENORMOUS and the orphaned list is still 0 entries

rescan now just makes the page unable to load. because it takes so long the only way to do areload is in the background with a job

I believe there are crons already setup. check permissions as part of scan so that renames and such are only offered if possible. and a warning shown if not. Also AI should not even show matches below 75% confidence

build a special importer for "/media/lyra_data1/audiobooks/books/Science Fiction/VA/Top 100-ish Sci-Fi Books" the number is for a "collection" which needs to be a special type of series. It is never a primary series but it should be stored as a series with a new flag added. edit form will also need a checkbox that can be set to show that the "series" is a collection. and it will need to be added to the api as well. For this directory parse the subdirs like '82 - The Lathe of Heaven - Ursula K Le Guin - 1971' as '[collectionNum] - [title] - [author or authors] - [year]' and the collection name is "Top 100-ish Sci-Fi Books" the books should be moved to their proper locaton based on the author and have a normal book title directory. Collections to not create subdirectories in the storage path like a regular series does. get cover image and other enrichment like a normal import. the script should have a dry run that would show the details for each book to be moved and imported. and should be able to be run with specific books for testing by specifying them on the commandline. add collections to the docs and all the places where series is used

---

## 2025-10-17

continue

the import-bk script needs to support this

add a rule to always add the user prompt to prompts.md or update the one that should already be there to work

Full error trace: Method App\Console\Commands\ImportBooksFromDownloads::getStoragePath does not exist.

add collection to the book listing on import and book info. make sure that the image gets set to the correct file IN the destination directory for all imports not the source directory

this is definately a collection: /media/lyra_data1/audiobooks/books/Science Fiction/VA/Top 100-ish Sci-Fi Books/24 - Snow Crash - Neal Stephenson - 1992

Revelation Space book showing Collection: No even though it detected the collection. The AI found the actual Revelation Space series which is correct, but it should show BOTH the primary series AND the collection

no fix, actually worse because it is createing a phantom series. Gray Lensman shows "Detected collection" message but then Collection field shows "No"

the message says the book was moved successfully but it didn't get moved at all. not at the dest location and still in the source location

the files are being moved to directories that use the Collection NOT what is shown in the summary. This is MAJOR issue. the data shown for approval MUST be EXACTLY what is used.

there MUST be nothing that modifies the data AFTER it is approved. The approved data was correct. but far more important that this fix is that the data as presented is what is used.

on import the messages make it look like creating the db entry takes a long time when the real time seems to be moving the files. add a message to make that clear

on the book edit screen autofill metadata button doesn't work. Error: Cannot read properties of undefined (reading 'match') at form.js:161

the button is still not functional neither is the raw json edit button. no console logs. no function

when a new image is updated from the autofill the image in the corner of the form should update to show that. it should always show the image that would be "selected" on the radio buttons if you click on it

when doing autofill when results come back select the first option by default. add a magic autofill button (with just a magic wand icon) that runs with the search and populates the form with the first result. Default the autofill to audible not google books. in the autofill popup instead of having a dropdown to select the source add a separate search button for each source. and a button with the search icon only that searches all sources and shows all results as they come in in the same list

---

## 2026-04-04

the backend is granting badges that don't make sense like monthly goal crusher for people who have never set a goal. or 5 series explorer for users who haven't finished a single book. but not books like "First Library" for users with multiple books in their library. We also don't have a wishlist so "First Wishlist" is impossible. Evaluate all logic for granting badges and make sure that they are granted correctly and that all badges can be earned

for listening speeds also make sure the user listened for a reasonable time at each speed to count

I would also like to have stats showing minutes listened per day, per week, and per month. There may be goals set for an ammount listened within a play list

"Reading Timeline" is always blank

in theory I should be able to go back and look at an given day/week/month and get the time listened along with requesting the details of which books and time per book

from the server perspective the user should be able to request timeline data for any time period with any resolution of agregation. aka request one value per month for specified date range. or simply now-[specified start time]. Or get the same data for week or only on weekends, or only on thursdays. So there should be much flexibility based on client needs to get ranges summarized or specific data.
include this in the final pass on timeline data

---

## 2026-02-13

implement applie login and facebook login support and document in openapi.json

---

## 2025-12-19

admin/BookController.php is too large move functionality out of it where possible

---

## 2025-12-10

The clients are getting api responses from `https://books.thelin.org/api/v1/books/10708` and similar urls containing things like "series":[{"name":null,"series_number":null}] this doesn't follow the spec listed at `api-docs/openapi.json`. Fix that and make a test suite that can be run independently of other tests that verifies the api output. Make an api endpoint that can be hit without auth by an uptime monitor that will show that each api endpoint is returning data that follows the spec

---

## 2025-12-11

implement the metadata lookup for audiobookbay and hardcover. audiobookbay should not need auth credentials for any of the features currently implemented. validate the data with integration tests that hit the actual audiobookbay url. Do the same for hardcover. these tests should not be part of any existing test suite. They are only for developing the endpoint handlers or for verifying the handlers going forward so they would need to be ran specifically and not even in global test suite runs. among other things this is an example url that should work https://books.thelin.org/admin/books/search?source=audiobookbay&title=The%20Last%20Guardian&author=Eoin%20Colfer&series=&api_id=

---

## 2025-12-13

add a sanity check for imports to make sure there are audio files in the destination directory

when importing a multi book directory default to using the same genre for all future books after selecting a genre in one book

the directory should be set at the beginning of the import and just USED (do not create a \_01 directory)

---

## 2025-12-15

I am trying to save an update that changes the coverimage and the series but neither are being saved.

---

## 2026-02-21

Android client crashes parsing `/api/v1/badges/unnotified` with: `Unexpected symbol 'n' in numeric literal` at `criteria_met.first_listening_date` because the API returns `null` for date fields where the client expects a number. Fix the API response to be type-stable and update OpenAPI + tests.

still not seeing recent activity not from this device

all cover images should be relative to the directoryPath. So "coverImage": "Romance/J.R. Ward/Black Dagger Brotherhood/02 Lover Eternal/cover_audible_1765842643.jpg", should just be cover_audible_1765842643.jpg. Look at import edit and display logics to make sure we are using the new format. But also support existing data that includes the directoryPath

---

## 2025-12-17

updating the path in the form still isn't moving the files

---

## 2025-12-18

I am trying to optimize the sizes of the blocks in the import cli. but have been unable to increase the size of the submenu for editing individual fields but one row so that all content shows up without scrolling and maximizes all other box sizes according to the section sizes

---

## 2026-02-07

restoring a deleted book doesn't restore the db record and does not set the file permissions using the permissions script

fix auto-processing bug where books were imported without user confirmation even without --auto flag

fix series name removal from book titles across all import paths (multi-book splits, single books, enrichment overrides)

add logic to check for existing books in a series when determining genre. If there are already other books by that author with that series then use the same genre as them

for multi-book archives the title still has the series

commit all changes

---

## 2026-04-13

delete genres that do not contain any books

make sure the api endpoints ignore deleted genres

add an icon and an emoji to the genre table for each genre. Search the internet for good images

make sure to add the new content to the genre manage page and to the api (and obviously openapi.json)

---

## 2026-04-17

I need a separate server instance that uses a separate db and separate file source that is 100% api compatible with the main server. The admin interface will be somewhat different because the source of the books will be the librivox api. it should allow browsing books that are already on librivox and selecting which ones to download and store on the server but also may expose the librivox content directly via the api and provide a passthrough to the client without ever storing the book locally as anything more than a cache. this may be able to expose the librivox books based on a permission on the server. I haven't decided if it needs to be a fully separate instance or integrated into the regular app and gated between the two libraries on permissions. One core goal is to be able to test the client app or even distribute it entirely based on librivox content but using an api that is 100% compatible with the regular content.

I also need to make sure that there is 0!!! drift in the api between the two variants. If there is ever any drift the project will have failed.

it is this 0 drift requirement that makes me wonder if the idea of useing a separate project or even instance may be the wrong direction

local ids with librivox ids also available in the db

Make full and Lite self-hosted deployments portable across Linux, macOS, and Windows: eliminate machine-specific `/media` storage defaults, retain Docker Desktop support, document native PHP deployment, and require HTTPS for client-facing endpoints.

1
add the ability to scan a QR code on the api selection page that will be the primary way that clients change modes. So they will hit a different instance by changing the api. The client should never know that the two modes exist just that they get data from an api and that api confirms to the expected standards

Allow running both "instances" from the same codebase gated on the uri requesting the data. so the same .env can support both variants and swap the data sources based on the incoming address

proceed on a branch

---

## 2026-07-26

Cache the file chunk hashes that are optionally sent in download manifests after first generation.
Add an Artisan command to pre-generate them for specific books, newest books, all books, or a list
of books, with a system-load threshold so it can run frequently from cron and only work when the
server is not busy.

## 2026-07-26

Update installation documentation for all users so required server cron/scheduler scripts and
optional maintenance jobs are covered. Include Docker vs native behavior, required scheduler and
queue worker setup, scheduled maintenance commands, and optional low-load chunk-hash precompute
cron examples.

## 2026-07-26

Update the chunk hash script to show the author and title along with book_id.

## 2026-07-26

Update the chunk hash script so it only processes books that do not already have fresh file hashes.
Repeated `--newest=10` runs should eventually advance through all hashable books instead of
staying on the newest already-cached books.

## 2026-07-26

Update normal chunk-hash command runs to use a DB-only missing-cache filter so already-cached books
do not require filesystem operations before the batch starts. Add `--refresh` for the slower path
that lists files and compares size/mtime against cached rows. Do not print per-book lines for
skipped or missing candidates.

## 2026-07-26

Print the processing time for each book in the chunk-hash command output. Reduce startup latency by
streaming candidate books and loading authors only for processed books that are actually printed.

## 2026-07-26

Simplify chunk-hash command output by removing cached/missing/skipped counts unless they are
nonzero for the processed book.

---

## 2026-07-26

implement an app:refresh command ported from /home/devel/src/school-bus-dashboard

## 2026-07-26

Create server-side audiobook chapter autodetection similar to chunk metadata handling so
`librarian.json` includes chapters when embedded chapter metadata is available.

## 2026-07-26

Store detected chapters in the database so `librarian.json` can be regenerated from DB rows.
If existing `librarian.json` chapters are found and the database has none, import those chapters
into the database.

## 2026-07-26

Make sure both `books:cache-file-chunk-hashes` and `books:detect-chapters` support inclusive
book-id ranges with `--book`, while preserving repeated single-book selections.

## 2026-07-27

Address the npm audit issues reported by the pre-push hook for `brace-expansion` and `postcss`.

## 2026-07-27

When editing a book and running metadata update, genres are being added with many blank entries
and the genre already set from the directory path is being changed. Neither should happen.

## 2026-07-28

Normal Jest hook runs are failing because the new autofill regression test imports large DOM
wiring files with low coverage, dropping global coverage below the enforced threshold.
