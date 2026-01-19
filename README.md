# Amtgard ORK 3

[![Code Climate](https://codeclimate.com/github/amtgard/ORK3/badges/gpa.svg)](https://codeclimate.com/github/amtgard/ORK3)

This is the third major release of the [Amtgard Online Record Keeper](http://amtwiki.net/amtwiki/index.php/ORK).

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

3. Download the components, buid, and start your containers. There are two, one for the PHP and another for the Maria DB.

```
docker-compose -f docker-compose.php8.yml up

    or

docker-compose -f docker-compose.php8.yml up -d

    to do the build, run and detatch (run in background)
```

4. Obtain the redacted database mentioned above and depending on the name and where it is located, run the following command on your desktop (you will require mysql in your development environment)
```
mysql -P 24306 --protocol=tcp -h localhost -u root -proot ork < ~/Downloads/[somedate]redacted.sql

```
this will take a while. After it completes, need to run the following:
```
mysql -P 24306 --protocol=tcp -h localhost -u root -proot 
```
Once the SQL prompt appears enter:
```
SET GLOBAL sql_mode = '';
exit
```
This was needed to allow the database to accept certain values sent by the PHP APIs. If you find that even though you are logged into the ORK as an admin you cannot change values locally, redo this step, logout and log back in.

5. You should be able to connect to the running PHP server now and can access from local at: `http://localhost:19080/orkui/`

