# Mobile App QR Connection

This server can generate QR codes that connect the Android/iOS client to the current server
instance. This is for self-hosted deployments: the server may be running on any user-owned domain,
LAN host, tunnel, or reverse proxy, and it should not assume an official Ablibrarian domain.

- The login page shows a "Connect the mobile app" QR code before the user signs in.
- After sign-in, the user menu includes **Connect Mobile App**, which opens the same QR code in a
  modal.
- The QR code encodes this server's redirector URL:
  `https://<this-server>/app/connect/server?apiUrl=https%3A%2F%2F<this-server>%2Fapi%2Fv1`
- The redirector page is served from the user's own server. It offers app custom-scheme open
  buttons and store links for cases where the app is not installed.
- The redirector stores the API URL in a short-lived `ablibrarian_connect_api_url` cookie and
  browser `localStorage` so the page can retain the target server if the user installs the app and
  returns to the redirector.

Optional store link configuration:

```env
ABLIBRARIAN_ANDROID_STORE_URL=https://play.google.com/store/apps/details?id=com.ablibrarian.library
ABLIBRARIAN_IOS_STORE_URL=https://apps.apple.com/app/your-app-id
```

The client also accepts direct app links:

- `ablibrarian://connect/server?apiUrl=<encoded-api-url>`
- `ablibrarian-library://connect/server?apiUrl=<encoded-api-url>`

## HTTPS requirement for mobile clients

Use an HTTPS URL with a valid certificate for every server entered in the mobile app.
The app intentionally does not support arbitrary cleartext `http://` servers. For
Docker deployments, keep the application listener on loopback and use a TLS-terminating
reverse proxy; see [Docker HTTPS guidance](../docker/README.md#https-is-required-for-app-connections).
