// Dumps the entire Firestore books collection as JSON array for MongoDB import
// Usage: node functions/tools/firestore_books_dump.js > books.json
// Requires GOOGLE_APPLICATION_CREDENTIALS env var set or Firebase Admin SDK initialized

require("dotenv").config();
const admin = require("firebase-admin");
const {MongoClient} = require("mongodb");
const fs = require("fs");

const argv = require("minimist")(process.argv.slice(2));
const IMPORT_TO_MONGO = argv["import-to-mongo"] || false;
const COLLECTION = argv["collection"] || "books";
const ONE_BY_ONE = argv["one-by-one"] || false;

// Initialize Firebase Admin if not already initialized
if (!admin.apps.length) {
  admin.initializeApp();
}

const db = admin.firestore();

async function dumpBooks() {
  const booksRef = db.collection(COLLECTION);
  const snapshot = await booksRef.get();
  const books = [];
  snapshot.forEach((doc) => {
    const data = doc.data();
    data._id = doc.id;
    books.push(data);
  });

  if (IMPORT_TO_MONGO) {
    const uri = process.env.MONGODB_URI || "mongodb://localhost:27017";
    const dbName = process.env.MONGODB_DB || "ab_librarian";
    const client = new MongoClient(uri);
    try {
      await client.connect();
      const mdb = client.db(dbName);
      const collection = mdb.collection(COLLECTION);
      let result;
      if (ONE_BY_ONE) {
        let inserted = 0;
        for (const book of books) {
          try {
            await collection.insertOne(book);
            inserted++;
          } catch (e) {
            console.error(`Mongo insert error for _id=${book._id}:`, e.message);
          }
        }
        console.log(`Inserted ${inserted} of ${books.length} docs into ${dbName}.${COLLECTION}`);
      } else {
        result = await collection.insertMany(books);
        console.log(`Inserted ${result.insertedCount} docs into ${dbName}.${COLLECTION}`);
      }
    } finally {
      await client.close();
    }
  } else if (ONE_BY_ONE) {
    for (const book of books) {
      process.stdout.write(JSON.stringify(book) + "\n");
    }
    console.error(`Exported ${books.length} books (one per line).`);
  } else {
    process.stdout.write(JSON.stringify(books, null, 2));
    console.error(`Exported ${books.length} books.`);
  }
}


dumpBooks().catch((err) => {
  console.error("Error dumping books:", err);
  process.exit(1);
});
