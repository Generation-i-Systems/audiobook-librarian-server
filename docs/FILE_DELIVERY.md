# Letting the web server send book files

By default PHP streams every book file download itself. That works behind any web server, but
each download holds a PHP worker for as long as it runs. With `FILE_DELIVERY` set, the API still
checks the request (sign-in, book, file, logging) and then tells the web server which file to
send. The web server serves it, Range requests included, and the PHP worker is free at once.

Only the per-file download (`GET /api/v1/books/{book}/download/{file}`, which the apps and the
[LAN listener](LOCAL_TRANSFER.md) use) is handed over. ZIPs, covers and chunk checks still go
through PHP.

| `FILE_DELIVERY` | Web server |
| --- | --- |
| `php` (default) | any; nothing to set up |
| `x-accel-redirect` | nginx, or Caddy with `handle_response` |
| `x-sendfile` | Apache with mod_xsendfile, lighttpd |

Set up the web server **before** changing `FILE_DELIVERY`: a web server that ignores the header
sends the apps an empty file. After changing `.env`, run `php artisan config:cache` if you cache
configuration. Any other value behaves like `php`.

## nginx

Add an internal location that maps to your books folder (`BOOK_STORAGE_PATH`) in every server
block that serves the API, including a LAN listener:

```nginx
location ^~ /_protected_books/ {
    internal;
    alias /srv/audiobooks/;
}
```

`^~` stops regex locations (such as a download location on the LAN listener) from claiming the
redirected request. `internal` makes the location unreachable from outside. The nginx worker
user must be able to read the book files. Then set `FILE_DELIVERY=x-accel-redirect`.

If the location has another name, set `FILE_DELIVERY_ACCEL_PREFIX` to it.

The Docker image already has this location (for `/data/books`); only `FILE_DELIVERY` needs
setting in `.env.docker`.

## Caddy

Caddy has no built-in X-Accel-Redirect, but `php_fastcgi` can act on the header with
`handle_response`. Replace the site's `php_fastcgi` line with:

```caddy
php_fastcgi unix//run/php/php8.3-fpm.sock {
    @accel header X-Accel-Redirect *
    handle_response @accel {
        root * /srv/audiobooks
        rewrite * {rp.header.X-Accel-Redirect}
        uri strip_prefix /_protected_books
        file_server
    }
}
```

Use `{rp.header.X-Accel-Redirect}`, not `{resp.header...}`, which is empty inside
`handle_response`. Do the same in a LAN listener block. The Caddy user must be able to read the
book files. Then set `FILE_DELIVERY=x-accel-redirect`.

## Apache (mod_xsendfile)

```apache
XSendFile On
XSendFilePath /srv/audiobooks
```

Then set `FILE_DELIVERY=x-sendfile`. lighttpd needs `"allow-x-send-file" => "enable"` for the
PHP backend.

## Checking it

Download a book file and look at the web server's access log: the request is answered with the
full file size (or `206` for a range) while the PHP-FPM slow log and worker count stay quiet.
`curl -H "Authorization: Bearer $TOKEN" -o /dev/null -w '%{http_code} %{size_download}\n'
"$APP_URL/api/v1/books/<id>/download/<file>"` must print the real size; `0` means the web server
is not acting on the header.
