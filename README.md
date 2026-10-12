# Amtgard ORK 3

[![Code Climate](https://codeclimate.com/github/amtgard/ORK3/badges/gpa.svg)](https://codeclimate.com/github/amtgard/ORK3)

This is the third major release of the [Amtgard Online Record Keeper](https://ork.amtgard.com/orkui/).

## Contents

- [Before you start](#before-you-start)
- [Quick start](#quick-start)
- [Working on a feature branch](#working-on-a-feature-branch)
- [Troubleshooting and housekeeping](#troubleshooting-and-housekeeping)
- [Contributing](#contributing)
- [Producing a redacted database (maintainers)](#producing-a-redacted-database-maintainers)

## Before you start

The ORK uses Apache, PHP, SQL, HTML, CSS, Javascript and NGINX. You don't have to be a master of all of this, and ORK development might be a good place to build your skillset if you're just starting out. You'll likely not have to touch the NGINX or Apache bits at all.

Have a look at the [Open Issues](https://github.com/amtgard/ORK3/issues) and see if there's something easy looking or marked as a Good First Issue. Or reach out and ask if there's something we need working on.

**You need Docker, and nothing else.** No PHP, no MySQL or MariaDB on your machine — everything runs in containers. Get it from [Docker.com](https://www.docker.com/get-started/).

**You need a copy of the data.** Contact the <a href="mailto:technicalad@amtgard.com?subject=ORK%20Development&body=I'm%20interested%20in%20becoming%20an%20ORK%20Developer%20and%20require%20the%20Work%20for%20Hire%20Transfer%20Agreement.">Amtgard Technical Assistant Director</a> and ask for the Amtgard Work for Hire Transfer Agreement. This requires your legal name and an email address. Once it's signed you'll be sent a download link with:

| | | |
|---|---|---|
| **a redacted database** | `ork-redacted-YYYY-MM-DD-HHMM.sql.gz` | ~110 MB |
| **an assets archive** | `ork-assets-YYYY-MM-DD.zip` | ~900 MB, optional but recommended |
| **test login credentials** | a username and password, sent separately | |

The database is real ORK data with names, emails and credentials removed. The assets archive is everyone's heraldry and player photos — without it the site works fine, but every player, park and kingdom shows a blank shield.

## Quick start

**1. Clone the repository**

```
git clone https://github.com/amtgard/ORK3.git
cd ORK3
```

**2. Build and start the containers.** There are two: one for PHP, one for MariaDB.

```
docker-compose -f docker-compose.php8.yml up -d
```

Drop the `-d` if you'd rather watch the output instead of running detached.

**3. Import the database.** Adjust the filename to match what you downloaded:

```
gunzip -c ~/Downloads/ork-redacted-2026-10-08-1416.sql.gz \
  | awk 'BEGIN{print "SET autocommit=0;"; n=0} {print; n++} \
         n>=100000 && /;[ \t]*$/ {print "COMMIT;"; n=0} END{print "COMMIT;"}' \
  | docker exec -i ork3-php8-db mariadb -uroot -proot ork
```

This will take a while — several minutes is normal.

Three things about that command:

- It runs the client *inside* the container over a local socket, so you don't need MariaDB installed. It's also faster than connecting over the forwarded TCP port.
- The `-i` flag is required. It keeps stdin open so the file actually reaches the client.
- The `awk` wrapper batches transactions instead of committing every statement. It makes little difference to the redacted dump, but a raw production backup imports around 28× slower without it, so it's here to save you from ever finding that out. It only breaks between statements, so it's safe either way.

If the file isn't gzipped, use `cat` in place of `gunzip -c`.

**Then restart PHP:**

```
docker restart ork3-php8-app
```

This is easy to miss and the symptoms are confusing — see [Restart PHP after schema changes](#restart-php-after-schema-changes).

**4. Extract the assets** (optional). Unzip it **into the ORK3 folder you just cloned**:

```
unzip ~/Downloads/ork-assets-2026-10-08.zip -d /path/to/your/ORK3
```

The archive already has a top-level `assets/` directory, so it merges with the one in the repo — don't make a subfolder for it. Afterwards you'll have `assets/heraldry/`, `assets/images/` and `assets/players/` full of files. `git status` stays clean; those paths are gitignored.

**5. Open the site** at `http://localhost:19080/orkui/` and log in with the credentials you were given.

One of those accounts is a full **ORK admin**, so you can change anyone else's password or grant yourself whatever you need from inside the app. The redacted database contains no live sessions, so logging in is the only way in — by design. To print the accounts again, or reset their passwords:

```
docker exec -i ork3-php8-app php /var/www/ork.amtgard.com/db-migrations/dev-set-test-logins.php
```

## Working on a feature branch

The database you imported is a snapshot of production, so its schema matches whatever is currently on `master`. Nothing more is needed to run `master` locally.

A **feature branch** may add migrations that haven't reached production yet, so the imported database will be missing tables or columns the branch's code expects. Apply them by hand.

To see which migrations your branch adds on top of what has shipped, compare against **`origin/master`**, not your local `master` — a local branch you haven't pulled in a while will list migrations that are already in production:

```
git fetch origin
git diff --name-only --diff-filter=A origin/master...HEAD -- db-migrations/
```

Apply each one, oldest first. They're named by date, and order can matter:

```
docker exec -i ork3-php8-db mariadb -uroot -proot ork < db-migrations/[the-migration].sql
```

Then restart PHP (below).

**There is no migration-tracking table.** Nothing records what has already run, so keep track yourself. Re-importing the database resets you to `master`'s schema and you'll need to re-apply your branch's migrations afterwards.

## Troubleshooting and housekeeping

### Restart PHP after schema changes

```
docker restart ork3-php8-app
```

The ORK caches each table's schema (`DESCRIBE` / `SHOW KEYS`) in APCu for 24 hours. After a migration or an import, PHP keeps using the *old* cached schema, so new columns and tables appear not to exist: fields silently fail to save, new features look broken, and nothing in the logs explains why. Restarting the app container clears APCu and the schema is re-read.

Do this after importing a database as well as after a migration.

### Blank API keys, and what they turn off

`config.dev.php` is committed with every external service key empty, on purpose. Nothing in the [Quick start](#quick-start) needs one — but a few features then look broken when they are only unconfigured:

| key | what stops working |
|---|---|
| `GOOGLE_MAPS_API_KEY`, `GOOGLE_MAPS_ACCESS_API_KEY` | Geocoding a park's address into coordinates. Parks that already have coordinates still map fine. |
| `CARTO_API_KEY` | Basemap tiles on the Weather and Live Attendance maps. The map frame draws; the tiles stay blank. |
| `AMAZON_SES_HOST`, `AMAZON_SES_USERNAME`, `AMAZON_SES_PASSWORD` | Password reset. It refuses with "outbound mail is not configured" rather than resetting a password nobody can receive. |
| `SENDGRID_API_KEY` | Sending the weekly recap. |
| `BEHOLD_KEY` | The social media feed. |
| `CF_API_TOKEN`, `CF_ZONE_ID` | The Cloudflare figures in the weekly recap and on Platform Trends. Those sections are simply omitted. |
| `GA4_SA_KEY_PATH` | Human-visitor counts in the recap. Also omitted. |

Weather needs no key at all — it comes from [Open-Meteo](https://open-meteo.com/), which is free and unauthenticated.

**Amtgard single sign-on does not work locally.** `IDP_API_URL` points at `host.docker.internal:37080`, an identity provider you almost certainly aren't running, so the "log in with Amtgard" button will fail. Ordinary username and password login doesn't touch the IDP and works normally — which is what the credentials you were given are for.

If you do need a real key locally, there is no separate override file: edit `config.dev.php`, which **is tracked by git**, so take care not to commit it. Two of them can come from the environment instead, which avoids that — `CF_API_TOKEN` and `GA4_SA_KEY_PATH` fall back to `getenv()`, so you can pass them to the container with `-e`.

### Starting over with a clean database

If you're re-importing — refreshing from a newer dump, say — drop and recreate first so the new data isn't merged into the old:

```
docker exec ork3-php8-db mariadb -uroot -proot -e "DROP DATABASE IF EXISTS ork; CREATE DATABASE ork;"
```

Then run the import from step 3 again. **This erases your local database.** It only affects your container, never the real ORK, but any local test data you care about will be gone.

### Backing up your local database

```
docker exec ork3-php8-db mariadb-dump -uroot -proot ork | gzip -c > ork-backup-$(date +%F).sql.gz
```

Restore it the same way you imported, using the command in step 3.

## Contributing

Fork the repository, work on a branch, and open a Pull Request against `amtgard/ORK3`.

Anything in these instructions that trips you up, doesn't work, or doesn't make sense — say so. The documentation gets fixed from what people actually hit.

## Producing a redacted database (maintainers)

The redacted dump handed to new developers is built with `tools/dev-dump/`, which replaces doing it by hand. Run it against a **local** container only — it refuses anything whose name looks like production.

```
./tools/dev-dump/make-dev-dump.sh park                     # save your current local DB first
./tools/dev-dump/make-dev-dump.sh load ~/Downloads/ork-YYYY-MM-DD-HH-MM.sql
./tools/dev-dump/make-dev-dump.sh redact                   # applies redact.sql, then verifies
./tools/dev-dump/make-dev-dump.sh logins                   # known passwords incl. an ORK admin
./tools/dev-dump/make-dev-dump.sh export                   # the file you hand over
./tools/dev-dump/make-dev-dump.sh restore ~/ork-db-snapshots/ork-local-....sql.gz
```

`logins` must run **before** `export`, or the dump ships with no way in.

Snapshots of your real data go to `~/ork-db-snapshots`; the shareable dump goes to `~/ork-db-redacted`. Separate trees on purpose, so the file that must never leave the machine is never sitting next to the one being sent.

What gets removed, and what deliberately does not, is documented at the top of `tools/dev-dump/redact.sql`. In short: names, emails and stored credentials go, along with the audit log and every **live** token — sessions, OAuth, attendance links and self-reg links, all of which authenticate against *production*. Usernames and free-text notes stay, which is a judgement call appropriate to a dump for someone getting started; revisit it if the audience changes.

`verify` is read-only and can be run at any time; every count it prints must be zero. `export` additionally scans the finished file for email-shaped strings, so a mistake surfaces before the file reaches anyone.

When you send the dump, remember the recipient also needs the **assets archive** and the **login credentials** — neither is inside the database.
