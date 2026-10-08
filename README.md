# Amtgard ORK 3

[![Code Climate](https://codeclimate.com/github/amtgard/ORK3/badges/gpa.svg)](https://codeclimate.com/github/amtgard/ORK3)

This is the third major release of the [Amtgard Online Record Keeper](https://ork.amtgard.com/orkui/).

## Development
### Technologies used
The ORK uses Apache, PHP, SQL, HTML, CSS, and Javascript, NGINX. You don't have to be a master of all of this, and the ORK development might be a good place to build your skillset in these areas if you're just starting out. You'll likely not have to touch the NGINX or Apache bits at all.

Have a look at the [Open Issues](https://github.com/amtgard/ORK3/issues) and see if there's something easy looking or marked as a Good First Issue. Or reach out and ask if there's something we need working on.
### Using Docker is the preferred method
The easiest way to get up and running as a developer is to first contact the <a href="mailto:technicalad@amtgard.com?subject=ORK%20Development&body=I'm%20interested%20in%20becoming%20an%20ORK%20Developer%20and%20require%20the%20Work%20for%20Hire%20Transfer%20Agreement.">Amtgard Technical Assistant Director</a> and ask for the Amtgard Work for Hire Transfer Agreement. This requires your Legal Name and an email address. After this is signed then a redacted ORK Database will be made available for development purposes such that you can follow  the Docker workflow below.

1. Install Docker

Head to [Docker.com](https://www.docker.com/get-started/), download and install Docker

2. Check out this repository locally 

```
git clone https://github.com/amtgard/ORK3.git
cd ORK3
```

3. Download the components, build, and start your containers. There are two, one for the PHP and another for the Maria DB.

```
docker-compose -f docker-compose.php8.yml up

    or

docker-compose -f docker-compose.php8.yml up -d

    to do the build, run and detatch (run in background)
```

4. Obtain the redacted database mentioned above, then import it by piping the file into the database container. Adjust the path/name to wherever you downloaded it:
```
docker exec -i ork3-php8-db mariadb -uroot -proot ork < ~/Downloads/[somedate]redacted.sql
```
If the dump is gzipped, unzip it on the way in — no need to expand it first:
```
gunzip -c ~/Downloads/[somedate]redacted.sql.gz | docker exec -i ork3-php8-db mariadb -uroot -proot ork
```
This runs the client *inside* the container, over a local socket, so you do **not** need MySQL or MariaDB installed on your machine at all. It is also noticeably faster than connecting from your desktop over the forwarded TCP port, which is what earlier versions of this README told you to do.

The `-i` flag is required — it keeps stdin open so the file actually reaches the client.

This will take a while. After it completes, restart PHP so it picks up the imported schema (see *Restart PHP* below for why):
```
docker restart ork3-php8-app
```

**Optional — starting over with a clean database.** If you are re-importing (say, refreshing from a newer dump), drop and recreate first so the new data is not merged into the old:
```
docker exec ork3-php8-db mariadb -uroot -proot -e "DROP DATABASE IF EXISTS ork; CREATE DATABASE ork;"
```
Then run the import command above. **This erases your local database** — it only affects your container, never the real ORK, but any local test data you care about will be gone.

**Applying migrations.** The database you just imported is a snapshot of production, so its schema matches whatever is currently on `master`. Nothing more is needed to run `master` locally.

If you are working on a **feature branch**, that branch may add migrations that have not reached production yet — so the imported database will be missing tables or columns the branch's code expects. Apply them by hand.

To see which migrations your branch adds on top of what has shipped, compare against **`origin/master`**, not your local `master` — a local branch you have not pulled in a while will list migrations that are in production already:
```
git fetch origin
git diff --name-only --diff-filter=A origin/master...HEAD -- db-migrations/
```
Then apply each one, oldest first (they are named by date, and order can matter):
```
docker exec -i ork3-php8-db mariadb -uroot -proot ork < db-migrations/[the-migration].sql
```

**Restart PHP afterwards — this step is easy to miss:**
```
docker restart ork3-php8-app
```
The ORK caches each table's schema (`DESCRIBE` / `SHOW KEYS`) in APCu for 24 hours. After a migration, PHP keeps using the *old* cached schema, so new columns and tables appear not to exist: fields silently fail to save, new features look broken, and nothing in the logs explains why. Restarting the app container clears APCu and the schema is re-read. Do this after importing a database too.

There is no migration-tracking table — nothing records what has already run, so keep track yourself. Re-importing the database resets you to `master`'s schema, and you will need to re-apply your branch's migrations afterwards.

**Taking a backup** of your local database (the dump tool lives in the container too):
```
docker exec ork3-php8-db mariadb-dump -uroot -proot ork > ork-backup.sql
```

5. You should be able to connect to the running PHP server now and can access from local at: `http://localhost:19080/orkui/`

**Logging in.** The redacted database contains no live sessions, so you log in
normally with the test accounts set up for you. Whoever sent you the dump will
have given you the password; one of the accounts is a full **ORK admin**, and
from there you can grant yourself anything else you need. To see (or re-set)
the accounts at any time:
```
docker exec -i ork3-php8-app php /var/www/ork.amtgard.com/db-migrations/dev-set-test-logins.php
```
It prints a table of Tier / Persona / Username and refuses to run against
anything but the local container.

## Producing a redacted database (maintainers)

The redacted dump handed to new developers is built with `tools/dev-dump/`,
which replaces doing it by hand. Run it against a **local** container only —
it refuses anything whose name looks like production.

```
./tools/dev-dump/make-dev-dump.sh park                     # save your current local DB first
./tools/dev-dump/make-dev-dump.sh load ~/Downloads/ork-YYYY-MM-DD-HH-MM.sql
./tools/dev-dump/make-dev-dump.sh redact                   # applies redact.sql, then verifies
./tools/dev-dump/make-dev-dump.sh logins                   # known passwords incl. an ORK admin
./tools/dev-dump/make-dev-dump.sh export                   # the file you hand over
./tools/dev-dump/make-dev-dump.sh restore ~/ork-db-snapshots/ork-local-....sql.gz
```

`logins` must run **before** `export`, or the dump ships with no way in.

Snapshots of your real data go to `~/ork-db-snapshots`; the shareable dump goes
to `~/ork-db-redacted`. Separate trees on purpose, so the file that must never
leave the machine is never sitting next to the one being sent.

What gets removed, and what deliberately does not, is documented at the top of
`tools/dev-dump/redact.sql`. In short: names, emails and stored credentials go,
along with the audit log and every **live** token — sessions, OAuth, attendance
links and self-reg links, all of which authenticate against *production*.
Usernames and free-text notes stay, which is a judgement call appropriate to a
dump for someone getting started; revisit it if the audience changes.

`verify` is read-only and can be run at any time; every count it prints must be
zero. `export` additionally scans the finished file for email-shaped strings, so
a mistake surfaces before the file reaches anyone.
