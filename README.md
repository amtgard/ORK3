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
A docker-compose file is setup for quickly getting the environment running locally. With an up to date version of docker you can run
```
docker-compose up
```

You will still need to hydrate the database from a backup. If you have other environments using port 80 you may need to change the exposed port in the docker-compose file to keep from conflicting. Same goes for 3306 for the database. Out of the box and without port conflicts you should be able to run the command above, import the DB to the 'ork' database, and begin work. You may want to adjust the error reporting in config.dev.php as the codebase is old and uses deprecated methods and throws notices/deprecation messages

A .dev.env is setup to set the database credentials. Currently this is set to what is currently in the default config.dev.php file.
