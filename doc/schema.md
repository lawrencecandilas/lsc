## Internal Global Schema Specification

Here is an explanation on what the "Internal Global Schema Specification" is, and a list of all attributes, and what they do.

In creating this application, I wanted to make it 100% "database-centered" - meaning that basically, all user requests should map to a database operation: 

- view table
- insert new row
- update existing row
- delete exsing row

Actions that are not strictly data operations exist in this scheme as "triggers."  The two main types of triggers are **generators** and **row methods**.

* **Generators** come into play when a new row is created.  Generators are functions that are called "by a column" to generate value--instead of taking that from user-provided input. Generators are required to provide a value (or report failure), but can also do anything else as a "side effect."

* **Row methods** are presented in the "View" HTML output as buttons next to each row's data--each row gets its own copy of a "row method" button. Pressing the button (and causing an HTML form to be POSTed) causes the row method to be called with the row data as a parameter. Row methods can perform actions, modify that row (or other rows--in this or other tables--if it wants to), or delete the row. 
  * *Note: Creating row methods just to provide a "Delete" function is unnecessary as the application can automatically generate "Delete" buttons for each row.*

Because the application is centered around a database and database operations, the following was needed:

- Define the database, its tables and columns, what's allowed to go in them, and how to render them.
- Express dependencies between the tables--when a column uses data from another table.
- Identify and enable triggers-functions that are called at certain times.
- Access-type metadata such as "can the user delete this table from the HTML interface" or "do we even want the table to be user-editable" (some tables might be internal only).

So, to accomplish the above, I created an internal specification that is somewhat generalized, kinda-sorta allows extensibility, modify this application's behavior, or implement other applications. It probably sucks because I am not a database expert, but I have to start somewhere. 

&nbsp;

### Defining The Global Schema

I've used the term "Global Schema" because it lives in the PHP global variable `$GLOBALS["schemadef"]` - no, this application is not object oriented at all.

`$GLOBALS["schemadef"]` is an associative array.  Each element in this array is a column of a table.  More specifically, each element's key is the "table/column", and the corresponding value is a string of slash-separated attributes.
* Looking back I should have used a multidimensional associative array, e.g. `$GLOBALS["schemadef"]["table"]["column"]` would have been pretty keen. Oh well.

**Attributes** - these themselves consist of a key and a value, separated by a colon. 

* If a slash, colon, or comma needs to be part of a attibute's value, HTML entities should be used.

Some attributes may or must take multiple values; each of these values will be separated by a comma.  Some attributes don't take (or don't have to take) values, these will or may not have anything following them.

&nbsp;

### What Are The Purpose Of Attributes?
The initial reason why I created them was to specify the type of data each column contains, and information needed to test if a value is valid for the column.

#### Validation - super important.
Incoming information destined to be placed in tables should be validated.  Therefore the application needs to know what's valid for each column. And what that is exactly depends on the column's type. For example, if the type is numerical, information like the maximum and minimum possible values are needed. If the type is textual, then we need to know the maximum (and optionally minimum) possible length of that text.

#### Other things are also important ...
Things like:
* *presentation* - how the HTML output appears/is rendered - including controlling whether things are visible or not, 
* *requirements* - conditions that control whether things are enabled or not,
* *dependencies* - where a table needs another table to exist or data from that other table.

#### What about things that affect whole tables and not just specific columns?
I realized early on having some sort of scheme to store table metadata was needed. for example, when rendering the table to HTML, we'll want to display a nice title (and not just the SQL name of the table), and I needed to stick that data somewhere.

##### Virtual Column - FOR_THIS_APP
I decided a "virtual column" would be a good idea - anything in the column named `FOR_THIS_APP` are attributes that don't define any table column, but control aspects of the table as a whole. The application will not create, access, modify, or delete any column named `FOR_THIS_APP`. in the database, but refers to `FOR_THIS_APP`'s schema definition a lot.
&nbsp;

### List Of Attributes

* Table Metadata Attributes
  * Appearance
  * Requirements
  * Allowed Actions
  * Defaut Values
  * Dependency Relationships
  * Row Methods
  * Delete Action Related
* Column Metadata Attributes
  * Appearance
  * The "In-Journey" - What Puts Data In The Column (And How)?
  * Default Values - Providing
  * Default Values - Receiving
  * Validation 

&nbsp;

