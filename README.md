# About Relief Inventory

Relief Inventory is a collaborative open source software project to facilitate tracking of donated goods in disaster response scenarios. 
It was originally developed in cooperation with Adventist Community Services to facilitate warehouse management.

# Setup 

This software utilizes the Laravel Open Source PHP Framework and related software. The following brief list of steps
should help you to get an instance up and running in your environment:

Install prerequisite packages:

** Use your package manager to install relevant packages, i.e. **

  `sudo apt install php php-cli apache2 composer mariadb php-mysql php-redis redis git`

** Clone the Repository **

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

** Create datastructures **
  `php artisan migrate`

** Create a new user **
  `php artisan user:create -u myusername -e myemail@example.com`

** Spin up the instance **
  `composer run dev`
