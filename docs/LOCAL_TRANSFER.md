# Direct downloads on your own network

When a phone is on the same network as the server, the apps can download book files straight from
the server instead of going out to `APP_URL` and back. Behind Cloudflare, a tunnel or any CDN this
matters: every byte otherwise crosses your home connection twice, so downloads are capped by your
upload speed and break when the CDN's route changes.

The public `APP_URL` keeps working everywhere. The direct route is only used for book file
downloads, only while it answers, and the apps fall back to `APP_URL` on their own.

## How it works

- The server has a second HTTPS listener on your network (port 8443 by default) with its own
  self-signed certificate. It serves only book file downloads, chunk checks and `/api/v1/health/ping`.
- Signed-in apps ask `GET /api/v1/local-transfer` over `APP_URL` for the listener's addresses and
  the SHA-256 of its certificate's public key. Because that answer arrives over the public,
  CA-validated connection, the apps can trust exactly that key on the LAN address; no name, CA or
  plain-HTTP mode is involved.
- Any certificate with another key is refused and the download goes through `APP_URL`.

## Docker

The image generates the certificate on first start (in the storage volume, so the pinned key
survives rebuilds) and publishes the listener on port 8443. Tell the apps where to find it in
`.env.docker`, using the address phones on your Wi-Fi use to reach this host:

```bash
LOCAL_URL=https://192.168.1.20:8443
```

Then `docker compose --env-file .env.docker up -d`. Several addresses can be listed,
comma-separated (for example a LAN IP and a local DNS name). `LOCAL_PORT` changes the host port;
`LOCAL_URL` must use the same port.

Give the host a fixed LAN address (a DHCP reservation in your router) so `LOCAL_URL` stays right.

## Native installs

Add a listener for the download routes to your web server, point `LOCAL_TLS_CERT` at its
certificate, and set `LOCAL_URL`. A long-lived self-signed certificate is the simplest:

```bash
sudo mkdir -p /etc/ablibrarian/local-tls
sudo openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes \
    -keyout /etc/ablibrarian/local-tls/key.pem -out /etc/ablibrarian/local-tls/cert.pem \
    -days 3650 -subj "/CN=Audiobook Librarian local transfer" \
    -addext "subjectAltName=IP:192.168.1.20"
```

`.env`:

```bash
LOCAL_URL=https://192.168.1.20:8443
LOCAL_TLS_CERT=/etc/ablibrarian/local-tls/cert.pem
```

Put the address from `LOCAL_URL` in `subjectAltName` (add more with `,IP:...` or `,DNS:...`).
Phones connecting to an IP send no server name, so Caddy chooses the certificate by the IP it was
reached on. If another site serves that IP on any port, Caddy picks that site's certificate and
the handshake fails unless this one also names the IP. Reissuing from the same key with
`openssl req -x509 -key key.pem ...` keeps the pinned key, so the apps are unaffected.

The web server user must be able to read `cert.pem` (not `key.pem`). After changing `.env` run
`php artisan config:cache` if you cache configuration.

Use this file certificate, not Caddy's `tls internal`: Caddy renews internal certificates every
few days with a new key, which the apps would refuse until they next refresh the route.

### Caddy

```caddy
:8443 {
    tls /etc/ablibrarian/local-tls/cert.pem /etc/ablibrarian/local-tls/key.pem
    root * /path/to/server/public

    @transfer path_regexp ^/api/v1/(health/ping|books/[^/]+/download/.+)$
    handle @transfer {
        php_fastcgi unix//run/php/php8.3-fpm.sock
    }
    handle {
        respond 404
    }
}
```

### nginx

Use the server block in [`docker/nginx/local-transfer.conf`](../docker/nginx/local-transfer.conf),
changing `root`, the certificate paths and `fastcgi_pass` to match your install.

## Checking it

From a computer on the same network, with a signed-in token:

```bash
curl -s -H "Authorization: Bearer $TOKEN" "$APP_URL/api/v1/local-transfer"
# {"localTransfer":{"urls":["https://192.168.1.20:8443"],"spkiSha256":"..."}}

curl --insecure --pinnedpubkey "sha256//<spkiSha256 from above>" \
    https://192.168.1.20:8443/api/v1/health/ping
```

`--insecure` only skips the name and CA checks; `--pinnedpubkey` still refuses any other key.
`GET /api/v1/health/capabilities` lists `LOCAL_TRANSFER` once `LOCAL_URL` and the certificate are
both usable; `localTransfer` is `null` otherwise.

## Security notes

- The listener only exposes what downloads need, and those routes require the same sign-in as on
  `APP_URL`. Don't forward port 8443 in your router; it is meant for your network only.
- The LAN address is shown only to signed-in users, never in the public capabilities response.
- Replacing the certificate changes the pinned key. Apps refuse the new one until they refresh the
  route (on their next start or network change) and download through `APP_URL` meanwhile.