#### Table Metadata Attributes: Appearance
`friendly-object-name:TEXT`
What the table in general will be referred to in error messages, log messages, and buttons.

`instance-friendly-name-is:COLUMN_NAME-SAME_TABLE`
If a message needs to talk about a specific row (specific "instance" of an "object"), the value in this column will be used. The column's value should uniquely identify something and make sense to the end user.

`new-form-title:TEXT`
This title text will be rendered at the top of the "Create New" form.

`title:TEXT`
Friendly, user-facing title of table.  Displayed at the very top of the HTML output.

`toplink:TEXT`
If this is defined for the table, this text will be used to create a link in the navigation bar, and the user can then use it to view the table's data and create new rows ("objects").

* If this is NOT defined, it is assumed you don't want the end user to interact the table directly and the application won't generate a navigation link to access the table. Hacked requests targeting the table will only result in an error message and a logged hack event. 

#### Table Metadata Attributes: Requirements

`must-exist-in-fails-message:TEXT`
If `row-must-exist-in:X` is defined, and the user tries to create a new row but nothing is in table X, the request isn't processed and this message is displayed as an error message.

`row-must-exist-in:TABLE_NAME`
Specifies that at least one row must exist in the table named `TABLE_NAME` before the user may create a new row in this table. *See `must-exist-in-fails-message`.*

`single-row-only` [Does not take a value]
Table will be in "Single Row Mode" - the table may have a maximum of one row. The "Create New" form won't appear if the table already has a row. Hacked HTTP requests that try to force the issue will be refused as well.

`single-row-only-empty-message:TEXT`
If the table is in "Single Row Mode", this message will be displayed in the "View" HTML output if the table is empty.  This won't have any effect if `single-row-only` isn't specified.

#### Table Metadata Attributes: Allowed Actions

`allow-delete-by:COLUMN_NAME-SAME TABLE`
The application will render a "Delete `COLUMN_NAME`" button with the row. The supplied column name should uniquely identify the row. If this is not defined, it's assumed that you don't want the user directly deleting rows from the table--no delete button will be emitted; hacked HTTP requests targeting the row will be refused.

#### Table Metadata Attributes: Default Values

NOTE: There are column attributes that also control default values.  See "Column Attributes: Default Values" as well.

`defaults-here-keyed-by:COLUMN_NAME-SAME_TABLE`
If this is set, an "onChange" handler is added to HTML element representing the column in the "Create New" form. This handler will automatically fill fields in with default values when the user changes this element's value in the form.

`defaults-in-provider-keyed-by:COLUMN_NAME-OTHER_TABLE`
This should be specified when `defaults-here-keyed-by` is specified. When the user changes the element that makes the defaults populate, the column in this table that matches the value in the `defaults-here-keyed-by` value will be used to grab default values from. 

*Example:*
`defaults-provided-by:employees/defaults-here-keyed-by:pointer_to_employee/defaults-in-provider-keyed-by:employee_number`
Let's say the above is part of a table called `performance_reviews.`  With the above, the `employees` table can define defaults for `performance_reviews` in a "Create New" form.

`defaults-provided-by:TABLE_NAME`
Tells the application that the table named "TABLE_NAME" supplies default values for this table.

#### Table Metadata Attributes: Dependency Relationships

 `backref-by:COLUMN_NAME-SAME_TABLE`
When this is specified, a **backref** to this column linked to the table specified by `is-pointer-to` will be created when a new row is created.

The purpose of a backref is to efficiently detect if something is pointing to a row, which is needed in order to prevent a row in one table from being deleted if somethig else is using it.

When a row is deleted, the application checks if anything is pointing to it by querying the internal `backref` table, and won't proceed if something is found. Next, the application will check if that row might be pointing *to* anything, and if that's the case, the backref is deleted, then the row is deleted.

* If `is-pointer-to` is not specified, `backref-by` has no effect.

* If `is-pointer-to` is specified but neither `backref-by` nor `allow-delete-by` are specified, the application acts as though `backref-by:rowid` was specified.

`is-pointer-to:TABLE_NAME`
`pointer-links-by:COLUMN_NAME_OTHER_TABLE`
`shown-by:COLUMN_NAME_OTHER_TABLE1,COLUMN_NAME_OTHER_TABLE2` \[etc\]

The `is-pointer-to` attribute, when specifeid, tells the application that this column serves as a reference to another column in another table.  
The `pointer-links-by` attribute specifies that column. 

