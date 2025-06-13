/**
 * Import function triggers from their respective submodules:
 *
 * import {onCall} from "firebase-functions/v2/https";
 * import {onDocumentWritten} from "firebase-functions/v2/firestore";
 *
 * See a full list of supported triggers at https://firebase.google.com/docs/functions
 */

import {onRequest} from "firebase-functions/v2/https";
import * as logger from "firebase-functions/logger";

import * as functions from "firebase-functions";
import * as admin from "firebase-admin";


// Start writing functions
// https://firebase.google.com/docs/functions/typescript

export const helloWorld = onRequest((request, response) => {
  logger.info(
    "Hello logs!",
    {structuredData: true},
  );
  response.send("Hello from Firebase!");
});


admin.initializeApp();

interface Book {
  id: string;
  title?: string;
  author?: string[];
  [key: string]: any;
}


/**
 * GET /getBooksPaginated
 * Query params: page, pageSize, sortBy, sortDir, genre, author, title
 * - sortBy: 'title' | 'author' | 'dateAdded'
 * - sortDir: 'asc' | 'desc'
 * - genre: string (optional)
 * - author: string (optional)
 * - title: string (substring, optional)
 * Returns: { total, page, pageSize, data: Book[] }
 */
export const getBooksPaginated = functions.https.onRequest(
  async (req, res) => {
    try {
      const db: admin.firestore.Firestore = admin.firestore();
      const page = Math.max(
        1,
        parseInt(req.query.page as string) || 1
      );
      const pageSize = Math.max(
        1,
        Math.min(100, parseInt(req.query.pageSize as string) || 20)
      );
      const sortBy = (req.query.sortBy as string) || "dateAdded";
      const sortDir = (
        (req.query.sortDir as string) || "desc"
      ).toLowerCase() === "asc" ? "asc" : "desc";
      const genre = req.query.genre as string | undefined;
      const author = req.query.author as string | undefined;
      const title = req.query.title as string | undefined;

      let query: FirebaseFirestore.Query = db.collection("books");

      // Filtering
      if (genre) {
        query = query.where("genre", "array-contains", genre);
      }
      if (author) {
        query = query.where("author", "array-contains", author);
      }
      // Firestore does not support substring search;
      // we'll filter title in memory

      // Sorting
      if (["title", "dateAdded"].includes(sortBy)) {
        query = query.orderBy(
          sortBy,
          sortDir as FirebaseFirestore.OrderByDirection,
        );
      } else if (sortBy === "author") {
        // Firestore can't order by array, so fallback to in-memory
      } else {
        query = query.orderBy(
          "dateAdded",
          "desc",
        );
      }

      // Pagination (Firestore offset/limit is inefficient for large sets)
      const snapshot = await query.get();
      let books: Book[] = snapshot.docs.map((doc) => ({
        id: doc.id,
        ...doc.data(),
      }));

      // In-memory filter for title substring
      if (title) {
        const t = title.toLowerCase();
        books = books.filter(
          (b) =>
            typeof b.title === "string" &&
            b.title.toLowerCase().includes(t)
        );
      }

      // In-memory sort for author if needed
      if (sortBy === "author") {
        books.sort((a, b) => {
          const aAuthor = Array.isArray(a.author) ? a.author[0] || "" : "";
          const bAuthor = Array.isArray(b.author) ? b.author[0] || "" : "";
          return sortDir === "asc" ?
            aAuthor.localeCompare(bAuthor) :
            bAuthor.localeCompare(aAuthor);
        });
      }

      const total = books.length;
      const start = (page - 1) * pageSize;
      const end = start + pageSize;
      const data = books.slice(start, end);

      res.status(200).json({
        total,
        page,
        pageSize,
        data,
      });
    } catch (error) {
      console.error("Error in getBooksPaginated:", error);
      res.status(500).json({error: "Internal Server Error"});
    }
  }
);


export const getUniqueAuthors = functions.https.onRequest(async (req, res) => {
  try {
    const db: admin.firestore.Firestore = admin.firestore();
    const booksSnapshot: admin.firestore.QuerySnapshot =
        await db.collection("books").get();

    if (booksSnapshot.empty) {
      console.log("No books found.");
      res.status(200).send([]);
      return;
    }

    const authorsData: Record<
      string,
      {
        count: number;
        genres: Set<string>;
        series: Record<string, number>;
      }
    > = {};

    booksSnapshot.forEach((doc: admin.firestore.QueryDocumentSnapshot) => {
      const bookData = doc.data();
      if (bookData && Array.isArray(bookData.author)) {
        bookData.author.forEach((author: string) => {
          if (typeof author === "string" && author.trim() !== "") {
            const trimmedAuthor = author.trim();
            if (!authorsData[trimmedAuthor]) {
              authorsData[trimmedAuthor] = {
                count: 0,
                genres: new Set(),
                series: {},
              };
            }

            authorsData[trimmedAuthor].count += 1;

            if (Array.isArray(bookData.genre)) {
              bookData.genre.forEach((genre: string) => {
                if (typeof genre === "string" && genre.trim() !== "") {
                  authorsData[trimmedAuthor].genres.add(genre.trim());
                }
              });
            }

            if (bookData.series && typeof bookData.series === "object") {
              Object.entries(bookData.series).forEach(
                ([seriesName, seriesNumber]) => {
                  if (
                    typeof seriesName === "string" &&
                  typeof seriesNumber === "number"
                  ) {
                    authorsData[trimmedAuthor].series[seriesName] =
                    seriesNumber;
                  }
                });
            }
          }
        });
      }
    });

    const result = Object.entries(authorsData).map(
      ([author, data]) => ({
        author,
        count: data.count,
        genres: Array.from(data.genres).sort(),
        series: data.series,
      })
    );

    console.log(
      "Authors data:",
      result,
    );
    res.status(200).send(result);
  } catch (error) {
    console.error("Error fetching unique authors:", error);
    res.status(500).send("Internal Server Error");
  }
});

export const getBooksByGenreCount = functions.https.onRequest(
  async (req, res) => {
    try {
      const db: admin.firestore.Firestore = admin.firestore();
      const booksSnapshot: admin.firestore.QuerySnapshot =
        await db.collection("books").get();

      if (booksSnapshot.empty) {
        console.log("No books found.");
        res.status(200).send({});
        return;
      }

      const genreCount: Record<string, number> = {};
      // Object to store genre counts

      booksSnapshot.forEach(
        (doc: admin.firestore.QueryDocumentSnapshot) => {
          const bookData = doc.data();
          if (bookData && Array.isArray(bookData.genre)) {
            bookData.genre.forEach((genre: string) => {
              if (
                typeof genre === "string" &&
              genre.trim() !== ""
              ) {
                const trimmedGenre = genre.trim();
                genreCount[trimmedGenre] =
                (genreCount[trimmedGenre] || 0) + 1;
              }
            });
          }
        }
      );

      console.log("Books by genre count:", genreCount);
      res.status(200).send(genreCount);
    } catch (error) {
      console.error("Error fetching books by genre count:", error);
      res.status(500).send("Internal Server Error");
    }
  });
