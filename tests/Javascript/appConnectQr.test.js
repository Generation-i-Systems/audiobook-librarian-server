// @jest-environment jsdom
import { initializeAppConnectQrs, renderAppConnectQr } from "../../resources/js/app-connect-qr.js";

describe("app connect QR", () => {
    it("renders the connection URL into a QR canvas", () => {
        document.body.innerHTML = '<div class="app-connect-qr" data-connect-url="https://books.example.test/app/connect/server?apiUrl=https%3A%2F%2Fbooks.example.test%2Fapi%2Fv1"></div>';
        const container = document.querySelector(".app-connect-qr");
        const toCanvas = jest.fn((canvas, url, options, callback) => callback());

        renderAppConnectQr(container, toCanvas);

        expect(toCanvas).toHaveBeenCalledWith(
            expect.any(HTMLCanvasElement),
            "https://books.example.test/app/connect/server?apiUrl=https%3A%2F%2Fbooks.example.test%2Fapi%2Fv1",
            { width: 200, margin: 1 },
            expect.any(Function),
        );
        expect(container.querySelector("canvas")).not.toBeNull();
    });

    it("shows the connection URL when rendering fails", () => {
        document.body.innerHTML = '<div class="app-connect-qr" data-connect-url="https://books.example.test/app/connect/server"></div>';
        const container = document.querySelector(".app-connect-qr");
        const error = new Error("Canvas is unavailable");
        const toCanvas = jest.fn((canvas, url, options, callback) => callback(error));
        const consoleError = jest.spyOn(console, "error").mockImplementation(() => {});

        renderAppConnectQr(container, toCanvas);

        expect(container.textContent).toBe("https://books.example.test/app/connect/server");
        expect(consoleError).toHaveBeenCalledWith("[app-connect-qr] Failed to render QR code", error);
        consoleError.mockRestore();
    });

    it("renders every connection QR code on the page", () => {
        document.body.innerHTML = '<div class="app-connect-qr" data-connect-url="https://one.example.test"></div><div class="app-connect-qr" data-connect-url="https://two.example.test"></div>';
        const toCanvas = jest.fn((canvas, url, options, callback) => callback());

        initializeAppConnectQrs(toCanvas);

        expect(toCanvas).toHaveBeenCalledTimes(2);
        expect(document.querySelectorAll(".app-connect-qr canvas")).toHaveLength(2);
    });

    it("ignores a missing QR container", () => {
        expect(() => renderAppConnectQr(null, jest.fn())).not.toThrow();
    });
});
