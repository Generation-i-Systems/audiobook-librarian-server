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
import {OAuth2Client} from "google-auth-library";


// Start writing functions
// https://firebase.google.com/docs/functions/typescript

export const helloWorld = onRequest((request, response) => {
  logger.info(
    "Hello logs!",
    {structuredData: true},
  );
  response.send("Hello from Firebase!");
});

/**
 * GET /books/{bookId}/download/manifest
 * Returns metadata about the content of a book download zip
 * Returns: {
 *   formats: Array<{type: string, size: number, chapters: number}>,
 *   chapters: Array<{title: string, duration: number, start_time: number}>
 * }
 */
export const getBookDownloadManifest = functions.https.onRequest(async (req, res) => {
  try {
    // Set CORS headers for preflight requests
    res.set("Access-Control-Allow-Origin", "*");
    res.set("Access-Control-Allow-Methods", "GET");

    // Handle preflight OPTIONS request
    if (req.method === "OPTIONS") {
      res.status(204).send("");
      return;
    }

    // Ensure GET request method
    if (req.method !== "GET") {
      res.status(405).send({message: "Method not allowed"});
      return;
    }

    // Get book ID from the request path
    // Assuming path is /books/{bookId}/download/manifest
    const bookId = req.path.split("/")[2];

    if (!bookId) {
      res.status(400).send({message: "Book ID is required"});
      return;
    }

    // Check if the user is authenticated
    const authHeader = req.headers.authorization;
    if (!authHeader || !authHeader.startsWith("Bearer ")) {
      res.status(401).send({message: "Unauthorized"});
      return;
    }

    const idToken = authHeader.split("Bearer ")[1];
    let decodedToken;
    try {
      decodedToken = await admin.auth().verifyIdToken(idToken);
    } catch (error) {
      logger.error("Invalid authentication token", error);
      res.status(401).send({message: "Invalid authentication token"});
      return;
    }

    // Get book data from Firestore
    const db = admin.firestore();
    const bookDoc = await db.collection("books").doc(bookId).get();

    if (!bookDoc.exists) {
      res.status(404).send({message: "Book not found"});
      return;
    }

    const bookData = bookDoc.data() || {};

    // Check if the user has access to this book
    // This would depend on your application's access control logic
    // For example, check if the book is in the user's library
    const userLibrary = await db.collection("users")
      .doc(decodedToken.uid)
      .collection("library")
      .doc(bookId)
      .get();

    if (!userLibrary.exists) {
      res.status(403).send({message: "Access denied to this book"});
      return;
    }

    // Get or generate the book download manifest
    // This could be stored in Firestore or generated on-demand
    // For this example, we'll generate a sample manifest

    // Sample manifest data structure
    const manifest: {
      formats: Array<{
        type: string;
        size: number;
        chapters: number;
        bitrate: number;
      }>;
      chapters: Array<{
        number: number;
        title: string;
        duration: number;
        start_time: number;
      }>;
    } = {
      formats: [
        {
          type: "mp3",
          size: 250000000, // Size in bytes
          chapters: bookData.chapters?.length || 10,
          bitrate: 192000, // bits per second
        },
        {
          type: "m4b",
          size: 200000000,
          chapters: bookData.chapters?.length || 10,
          bitrate: 128000,
        },
      ],
      chapters: [],
    };

    // Generate chapter metadata
    if (bookData.chapters && Array.isArray(bookData.chapters)) {
      let startTime = 0;
      manifest.chapters = bookData.chapters.map(
        (chapter: Record<string, unknown>, index: number) => {
          const chapterInfo = {
            number: index + 1,
            title: (chapter.title as string) || `Chapter ${index + 1}`,
            duration: (chapter.duration as number) || 1800, // Default 30 minutes if not specified
            start_time: startTime,
          };

          // Update start time for next chapter
          startTime += chapterInfo.duration;

          return chapterInfo;
        });
    } else {
      // Generate sample chapters if no chapter data exists
      const averageChapterDuration = 1800; // 30 minutes
      const numChapters = 10;

      for (let i = 0; i < numChapters; i++) {
        manifest.chapters.push({
          number: i + 1,
          title: `Chapter ${i + 1}`,
          duration: averageChapterDuration,
          start_time: i * averageChapterDuration,
        });
      }
    }

    // Return the manifest
    res.status(200).send(manifest);
  } catch (error) {
    logger.error("Error generating book download manifest", error);
    res.status(500).send({message: "Internal server error"});
  }
});

