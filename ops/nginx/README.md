# Host nginx configuration (production)

Production's **host** nginx config is not managed by this repository. It lives on
the prod box at `/etc/nginx/` and is edited by hand. What's here are the pieces
worth keeping under version control, so they can be reviewed and restored.

Not to be confused with:

- `nginx.ork3.config` — the nginx config *inside* the app container, baked at build time.
- `staging/nginx-ork-staging.conf` — staging's host config, which *is* tracked.

## The blue/green trap

`greenblue.sh` deploys by rebuilding the app container in the other colour and
re-pointing nginx at it:

```bash
rm "/etc/nginx/sites-enabled/default"
ln -s "/etc/nginx/sites-available/$TARGET_COLOR" "/etc/nginx/sites-enabled/default"
service nginx restart
```

So `sites-enabled/default` is **disposable** — it is deleted on every deploy.
Anything you add there survives until the next colour swap and then vanishes.
`sites-available/blue` and `sites-available/green` are the real files, and they
must be kept in step: whatever one has, the other needs, or the config
flip-flops depending on which colour happens to be live.

Edit the colour files, never `sites-enabled/default`, and keep shared config in
a snippet included from both.

## snippets/dev-downloads.conf

Serves the redacted developer database and assets archive at
`https://ork.amtgard.com/dev-downloads/` behind Basic auth, so they don't have
to be hosted on someone's personal domain. See the "Producing a redacted
database" section of the main [README](../../README.md).

To deploy or restore it:

```bash
# 1. the snippet itself
sudo cp ops/nginx/snippets/dev-downloads.conf /etc/nginx/snippets/
sudo chmod 644 /etc/nginx/snippets/dev-downloads.conf

# 2. include it from BOTH colours, inside the port-443 server block,
#    above `location / { proxy_pass ... }`
#       include snippets/dev-downloads.conf;

# 3. check and reload
sudo nginx -t && sudo service nginx reload
```

Two things the snippet depends on, neither of them in git:

| | |
|---|---|
| `/var/www/ork-dev-downloads/` | the files, owned `ubuntu:ubuntu`, outside the repo so deploys never touch them |
| `/etc/nginx/.htpasswd-devdownloads` | credentials, `640 root:www-data`. Add a user with `htpasswd -B /etc/nginx/.htpasswd-devdownloads <name>` (drop `-c`, which truncates the file) |

The directory is deliberately **not** in `robots.txt`. A `Disallow` line would
advertise it; the Basic auth is what actually protects it, and the snippet also
sends `X-Robots-Tag: noindex, nofollow, noarchive`.
