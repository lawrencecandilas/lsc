# .INI options

The .INI file controls a few configuration options that are best not set within the app itself.

`lsc.php` will not run without an .INI file present.

## .INI file location and permissions
The application looks for the .INI file at this exact path: `/etc/lsc/$USER/lsc.ini`.
- Replace `$USER` with the local UNIX username that `lsc.php` will be running under. If `lsc.php` will be running as `webservices`, for example, the .INI file should appear at `/etc/lsc/webservices/lsc.ini`.
  - Webservers such as Apache will run PHP scripts as `www-data` by default. It is recommended you install and configure `php-fpm` to allow Apache to run the PHP script as a separate user if you are using Apache for other purposes.
  - `lsc.php` will not run if it detects its uid is 0 (root).
- There is no way to specify a different location or set a generic .INI file for any UNIX user by design.
- For best security: The permissions of the .INI file itself and its containing directory should be set to allow the local UNIX user to *read* the .INI file, but not *write* to it. Continuing with the `webservices` example above, a `chmod 750 /etc/lsc/webservices` and `chmod 640 /etc/lsc/webservices/lsc.ini` is recommended.

## .INI file options and what they do
Here is an example .INI file so you can see how it is laid out.
```
[general]
timezone="America/Chicago"

[database]
name="/var/lib/lsc.php/lawrence/lsc.sqlite3"

[whitelist]
bin[Ethercalc]=/usr/bin/ethercalc
bin[SQLite Web]=/home/lawrence/.local/bin/sqlite_web

[defaults]
homedir=/home/lawrence/workspace/webapp
stdoutdir=/home/lawrence/workspace/webapp
```

### `[general]` section
TODO `alertlog=$LOCAL_PATH` (Optional)
If this is defined, the application will log tagged errors to this file. Tagged errors include "bad_db," "suspect_hack," and "suspect_bug" errors.

TODO `loginlog=$LOCAL_PATH` (Optional)
If this is defined, the application will log when users log in and log out.

`timezone=$PHP_TIMEZONE` (Optional)
Needs to be a string recognized by PHP time functions. The application will use this to correctly compute times in the Action History. 
- If this is not specified, local server time will be used.


### `[database]` section
`name` (Required)
Tells the application where its SQLite database lives. Must be writeable by the location. **Do not put this in a path serveable by your webserver (e.g. it probably shouldn't start with `/var/www`.)** 
- The recommended location would something like `/var/lib/lsc.php` and this would be a directory you have to create and `chmod`.

### `[whitelist]` section
`bin[$FRIENDLY_NAME]=$PATH` (At least one required, specify as many as you want)
Specifies a binary you want to be selectable in the Services table.
- `$FRIENDLY_NAME` will be how it appears in the dropdown.
- `$PATH` is the path to the executable.
Executables that are not specifically listed here cannot be used with `lsc.php`.
For best security, only list executables you want to remotely control with `lsc.php`.
This list can be updated at any time and updates will be immediately reflected in the "Create New" section of the Services table. Existing running processes or defined services won't be affected, but new processes/services can't be created.
If an executable here matches the application's internal blacklist, the application won't run it.

### `[defaults]` section
`homedir=$PATH` (Optional)
This directory will appear as a suggested default value for "Home Directory" in the Configuration table. It can be overriden.

`stdoutdir=$PATH` (Optional)
This directory will appear as a suggested default value for "STDOUT File Directory" in the Configuration table. It can be overriden.