// Google OAuth client for token verification
const googleClient = new OAuth2Client();

/**
 * POST /auth/google
 * Exchanges a Google OAuth token for an app JWT token
 * Request body: { id_token: string, access_token?: string }
 * Returns: { token: string, user: User, isNewUser: boolean }
 */
export const googleAuth = functions.https.onRequest(async (req, res) => {
  try {
    // Set CORS headers for preflight requests
    res.set("Access-Control-Allow-Origin", "*");
    res.set("Access-Control-Allow-Methods", "POST");
    res.set("Access-Control-Allow-Headers", "Content-Type");

    // Handle preflight OPTIONS request
    if (req.method === "OPTIONS") {
      res.status(204).send("");
      return;
    }

    // Ensure POST request method
    if (req.method !== "POST") {
      res.status(405).send({message: "Method not allowed"});
      return;
    }

    // Validate request body
    const {idToken} = req.body;

    if (!idToken) {
      res.status(400).send({
        message: "Missing required parameter: id_token",
      });
      return;
    }

    // Verify Google token
    let ticket;
    try {
      ticket = await googleClient.verifyIdToken({
        idToken,
        audience: process.env.GOOGLE_CLIENT_ID, // Should be set in environment variables
      });
    } catch (error) {
      logger.error("Google token verification failed", error);
      res.status(401).send({message: "Invalid Google token"});
      return;
    }

    // Get payload from verified ticket
    const payload = ticket.getPayload();

    if (!payload) {
      res.status(401).send({message: "Invalid Google token payload"});
      return;
    }

    // Get user info from payload
    const {sub: googleId, email, name, picture} = payload;

    if (!email) {
      res.status(401).send({message: "Email not provided in Google token"});
      return;
    }

    // Check if user exists in database
    let userRecord;
    let isNewUser = false;

    try {
      userRecord = await admin.auth().getUserByEmail(email);
    } catch (error) {
      // User doesn't exist, create a new one
      try {
        userRecord = await admin.auth().createUser({
          email: email,
          displayName: name,
          photoURL: picture,
          emailVerified: true, // Google already verified the email
        });

        // Store additional user metadata in Firestore
        await admin.firestore().collection("users").doc(userRecord.uid).set({
          googleId: googleId,
          email: email,
          name: name,
          photoURL: picture,
          createdAt: admin.firestore.FieldValue.serverTimestamp(),
        });

        isNewUser = true;
      } catch (createError) {
        logger.error("Error creating new user", createError);
        res.status(500).send({message: "Error creating user"});
        return;
      }
    }

    // Generate custom token for the user
    const token = await admin.auth().createCustomToken(userRecord.uid);

    // Return token and user info
    res.status(200).send({
      token: token,
      user: {
        id: userRecord.uid,
        name: userRecord.displayName,
        email: userRecord.email,
        photoURL: userRecord.photoURL,
      },
      isNewUser: isNewUser,
    });
  } catch (error) {
    logger.error("Error in Google authentication", error);
    res.status(500).send({message: "Internal server error"});
  }
});


admin.initializeApp();

// Common interfaces for API resources

// Bookmark interface
interface Bookmark {
  id?: string;
  bookId: string;
  userId: string;
  chapter: number;
  position: number;
  title?: string;
  note?: string;
  createdAt?: FirebaseFirestore.Timestamp;
  updatedAt?: FirebaseFirestore.Timestamp;
}

// Push Notification Device Registration interface
interface DeviceRegistration {
  id?: string;
  userId: string;
  deviceToken: string;
  platform: "ios" | "android" | "web";
  createdAt?: FirebaseFirestore.Timestamp;
  updatedAt?: FirebaseFirestore.Timestamp;
  enabled: boolean;
  topics?: string[];
}

// Narrator interface
interface Narrator {
  id?: string;
  name: string;
  bio?: string;
  profileImageUrl?: string;
  createdAt?: FirebaseFirestore.Timestamp;
  updatedAt?: FirebaseFirestore.Timestamp;
  books?: BookSummary[];
}

// Book Summary interface (used for listing books by a narrator)
interface BookSummary {
  id: string;
  title: string;
  coverImageUrl?: string;
  author?: string;
  series?: string;
  genre?: string;
}