The `shown-by` attribute tells the application how to present this to to the user - data from these columns in the other table will be used to render the column's value. Multiple columns can be specified.

* The `is-pointer-to` attribute allows the `backref-by` attribute to be specified, which will prevent the other table from being deleted while rows that point to something in it still exist.
*  `injourney:user-selects-from-this-list` uses the table in the `is-pointer-to` attribute as the list it will get values for user selection from.

&nbsp;

#### Table Metadata Attributes: Row Methods

`each-row-method:METHOD_NAME1,METHOD_DISPLAY1,TARGET_COLUMN1;METHOD_NAME1,METHOD_DISPLAY1,TARGET_COLUMN2` \[etc\]
Lists the row methods that will be emitted with the row's data in the "View" HTML output for the table. `METHOD_NAME` is the internal method name (passed in the POST request) and should not consist of anything but letters and underscores. `METHOD_DISPLAY` is the user-facing name that will be emitted in the HTML button. `TARGET_COLUMN` is how the method will know which row to perform an action on - and will be part of the HTML output.

&nbsp;

#### Table Metadata Attributes: Delete Action Related
`erase-upon-clear-logs`
If specified, this table will be SQL `DELETE`ed along with the internal `log` table when a `clear_logs` request is received.

#### Column Attributes: Appearance 
`display-using-other-table` \[Does not take a value\]
`display-sql-SELECT:COLUMN_NAME_OTHER_TABLE`
`display-sql-FROM:TABLE_NAME_OTHER_TABLE`
`display-sql-WHERE:COLUMN_NAME_OTHER_TABLE`
`display-sql-IS:COLUMN_NAME_THIS_TABLE`
Tells the application that data from another table should be used when displaying this column's value in the "View" HTML output.

`display-sql-SELECT` will be the "remote column" - the column in the other table that you want to use to display values for this column.
`display-sql-FROM` will be the "remote table" - the table that has data you want to use to display values for this column.
`display-sql-WHERE` will be the "key column" - the column in the other table that has a value we'll look for to find the row we want to use in the "remote table."
`display-sql-IS` will be the "key value" - the column in *this table* that has the value we're looking for in the "remote table" - that is used to find a matching row and pull the `display-sql-SELECT` column's value from it.  Normally you will want this to be the same column as the column you are defining, but you can use a different column if it makes sense.

If the value in `display-sql-IS` is not found in the column `display-sql-WHERE` of table `display-sql-FROM`, the message "\[Object missing\]" is displayed instead.

`dont-show` [Does not take a value]
Columns with `dont-show` specified won't be displayed in the "View" or "Create New" HTML output. The user won't be able to enter a value for columns with this attribute. This is what you want if the column's "in-journey" is `app-generated`.

`form-label:TEXT`
Label for the column's data. Should be short, user-friendly text describing what the column is.

`present-width:WIDTH_SPECIFIER` [Value optional]
Specifies a presentation width for the column's HTML output.
- If this is not specified or there is no value, the column's label and data or input field will be displayed on the same line.
- If the value is `full-width`, the column's label will take an entire line and it's data or input field will take an entire line below that.  `full-width` may be preferred if a column can take a large amount of text data.

&nbsp;

#### Column Attributes: The "In-Journey" - What Puts Data In The Column (And How)? ####

`injourney:IN_JOURNEY_METHOD`
I've used the term "in-journey" to refer to the way data gets into a column when a new row is made.

You might be thinking, "isn't it always something the user enters?" and no, this is not the case. Some columns may be automatically populated (and not even visible to the user). A good example is a UUID - those are typically automatically generated by the application and nothing the user should need to worry about physically entering.

Even when the user is expected to provide data - there are also multiple *ways* the user may do that--entering text in a field, selecting from a list, etc.--and this attribute tells the application which one to present in HTML output..

