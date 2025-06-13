// Test script to time Firebase Functions HTTP endpoints
// Usage: node functions/test/functions_timing_test.js

const axios = require("axios");

const FUNCTIONS = [
  {
    name: "getBooksPaginated",
    url: "https://us-central1-ab-librarian.cloudfunctions.net/getBooksPaginated",
    params: { page: 1, pageSize: 5 },
  },
  {
    name: "getBooksByGenreCount",
    url: "https://us-central1-ab-librarian.cloudfunctions.net/getBooksByGenreCount",
  },
  {
    name: "getUniqueAuthors",
    url: "https://getuniqueauthors-y7oqg7ythq-uc.a.run.app",
  },
];

async function timeFunction(fn) {
  const start = Date.now();
  try {
    const res = await axios.get(fn.url, fn.params ? { params: fn.params } : {});
    const duration = Date.now() - start;
    console.log(
      `${fn.name}: ${duration}ms | status: ${res.status} | result:`,
      Array.isArray(res.data) ? `Array(${res.data.length})` : typeof res.data === "object" ? JSON.stringify(res.data).slice(0, 200) : res.data
    );
  } catch (err) {
    const duration = Date.now() - start;
    console.error(`${fn.name}: ${duration}ms | ERROR`, err.response ? err.response.status : err.message);
  }
}

(async () => {
  for (const fn of FUNCTIONS) {
    await timeFunction(fn);
  }
})();
