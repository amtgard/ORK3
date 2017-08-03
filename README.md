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