`injourney:app-generates`
The application will generate this value when a new row is created. Because of this, it won't be presented in the "Create New" form. The application will expect a GENERATOR_ function to exist, do what's needed, and provide the value (or the row won't be created).
* Generators for some intents can be handled by the application itself and won't result in a function call - currently `uuid`..
* If you have a generator defined that relies on an automatically generated value, make sure the automatically generated value is defined first in the schema. Otherwise the value won't exist yet to pass to the generator.

`injourney:user-enters-text`
In-journeys that start with this mean the user will supply a value by typing it in a text field. Therefore, for columns that have attributes that start with `user-enters-text`, text input boxes will be emitted in the "Create New" form.

- `injourney:user-enters-text-for-localfile`
- `injourney:user-enters-text-for-localdir`
- `injourney:user-enters-text-for-number`
- `injourney:user-enters-text-for-urlprefix`

Right now these all function the same as `injourney:user-enters-text`, with the part after the `for` passed as a "context" variable to interested code - which there is none. This will probably go away as this information can be inferred from the column's intent.

`injourney:user-selects-from-this-list`
`this-list:item_1=display_text1,item_2=display_text2` \[etc...\]
This in-journey presents a list that the user can select from in the "Create New" HTML form output. For each item, the `display_text` is what the user will see in the form, and the `item` is the data that will be placed in the column when a new row is added. List items are presented in the order they appear.

If is specified without `this-list`, an empty list will be emitted. Note that if `req:y` is also specified this will make it impossible to create a new row.

If `this-list` is specified without `user-selects-from-this-list`, the `this-list` attribute is ignored.

`injourney:user-selects-from-list-in-other-table`
This in-journey presents a list that the user can select from in the "Create New" HTML form output. The list items come from another table. 

The other table used to get the list items is the one named in the `is-pointer-to` attribute. 

The user-visible text of each item is taken from the column named in `shown-by` attribute, and for each list item, the corresponding values that will be put into the column are taken from the column named in the `pointer-links-by` attribute.

`in-journey:user-selects-from-ini-list`
`ini-list-section:INI_SECTION_NAME`
`ini-list-array:INI_ARRAY_NAME`
This in-journey presents a list that the user can select from in the "Create New" HTML form output. The list items come the .INI file.
* `ini-list-section` identifies the `[section]`  of the .INI file used, and `ini-list-array` identifies the array used. All elements of the array in the .INI file are pulled in.
* If the array doesn't exist in the .INI file, an empty list will be emitted. Note that if `req:y` is also specified this will make it impossible to create a new row.

&nbsp;

#### Column Attributes: Default Values - Providing

`provides-defaults` [Does not take a value]
This attribute indicates that this column provides defaults for another column in another table.

`gives-default-for-table:TABLE_NAME`
This attribute tells the application which table this column provides a default value for. Has no effect if `provides-default` is not present.

`gives-default-for-column:COLUMN_NAME`
This attribute tells the application which column--in the table identified by `gives-default-for-table` attribute--that this column provides a default value for. Has no effect if `provides-default` is not present.

&nbsp;

#### Column Attributes: Default Values - Receiving

`default-value-from-ini:INI_NAME`
This column's field on the "Create New" form will be pre-populated with a default value from this variable in the .INI file.  Right now this only supports columns with "user-enters-text" in-journeys.

&nbsp;

#### Column Attributes: Validation 

`req:Y_OR_N`
This tells the application whether or not a value is required.

When the application creates its database, columns with `req:y` will be specified as `NOT NULL` in the creating SQL statements. The application uses HTML form validation to enforce this validation requirement, and also checks again when processing submitted requests, so an SQL error should never be returned to the user even if hacked query strings are used.

`type:TYPE`
This tells the application the fundamental type of the data in this column.

Valid values are `str` for text-based data and `int` for numeric (integers only) data.

This also determines whether `TEXT` or `INTEGER` is specified in SQL statements when the application creates its database.

The application uses HTML form validation to enforce this validation requirement, and also checks again when processing submitted requests, so an SQL error should never be returned to the user even if query strings are hacked.

`data:DATA_INTENT`
This tells the application the expected "intent" of the data in this column. The intent of a column can determine:

- additional validation as far as valid characters,
- additional/special things to do when presenting the value in "View" or "Create New" forms.

Currently supported intents are:

- `uuid` : must be a valid UUIDv4.
- `file` : must be the name of a local file.
- `port` : TCP/IP port number.
- `url`  : must be a valid URL.
- `cmd`  : is command-line text (affects presentation).
- `name` : is a name (may affect presentation).
- `pid`  : is a UNIX process ID (may affect presentation).
- `date` : is a date.

`maxlen:NUMBER`, `minlen:NUMBER`
Determines the minimum and maximum length of `str` types. Has no effect on `int` types.

The application uses HTML form validation to enforce this validation requirement, and also checks again when processing submitted requests, so an SQL error should never be returned to the user even if hacked query strings are used.


