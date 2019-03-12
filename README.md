# Amtgard ORK 3

[![Code Climate](https://codeclimate.com/github/amtgard/ORK3/badges/gpa.svg)](https://codeclimate.com/github/amtgard/ORK3)

This is the third major release of the [Amtgard Online Record Keeper](http://amtwiki.net/amtwiki/index.php/ORK).

## Development

### Envirionment

Git, PHP 5.6, MySQL 5.5 and Apache.

### Basic Setup

To set up you will need a copy of the codebase, a recent copy of the database, and an LAMP or WAMP installation.

Clone the code to a a reasonable place in your web root.

Rehydrate the database from https://amtgard.com/ork/assets/backups

### Set Up the Config File

Copy `config.dev.php` to `config.php`. Make sure to change the admin email to your own in `config.php`.

### View the Site

You can now view the site at http://servername/ork/orkui/.

### Using Docker
A docker-compose file is setup for quickly getting the environment running locally. If there are other environments using port 80 change the exposed port in the docker-compose file to keep from conflicting. Same goes for 3306 for the database. Run the following Docker command from the cloned directory.
```
docker-compose up
```
Once this completes and MySql is waiting on a socket there are some setup tasks required. In another window issue the following commands:
```
mysql -P 3306 --protocol=tcp -h localhost -u ork  -p ork < ork.sql
```
This will setup the database.  The default password for the user 'ork' is 'secret'.

Download and extract a recent Ork backup from https://amtgard.com/ork/assets/backups/

```
mysql -P 3306 --protocol=tcp -h localhost -u ork  -p ork < ~/Downloads/2019.02.28.02.00.amtgard_ork3production.sql
```
This will take a while but will hydrate the database with the backup that was downloaded and extracted above.
```
mysql -P 3306 --protocol=tcp -h localhost -u root  -p ork 
```
Use the root password of root. Once the SQL prompt appears enter:
```
SET GLOBAL sql_mode = '';
exit
```
This was needed to allow the database to accept certain values sent by the PHP APIs.

Navigate to http://localhost/ork/orkui and see the contents of the backup that was restored.  Login with your ORK accound and password provided you have recently setup your password before the backup database was created on the production server.