/**
 * GET /books/{bookId}/bookmarks
 * Returns all bookmarks for a book for the authenticated user
 * Returns: Array<Bookmark>
 */
export const getBookmarks = functions.https.onRequest(async (req, res) => {
  try {
    // Set CORS headers for preflight requests
    res.set("Access-Control-Allow-Origin", "*");
    res.set("Access-Control-Allow-Methods", "GET");

    // Handle preflight OPTIONS request
    if (req.method === "OPTIONS") {
      res.status(204).send("");
      return;
    }

    // Ensure GET request method
    if (req.method !== "GET") {
      res.status(405).send({message: "Method not allowed"});
      return;
    }

    // Extract bookId from URL
    const pathParts = req.path.split("/");
    if (pathParts.length < 3) {
      res.status(400).send({message: "Invalid request path"});
      return;
    }
    const bookId = pathParts[2]; // books/{bookId}/bookmarks

    // Verify authentication
    const authHeader = req.headers.authorization;
    if (!authHeader || !authHeader.startsWith("Bearer ")) {
      res.status(401).send({message: "Unauthorized"});
      return;
    }

    const idToken = authHeader.split("Bearer ")[1];
    let decodedToken;
    try {
      decodedToken = await admin.auth().verifyIdToken(idToken);
    } catch (error) {
      logger.error("Invalid authentication token", error);
      res.status(401).send({message: "Invalid authentication token"});
      return;
    }

    // Get bookmarks from Firestore
    const db = admin.firestore();
    const bookmarksSnapshot = await db.collection("bookmarks")
      .where("userId", "==", decodedToken.uid)
      .where("bookId", "==", bookId)
      .orderBy("chapter")
      .orderBy("position")
      .get();

    const bookmarks: Bookmark[] = [];
    bookmarksSnapshot.forEach((doc) => {
      bookmarks.push({
        id: doc.id,
        ...doc.data() as Omit<Bookmark, "id">,
      });
    });

    res.status(200).send(bookmarks);
  } catch (error) {
    logger.error("Error getting bookmarks", error);
    res.status(500).send({message: "Internal server error"});
  }
});

/**
 * POST /books/{bookId}/bookmarks
 * Creates a new bookmark for a book
 * Request body: { chapter: number, position: number, title?: string, note?: string }
 * Returns: Bookmark
 */
export const createBookmark = functions.https.onRequest(async (req, res) => {
  try {
    // Set CORS headers for preflight requests
    res.set("Access-Control-Allow-Origin", "*");
    res.set("Access-Control-Allow-Methods", "POST");
    res.set("Access-Control-Allow-Headers", "Content-Type");

    // Handle preflight OPTIONS request
    if (req.method === "OPTIONS") {
      res.status(204).send("");
      return;
    }

    // Ensure POST request method
    if (req.method !== "POST") {
      res.status(405).send({message: "Method not allowed"});
      return;
    }

    // Extract bookId from URL
    const pathParts = req.path.split("/");
    if (pathParts.length < 3) {
      res.status(400).send({message: "Invalid request path"});
      return;
    }
    const bookId = pathParts[2]; // books/{bookId}/bookmarks

    // Verify authentication
    const authHeader = req.headers.authorization;
    if (!authHeader || !authHeader.startsWith("Bearer ")) {
      res.status(401).send({message: "Unauthorized"});
      return;
    }

    const idToken = authHeader.split("Bearer ")[1];
    let decodedToken;
    try {
      decodedToken = await admin.auth().verifyIdToken(idToken);
    } catch (error) {
      logger.error("Invalid authentication token", error);
      res.status(401).send({message: "Invalid authentication token"});
      return;
    }

    // Validate request body
    const {chapter, position, title, note} = req.body;

    if (typeof chapter !== "number" || chapter < 0) {
      res.status(400).send({message: "Invalid chapter number"});
      return;
    }

    if (typeof position !== "number" || position < 0) {
      res.status(400).send({message: "Invalid position"});
      return;
    }

    // Create bookmark in Firestore
    const db = admin.firestore();

    // Verify book exists
    const bookDoc = await db.collection("books").doc(bookId).get();
    if (!bookDoc.exists) {
      res.status(404).send({message: "Book not found"});
      return;
    }

    const bookmarkData: Bookmark = {
      bookId,
      userId: decodedToken.uid,
      chapter,
      position,
      title: title || "",
      note: note || "",
      createdAt: admin.firestore.FieldValue.serverTimestamp() as FirebaseFirestore.Timestamp,
      updatedAt: admin.firestore.FieldValue.serverTimestamp() as FirebaseFirestore.Timestamp,
    };

    const bookmarkRef = await db.collection("bookmarks").add(bookmarkData);
    const newBookmark = await bookmarkRef.get();

    res.status(201).send({
      id: bookmarkRef.id,
      ...newBookmark.data(),
    });
  } catch (error) {
    logger.error("Error creating bookmark", error);
    res.status(500).send({message: "Internal server error"});
  }
});

