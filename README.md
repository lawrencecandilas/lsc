# lsc.php - Lawrence's Service Controller.
`lsc.php` is a simple web-based control panel that allows one to define services, and once defined, start and stop them.

A *service* is a simply a command, that takes a filename, URL prefix, and/or a port number as part of its arguments. These types of commands might be ones that run in the background once started, then serve files over the network; these also may be something you wish to start and stop remotely.

`lsc.php` gets a couple setting from an .INI file, and also uses an SQLite database to store addiitional settings and application state.

### Installation
`lsc.php` is a PHP application, so you will need a web server such as Apache running and setup to handle PHP scripts. You may want to use php-fpm or other configuration that allows `lsc.php` as a different local user than `www-data` for security purposes.  

- `lsc.php` requires the PHP `sqlite3` module.  You can install this under Debian with a `sudo apt-get install php-sqlite3`.
- Create the .INI file.
- Copy `lsc.php` to a location accessible and serveable by your webserver.  You can rename `lsc.php` if you want.
- `lsc.php` automatically creates its database when you access it the first time.

`lsc.php` was developed and tested under a Linux Debian 11 system with PHP 8.2.  Earlier versions of PHP may not be supported.

### Walkthrough / How To Use
- First, whitelist your desired executables in lsc's .INI file--`/etc/lsc/$USER/lsc.ini`, where `$USER` is the local user account that `lsc.php` is running under. 
  - You will likely need to be `root` to create this file.
  - Add one or more executables to the `[whitelist]` section.
    - Executables are added with `bin[$NAME]=$PATH` lines. 
    - `$NAME` will be the name as it appears in `lsc.php`.  
    - `$PATH` needs to be the **full path** to the executable you are whitelisting.
- Next, click on the Configure tab, and create a configuration.
- Then, click on the Locations tab, and define a location:
  - A *location* is simply a combination of TCP/IP port number and (optionally) a URL prefix.  
- Then, click on the Services tab, and define a service:
  - Here you will select an executable, a location, and enter the arguments. 
  - In the arguments you can specify placeholders for a port (`{PORT}`), URL prefix (`{URLPREFIX}`), and file name (`{LOCALFILE}`).
  - You can, optionally, also specify default values for the above.  These can be overriden when you start the service.
- Now, you should be able to go to the Processes tab and start the service - you will have to enter a filename (if needed) and select a location first.

### Security Considerations
IMPORTANT: **Currently `lsc.php` doesn't implement access control.  You absolutely should configure HTTP Authentication in your web server to protect `lsc.php` with a username and password.**

`lsc.php` as shipped has a number of security safety features. Most of these can be bypassed by wrapper scripts and appropriate local configuration, so these features won't absolutely guarantee security.

`lsc.php` ...
- ... will not run as root - if running as root, it will not even read the .INI file or open the database.
  - Because of that, only processes running as the same user can be started/stopped.
  - It's recommended to use php-fpm or similar to avoid running `php.lsc` as the `www-data` user
- ... will only launch executables that are whitelisted in the .INI file.  
  - The .INI file should not have write permissions by the user or group account that `lsc.php` is running as.
- ... checks the suid/sgid bits before executing commands - will not run them if either is set.
- ... has an internal blacklist of directories and executable names that it will not run, such as anything in `/sbin` or `/bin/password.`

### License
GPLv3.

