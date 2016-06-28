# Amtgard ORK 3

This is the third major release of the [Amtgard Online Record Keeper](http://amtwiki.net/amtwiki/index.php/ORK).

## Development

To get started with development, you will need Docker. Once that's installed, just run `docker-compose up` to bootstrap your environment.

After that, you'll need to do two things:

### Initial Database Migration

To do this, enter your web container:

```
docker exec -it containername bash
```

The exact value of `containername` may be different for you, but will generally be something like `projectname_web_1`.

The following two commands are run from inside your container.

You will need mysql-client (which is not installed by default in the container, for production deployment reasons):

```
apt-get install -y mysql-client
```

Finally, run the migration:

```
mysql -u root -h mysql -p -D ork < ork.sql
```

You'll need to enter the MySQL root password, which for this development instance is `secret`.

This migration contains no data. If you need test data, ask a project admin for a database dump from production.

### Set Up the Config File

Copy `config.dev.php` to `config.php`. Make sure to change the admin email to your own in `config.php`.

### View the Site

You can now view the site at http://localhost:8080/orkui/.