/**
 * GET /books/{bookId}/bookmarks/{bookmarkId}
 * Returns a specific bookmark
 * Returns: Bookmark
 */
export const getBookmark = functions.https.onRequest(async (req, res) => {
  try {
    // Set CORS headers for preflight requests
    res.set("Access-Control-Allow-Origin", "*");
    res.set("Access-Control-Allow-Methods", "GET");

    // Handle preflight OPTIONS request
    if (req.method === "OPTIONS") {
      res.status(204).send("");
      return;
    }

    // Ensure GET request method
    if (req.method !== "GET") {
      res.status(405).send({message: "Method not allowed"});
      return;
    }

    // Extract bookId and bookmarkId from URL
    const pathParts = req.path.split("/");
    if (pathParts.length < 5) {
      res.status(400).send({message: "Invalid request path"});
      return;
    }
    const bookId = pathParts[2]; // books/{bookId}/bookmarks/{bookmarkId}
    const bookmarkId = pathParts[4];

    // Verify authentication
    const authHeader = req.headers.authorization;
    if (!authHeader || !authHeader.startsWith("Bearer ")) {
      res.status(401).send({message: "Unauthorized"});
      return;
    }

    const idToken = authHeader.split("Bearer ")[1];
    let decodedToken;
    try {
      decodedToken = await admin.auth().verifyIdToken(idToken);
    } catch (error) {
      logger.error("Invalid authentication token", error);
      res.status(401).send({message: "Invalid authentication token"});
      return;
    }

    // Get bookmark from Firestore
    const db = admin.firestore();
    const bookmarkDoc = await db.collection("bookmarks").doc(bookmarkId).get();

    if (!bookmarkDoc.exists) {
      res.status(404).send({message: "Bookmark not found"});
      return;
    }

    const bookmarkData = bookmarkDoc.data() as Bookmark;

    // Verify bookmark belongs to the authenticated user and specified book
    if (bookmarkData.userId !== decodedToken.uid || bookmarkData.bookId !== bookId) {
      res.status(403).send({message: "Access denied"});
      return;
    }

    res.status(200).send({
      id: bookmarkDoc.id,
      ...bookmarkData,
    });
  } catch (error) {
    logger.error("Error getting bookmark", error);
    res.status(500).send({message: "Internal server error"});
  }
});

/**
 * PUT /books/{bookId}/bookmarks/{bookmarkId}
 * Updates a specific bookmark
 * Request body: { chapter?: number, position?: number, title?: string, note?: string }
 * Returns: Bookmark
 */
