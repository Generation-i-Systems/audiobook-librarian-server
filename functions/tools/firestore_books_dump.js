// Dumps the entire Firestore books collection as JSON array for MongoDB import
// Usage: node functions/tools/firestore_books_dump.js > books.json
// Requires GOOGLE_APPLICATION_CREDENTIALS env var set or Firebase Admin SDK initialized

const admin = require("firebase-admin");
const fs = require("fs");

// Initialize Firebase Admin if not already initialized
if (!admin.apps.length) {
  admin.initializeApp();
}

const db = admin.firestore();

async function dumpBooks() {
  const booksRef = db.collection("books");
  const snapshot = await booksRef.get();
  const books = [];
  snapshot.forEach((doc) => {
    const data = doc.data();
    // Optionally, remove Firestore-only fields
    data._id = doc.id; // MongoDB uses _id
    books.push(data);
  });
  // Output as pretty JSON array
  process.stdout.write(JSON.stringify(books, null, 2));
  console.error(`Exported ${books.length} books.`);
}

dumpBooks().catch((err) => {
  console.error("Error dumping books:", err);
  process.exit(1);
});
