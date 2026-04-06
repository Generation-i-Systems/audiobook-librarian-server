// @jest-environment jsdom
import { jest } from "@jest/globals";

describe("planned-actions behavior", () => {
    let fetchMock;
    let showToastMock;

    beforeAll(async () => {
        await import("@/admin/books/planned-actions.js");
    });

    beforeEach(() => {
        document.body.innerHTML = `
            <meta name="csrf-token" content="csrf-token-value">
            <form id="book-form">
                <input type="hidden" name="_token" value="fallback-token">
                <input type="hidden" name="originalDirectoryPath" value="library/original">
                <input type="text" id="directoryPath" value="library/original">
                <input type="hidden" id="coverImageUrl" name="coverImageUrl" value="https://example.com/manual.jpg">
                <input type="hidden" id="audibleCoverImageUrl" name="audibleCoverImageUrl" value="https://example.com/audible.jpg">
                <input type="text" id="coverImageUrlText" value="">
                <input type="radio" name="coverImageCandidate" value="cover.jpg" checked>
                <div id="planned-actions-preview"></div>
            </form>
        `;

        fetchMock = jest.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ actions: [] }),
        });
        global.fetch = fetchMock;

        showToastMock = jest.fn();
        window.BookForm = { showToast: showToastMock };
        window.BOOK_FORM_ROUTES = {
            plannedActions: "/planned-actions",
            executeImmediateMove: "/move-now",
        };

        document.dispatchEvent(new Event("DOMContentLoaded"));
    });

    afterEach(() => {
        jest.clearAllMocks();
    });

    test("syncs typed cover URL into hidden inputs and clears selected candidates", () => {
        const textInput = document.getElementById("coverImageUrlText");
        const hiddenInput = document.getElementById("coverImageUrl");
        const audibleHiddenInput = document.getElementById(
            "audibleCoverImageUrl",
        );
        const candidate = document.querySelector(
            'input[name="coverImageCandidate"]',
        );

        textInput.value = "https://example.com/new-cover.jpg";
        textInput.dispatchEvent(new Event("change", { bubbles: true }));

        expect(hiddenInput.value).toBe("https://example.com/new-cover.jpg");
        expect(audibleHiddenInput.value).toBe("");
        expect(candidate.checked).toBe(false);
    });

    test("clears stale cover URL fields when a candidate is selected", () => {
        const textInput = document.getElementById("coverImageUrlText");
        const hiddenInput = document.getElementById("coverImageUrl");
        const audibleHiddenInput = document.getElementById(
            "audibleCoverImageUrl",
        );
        const candidate = document.querySelector(
            'input[name="coverImageCandidate"]',
        );

        textInput.value = "https://example.com/new-cover.jpg";
        hiddenInput.value = "https://example.com/new-cover.jpg";
        audibleHiddenInput.value = "https://example.com/old.jpg";
        candidate.checked = true;

        candidate.dispatchEvent(new Event("change", { bubbles: true }));

        expect(textInput.value).toBe("");
        expect(hiddenInput.value).toBe("");
        expect(audibleHiddenInput.value).toBe("");
    });

    test("renders planned actions preview with revert and update now buttons", async () => {
        fetchMock.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                actions: [
                    { type: "move_files", message: "Move directory" },
                    { type: "update_cover", message: "Update cover" },
                ],
            }),
        });

        const directoryPath = document.getElementById("directoryPath");
        directoryPath.value = "library/updated";
        directoryPath.dispatchEvent(new Event("blur", { bubbles: true }));

        await new Promise((resolve) => setTimeout(resolve, 0));

        const preview = document.getElementById("planned-actions-preview");
        expect(preview.style.display).toBe("block");
        expect(preview.textContent).toContain("Move directory | Update cover");
        expect(preview.textContent).toContain("Update Now");
        expect(preview.textContent).toContain("Revert");
        expect(fetchMock).toHaveBeenLastCalledWith(
            "/planned-actions",
            expect.objectContaining({
                method: "POST",
                headers: expect.objectContaining({
                    "X-CSRF-TOKEN": "csrf-token-value",
                }),
            }),
        );
    });

    test("revert button restores original values", async () => {
        fetchMock
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    actions: [
                        { type: "move_files", message: "Move directory" },
                    ],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ actions: [] }),
            });

        const directoryPath = document.getElementById("directoryPath");
        const coverImageUrl = document.getElementById("coverImageUrl");
        const audibleCoverImageUrl = document.getElementById(
            "audibleCoverImageUrl",
        );
        const coverText = document.getElementById("coverImageUrlText");

        directoryPath.value = "library/changed";
        coverImageUrl.value = "https://example.com/changed.jpg";
        audibleCoverImageUrl.value = "https://example.com/new-audible.jpg";
        coverText.value = "https://example.com/typed.jpg";

        directoryPath.dispatchEvent(new Event("change", { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        const revertButton = Array.from(
            document.querySelectorAll("button"),
        ).find((button) => button.textContent === "Revert");
        revertButton.click();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(directoryPath.value).toBe("library/original");
        expect(coverImageUrl.value).toBe("https://example.com/manual.jpg");
        expect(audibleCoverImageUrl.value).toBe(
            "https://example.com/audible.jpg",
        );
        expect(coverText.value).toBe("https://example.com/audible.jpg");
    });

    test("update now executes immediate move and updates original path", async () => {
        fetchMock
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    actions: [
                        { type: "move_files", message: "Move directory" },
                    ],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    success: true,
                    directoryPath: "library/final",
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ actions: [] }),
            });

        const directoryPath = document.getElementById("directoryPath");
        directoryPath.value = "library/final";
        directoryPath.dispatchEvent(new Event("blur", { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        const updateNowButton = Array.from(
            document.querySelectorAll("button"),
        ).find((button) => button.textContent === "Update Now");
        updateNowButton.click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(showToastMock).toHaveBeenCalledWith(
            "Moving directory...",
            "info",
        );
        expect(showToastMock).toHaveBeenCalledWith(
            "Directory moved successfully!",
            "success",
        );
        expect(document.getElementById("directoryPath").value).toBe(
            "library/final",
        );
        expect(
            document.querySelector('input[name="originalDirectoryPath"]').value,
        ).toBe("library/final");
        expect(fetchMock).toHaveBeenNthCalledWith(
            3,
            "/move-now",
            expect.objectContaining({
                method: "POST",
                body: JSON.stringify({
                    oldDirectoryPath: "library/original",
                    newDirectoryPath: "library/final",
                }),
            }),
        );
    });

    test("update now shows warning when old and new paths are the same", async () => {
        fetchMock.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                actions: [{ type: "move_files", message: "Move directory" }],
            }),
        });

        const directoryPath = document.getElementById("directoryPath");
        directoryPath.value = "library/original";
        directoryPath.dispatchEvent(new Event("blur", { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        const updateNowButton = Array.from(
            document.querySelectorAll("button"),
        ).find((button) => button.textContent === "Update Now");
        updateNowButton.click();

        expect(showToastMock).toHaveBeenCalledWith(
            "Old and new paths are the same",
            "warning",
        );
    });

    test("planned actions hides preview when server returns no actionable changes", async () => {
        fetchMock.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                actions: [{ type: "no_op", message: "No changes required" }],
            }),
        });

        const directoryPath = document.getElementById("directoryPath");
        directoryPath.dispatchEvent(new Event("change", { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        const preview = document.getElementById("planned-actions-preview");
        expect(preview.style.display).toBe("block");
        expect(preview.textContent).toContain("No changes required");
        expect(preview.textContent).not.toContain("Revert");
    });

    test("does nothing when planned actions route is missing", async () => {
        window.BOOK_FORM_ROUTES = {};

        const directoryPath = document.getElementById("directoryPath");
        directoryPath.dispatchEvent(new Event("change", { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    test("update now shows danger toast when move route is missing", async () => {
        fetchMock.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                actions: [{ type: "move_files", message: "Move directory" }],
            }),
        });

        const directoryPath = document.getElementById("directoryPath");
        directoryPath.value = "library/final";
        directoryPath.dispatchEvent(new Event("blur", { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        const updateNowButton = Array.from(
            document.querySelectorAll("button"),
        ).find((button) => button.textContent === "Update Now");
        window.BOOK_FORM_ROUTES = { plannedActions: "/planned-actions" };
        updateNowButton.click();

        expect(showToastMock).toHaveBeenCalledWith(
            "Move URL not configured",
            "danger",
        );
    });

    test("update now shows error toast when move request fails", async () => {
        fetchMock
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    actions: [
                        { type: "move_files", message: "Move directory" },
                    ],
                }),
            })
            .mockResolvedValueOnce({
                ok: false,
                json: async () => ({
                    success: false,
                    message: "Server exploded",
                }),
            });

        const directoryPath = document.getElementById("directoryPath");
        directoryPath.value = "library/final";
        directoryPath.dispatchEvent(new Event("blur", { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        const updateNowButton = Array.from(
            document.querySelectorAll("button"),
        ).find((button) => button.textContent === "Update Now");
        updateNowButton.click();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(showToastMock).toHaveBeenCalledWith(
            "Failed to move directory: Server exploded",
            "danger",
        );
    });

    test("uses fallback token when csrf meta tag is missing", async () => {
        document.querySelector('meta[name="csrf-token"]').remove();
        fetchMock.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ actions: [] }),
        });

        const directoryPath = document.getElementById("directoryPath");
        directoryPath.dispatchEvent(new Event("blur", { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(fetchMock).toHaveBeenLastCalledWith(
            "/planned-actions",
            expect.objectContaining({
                headers: expect.objectContaining({
                    "X-CSRF-TOKEN": "fallback-token",
                }),
            }),
        );
    });

    test("submit syncs cover text to hidden inputs", () => {
        const form = document.getElementById("book-form");
        const textInput = document.getElementById("coverImageUrlText");
        const hiddenInput = document.getElementById("coverImageUrl");

        textInput.value = "https://example.com/submitted.jpg";
        form.dispatchEvent(new Event("submit", { bubbles: true }));

        expect(hiddenInput.value).toBe("https://example.com/submitted.jpg");
    });

    test("update now shows error when paths are missing", async () => {
        fetchMock.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                actions: [{ type: "move_files", message: "Move directory" }],
            }),
        });

        document.querySelector('input[name="originalDirectoryPath"]').value =
            "";
        const directoryPath = document.getElementById("directoryPath");
        directoryPath.value = "library/final";
        directoryPath.dispatchEvent(new Event("blur", { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        const updateNowButton = Array.from(
            document.querySelectorAll("button"),
        ).find((button) => button.textContent === "Update Now");
        updateNowButton.click();

        expect(showToastMock).toHaveBeenCalledWith(
            "Cannot execute move: missing directory paths",
            "danger",
        );
    });

    test("refresh ignores non-ok preview responses", async () => {
        fetchMock.mockResolvedValueOnce({
            ok: false,
            json: async () => ({
                actions: [{ type: "move_files", message: "Move directory" }],
            }),
        });

        const directoryPath = document.getElementById("directoryPath");
        directoryPath.dispatchEvent(new Event("blur", { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(
            document.getElementById("planned-actions-preview").textContent,
        ).toBe("");
    });
});