export const updateBookmark = functions.https.onRequest(async (req, res) => {
  try {
    // Set CORS headers for preflight requests
    res.set("Access-Control-Allow-Origin", "*");
    res.set("Access-Control-Allow-Methods", "PUT");
    res.set("Access-Control-Allow-Headers", "Content-Type");

    // Handle preflight OPTIONS request
    if (req.method === "OPTIONS") {
      res.status(204).send("");
      return;
    }

    // Ensure PUT request method
    if (req.method !== "PUT") {
      res.status(405).send({message: "Method not allowed"});
      return;
    }

    // Extract bookId and bookmarkId from URL
    const pathParts = req.path.split("/");
    if (pathParts.length < 5) {
      res.status(400).send({message: "Invalid request path"});
      return;
    }
    const bookId = pathParts[2]; // books/{bookId}/bookmarks/{bookmarkId}
    const bookmarkId = pathParts[4];

    // Verify authentication
    const authHeader = req.headers.authorization;
    if (!authHeader || !authHeader.startsWith("Bearer ")) {
      res.status(401).send({message: "Unauthorized"});
      return;
    }

    const idToken = authHeader.split("Bearer ")[1];
    let decodedToken;
    try {
      decodedToken = await admin.auth().verifyIdToken(idToken);
    } catch (error) {
      logger.error("Invalid authentication token", error);
      res.status(401).send({message: "Invalid authentication token"});
      return;
    }

    // Get existing bookmark from Firestore
    const db = admin.firestore();
    const bookmarkDoc = await db.collection("bookmarks").doc(bookmarkId).get();

    if (!bookmarkDoc.exists) {
      res.status(404).send({message: "Bookmark not found"});
      return;
    }

    const bookmarkData = bookmarkDoc.data() as Bookmark;

    // Verify bookmark belongs to the authenticated user and specified book
    if (bookmarkData.userId !== decodedToken.uid || bookmarkData.bookId !== bookId) {
      res.status(403).send({message: "Access denied"});
      return;
    }

    // Update bookmark data
    const {chapter, position, title, note} = req.body;
    const updates: {[key: string]: unknown} = {
      updatedAt: admin.firestore.FieldValue.serverTimestamp(),
    };

    if (typeof chapter === "number" && chapter >= 0) {
      updates.chapter = chapter;
    }

    if (typeof position === "number" && position >= 0) {
      updates.position = position;
    }

    if (typeof title === "string") {
      updates.title = title;
    }

    if (typeof note === "string") {
      updates.note = note;
    }

    // Update bookmark in Firestore
    await db.collection("bookmarks").doc(bookmarkId).update(updates);

    // Get updated bookmark
    const updatedBookmarkDoc = await db.collection("bookmarks").doc(bookmarkId).get();

    res.status(200).send({
      id: updatedBookmarkDoc.id,
      ...updatedBookmarkDoc.data(),
    });
  } catch (error) {
    logger.error("Error updating bookmark", error);
    res.status(500).send({message: "Internal server error"});
  }
});

/**
 * DELETE /books/{bookId}/bookmarks/{bookmarkId}
 * Deletes a specific bookmark
 * Returns: { message: string }
 */
export const deleteBookmark = functions.https.onRequest(async (req, res) => {
  try {
    // Set CORS headers for preflight requests
    res.set("Access-Control-Allow-Origin", "*");
    res.set("Access-Control-Allow-Methods", "DELETE");

    // Handle preflight OPTIONS request
    if (req.method === "OPTIONS") {
      res.status(204).send("");
      return;
    }

    // Ensure DELETE request method
    if (req.method !== "DELETE") {
      res.status(405).send({message: "Method not allowed"});
      return;
    }

    // Extract bookId and bookmarkId from URL
    const pathParts = req.path.split("/");
    if (pathParts.length < 5) {
      res.status(400).send({message: "Invalid request path"});
      return;
    }
    const bookId = pathParts[2]; // books/{bookId}/bookmarks/{bookmarkId}
    const bookmarkId = pathParts[4];

    // Verify authentication
    const authHeader = req.headers.authorization;
    if (!authHeader || !authHeader.startsWith("Bearer ")) {
      res.status(401).send({message: "Unauthorized"});
      return;
    }

    const idToken = authHeader.split("Bearer ")[1];
    let decodedToken;
    try {
      decodedToken = await admin.auth().verifyIdToken(idToken);
    } catch (error) {
      logger.error("Invalid authentication token", error);
      res.status(401).send({message: "Invalid authentication token"});
      return;
    }

    // Get existing bookmark from Firestore
    const db = admin.firestore();
    const bookmarkDoc = await db.collection("bookmarks").doc(bookmarkId).get();

    if (!bookmarkDoc.exists) {
      res.status(404).send({message: "Bookmark not found"});
      return;
    }

    const bookmarkData = bookmarkDoc.data() as Bookmark;

    // Verify bookmark belongs to the authenticated user and specified book
    if (bookmarkData.userId !== decodedToken.uid || bookmarkData.bookId !== bookId) {
      res.status(403).send({message: "Access denied"});
      return;
    }

    // Delete bookmark from Firestore
    await db.collection("bookmarks").doc(bookmarkId).delete();

    res.status(200).send({
      message: "Bookmark deleted successfully",
    });
  } catch (error) {
    logger.error("Error deleting bookmark", error);
    res.status(500).send({message: "Internal server error"});
  }
});

/**
 * POST /notifications/register
 * Registers a device for push notifications
 * Request body: { deviceToken: string, platform: "ios" | "android" | "web", topics?: string[] }
 * Returns: DeviceRegistration
 */
