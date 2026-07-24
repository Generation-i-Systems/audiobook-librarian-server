import "./bootstrap";
import QRCode from "qrcode";
import { initializeAppConnectQrs } from "./app-connect-qr";

// Initialize Alpine.js
document.addEventListener("alpine:init", () => {
    console.log("Alpine.js initialized");
});

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => initializeAppConnectQrs(QRCode.toCanvas));
} else {
    initializeAppConnectQrs(QRCode.toCanvas);
}
