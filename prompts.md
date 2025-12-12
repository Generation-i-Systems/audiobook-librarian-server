# AI Prompts Log

This file contains the text of prompts sent to the AI assistant.

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

build a special importer for "/media/lyra_data1/audiobooks/books/Science Fiction/VA/Top 100-ish Sci-Fi Books"  the number is for a "collection" which needs to be a special type of series. It is never a primary series but it should be stored as a series with a new flag added. edit form will also need a checkbox that can be set to show that the "series" is a collection. and it will need to be added to the api as well. For this directory parse the subdirs like '82 - The Lathe of Heaven - Ursula K Le Guin - 1971' as '[collectionNum] - [title] - [author or authors] - [year]' and the collection name is "Top 100-ish Sci-Fi Books" the books should be moved to their proper locaton based on the author and have a normal book title directory. Collections to not create subdirectories in the storage path like a regular series does. get cover image and other enrichment like a normal import. the script should have a dry run that would show the details for each book to be moved and imported. and should be able to be run with specific books for testing by specifying them on the commandline. add collections to the docs and all the places where series is used

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

## 2025-12-10

The clients are getting api responses from `https://books.thelin.org/api/v1/books/10708` and similar urls containing things like "series":[{"name":null,"series_number":null}] this doesn't follow the spec listed at `api-docs/openapi.json`. Fix that and make a test suite that can be run independently of other tests that verifies the api output. Make an api endpoint that can be hit without auth by an uptime monitor that will show that each api endpoint is returning data that follows the spec
