# About Relief Inventory

Relief Inventory is a collaborative open source software project to facilitate tracking of donated goods in disaster response scenarios. 
It was originally developed in cooperation with Adventist Community Services to facilitate warehouse management.

You can find the latest information about the Relief Inventory project at our [website](https://reliefinventory.fiforms.net)

# Setup 

This software utilizes the Laravel Open Source PHP Framework and related software. The following brief list of steps
should help you to get an instance up and running in your environment:

Install prerequisite packages:

** Use your package manager to install relevant packages, i.e. **

    sudo apt install php php-cli composer mariadb-server php-mysql php-redis git php-xml npm
  
** If you're going to run this in a server environment, you'll need a webserver like Apache or nginx, as well as a cache server **

    sudo apt install apache2 redis

** Clone the Repository (or see below for using the Eclipse editor instead) **

    git clone https://path/to/reliefinventory

** Create a database to hold data (change YourPasswordHere accordingly) **

    mysql
    create user reliefinventory@localhost identified by 'YourPasswordHere';
    create database reliefinventory;
    grant all on reliefinventory.* to reliefinventory@localhost;
    exit

** Create your local configuration, and edit .env, adding the database info and password you created above **

    cd reliefinventory
    cp .env.example .env
    nano .env

** Install Laravel packages using composer **

    composer install
    php artisan key:generate
    
  
** Install node packages **

    npm install

** Create datastructures (If you have a database backup, this would be the point to restore it instead) **

    php artisan migrate
  
** Create a new user (YOU MUST do this before loading test data) **

    php artisan user:create
  
** Load initial data **

    # Creating essential data
    php artisan db:seed
    # Loading additional test data
    php artisan db:seed --class=TestDataSeeder

** Spin up the instance **

    composer run dev
  
== Running in Production ==
(These steps are still incomplete)

** Setup SMTP Server **

Modify these values in .env to point to a working SMTP server, e.g. you can 
use an app password from Gmail and relay through a gmail account:

    MAIL_MAILER=smtp
    MAIL_HOST=smtp.gmail.com
    MAIL_PORT=587
    MAIL_USERNAME=my_email_address@example.com
    MAIL_PASSWORD=apppasswordhere
    MAIL_ENCRYPTION=tls
    MAIL_FROM_ADDRESS="my_email_address@example.com"
    MAIL_FROM_NAME="${APP_NAME}"
    
** Setup Cloudflare Turnstile for User Registration **

Get a Turnstile Site Key and Secret Key from [Cloudflare](https://www.cloudflare.com/application-services/products/turnstile/)

Put them into .env:

    CLOUDFLARE_TURNSTILE_SITE_KEY=
    CLOUDFLARE_TURNSTILE_SECRET_KEY=

** Ban User Signups from Disposable Email Domains **

Download the listing of disposable domains from 
[this GitHub Project](https://github.com/disposable-email-domains/disposable-email-domains)

    php artisan user:import-banned /path/to/disposable_email_blocklist.conf
    
** Import Listing of Counties **

Download a county listing from the [census.gov website](https://www.census.gov/library/reference/code-lists/ansi.html#cou)

    php artisan counties:import /path/to/downloaded/file.txt

** Recompile Project **

    composer dump-autoload --optimize
    npm run build
  
  
# Eclipse IDE

The Eclipse IDE is a helpful editor that can streamline your workflow. You can download [Eclipse IDE for PHP Developers](https://www.eclipse.org/downloads/packages/)

# MySQL Workbench

A helpful graphical tool for working directly with the MySQL Database is MySQL Workbench from Oracle. (If you're using MariaDB as suggested above, you'll get a warning about compatibility but it does work well with MariaDB). You can [download MySQL Workbench here](https://www.mysql.com/products/workbench/)


# LICENSE

## Relief Inventory - Open Source Software

### Copyright (C) 2024-2025 Adventist Community Services
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
    
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.
    
    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.

## Original Authors
This software was originally designed and written by "The Preacher Hackers," a group of Seventh-day Adventist Ministers dedicated to the cause of helping those in need. Original contributing authors include [Daniel McFeeters](https://www.pastordaniel.net), Mark Kent, and Jonathan Cantrell.

## Purpose and Rationale
The **Relief Inventory** project is an open-source software system designed to facilitate the management of donated goods in disaster relief efforts. It allows organizations to efficiently track, store, and distribute donated items to those in need. The goal of this project is to provide a **transparent, accessible, and collaborative** solution that enhances humanitarian aid operations.

To ensure that this software remains open and freely available to all, the project is licensed under the **GNU General Public License v3.0**. This license ensures that:
- The software remains **free and open-source** for anyone to use, modify, and distribute.
- Any modifications or derivative works are also **licensed under GPL-3.0**, preserving openness.
- Users have the **freedom to improve** the software while contributing back to the community.

Please see [LICENSE.md](LICENSE.md) for details

## Attributions, Disclaimers and Additional Free Licenses
Much of the code in this project has been generated by the "artisan" command in [Laravel](https://laravel.com/), with modifications made to fit the requirements of this project. Additonal code has been generated or modified through the use of Large Language Models such as ChatGPT in order to accelerate the development process. However, it is the belief of the developers that such use of existing tools should not influence the licensing terms set forth above. 


