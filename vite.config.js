import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import path from "path";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                "resources/sass/app.scss",
                "resources/js/app.js",
                "resources/js/admin/book-create.js",
                "resources/js/admin/book-autocomplete.js",
                "resources/js/admin/books/form.js",
                "resources/js/admin/books/planned-actions.js",
                "resources/js/admin/books/directory-browser.js",
                "resources/js/admin/books/series-rename.js",
                "resources/js/admin/books/directory-conflict.js",
                "resources/js/admin/books/import_file.js",
                "resources/js/admin/books/autofill-simple.js",
                "resources/js/admin/books/form-helpers.js",
                "resources/js/admin/books/form-autocomplete.js",
                "resources/js/admin/books/form-cover.js",
                "resources/js/admin/books/import_directory.js",
                "resources/js/admin/books/form-directory.js",
                "resources/js/admin/books/init-book-form.js",
                "resources/js/admin/jobs/index.js",
                "resources/js/ai-query.js",
                "resources/js/global-ajax-auth.js",
            ],
            publicDirectory: "public",
            refresh: true,
        }),
    ],
    resolve: {
        alias: {
            "~bootstrap": path.resolve(__dirname, "node_modules/bootstrap"),
            "~alpinejs": path.resolve(__dirname, "node_modules/alpinejs"),
        },
    },
});
