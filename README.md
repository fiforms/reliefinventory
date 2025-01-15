# About Relief Inventory

Relief Inventory is a collaborative open source software project to facilitate tracking of donated goods in disaster response scenarios. 
It was originally developed in cooperation with Adventist Community Services to facilitate warehouse management.

# Setup 

This software utilizes the Laravel Open Source PHP Framework and related software. The following brief list of steps
should help you to get an instance up and running in your environment:

Install prerequisite packages:

** Use your package manager to install relevant packages, i.e. **

  `sudo apt install php php-cli composer mariadb php-mysql php-redis git`
  
** If you're going to run this in a server environment, you'll need a webserver like Apache or nginx, as well as a cache server **

  `sudo apt install apache2 redis`

** Clone the Repository (or see below for using the Eclipse editor instead) **

  `git clone https://path/to/reliefinventory`

** Create a database to hold data (change YourPasswordHere accordingly) **
  `mysql`
  `create user reliefinventory@localhost identified by 'YourPasswordHere';`
  `create database reliefinventory;`
  `grant all on reliefinventory.* to reliefinventory@localhost;`
  `exit`

** Create your local configuration, and edit .env, adding the database info and password you created above **
  `cd reliefinventory`
  `cp .env.example .env`
  `nano .env`

** Install Laravel packages using composer **
  `composer install`
  
** Install node packages **
  `npm install`

** Create datastructures (If you have a database backup, this would be the point to restore it instead) **
  `php artisan migrate`
  
** Create a new user (YOU MUST do this before loading test data) **
  `php artisan user:create -u myusername -e myemail@example.com`
  
** Load initial data **
  `# Creating essential data`
  `php artisan db:seed`  
  `# Loading additional test data`
  `php artisan db:seed --class=TestDataSeeder`

** Spin up the instance **
  `composer run dev`
  
# Eclipse IDE

The Eclipse IDE is a helpful editor that can streamline your workflow. You can download [Eclipse IDE for PHP Developers](https://www.eclipse.org/downloads/packages/)

# MySQL Workbench

A helpful graphical tool for working directly with the MySQL Database is MySQL Workbench from Oracle. (If you're using MariaDB as suggested above, you'll get a warning about compatibility but it does work well with MariaDB). You can [download MySQL Workbench here](https://www.mysql.com/products/workbench/)
