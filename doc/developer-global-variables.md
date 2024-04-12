This code uses quite a few global variables. If I didn't use them, I'd probably be passing around a global state object to just about every function anyway. 

Anyway, here's what they all are and do. 

Almost all are initialized in the `set_globals()` function called very early.

**`$GLOBALS["bad_db"]`**
`merr()` sets this to `true` if an error message is tagged as being caused by something in the database not being where it should be.

**`$GLOBALS["dbo"]`**
Database object. Created by `open_database()`.

**`$GLOBALS["dbo2"]`**
Second database object only used to read the database. Created by `open_database_2()`. I forget why I did this and may not need it.

**`$GLOBALS["disabled"]`**
If this is `true`, the end user will see the error message "This application is currently not processing requests." and won't be able to do anything.
This variable is set to `true` if a file named `disabled` is present in various places in `/etc/lsc`.
The check is made very early in the code by `validate_action()`. Neither the .INI file or database are even opened, and any sessions won't be connected.

**`$GLOBALS["extra_goodies"]`**
For any output method: Any elements in this array will be emitted by `output_messages()` after emitting items in the `errors` and `notices` message piles. This is used by `ROWMETHOD_stdout_running()` to deliver the latest contents of the process's `stdout` file.

**`$GLOBALS["hostname"]`**
Contains the current UNIX hostname as reported by the PHP function `get_hostname()`.

**`$GLOBALS["js"]`**
For the HTML output method: any elements in this array will be emitted between `<script>`/`</script>` tags toward the end of the HTML. Javascript is generated and used by the new row form to automatically populate default values if connected to a list in the form.

**`$GLOBALS["outmsgs"]`**
Outgoing message piles. Used to generate responses to requests. There are 5 types of outgoing messages and each is another level of associative array. Types of outgoing messages include:
- Notices (`$GLOBALS["outmsgs"]["notices"]`): notifying of something happening or something being successful.
- Errors (`$GLOBALS["outmsgs"]["errors"]`): notifying of something going wrong or something being unsuccessful.
- Buttons (`$GLOBALS["outmsgs"]["buttons"]`): Not really a "message" - it's HTML code that looks like a button and allows the end user to perform an immediate request from the response page.
- Debug (`$GLOBALS["outmsgs"]["debug"]`): Internal developer data, usually if something is going wrong.
- Trace (`$GLOBALS["outmsgs"]["trace"]`): Internal developer data, mostly messages that show flow through the application code. Any function calls that involve database operations announce themselves via trace messages.

Debug and trace messages are visible in the HTML source code as HTML comments, if enabled.  Either or both are enabled if a file named `/etc/lsc/debug` or `/etc/lsc/trace` (respectively) are present.

**`$GLOBALS["readonly"]`**
If this is `true`, the `new_row` and `delete_row` methods will return an error message instead of doing anything, and a warning message will also be displayed when the table is viewed. 
This variable is set to `true` if a file named `readonly` is present in various places in `/etc/lsc`.
The check is made very early in the code by `validate_action()`. 
- Intended to disallow modification of application data. Will not affect rights/sessions/user functions - users will be able to still login, logout, and perform account management.

**`$GLOBALS["schemadef"]`**
This is set by `set_schemadef()` - before `set_globals()`.
Contains the entire global schema definition of the application.  See "Developer - Internal Global Schema Specification" for more details.

**`$GLOBALS["scriptname"]`**
**`$GLOBALS["scriptname_out"]`**
Contains the filename of the script. Dervied from `$_SERVER['SCRIPT_NAME']`. 
is simply `$GLOBALS["scriptname_out"]` run through `safe4html()` and that one should be used when generating HTML output.
These are used extensively throughout the code - any code generating HTML that has a `<form>`/`</form>` block will reference this.

**`$GLOBALS["sqltxn_commit"]`**
This is not set by `set_globals()`.  
Here's what this does:
- If this global variable is set--it means a database transaction is in progress.
  - `begin_sql_transaction()` will set this initially, to `false`.
- Then .. later `end_any_sql_transaction()` should be called.
   - If `$GLOBALS["sqltxn_commit"]` is `true`, transaction is ended with a COMMIT.
   - If `$GLOBALS["sqltxn_commit"]` is `false`,, transaction is ended with a ROLLBACK.
- `end_any_sql_transaction()` then unsets `$GLOBALS["sqltxn_commit"]`.

So, code should/will follow this pattern:
- Call `begin_sql_transaction()`.
- Perform one or more DB calls.
- If all calls are successful, set `$GLOBALS["sqltxn_commit"]` to `true`. 
- Leave set to `false` if there was some sort of error.
- Call `end_any_sql_transaction()` which will `COMMIT` or `ROLLBACK` the transaction as needed.

If `end_any_sql_transaction()` is called and `$GLOBALS["sqltxn_commit"]` is not set, it does nothing.


**`$GLOBALS["suspect_bug"]`**
`merr()` sets this to `true` if an error message is tagged as something that shouldn't happen.

**`$GLOBALS["suspect_hack_flag"]`**
`merr()` sets this to `true` if an error message is tagged as something that would only happen if someone was misusing with the HTML interface, such as sending POST requests that are normally impossible to send via the UI forms. 

**`$GLOBALS["username"]`**
Contains the current UNIX username that `lsc.php` is running under (this has nothing to do with who is logged into the application). Set by the PHP function `get_current_user()`. 
- `start_output()` uses this to render the right half of the app header title on each page.
- This variable is not used to check if the application is running as root--`posix_getuid` is used for that instead.