export const registerDevice = functions.https.onRequest(async (req, res) => {
  try {
    // Set CORS headers for preflight requests
    res.set("Access-Control-Allow-Origin", "*");
    res.set("Access-Control-Allow-Methods", "POST");
    res.set("Access-Control-Allow-Headers", "Content-Type, Authorization");

    // Handle preflight OPTIONS request
    if (req.method === "OPTIONS") {
      res.status(204).send("");
      return;
    }

    // Ensure POST request method
    if (req.method !== "POST") {
      res.status(405).send({message: "Method not allowed"});
      return;
    }

    // Verify authentication
    const authHeader = req.headers.authorization;
    if (!authHeader || !authHeader.startsWith("Bearer ")) {
      res.status(401).send({message: "Unauthorized"});
      return;
    }

    const idToken = authHeader.split("Bearer ")[1];
    let decodedToken;
    try {
      decodedToken = await admin.auth().verifyIdToken(idToken);
    } catch (error) {
      logger.error("Invalid authentication token", error);
      res.status(401).send({message: "Invalid authentication token"});
      return;
    }

    // Validate request body
    const {deviceToken, platform, topics = []} = req.body;

    if (!deviceToken) {
      res.status(400).send({message: "Device token is required"});
      return;
    }

    if (!platform || !["ios", "android", "web"].includes(platform)) {
      res.status(400).send({
        message: "Valid platform (ios, android, or web) is required",
      });
      return;
    }

    const db = admin.firestore();

    // Check if device is already registered
    const existingDevices = await db.collection("devices")
      .where("deviceToken", "==", deviceToken)
      .where("userId", "==", decodedToken.uid)
      .get();

    let deviceRegistration: DeviceRegistration;
    let deviceId: string;

    if (!existingDevices.empty) {
      // Update existing device registration
      deviceId = existingDevices.docs[0].id;
      await db.collection("devices").doc(deviceId).update({
        platform,
        topics,
        enabled: true,
        updatedAt: admin.firestore.FieldValue.serverTimestamp(),
      });
    } else {
      // Create new device registration
      deviceRegistration = {
        userId: decodedToken.uid,
        deviceToken,
        platform: platform as "ios" | "android" | "web",
        enabled: true,
        topics,
        createdAt: admin.firestore.FieldValue.serverTimestamp() as FirebaseFirestore.Timestamp,
        updatedAt: admin.firestore.FieldValue.serverTimestamp() as FirebaseFirestore.Timestamp,
      };

      const deviceRef = await db.collection("devices").add(deviceRegistration);
      deviceId = deviceRef.id;
    }

    // Get the complete device registration
    const deviceDoc = await db.collection("devices").doc(deviceId).get();

    res.status(200).send({
      id: deviceId,
      ...deviceDoc.data(),
    });
  } catch (error) {
    logger.error("Error registering device for notifications", error);
    res.status(500).send({message: "Internal server error"});
  }
});

/**
 * PUT /notifications/settings
 * Updates notification settings for a device
 * Request body: { deviceToken: string, enabled: boolean, topics?: string[] }
 * Returns: DeviceRegistration
 */
export const updateNotificationSettings = functions.https.onRequest(
  async (req, res) => {
    try {
      // Set CORS headers for preflight requests
      res.set("Access-Control-Allow-Origin", "*");
      res.set("Access-Control-Allow-Methods", "PUT");
      res.set("Access-Control-Allow-Headers", "Content-Type, Authorization");

      // Handle preflight OPTIONS request
      if (req.method === "OPTIONS") {
        res.status(204).send("");
        return;
      }

      // Ensure PUT request method
      if (req.method !== "PUT") {
        res.status(405).send({message: "Method not allowed"});
        return;
      }

      // Verify authentication
      const authHeader = req.headers.authorization;
      if (!authHeader || !authHeader.startsWith("Bearer ")) {
        res.status(401).send({message: "Unauthorized"});
        return;
      }

      const idToken = authHeader.split("Bearer ")[1];
      let decodedToken;
      try {
        decodedToken = await admin.auth().verifyIdToken(idToken);
      } catch (error) {
        logger.error("Invalid authentication token", error);
        res.status(401).send({message: "Invalid authentication token"});
        return;
      }

      // Validate request body
      const {deviceToken, enabled, topics} = req.body;

      if (!deviceToken) {
        res.status(400).send({message: "Device token is required"});
        return;
      }

      if (typeof enabled !== "boolean") {
        res.status(400).send({message: "Enabled status must be a boolean"});
        return;
      }

      const db = admin.firestore();

      // Find the device registration
      const deviceSnapshot = await db.collection("devices")
        .where("deviceToken", "==", deviceToken)
        .where("userId", "==", decodedToken.uid)
        .get();

      if (deviceSnapshot.empty) {
        res.status(404).send({message: "Device registration not found"});
        return;
      }

      const deviceId = deviceSnapshot.docs[0].id;
      const updates: {[key: string]: unknown} = {
        enabled,
        updatedAt: admin.firestore.FieldValue.serverTimestamp(),
      };

      if (topics !== undefined) {
        updates.topics = topics;
      }

      // Update device registration
      await db.collection("devices").doc(deviceId).update(updates);

      // Get updated device registration
      const updatedDeviceDoc = await db.collection("devices").doc(deviceId).get();

      res.status(200).send({
        id: deviceId,
        ...updatedDeviceDoc.data(),
      });
    } catch (error) {
      logger.error("Error updating notification settings", error);
      res.status(500).send({message: "Internal server error"});
    }
  }
);

