# Exact Social connect relay

Connects Facebook Pages to the Exact Social WordPress plugin through the Exact Social app. Meta only redirects to registered URIs and can't list every WordPress site, so the app's redirect URI is this relay. It swaps Meta's code for tokens with the app secret, which never leaves this server, and keeps them, encrypted, until the site that started the connect collects them (at most 10 minutes). After that the site talks to Facebook directly; the relay is only used to connect and reconnect.

Plain PHP 8.0+ with the `curl` and `sodium` extensions. No dependencies.

## Flow

1. **Site → browser → relay.** The plugin makes a random `verifier`, keeps it, and sends the browser to
   `GET /facebook/start?return=<url>&challenge=<base64url(sha256(verifier))>`.
   `return` is the address on the site to come back to.
2. **Relay → Facebook.** The relay shows which site the Pages will go to and links to Facebook's login dialog (Facebook Login for Business, with `FB_CONFIG_ID`). A cookie ties the callback to this browser, so a dialog link made by someone else can't send Pages to their site.
3. **Facebook → relay.** `GET /facebook/callback` exchanges the code for a long-lived user token, reads the person and their Pages (each with a Page token that doesn't expire), stores them encrypted under a one-time `relay_code`, and redirects to `return` with `&relay_code=…`, or `&relay_error=cancelled|denied|failed`.
4. **Site → relay, server to server.** `POST /collect` with `code` and `verifier` (form-encoded). The relay removes the record, then answers `200` with the JSON below when the verifier matches, or `404 {"error":"invalid_code"}`. A code works once; a wrong verifier burns it.

```json
{
  "network": "facebook",
  "user": { "id": "…", "name": "…" },
  "user_token": "…",
  "expires_in": 5183944,
  "pages": [
    { "id": "…", "name": "…", "access_token": "…", "picture": "https://…", "tasks": ["CREATE_CONTENT", "MANAGE", "MODERATE"] }
  ]
}
```

Tokens never appear in a URL or pass through the browser; only the one-time code does, and it's useless without the verifier.

## Deploy with Coolify

1. New resource → public Git repository, branch `main`, **Build Pack: Dockerfile** (the `Dockerfile` here: PHP 8.3 with Apache, web root `public/`, `store/` inside the container).
2. Ports Exposes `80`; domain `https://connect.exactwp.com` (Coolify's proxy handles HTTPS).
3. Environment variables, as **runtime** variables (not build variables): `FB_APP_ID`, `FB_APP_SECRET`, `FB_CONFIG_ID`, `RELAY_URL` (`https://connect.exactwp.com`), `RELAY_KEY`. Lock `FB_APP_SECRET` and `RELAY_KEY` so they're hidden in the UI.
4. Health check path `/` (answers `ok`). Keep one replica: records sit on the container's disk.

## Deploy on a plain server

1. Upload the folder to the server and point the web root of the relay's host (e.g. `connect.exactwp.com`, HTTPS only) at `public/`. Everything else stays outside the web root.
2. Create `store/` beside `public/`, writable by PHP and nothing else (`chmod 700`).
3. Settings, as environment variables or in `config.php` beside `public/` (copy `config.example.php`; it's gitignored):

   | Name | Value |
   |---|---|
   | `FB_APP_ID` | Meta App ID |
   | `FB_APP_SECRET` | Meta App Secret. Only here. |
   | `FB_CONFIG_ID` | Facebook Login for Business configuration ID |
   | `RELAY_URL` | The relay's address, e.g. `https://connect.exactwp.com` |
   | `RELAY_KEY` | 32 random bytes, base64: `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'` |
   | `RELAY_STORE` | Optional; defaults to `store/` beside `public/` |

4. In the Meta app, Facebook Login for Business → Settings → Valid OAuth Redirect URIs: `<RELAY_URL>/facebook/callback` (Strict Mode on).
5. `GET /` answers `ok`. Missing or wrong settings give a 500 page and a line in the PHP error log.

Rewrite every path to `public/index.php` if the server doesn't already (nginx: `try_files $uri /index.php?$query_string;`; Apache: a `FallbackResource /index.php` in `public/.htaccess`).

## Check

```bash
php tests/check.php
```

Covers the verifier check, return-address rules, and the store (encrypted on disk, read once, expiry, sweep).
