# lsc
lsc is a simple single-file PHP app that lets one define services, then start and stop them as needed.  sqlite3 is used for a local database.

## Safety Measures
* `lsc` will not run as root
* A defined service must be chosen from a whitelist of executables.  This whitelist is defined in an INI file.
* `lsc` can only manage services that it starts, and that are running under the same local account as itself.
* `lsc` will refuse to run setuid or setgid executables.
* `lsc` will refuse to run executables that match an internal blacklist, such as `/bin/rm`.