/**
 * DELETE /notifications/unregister
 * Unregisters a device from push notifications
 * Request body: { deviceToken: string }
 * Returns: { message: string }
 */
export const unregisterDevice = functions.https.onRequest(async (req, res) => {
  try {
    // Set CORS headers for preflight requests
    res.set("Access-Control-Allow-Origin", "*");
    res.set("Access-Control-Allow-Methods", "DELETE, POST");
    res.set("Access-Control-Allow-Headers", "Content-Type, Authorization");

    // Handle preflight OPTIONS request
    if (req.method === "OPTIONS") {
      res.status(204).send("");
      return;
    }

    // Accept both DELETE and POST for unregistering
    if (req.method !== "DELETE" && req.method !== "POST") {
      res.status(405).send({message: "Method not allowed"});
      return;
    }

    // Verify authentication
    const authHeader = req.headers.authorization;
    if (!authHeader || !authHeader.startsWith("Bearer ")) {
      res.status(401).send({message: "Unauthorized"});
      return;
    }

    const idToken = authHeader.split("Bearer ")[1];
    let decodedToken;
    try {
      decodedToken = await admin.auth().verifyIdToken(idToken);
    } catch (error) {
      logger.error("Invalid authentication token", error);
      res.status(401).send({message: "Invalid authentication token"});
      return;
    }

    // Validate request body
    const {deviceToken} = req.body;

    if (!deviceToken) {
      res.status(400).send({message: "Device token is required"});
      return;
    }

    const db = admin.firestore();

    // Find the device registration
    const deviceSnapshot = await db.collection("devices")
      .where("deviceToken", "==", deviceToken)
      .where("userId", "==", decodedToken.uid)
      .get();

    if (deviceSnapshot.empty) {
      res.status(404).send({message: "Device registration not found"});
      return;
    }

    const deviceId = deviceSnapshot.docs[0].id;

    // Delete device registration
    await db.collection("devices").doc(deviceId).delete();

    res.status(200).send({
      message: "Device unregistered successfully",
    });
  } catch (error) {
    logger.error("Error unregistering device", error);
    res.status(500).send({message: "Internal server error"});
  }
});

/**
 * GET /narrators/{narratorId}
 * Returns details about a specific narrator including their bio and books
 * Returns: Narrator
 */
