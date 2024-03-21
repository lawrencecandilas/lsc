## Internal Schema Interface

In creating this application, I wanted to make it 100% "database-centered" - meaning that basically, all operations are some type of database operation: 
- view table
- insert new row
- update existing row
- delete exsing row

Because everything revolves around a database, there was a need to somehow do the following:
- express dependencies between the tables,
- describe what is valid data in each table's column,
- describe how it should be presented in HTML forms, and
- describe local functions that should be called upon various trigger events,
- other metadata such as "can the user delete this table from the HTML interface" or "do we even want the table to be user-editable" (some tables might be internal only).

So, to accomplish the above, I created an internal interface that is somewhat generalized, allows extensibility, and could be used to implement other applications.  
