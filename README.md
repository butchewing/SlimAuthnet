# SlimAuthnet

A neat little thingy to process credit cards with Authorize.net built with Slim Framework.

## Features
* Admin Login with Basic Authentication
* REST API Key Generator
* Authorize.net AIM Processing
* System Logger

## Install Composer

If you have not installed Composer, do that now. I prefer to install Composer globally in `/usr/local/bin`, but you may also install Composer locally in your current working directory. For this tutorial, I assume you have installed Composer locally.

<http://getcomposer.org/doc/00-intro.md#installation>

## Install the Application

After you install Composer, run this command from the directory in which you want to install your new Slim Framework application.

    composer install

* Point your virtual host document root to your new application's `public/` directory.
* Ensure `logs/` and `templates/cache` are web writeable.
* Set Environment Variables with [PHP dotenv](https://github.com/vlucas/phpdotenv)

## Create Database
See the sql.txt file

Default Admin Login: admin/admin
Be sure to change that straight away!