export const getNarratorDetails = functions.https.onRequest(
  async (req, res) => {
    try {
      // Set CORS headers for preflight requests
      res.set("Access-Control-Allow-Origin", "*");
      res.set("Access-Control-Allow-Methods", "GET");

      // Handle preflight OPTIONS request
      if (req.method === "OPTIONS") {
        res.status(204).send("");
        return;
      }

      // Ensure GET request method
      if (req.method !== "GET") {
        res.status(405).send({message: "Method not allowed"});
        return;
      }

      // Extract narratorId from URL
      const pathParts = req.path.split("/");
      if (pathParts.length < 2) {
        res.status(400).send({message: "Invalid request path"});
        return;
      }
      const narratorId = pathParts[2]; // narrators/{narratorId}

      if (!narratorId) {
        res.status(400).send({message: "Narrator ID is required"});
        return;
      }

      const db = admin.firestore();

      // Get narrator details from Firestore
      const narratorDoc = await db.collection("narrators").doc(narratorId).get();

      if (!narratorDoc.exists) {
        res.status(404).send({message: "Narrator not found"});
        return;
      }

      const narratorData = narratorDoc.data() as Omit<Narrator, "books">;

      // Find all books narrated by this narrator
      const booksSnapshot = await db.collection("books")
        .where("narratorId", "==", narratorId)
        .get();

      const books: BookSummary[] = [];
      booksSnapshot.forEach((doc) => {
        const bookData = doc.data();
        books.push({
          id: doc.id,
          title: bookData.title || "",
          coverImageUrl: bookData.coverImageUrl || "",
          author: bookData.author || "",
          series: bookData.series || "",
          genre: bookData.genre || "",
        });
      });

      // Combine narrator data with books
      const narrator: Narrator = {
        id: narratorDoc.id,
        ...narratorData,
        books,
      };

      res.status(200).send(narrator);
    } catch (error) {
      logger.error("Error getting narrator details", error);
      res.status(500).send({message: "Internal server error"});
    }
  }
);

/**
 * GET /narrators
 * Returns list of all narrators
 * Returns: { id: string, name: string, profileImageUrl?: string }[]
 */
export const listNarrators = functions.https.onRequest(async (req, res) => {
  try {
    // Set CORS headers for preflight requests
    res.set("Access-Control-Allow-Origin", "*");
    res.set("Access-Control-Allow-Methods", "GET");

    // Handle preflight OPTIONS request
    if (req.method === "OPTIONS") {
      res.status(204).send("");
      return;
    }

    // Ensure GET request method
    if (req.method !== "GET") {
      res.status(405).send({message: "Method not allowed"});
      return;
    }

    const db = admin.firestore();

    // Get all narrators from Firestore
    const narratorsSnapshot = await db.collection("narrators").get();

    const narrators = narratorsSnapshot.docs.map((doc) => {
      const data = doc.data();
      return {
        id: doc.id,
        name: data.name || "",
        profileImageUrl: data.profileImageUrl || "",
      };
    });

    res.status(200).send(narrators);
  } catch (error) {
    logger.error("Error listing narrators", error);
    res.status(500).send({message: "Internal server error"});
  }
});

/**
 * GET /narrators/search
 * Searches for narrators by name
 * Query parameters: q=searchTerm
 * Returns: { id: string, name: string, profileImageUrl?: string }[]
 */
export const searchNarrators = functions.https.onRequest(async (req, res) => {
  try {
    // Set CORS headers for preflight requests
    res.set("Access-Control-Allow-Origin", "*");
    res.set("Access-Control-Allow-Methods", "GET");

    // Handle preflight OPTIONS request
    if (req.method === "OPTIONS") {
      res.status(204).send("");
      return;
    }

    // Ensure GET request method
    if (req.method !== "GET") {
      res.status(405).send({message: "Method not allowed"});
      return;
    }

    // Get search query
    const query = req.query.q as string;

    if (!query || typeof query !== "string") {
      res.status(400).send({
        message: "Search query (q) is required",
      });
      return;
    }

    const db = admin.firestore();

    // Note: Firestore doesn't support direct substring search, so we'll get all
    // narrators and filter client-side. In a production app, consider using
    // a search service like Algolia or ElasticSearch for better performance.
    const narratorsSnapshot = await db.collection("narrators").get();

    const searchLower = query.toLowerCase();
    const narrators = narratorsSnapshot.docs
      .map((doc) => {
        const data = doc.data();
        return {
          id: doc.id,
          name: data.name || "",
          profileImageUrl: data.profileImageUrl || "",
        };
      })
      .filter((narrator) =>
        narrator.name.toLowerCase().includes(searchLower)
      );

    res.status(200).send(narrators);
  } catch (error) {
    logger.error("Error searching narrators", error);
    res.status(500).send({message: "Internal server error"});
  }
});

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
      const narrator = req.query.narrator as string | undefined;

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

      // In-memory filter for narrator substring
      if (narrator) {
        const n = narrator.toLowerCase();
        books = books.filter(
          (b) =>
            typeof b.narrator === "string" &&
            b.narrator.toLowerCase().includes(n)
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

/**
 * GET /getUniqueAuthors
 * Returns: { author: string, count: number, genres: string[], series: Record<string, number> }[]
 */
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
