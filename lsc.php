<?php
error_reporting(E_ALL);
ini_set('display_errors','On');

# This is lsc.php
# Developed and tested under Debian Linux, PHP v8.2, php-sqlite3 required
# https://github.com/lawrencecandilas/lsc.php
# Copyright 2024 Lawrence Candilas | Released under GPLv3
# ----------------------------------------------------------------------------
# Big Section Index:
# - MAIN
# - SUBROUTINES
# - Master Schema Definition
# - Callables
#
#   All of these really should be separate files but they're all combined here
#   for simplicity of use.'

# ----------------------------------------------------------------------------
# == = = = = = = = = = = = = = = = = MAIN = = = = = = = = = = = = = = = = = ==
# ----------------------------------------------------------------------------


set_schemadef();

# Get script name.
# - Essential when the output format is HTML - used to generate the URL in
#   <form> elements and the "[OK]" links that reload the page after the user
#   views the response.
$my_name_tmp=explode('/',$_SERVER['SCRIPT_NAME']);
$my_name=$my_name_tmp[array_key_last($my_name_tmp)];

# Get the current user and hostname.
# - Used in HTML output and possibly needed by generators.
# - The username that the script is running as is also used in determining
#   where to find the .INI file.
$user=get_current_user(); $hostname=gethostname();

# Above needed for this very important function that sets a plethora of
# global variables.
set_globals($user,$hostname,$my_name);

# Get and validate "action" query string from HTTP request.
# - validate_action() will populate a default action if needed, including
#   setting the action to 'uid_0_muzzle' if this happens to be running as
#   root.
# - "Action" is only recognized from POST, never GET.
$ACTION=validate_action(@$_POST["action"]);

# Get and validate "format" query string from HTTP request.
# - validate_output_format() will populate a default output format if
#   needed--html--or set it to html if an unsupported format is requested.
$GLOBALS["output_format"]=validate_output_format(@$_GET["format"]);
output_buffer_setup($GLOBALS["output_format"]);

# Safety: Only set $ini_file if not UID 0.
# - .INI file contains location of database, without it, the database is not
#   going to get read.
# - If this app is mistakenly run as root, the .ini file isn't even touched,
# and that means things like the database are not possible to know.
if($ACTION!="uid_0_muzzle") {
 $ini_file="/etc/lsc/".$user."/lsc.ini";
 }

# What table are we working on?
# - "none" is a default value, will be overridden by "table" value in $_GET[]
# or $_POST[] later, and if it's not, then we handle that specially.
$table="none";

# Variables to track session.
$session=Array();
$connected=false;

# Incoming HTTP requests without session cookies force the action to be 
# "not_authenticated" if the action isn't "login."
#
# The action "not_authenticated" will show the login form, along with a
# button that calls the "login" action. 
#
# TODO: Handle API keys for non-HTTP formats
if(!isset($_COOKIE["sid"])) {
 if($ACTION!=="login"){$ACTION="not_authenticated";} 
 }

# ----------------------------------------------------------------------------
# Execute action.
#
# Everything is centered around the database so any action that is not a
# user login or administrative function will be in terms of that database.
#
# Things that are not database operations must be expressed as side effects
# in GENERATOR_ or ROWMETHOD_ functions.  That code is toward the end.
#
# Exceptions are "clear_log" and other actions relating to users, sessions, 
# and logins.
# ----------------------------------------------------------------------------

switch ($ACTION) {
 case "new_row":
 # ---------------------------------------------------------------------------
  $P=Array("table"=>"");
   find_and_sanitize_incoming($_POST,Array(),$P);
   is_table_known($P["table"]);
   if(any_errors()) { break; }
  $ARRAY_IN_DATA=Array(); 
  fill_data_array_from_query_string($P["table"],$_POST,$ARRAY_IN_DATA);
   if(any_errors()) { break; }
  bounce_readonly($ACTION);
   if(any_errors()) { break; }
  bounce_no_toplink($P["table"]);
   if(any_errors()) { break; }
  $ini=Array(); $ini=ingest_ini($ini_file);
   if(any_errors()) { break; }
  open_database($ini["database"]["name"]);
   if(any_errors()) { break; }
  begin_sql_transaction();
   if(any_errors()) { break; }
  $connected=connect_session(@$_COOKIE["sid"],$session);
  if((!$connected) or any_errors()) { $ACTION="not_authenticated"; break; }
   if(modify_action_for_redirect($ACTION,$session)) { break; }
  validate_data_array($P["table"],$ARRAY_IN_DATA);
   if(any_errors()) { break; }
  bounce_single_row_only($P["table"]);
   if(any_errors()) { break; }
  fill_data_array_from_app_generated_injourneys($P["table"],
						$ARRAY_IN_DATA,
						$_POST,
						$ini,
						$session);
   if(any_errors()) { break; }
  $result=check_unique($P["table"],$ARRAY_IN_DATA);
  if(!$result) { break; }
  set_report_names_for_insert($P["table"],$ARRAY_IN_DATA);
  make_backrefs_for_new_row($P["table"],$ARRAY_IN_DATA);
   if(any_errors()) { break; }
  check_rights($session,$P["table"],"NEW",$ACTION);
   if(any_errors()) { break; }
  claim_rights($session,$P["table"],$ARRAY_IN_DATA);
   if(any_errors()) { break; }
  insert_row($P["table"],$ARRAY_IN_DATA);
  $o=$GLOBALS["report"]["target_objectname"]." '".$GLOBALS["report"]["target_instancename"]."'";
   if(any_errors()) {
    report_and_log_new_sql_txn(false,$session,"Tried to create ".$o,"; it failed",""); 
    break;
    }else{
    $GLOBALS["sqltxn_commit"]=true;
    report_and_log_new_sql_txn(true,$session,"Created new ".$o,""); 
    }
  break;
 # ---------------------------------------------------------------------------
 case "delete_row": 
 # ---------------------------------------------------------------------------
  $P=Array("table"=>"","target"=>"");
   find_and_sanitize_incoming($_POST,Array(),$P);
   is_table_known($P["table"]);
   if(any_errors()) { break; }
  bounce_readonly($ACTION);
   if(any_errors()) { break; }
  bounce_no_toplink($P["table"]);
   if(any_errors()) { break; }
  $ini=Array(); $ini=ingest_ini($ini_file);
   if(any_errors()) { break; }
  #run_any_actions('before-delete',$P["table"],$ARRAY_IN_DATA);
   #if(count($GLOBALS["outmsgs"]["errors"])!=0) { break; }
  bounce_readonly($ACTION);
   if(any_errors()) { break; }
  open_database($ini["database"]["name"]);
   if(any_errors()) { break; }
  begin_sql_transaction();
   if(any_errors()) { break; }
  $connected=connect_session(@$_COOKIE["sid"],$session);
  if((!$connected) or any_errors()) { $ACTION="not_authenticated"; break; }
   if(modify_action_for_redirect($ACTION,$session)) { break; }
  $deleteable=set_report_names_for_delete($P["table"],$P["target"]);
   if(any_errors()) { break; }
   if(!$deleteable) { break; }
  $for_rights=resolve_deleteby_to_owneridby($P["table"],$P["target"]);
  check_rights($session,$P["table"],$for_rights,$ACTION);
   if(any_errors()) { break; }
  waive_rights($session,$P["table"],$for_rights);
   if(any_errors()) { break; }
  delete_row($P["table"],$P["target"]);
  $o=$GLOBALS["report"]["target_objectname"]." '".$GLOBALS["report"]["target_instancename"]."'";
   if(any_errors()) {
    break;
    }else{
    $GLOBALS["sqltxn_commit"]=true;
    report_and_log_new_sql_txn(true,$session,"Deleted ".$o,"");
    }
  break;
 # ---------------------------------------------------------------------------
 case "row_method_action":
 # ---------------------------------------------------------------------------
  $P=Array("table"=>"","target"=>"","target_table"=>"optional","row_method"=>"","return_to"=>"optional");
   find_and_sanitize_incoming($_POST,Array(),$P);
   is_table_known($P["table"]);
   if(any_errors()) { break; }
  bounce_readonly($ACTION);
   if(any_errors()) { break; }
  $ini=Array(); $ini=ingest_ini($ini_file);
   if(any_errors()) { break; }
  open_database($ini["database"]["name"]);
   if(any_errors()) { break; }
  begin_sql_transaction();
   if(any_errors()) { break; }
  $connected=connect_session(@$_COOKIE["sid"],$session);
  if((!$connected) or any_errors()) { $ACTION="not_authenticated"; break; }
   if(modify_action_for_redirect($ACTION,$session)) { break; }
  if($P["target_table"]==="optional") { $P["target_table"]=$P["table"]; }
  check_rights($session,$P["target_table"],$P["target"],$ACTION,$P["row_method"]);
   if(any_errors()) { break; }
  $GLOBALS["sqltxn_commit"]=true;
   # Row methods are responsible for clearing $GLOBALS["sqltx_commit"] if 
   # writes should not be committed. It's assumed most row method actions will
   # at least want to write to the log even in the case of failure.
  row_method_action($P["row_method"],
		    $P["table"],$P["target"],$P["target_table"],
		    $_POST,$ini,$session);
   if(any_errors()) { break; }
   break;
 # ---------------------------------------------------------------------------
 case "show":
 # ---------------------------------------------------------------------------
  $P=Array("table"=>"none");
   find_and_sanitize_incoming(Array(),$_GET,$P);
   is_table_known($P["table"]);
   if(any_errors()) { break; }
   $table=$P["table"];
  #bounce_readonly($ACTION);
  bounce_no_toplink($P["table"]);
   if(any_errors()) { break; }
  $ini=Array(); $ini=ingest_ini($ini_file);
   if(any_errors()) { break; }
  open_database($ini["database"]["name"]);
   if(any_errors()) { break; }
  begin_sql_transaction(); # required in case cookies are invalidated.
   if(any_errors()) { break; }
  $connected=connect_session(@$_COOKIE["sid"],$session);
  if((!$connected) or any_errors()) { $ACTION="not_authenticated"; break; }
   if(modify_action_for_redirect($ACTION,$session)) { break; }
  break;
 # ---------------------------------------------------------------------------
 case "clear_logs":
 # ---------------------------------------------------------------------------
  $P=Array("table"=>"none");
   find_and_sanitize_incoming(Array(),$_GET,$P);
   is_table_known($P["table"]);
   if(any_errors()) { break; }
  bounce_readonly($ACTION);
   if(any_errors()) { break; }
  $ini=Array(); $ini=ingest_ini($ini_file);
   if(any_errors()) { break; }
  open_database($ini["database"]["name"]);
   if(any_errors()) { break; }
  begin_sql_transaction();
   if(any_errors()) { break; }
  $connected=connect_session(@$_COOKIE["sid"],$session);
  if((!$connected) or any_errors()) { $ACTION="not_authenticated"; break; }
   if(modify_action_for_redirect($ACTION,$session)) { break; }
  delete_all_rows_bypass_schema("log");
   if(any_errors()) { break; }
  do_erase_upon_clear_logs();
   if(any_errors()) { break; }
  update_row("internal",Array("nlog"=>0),"rowid",1);
   if(any_errors()) {
    report_and_log_new_sql_txn(false,$session,"Tried to clear action history; it failed","");
    break;
    }else{
    $GLOBALS["sqltxn_commit"]=true;
    report_and_log_new_sql_txn(true,$session,"Cleared action history","");
    }
  break;
 # ---------------------------------------------------------------------------
 case "login":
 # ---------------------------------------------------------------------------
  $P=Array("table"=>"none","username"=>"","password"=>"");
   find_and_sanitize_incoming($_POST,Array(),$P);
   if(any_errors()) { break; }
  $ini=Array(); $ini=ingest_ini($ini_file);
   if(any_errors()) { break; }
  open_database($ini["database"]["name"]);
   if(any_errors()) { break; }
  create_missing_tables();
   if(any_errors()) { break; }
  begin_sql_transaction();
   if(any_errors()) { break; }
  invalidate_session(@$_COOKIE["sid"]);
   if(any_errors()) { break; }
  $userinfo=Array();
  $for_uid=authenticate($P["username"],$P["password"],$userinfo);
   if($for_uid==="") { break; }
   if(any_errors()) { break; }
  $forcing_password_reset=false;
  if($userinfo["force_password_reset"]==1) { $forcing_password_reset=true; }
  make_session($for_uid,$session,$forcing_password_reset);
   if(any_errors()) { break; }
  $session["appuser-uid"]=$P["username"];
  $GLOBALS["sqltxn_commit"]=true;
  report_and_log_new_sql_txn(true,$session,"Logged in","");
  # Login successful at this point.
  $ACTION="show";
   if(modify_action_for_redirect($ACTION,$session)) { break; }
  break;
 # ---------------------------------------------------------------------------
 case "logout":
 # ---------------------------------------------------------------------------
  $P=Array("table"=>"none");
   find_and_sanitize_incoming(Array(),$_GET,$P);
   is_table_known($P["table"]);
   if(any_errors()) { break; }
  $ini=Array(); $ini=ingest_ini($ini_file);
   if(any_errors()) { break; }
  open_database($ini["database"]["name"]);
   if(any_errors()) { break; }
  begin_sql_transaction();
   if(any_errors()) { break; }
  $connected=connect_session(@$_COOKIE["sid"],$session);
  if((!$connected) or any_errors()) { $ACTION="not_authenticated"; break; }
  invalidate_session($_COOKIE["sid"]);
  $GLOBALS["sqltxn_commit"]=true;
   mnotice("Thank you for using lsc - you have been logged out","");
  quietly_log_new_sql_txn(true,$session,"Logged out","");
  # Logout successful at this point.
  $ACTION="not_authenticated";
  $session=Array();
  break;
 # ---------------------------------------------------------------------------
 case "adm_modify_user_table":
 # ---------------------------------------------------------------------------
  $P=Array( "table"				=>"none"
	   ,"modusertable_action"		=>"optional"
 	   ,"modusertable_new_username"		=>"optional"
 	   ,"modusertable_existing_uid"		=>"optional"
	   );
   find_and_sanitize_incoming($_POST,Array(),$P);
   is_table_known($P["table"]);
   if(any_errors()) { break; }
  $ini=Array(); $ini=ingest_ini($ini_file);
   if(any_errors()) { break; }
  open_database($ini["database"]["name"]);
   if(any_errors()) { break; }
  begin_sql_transaction();
   if(any_errors()) { break; }
  $connected=connect_session(@$_COOKIE["sid"],$session);
  if((!$connected) or any_errors()) { $ACTION="not_authenticated"; break; }
   if(modify_action_for_redirect($ACTION,$session)) { break; }
  process_modusertable_request($session,$P);
   if(any_errors()) { break; }
  $GLOBALS["sqltxn_commit"]=true;
  break;
 # ---------------------------------------------------------------------------
 case "modify_my_account":
 # ---------------------------------------------------------------------------
  $P=Array("table"=>"none","reset_password"=>"","random_password"=>"","kill_sessions"=>"","disable_account"=>"");
   find_and_sanitize_incoming($_POST,Array(),$P);
   is_table_known($P["table"]);
   if(any_errors()) { break; }
  $ini=Array(); $ini=ingest_ini($ini_file);
   if(any_errors()) { break; }
  open_database($ini["database"]["name"]);
   if(any_errors()) { break; }
  begin_sql_transaction();
   if(any_errors()) { break; }
  $connected=connect_session(@$_COOKIE["sid"],$session);
  if((!$connected) or any_errors()) { $ACTION="not_authenticated"; break; }
   if(modify_action_for_redirect($ACTION,$session)) { break; }
  modify_my_account($session,$P);
   if(any_errors()) { break; }
  $GLOBALS["sqltxn_commit"]=true;
  break;
 # ---------------------------------------------------------------------------
 case "modify_my_password":
 # ---------------------------------------------------------------------------
  $P=Array("table"=>"none","old_password"=>"","new_password"=>"");
   find_and_sanitize_incoming($_POST,Array(),$P);
   is_table_known($P["table"]);
   if(any_errors()) { break; }
  $ini=Array(); $ini=ingest_ini($ini_file);
   if(any_errors()) { break; }
  open_database($ini["database"]["name"]);
   if(any_errors()) { break; }
  begin_sql_transaction();
   if(any_errors()) { break; }
  $connected=connect_session(@$_COOKIE["sid"],$session);
  if((!$connected) or any_errors()) { $ACTION="not_authenticated"; break; }
  $successful=modify_my_password($session,$P["old_password"],$P["new_password"]);
   if(!$successful) { break; }
   if(any_errors()) { break; }
  $GLOBALS["sqltxn_commit"]=true;
  report_and_log_new_sql_txn(true,$session,"Updated password","");
  # Logout successful at this point.
  break;
 # ---------------------------------------------------------------------------
 case "show_form_reset_my_password":
 case "show_form_my_account":
 case "show_form_user_management":
 # ---------------------------------------------------------------------------
  $P=Array("table"=>"none");
   find_and_sanitize_incoming(Array(),$_GET,$P);
   is_table_known($P["table"]);
   if(any_errors()) { break; }
  $ini=Array(); $ini=ingest_ini($ini_file);
   if(any_errors()) { break; }
  open_database($ini["database"]["name"]);
   if(any_errors()) { break; }
  begin_sql_transaction();
   if(any_errors()) { break; }
  $connected=connect_session(@$_COOKIE["sid"],$session);
  if((!$connected) or any_errors()) { $ACTION="not_authenticated"; break; }
   if(modify_action_for_redirect($ACTION,$session)) { break; }
  break;
 # ---------------------------------------------------------------------------
 case "disabled":
 # ---------------------------------------------------------------------------
  # validate_action() already issued a message at this point.
  # fall through
 # ---------------------------------------------------------------------------
 case "uid_0_muzzle":
 # ---------------------------------------------------------------------------
  # validate_action() already issued a message at this point.
  # fall through
 # ---------------------------------------------------------------------------
 case "not_authenticated":
 # ---------------------------------------------------------------------------
  # validate_action() already issued a message at this point.
  # fall through
 # ---------------------------------------------------------------------------
 default:
 # ---------------------------------------------------------------------------
  # we do not call open_database() here on purpose.
  $P["table"]="none";
  break;
 }

# ----------------------------------------------------------------------------
# Generate output / report results.
# Report results of action, in a manner dependent upon the specific action.
# - Action "show" (which is default if none is specified), will output the
#   table.
# - If the action is "show" and the table is "none", null_request() is 
#   invoked - for "html" format this might display a usage page.
# ----------------------------------------------------------------------------

start_output($P["table"],$session,$ACTION);

# Generate return link.
$return_link=$my_name;
if(isset($P["return_to"]) and $P["return_to"]!=="optional") {
 $return_link.="?table=".$P["return_to"];
} else {
 if(isset($P["table"])) {
  $return_link.="?table=".$P["table"];
  }
}
if($GLOBALS["output_format"]!=="html") {
 $return_link.="&format=".$GLOBALS["output_format"];
 }

# Nothing beyond this point should be writing to the database.
# Reading database may still happen, though.
end_any_sql_transaction();

switch ($ACTION) {
 case "new_row":
  content_panel_start($table,$ACTION);
   output_messages();
   content_panel_end();
  history_panel_start();
  htmlout("<p class='return-link'><a href='".$return_link."'>[OK]</a></p>");
  history_panel_end();
  break; 
 case "delete_row":
  content_panel_start($table,$ACTION);
   output_messages();
   content_panel_end();
  history_panel_start();
  htmlout("<p class='return-link'><a href='".$return_link."'>[OK]</a></p>");
  history_panel_end();
  break; 
 case "row_method_action":
 case "clear_logs":
  content_panel_start($table,$ACTION);
   output_messages();
   content_panel_end();
  history_panel_start();
  htmlout("<p class='return-link'><a href='".$return_link."'>[OK]</a></p>");
  history_panel_end();
  break; 
 case "show":
  if($table=="none") { null_request(); break; }
  if(any_errors()) { 
   content_panel_start($table,$ACTION);
    output_messages();
    content_panel_end();
   history_panel_start();
   htmlout("<p class='return-link'><a href='".$my_name."'>[Home]</a></p>");
   history_panel_end();
   break;
   }
  $rows=Array();
  $rows=read_table_all_rows($table);
  open_database_2($ini["database"]["name"]);
  content_panel_start($table,$ACTION); 
   output_messages();
   output_table_noneditable($table,$rows);
   output_new_form($table,count($rows),$ini);
   content_panel_end();
  history_panel_start();
  output_log($return_link);
  history_panel_end();
  break;
 case "show_form_my_account":
  open_database_2($ini["database"]["name"]);
  content_panel_start_full("none",$ACTION);
   output_messages();
   usrmgmt_form($session);
   content_panel_end();
  break;
 case "show_form_user_management":
  open_database_2($ini["database"]["name"]);
  content_panel_start_full("none",$ACTION);
   output_messages();
   usrmgmt_admin_form($session);
   content_panel_end();
  break;
 case "show_form_reset_my_password":
  content_panel_start_full("none",$ACTION);
   output_messages();
   show_form_reset_my_password_form($session);
   content_panel_end();
  break;
 case "not_authenticated":
  content_panel_start_full("none",$ACTION);
   output_messages();
   login_form();
   content_panel_end();
  break;
 default:
  content_panel_start($table,$ACTION);
   output_messages();
   content_panel_end();
  history_panel_start();
  history_panel_end();
 }

if($GLOBALS["output_format"]==="html") {
 # Output any generated Javascript.
 # - Javascript is used to populate default values in form fields when the
 #   a list item that provides default values changes selection.
 htmlout("<script type='text/javascript'>");
 htmlout($GLOBALS["js"]);
 htmlout("</script>\n");
 }

# Wrap it up.
finish_output();

# Up to this point, we have been buffering everything.
# Time to spit it all out.
switch ($GLOBALS["output_format"]) {
 case "text":
  foreach($GLOBALS["output_buffer"]["text"]["error"] as $text_line) {
   echo "Error: ".$text_line."\n";
   }
  foreach($GLOBALS["output_buffer"]["text"]["notice"] as $text_line) {
   echo $text_line."\n";
   }
  if(count($GLOBALS["output_buffer"]["text"]["data"])>0) {
   echo "-----------------------------------------------------------------------------\n";
   foreach($GLOBALS["output_buffer"]["text"]["data"] as $text_line) {
    echo $text_line."\n";
    }
   echo "-----------------------------------------------------------------------------\n";
   }
  if(isset($GLOBALS["output_buffer"]["text"]["table-row-colnames"][0])) {
   echo $GLOBALS["output_buffer"]["text"]["table-row-colnames"][0]."\n";
   }
  if(isset($GLOBALS["output_buffer"]["text"]["table-row"])) {
   foreach($GLOBALS["output_buffer"]["text"]["table-row"] as $text_line) {
    echo $text_line."\n";
    }
   }
  break;
 case "html":
  foreach($GLOBALS["output_buffer"]["html"] as $html_line) {
   echo $html_line;
   }
  break;
 }

# All done.
exit;

# ----------------------------------------------------------------------------
# == = = = = = = = = = = = = = = = END MAIN = = = = = = = = = = = = = = = = ==
# ----------------------------------------------------------------------------


# ----------------------------------------------------------------------------
# == = = = = = = = = = = = = = = SUBROUTINES  = = = = = = = = = = = = = = = ==
# ----------------------------------------------------------------------------

# Section Index:
# - Rights handling
# - Authentication and session handling
# - Account management
# - Null request handler
# - Row method handling functions
# - Section output start and stop
# - Content output functions (stuff that goes in sections)
# - Schema definition parsing functions
# - Handling generated data
# - Handling provided data via HTTP Request
# - HTTP "action" and "format" query string
# - Table/table form output support
# - Presentation support
# - Filename functions
# - Internal executable blacklist
# - Database access checking functions
# - Database read and write functions (high-level)
# - Database read and write functions (low-level)
# - Output buffer related
# - Error reporting, status checking, and notification
# - Initialization-related
# - Code I borrowed from somewhere else
# - Style sheet


# ----------------------------------------------------------------------------
# [ Rights handling ]
# Security model is mostly implemeted here.
# ----------------------------------------------------------------------------

#  "apprights"	=> "CREATE TABLE apprights (
#			 uid TEXT NOT NULL
#			,target_table_name TEXT NOT NULL
#			,target_row_identifier TEXT NOT NULL
#			,owns INTEGER NOT NULL
#			);",


function resolve_deleteby_to_owneridby($in_table,$in_target_row) {
# This is needed by the "delete_row" method because:
# - delete_row" HTTP method receives an identifier meant to match data in the
# column named by the "allow-delete-by" table schema attribute.
# - rights are keyed by an identifier in the "owner-identified-by" column.
# So this function will take an "allow-delete-by" identifier, find its row
# (hopefully there should be only one), and then return the value in the
# "owner-identified-by" column.

 $table_metadata=schema_rowattr($in_table.'/FOR_THIS_APP');
 if(!isset($table_metadata["allow-delete-by"])) { return $in_target_row; }
 if(!isset($table_metadata["owner-identified-by"])) { return $in_target_row; }

 $allow_delete_columnname=$table_metadata["allow-delete-by"];
 $owneridby_columnname=$table_metadata["owner-identified-by"];

 $sql="SELECT * FROM ".$in_table." WHERE ".$allow_delete_columnname." = :in_target";
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $statement->bindValue(":in_target",$in_target_row,SQLITE3_TEXT);
  mtrace("parameters: in_target=\"$in_target_row\"");
 $results=$statement->execute();
 $out="";
 while($row=$results->fetchArray(SQLITE3_ASSOC)) {
  $out=$row[$owneridby_columnname];
  break;
  }
 return $out;
 }


function check_rights($in_session,$in_table,$in_target_row,$in_action,$in_subaction="") {
# Returns true if uid in session is approved to perform the action.
# Issues merr()'s and returns false if right cannot be verified.
# - Superuser will always be approved.
 
 if(!isset($in_session["sid"])) { 
  merr("Authorization required, which only works if you are logged in");
  return false; 
  }

 $superuser_uid=intvar("superuser_uid");
 if($superuser_uid==="") {
  merr("Cannot read internal variable 'superuser_uid'");
  return false;
  }

 if($in_session["uid"]===$superuser_uid) { return true; }

 if($in_target_row==="NEW") {
  # User wants to create a new row.
  # We currently allow any user to do that.
  return true;
  }

 # Must find key column of $in_target_row
 $table_metadata=schema_rowattr($in_table.'/FOR_THIS_APP');
 if(!isset($table_metadata["owner-identified-by"])) {
  # Policy: Only superuser can do things to tables that don't take rights.
  merr("Not authorized - Only the superuser can perform this action on objects in tables that don't track rights"); 
  return false;
  }

 # TODO: Make this meaningful
 $find_owner_using=$table_metadata["owner-identified-by"];
 
 $existing_rights=read_table_filtered_rows("apprights","target_row_identifier",$in_target_row);
 if(any_db_error()) { 
  merr("Database error: unable to read apprights table");
  return false;
  }
 if(count($existing_rights)==0) {
  # Policy: Only superuser can do things to tables that have no rights assigned. 
  merr("Not authorized - Only the superuser can perform this action on objects with an empty rights table"); 
  return false;
  }
 $found_owns=0;
 foreach($existing_rights as $existing_right) {
  # Searching for any rights that represet ownership.
  if($existing_right["owns"]==1) {
   $found_owns=1;
   # authorized!
   if($in_session["uid"]===$existing_right["uid"]) { return true; }
   }
  }
 if($found_owns==0) {
  # Policy: Only superuser can do things to tables that don't have owners.
  merr("Not authorized - Only the superuser can perform this action on objects without owners"); 
  return false;
  } else {
  merr("Not authorized - you don't own this object"); 
  return false;
  } 
 }


function claim_rights($in_session,$in_table,$in_array_data) { 
# Adds a right to the apprights table, and some user-facing text describing
# it to the uicache table.  
# - Specifically, the right added is the "onwer" right.
# - Should be done when a new row is added to table.
# - Returns true if successful, merr()'s and returns false if it fails.

 if(!isset($in_session["sid"])) { 
  merr("Missing session ID - cannot claim rights");
  return false; 
  }

 # Must find key column of $in_target_row
 $table_metadata=schema_rowattr($in_table.'/FOR_THIS_APP');
 if(!isset($table_metadata["owner-identified-by"])) {
  # Policy: Only superuser can do things to tables that don't take rights.
  $superuser_uid=intvar("superuser_uid");
  if($superuser_uid==="") {
   merr("Cannot read internal variable 'superuser_uid'");
   return false;
   }
  if($in_session["uid"]===$superuser_uid) { return true; }
  merr("Not authorized - only the superuser can perform this action on unownable objects"); 
  return false;
  }

 $find_owner_using_this=$table_metadata["owner-identified-by"];

 $rights_data["uid"]=$in_session["uid"];
 $rights_data["target_table_name"]=$in_table;
 $rights_data["target_row_identifier"]=$in_array_data[$find_owner_using_this];
 $rights_data["owns"]=1;
 insert_row("apprights",$rights_data);
 if(any_db_error()) { 
  merr("Database error: unable to update apprights table");
  return false;
  }

 $uicache_data["objtype"]="rights";
 $uicache_data["objid0"]=$in_table;
 $uicache_data["objid1"]=$find_owner_using_this;
 $uicache_data["objid2"]=$in_array_data["$find_owner_using_this"];
 $uicache_data["uidata"]="owner: ".$in_session["appuser-uid"];
 insert_row("uicache",$uicache_data);
 if(any_db_error()) { 
  merr("Database error: unable to update apprights table");
  return false;
  }
 return true;

 }


function waive_rights($in_session,$in_table,$in_target_row) {
# Removes a right from the apprights table for the given table.
# Should be done when a row or user is deleted.
# Returns true if successful, merr()'s and returns false if it fails.

 if(!isset($in_session["sid"])) { 
  merr("Missing session ID - cannot waive rights");
  return false; 
  }

 # Must find key column of $in_target_row
 $table_metadata=schema_rowattr($in_table.'/FOR_THIS_APP');
 if(!isset($table_metadata["owner-identified-by"])) {
  # Policy: Only superuser can do things to tables that don't take rights.
  merr("Not authorized - Only the superuser can perform this action on objects in tables that don't track rights"); 
  return false;
  }

 # $find_owner_using_this=$table_metadata["owner-identified-by"];

 delete_row_bypass_schema("apprights","target_row_identifier",$in_target_row);
 if(any_db_error()) { 
  merr("Database error: unable to update apprights table");
  return false;
  }
 delete_row_bypass_schema("uicache","objid2",$in_target_row);
 if(any_db_error()) { 
  merr("Database error: unable to update apprights table");
  return false;
  }

 return true;

 }


# ----------------------------------------------------------------------------
# [ Authentication and session handling ]
# ----------------------------------------------------------------------------


function authenticate($in_username,$in_password,&$out_userinfo) {
# Returns UID (account number) if provided password authenticates.
# Otherwise returns null.
# Returns other attributes in $out_userinfo associative array.

 # NO authentication if app is disabled.
 # We should not even get here, but doesn't hurt to check.
 if($GLOBALS["disabled"]) { 
  merr("Logins can't be processed while the application is not accepting requests","hack");
  return "";
  }

 # Deal with any total garbage we happen to get.
 $wtf=false;
 if($in_password===""){ $wtf=true; }
 if($in_username===""){ $wtf=true; }
 if($wtf) {
  # Missing username/password should not happen because of HTML
  # validation.
  merr("Username or password is missing","hack");
  return "";
  }

 $need_to_update_user_record=false;

 $accepted=false;

 $existing_appuser=Array();
 $result=read_row_expecting_just_one($existing_appuser,"appusers","username",$in_username);
 if(any_db_error()) { 
  merr("Unable to read appusers table - if you keep seeing this message try again later");
  return "";
  }

 do {
  # If database returned no data, username doesn't refer to a user that exists.
  # We won't tell the end user that, though (see message issued below).
  if($result===false) { break; }

  # Look at current time versus last time there was an auth attempt on this account
  $seconds_since_last_attempt=time()-$existing_appuser["last_login_attempt"];
  if($seconds_since_last_attempt>60) {
   # Lower failed count by 1 for every 20 seconds that has passed.
   $n=$existing_appuser["failed_logins_consec"]-intdiv($seconds_since_last_attempt,20);
   if($n<0){$n=0;} # No lower than 0.
   $existing_appuser["failed_logins_consec"]=$n; # Set it.
   }

  # Do not accept if account is not enabled.
  # No need to update last login time or failed login counts in this case.
  # They're not going to get in.
  if($existing_appuser["enabled"]!=1) {
   break;
   }

  # Do not accept if too many consecutive failed logins.
  if($existing_appuser["failed_logins_consec"]>10) {
   # We don't increment the failed login counter here on purpose.
   # Prevents an attacker from making this value way too high.
   $existing_appuser["last_login_attempt"]=time();
   $need_to_update_user_record=true;
   break;
   }

  # Do not accept if password doesn't match.
  if(hash("sha512",$in_password)!==$existing_appuser["password"]) { 
   $existing_appuser["failed_logins_consec"]++;
   $existing_appuser["last_login_attempt"]=time();
   $need_to_update_user_record=true;
   break;
   }

  # If none of the above are true, we're good.
  $accepted=true;
  # Reset failed login count if needed.
  $existing_appuser["failed_logins_consec"]=0;
  $existing_appuser["last_login_attempt"]=time();
  $need_to_update_user_record=true;
  break;
  } while (false);

 # If the uid in the appuser table is "new_superuser", the database is new and
 # this would be the admin logging in for the first time.
 if(@$existing_appuser["uid"]==="new_superuser") {
  $need_to_update_user_record=true;
  $new_superuser_uid=guidv4();
   $existing_appuser["uid"]=$new_superuser_uid;
   $changed_rows["superuser_uid"]=$new_superuser_uid;
  update_row("internal",$changed_rows,"rowid",1);
  if(any_db_error()) {
   merr("Database error: unable to update internal table - if you keep seeing this message try again later");
   return "";
   }
  mnotice("This is a new instance of lsc.php! A new database has been successfully created");
  }

 # Note that user record is updated on failed login to update the failed login
 # count.
 if($need_to_update_user_record) {
  update_row("appusers",$existing_appuser,"username",$in_username);
  if(any_db_error()) {
   merr("Database error: unable to update appusers table - if you keep seeing this message try again later");
   return "";
   } 
  }

 if(!$accepted) { 
  merr("Login not accepted");
  return "";
  }

 $out_userinfo["force_password_reset"]=$existing_appuser["force_password_reset"];
 return $existing_appuser["uid"];
 }


function make_session($in_uid,&$session,$in_forcing_password_reset=false) {
# Creates a new session for the given UID.
# Assumes UID has been authenticated through authenticate().

 # NO sessions if app is disabled.
 # We should not even get here, but doesn't hurt to check.
 if($GLOBALS["disabled"]) { 
  merr("New sessions can't be created while the application is not accepting requests","hack");
  return "";
  }


 $existing_sessions=read_table_filtered_rows("sessions","uid",$in_uid);
 if(count($existing_sessions)>4) {
  merr("Too many active sessions - either log out of one of them or wait for one of them to expre");
  return; 
  }

 $new_session=Array( "uid"		=> $in_uid
		    ,"sid"		=> guidv4()
		    ,"created"		=> time()
		    ,"raw_session_tags"	=> ""
		   );
 $superuser_uid=intvar("superuser_uid");
 if($superuser_uid==="") {
  merr("Cannot read internal variable 'superuser_uid'");
  return;
 }

 # If the user we're making a session for is the superuser, we'll set the
 # "is_superuser" session tag.
 #
 # This flag should NOT be used to OK anything requiring superuser privileges.
 # It CAN be used to display something or to indicate a check should be done. 
 #
 # Confirming superuser privileges should be done by comparing the uid with 
 # the superuser_uid in the internal table only!
 if($in_uid===$superuser_uid) {
  add_session_tag($new_session,"tag_is_superuser",true);
  }
 if($in_forcing_password_reset) {
  add_session_tag($new_session,"tag_redirect_to_password_reset_form",true);
  }

 insert_row("sessions",$new_session); 
 if(any_db_error()) { 
  merr("Unable to create a new session.");
  return;
  }

 setcookie("sid",$new_session["sid"],(time()+86400),".");

 unpack_raw_session_tags($new_session);
 $session=$new_session;
 }


function connect_session($in_cookie_sid,&$session) {
# Checks if session exists, and if it does, if its valid.
# * If session is good, sid and uid entered into provided associative array
# and true is returned.
# * If session doesn't exist or isn't connected for some reason, an merr() may
# be generated and false is returned.

 # NO sessions if app is disabled.
 # We should not even get here, but doesn't hurt to check.
 if($GLOBALS["disabled"]) { 
  merr("Existing sessions can't be connected while the application is not accepting requests");
  return false;
  }

 $existing_session=Array();
 $result=read_row_expecting_just_one($existing_session,"sessions","sid",$in_cookie_sid);
 if(any_db_error()) { 
  merr("Unable to read session table.");
  return false;
  }
 # Bounce if sid not found in table.
 if($result===false) {
  mnotice("Session expired. Log in again.");
  # Invalidate just in case.
  invalidate_session($in_cookie_sid);
  return false;
  }
 # Don't use (and invalidate) session if expired.
 if(($existing_session["created"]+86400)<time()) {
  invalidate_session($in_cookie_sid);
  mnotice("Session expired. Log in again.");
  return false;
  }
 # Now see if uid exists in user table.
 $existing_user=Array();
 $result=read_row_expecting_just_one($existing_user,"appusers","uid",$existing_session["uid"]);
 if(any_db_error()) { merr("Unable to read appusers table."); return; }
 if($result===false) { merr("Your account was removed."); return; }

 $session=$existing_session;
 unpack_raw_session_tags($session);

 # populate outgoing session associative array with data from user table.
 $session["appuser-uid"]=$existing_user["username"];

 return true;
 }


function invalidate_session($in_cookie_sid) {
# Deletes row with matching sid from sessions table.
# merr() only issued if there is a db error.  

 setcookie("sid","",(time()+3600));
 delete_row_bypass_schema("sessions","sid",$in_cookie_sid);
 if(any_db_error()) { 
  merr("Database error: unable to update session table");
  return;
  }
 }


function kill_all_other_sessions($in_session=Array("uid"=>0)) {
# Deletes all rows with matching uid from session table, except the one that
# has the sid of the current session. 
# Returns false and issues an merr() if there is a problem.
# Returns true if no problems (only 1 sid in the table is not a problem).

 $sql="DELETE FROM sessions WHERE uid = :x AND NOT ( sid = :y )";
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $statement->bindValue(":x",$in_session["uid"],SQLITE3_TEXT);
 $statement->bindValue(":y",$in_session["sid"],SQLITE3_TEXT);
 $results=$statement->execute();
 if(any_db_error()) {
  merr("Database error: unable to update session table. No sessions were logged out");
  return false;
  }
 mnotice("Any other sessions were automatically logged out");
 return true;
 }


function modify_action_for_redirect(&$in_ACTION,$in_session) {
# Change $ACTION to a different action if a session tag indicates a redirect
# is needed. Used to implement forcing a user to reset their password.

 if(isset($in_session["tag_redirect_to_password_reset_form"])) {
  $in_ACTION="show_form_reset_my_password"; 
  clear_session_tag($in_session,"tag_upcoming_forced_password_reset");
  update_session_tags($in_session); 
  return true;
  }
 return false;
 }


function unpack_raw_session_tags(&$in_session) {
# Session tags are stored in the database as a string of characters.
# Goes through the packed session tags and creates a new key-value pair in
# the provided array for any found.

 for($i=0; $i<strlen($in_session["raw_session_tags"]); $i++) {
  switch ($in_session["raw_session_tags"][$i]) {
   case "r": $in_session["tag_redirect_to_password_reset_form"]=true; break;
   case "s": $in_session["tag_is_superuser"]=true; break;
   case "u": $in_session["tag_upcoming_forced_password_reset"]=true; break;
   default:
    mdebug("Unknown raw session tag \"".$in_session["raw_session_tags"][$i]."\" to session tags.");
    return; 
   }
  } 
 }


function add_session_tag(&$in_session,$in_new_tag,$dont_modify_array=false) {
# Adds a session tag to the current session, if it is not already there.
# Will indicate session was modified by setting $in_session["modified"].
# - This "modified" flag indicates the session needs to be updated back on the
#   database.
# Unless $dont_modify_array is true, then add_session_tag() doesn't do that.
# - $dont_modify_array will be true when making a new session.

 $raw_session_char="";
 if(!isset($in_session["raw_session_tags"])) { $in_session["raw_session_tags"]=""; }
 switch ($in_new_tag) {
  case "r": $raw_session_char="r"; break;
  case "tag_redirect_to_password_reset_form": $raw_session_char="r"; break;
  case "s": $raw_session_char="s"; break;
  case "tag_is_superuser": $raw_session_char="s"; break;
  case "u": $raw_session_char="u"; break;
  case "tag_upcoming_forced_password_reset": $raw_session_char="u"; break;
  default:
   mdebug("Attempt to add unknown tag \"".$in_new_tag."\" to session tags.");
   return; 
  }
 if(!$dont_modify_array){ $in_session[$in_new_tag]=true; }
 if(!str_contains($in_session["raw_session_tags"],$raw_session_char)) {
  $in_session["raw_session_tags"].=$raw_session_char;
  }
 if(!$dont_modify_array){ $in_session["modified"]=true; };
 }


function clear_session_tag(&$in_session,$in_remove_this_tag) {
# Removes a session tag from the current session, if it exists.
# Will indicate session was modified by setting $in_session["modified"].
# - This "modified" flag indicates the session needs to be updated back on the
#   database.

 $char_to_remove="";
 if($in_remove_this_tag==="tag_redirect_to_password_reset_form" or $in_remove_this_tag==="r") {
  $char_to_remove="r";
  unset($in_session["tag_redirect_to_password_reset_form"]);
  }
 if($in_remove_this_tag==="tag_is_superuser" or $in_remove_this_tag==="s") {
  $char_to_remove="s";
  unset($in_session["tag_is_superuser"]);
  }
 if($in_remove_this_tag==="tag_upcoming_forced_password_reset" or $in_remove_this_tag==="u") {
  $char_to_remove="u";
  unset($in_session["tag_upcoming_forced_password_reset"]);
  }
 if($char_to_remove==="") { return; }

 $new_session_tag_string="";
 for($i=0; $i<strlen($in_session["raw_session_tags"]); $i++) {
  if($in_session["raw_session_tags"][$i]!==$char_to_remove) {
   $new_session_tag_string.=$in_session["raw_session_tags"][$i];
   }
  }
 $in_session["raw_session_tags"]=$new_session_tag_string;
 $in_session["modified"]=true;
 }


function update_session_tags($in_session=Array("sid"=>0)) {
# Issues database operation to update session tags in database.
# If a database error occurs, reutrns false, otherwise returns true.

 # If no session provided, just bounce.
 if($in_session["sid"]==0) { return true; }
 # If session data wasn't modified, bounce.
 if(!isset($in_session["modified"])) { return true; }

 # Only updating raw_session_tags, managed by the functions above.
 $data_to_change["raw_session_tags"]=$in_session["raw_session_tags"];
 update_row("sessions",$data_to_change,"sid",$in_session["sid"]);
 if(any_db_error()) {
  merr("Database error: unable to update sessions table - account not updated");
  merr("If you attempt to log in later you may need to use the old password");
  return false;
  }
 return true;
 }


# ----------------------------------------------------------------------------
# [ Account management ]
# ----------------------------------------------------------------------------


function process_modusertable_request(&$in_session,$in_array_data) {
# Processes requests from the "User Management" form which allows creating,
# issuing commands targeting, and deleting user accounts.

 if($GLOBALS["output_format"]!=="html") { return; } # HTML format only.
 # Valid session required.
 if(!isset($in_session["appuser-uid"])) { 
  merr("Session expired - please log in to continue'"); 
  return;
  }
 # Only superuser can issue modusertable requests.
 $superuser_uid=intvar("superuser_uid");
 if($superuser_uid==="") {
  merr("Cannot read internal variable 'superuser_uid'"); 
  return false; 
  }
 if($in_session["uid"]!==$superuser_uid) {
  merr("Account doesn't meet requirements to make this request. This request was not processed.","hack");
  return false;
  }
   
 # Validate parameters of the modusertable_action.
 $validated=false;

 switch ($in_array_data["modusertable_action"]) { 
  # --------------------------------------------------------------------------
  case "new_user":
  # --------------------------------------------------------------------------
   # was username provided? ...
   if(!isset($in_array_data["modusertable_new_username"])) {
    merr("Missing parameter 'modusertable_new_username'","hack");
    return false;
    }
   # is username ...
   $tmp=$in_array_data["modusertable_new_username"];
   # not too long? ...
   if(strlen($tmp)>32) {
    merr("New username is too long - must be 31 characters or less");
    }
   # and only contains valid characters?
   $valid_username_characters='@_-0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
   for($i=0; $i<strlen($tmp); $i++) {
    if(str_contains($valid_username_characters,$tmp[$i])) { continue; }
    merr("New username has an invalid character - only letters, numbers, and the following: \"@\", \"_\", and \"-\"");
    break;
    } 
   if(any_errors()) { break; } # don't validate if any errors

   # Unsetting this because not needed for new user creation.
   # (needed for all other modusertable_action's though)
   unset($in_array_data["modusertable_existing_uid"]);

   $validated=true; break;
  # --------------------------------------------------------------------------
  case "sessions_delete_all":
  # --------------------------------------------------------------------------
   $validated=true; break;
  # --------------------------------------------------------------------------
  case "password_expire":
  # --------------------------------------------------------------------------
   $validated=true; break;
  # --------------------------------------------------------------------------
  case "password_reset":
  # --------------------------------------------------------------------------
   $validated=true; break;
  # --------------------------------------------------------------------------
  case "account_disable":
  # --------------------------------------------------------------------------
   $validated=true; break;
  # --------------------------------------------------------------------------
  case "account_enable":
  # --------------------------------------------------------------------------
   $validated=true; break;
  # --------------------------------------------------------------------------
  case "account_delete":
  # --------------------------------------------------------------------------
   $validated=true; break;
  # --------------------------------------------------------------------------
  case "pass_crown":
  # --------------------------------------------------------------------------
   $validated=true; break;
  # --------------------------------------------------------------------------
  default: 
  # --------------------------------------------------------------------------
   merr("Unknown user table modification request '".safe4html($in_array_data["modusertable_action"])."'","hack");
   break;
  }

 if(!$validated) { return false; }

 $need_to_update_user_record=false;

 $existing_appuser=Array();
 if(isset($in_array_data["modusertable_existing_uid"])) {
  $result=read_row_expecting_just_one($existing_appuser,"appusers","uid",$in_array_data["modusertable_existing_uid"]);
  if(any_db_error()) { 
   merr("Unable to read appusers table - if you keep seeing this message try again later");
   return false;
   }
  if($result===false) {
   return false;
   }
  } 

 # execute
 $modusertable_action=$in_array_data["modusertable_action"];
 $changes=Array();
 $success_messages=Array();

 switch ($modusertable_action) {
  # --------------------------------------------------------------------------
  case "new_user":
  # --------------------------------------------------------------------------
   # new name ...
   $new_username=$in_array_data["modusertable_new_username"];
   # new uid ...
   $new_uid=guidv4();
   # new password, which won't ever be used ...
   $new_user_password=random_password();
   # stage the data ...
   $new_user_data=Array( "uid"				=> $new_uid
		        ,"username"			=> $new_username
		        ,"password"			=> hash("sha512",$new_user_password)
		        ,"created"			=> time()
		        ,"force_password_reset"		=> "1"
		        ,"enabled"			=> "0"
		        ,"failed_logins_consec"		=> "0"
		        ,"last_login_attempt"		=> "0"
		        );
   insert_row("appusers",$new_user_data);
   if(any_db_error()) { return false; }
   $success_messages[]=safe4html($new_username)."'s account created";
   $success_messages[]="Initial password is <span class='tt'>".$new_user_password."</span>";
   $success_messages[]="Communicate this password to the user in a secure fashion. There is no way to get this password again once you leave this page"; 
   $success_messages[]="Also, note that account must be enabled before logins will work";
   break;
  # --------------------------------------------------------------------------
  case "password_reset":
  # --------------------------------------------------------------------------
   $need_to_update_user_record=true;
   $new_user_password=random_password();
   $changes["password"]=hash("sha512",$new_user_password);
   $success_messages[]=safe4html($existing_appuser["username"])."'s password has been reset to <span class='tt'>".$new_user_password."</span>";
   $success_messages[]="Communicate this password to the user in a secure fashion. There is no way to get this password again once you leave this page"; 
   # fall through
  # --------------------------------------------------------------------------
  case "password_expire":
  # --------------------------------------------------------------------------
   $need_to_update_user_record=true;
   $changes["force_password_reset"]=1; 
   $success_messages[]=safe4html($existing_appuser["username"])." must reset password at next login"; 
   break;
  # --------------------------------------------------------------------------
  case "account_disable":
  # --------------------------------------------------------------------------
   if($existing_appuser["uid"]===$superuser_uid) { 
    merr("The superuser account cannot be disabled");
    break;
    }
   $need_to_update_user_record=true;
   $changes["enabled"]=0;
   $success_messages[]=safe4html($existing_appuser["username"])."'s account is disabled - user can't log in anymore";
   # fall through
  # --------------------------------------------------------------------------
  case "sessions_delete_all":
  # --------------------------------------------------------------------------
   $sql="DELETE FROM sessions WHERE uid = :x AND NOT ( sid = :y )";
    mtrace("sql: \"$sql\"");
   $statement=$GLOBALS["dbo"]->prepare($sql);
   $statement->bindValue(":x",$existing_appuser["uid"],SQLITE3_TEXT);
   $statement->bindValue(":y",$in_session["sid"],SQLITE3_TEXT);
   $results=$statement->execute();
   if(any_db_error()) {
    merr("Unable to write session table. No sessions were logged out.");
    return false;
    }
   $success_messages[]="All sessions for ".safe4html($existing_appuser["username"])." were logged out";
   if($existing_appuser["uid"]===$in_session["uid"]) { 
    $success_messages[]="Except this session, of course.";
    }
   break;
  # --------------------------------------------------------------------------
  case "account_enable":
  # --------------------------------------------------------------------------
   $need_to_update_user_record=true;
   $changes["enabled"]=1; 
   $success_messages[]=safe4html($existing_appuser["username"])."'s account is enabled - user can now log in";
   break;
  # --------------------------------------------------------------------------
  case "account_delete":
  # --------------------------------------------------------------------------
   if($existing_appuser["enabled"]==1) {
    merr("Accounts must be disabled before being deleted");
    if($existing_appuser["uid"]===$superuser_uid) {
     mnotice("And you can't disable the superuser account, if you were thinking of trying that");
     }
    return false;
    }
   if($existing_appuser["uid"]===$superuser_uid) { 
    merr("The superuser account cannot be deleted","hack");
    return false;
    }
   $sql="DELETE FROM appusers WHERE uid = :x";
    mtrace("sql: \"$sql\"");
   $statement=$GLOBALS["dbo"]->prepare($sql);
   $statement->bindValue(":x",$existing_appuser["uid"],SQLITE3_TEXT);
   $results=$statement->execute();
   if(any_db_error()) {
    merr("Database error: unable to delete from appusers table. Try again later");
    return false;
    }
   $success_messages[]=safe4html($existing_appuser["username"])." account deleted";
   break;
  # --------------------------------------------------------------------------
  case "pass_crown":
   if($existing_appuser["uid"]===$superuser_uid) { 
    merr("This account is already superuser, so that was easy");
    return false;
    }
   if($existing_appuser["enabled"]==0) {
    merr("User must be enabled before you can make them superuser");
    }
   $new_superuser_uid=$existing_appuser["uid"];
   $inttable_changes["superuser_uid"]=$new_superuser_uid;
   update_row("internal",$inttable_changes,"rowid",1);
   if(any_db_error()) {
    merr("Database error: unable to update internal table. Try again later");
    }
   $result=kill_all_other_sessions($in_session);
    if($result===false) { break; } 
   invalidate_session($in_session["sid"]);
    if($result===false) { break; } 
   $success_messages[]=safe4html($existing_appuser["username"])." is now superuser - and will have to log out and back in to see that";
   break;
  # --------------------------------------------------------------------------
   break;
  }

  if($need_to_update_user_record) {
   update_row("appusers",$changes,"uid",$existing_appuser["uid"]);
   if(any_db_error()) {
    merr("Database error: unable to update appusers table. Try again later");
    return false;
    } 
   }

  foreach($success_messages as $message) {
   mnotice($message);
   }

  return true;
 }


function modify_my_account(&$session,$in_array_data) {
# Process account modification request.

 $user_data_changes=Array();
 $user_data_change_exists=false;
 $errorflag=false;
 
 if($in_array_data["reset_password"]==="on") {
  $user_data_change_exists=true;
  $user_data_changes["force_password_reset"]=1;
  add_session_tag($session,"tag_upcoming_forced_password_reset");
  $result=update_session_tags($session);
  if(!$result) { $errorflag=true; }
  }

 if($in_array_data["random_password"]==="on") {
  $new_password=random_password();
  $user_data_change_exists=true;
  $user_data_changes["password"]=hash("sha512",$new_password);
  }

 if($in_array_data["kill_sessions"]==="on") {
  $result=kill_all_other_sessions($session);
  if(!$result) { $errorflag=true; }
  }

 if($in_array_data["disable_account"]==="on") {
  # Check uid against superuser_uid in internal table
  $superuser_uid=intvar($in_variable_name);
  if($superuser_uid==="") {
   $errorflag=true;
  } else {
   if($session["uid"]===$superuser_uid) { 
    # No disabling the superuser.
    merr("Superuser account cannot be disabled.");
    $in_array_data["disable_account"]="blocked";
    } else {
    $user_data_change_exists=true;
    $user_data_changes["enabled"]=0;
    invalidate_session($session["sid"]);
    if(any_db_error()) {
     merr("Database error: unable to update session table - session may not be invalidated");
     $errorflag=true;
     }
    }
   }
  }
 
 if($errorflag) { return; }

 if($user_data_change_exists) {
  update_row("appusers",$user_data_changes,"uid",$session["uid"]);
   if(any_db_error()) { 
    merr("Database error: Unable to write appusers table - account not updated");
    return; 
    }
  }

 if($in_array_data["reset_password"]==="on") {
  mnotice("When you log in the next time you'll have to change your password");
  }
 if($in_array_data["random_password"]==="on") {
  mnotice("Your password has been set to <span class='tt'>".$new_password."</span>");
  mnotice("Add the above password to your password manager now - there is no way to get this password once you leave this page");
  }
 if($in_array_data["disable_account"]==="on") {
  mnotice("Account disabled. You will be automatically logged out. Thank you for using lsc.php!");
  }

 }


function modify_my_password(&$session, $in_old_password, $in_new_password) {
 $existing_appuser=Array();
 $result=read_row_expecting_just_one($existing_appuser,"appusers","uid",$session["uid"]);
 if(any_db_error()) { 
  merr("Unable to read appusers table - account not updated");
  return false;
  }

 $accepted=false;
 $other_issue=false;

 do {
  if($result===false) { 
   $other_issue=true;
   break;
   }
  if(hash("sha512",$in_old_password)!==$existing_appuser["password"]) { break; }
  if($existing_appuser["enabled"]!=1) {
   $other_issue=true;
   break;
   }
  if(hash("sha512",$in_new_password)===$existing_appuser["password"]) {
   merr("The new password you entered matches your old one - that won't count as a password reset");
   return false;
   }
  $accepted=true;
  } while (false);


 if(!$accepted) { 
  if($other_issue) {
   merr("Your account was removed or disabled while you were resetting the password. Password was not reset (assuming your account still exists)");
   invalidate_session(@$session["sid"]);
  } else {
   mnotice("Old password doesn't match records. Password was not reset");
   }
  return false;
  }

 $result=kill_all_other_sessions($session);
 if(!$result) { return false; }

 $existing_appuser["password"]=hash("sha512",$in_new_password);
 $existing_appuser["force_password_reset"]=0;
 update_row("appusers",$existing_appuser,"uid",$session["uid"]);
 if(any_db_error()) {
  merr("Unable to update appusers table. Account updates cannot be processed at this time. Whe you try later, if the new password doesn't work try the old one.");
  return false;
  }

 clear_session_tag($session,"tag_redirect_to_password_reset_form"); 
 $result=update_session_tags($session);
 if(!$result) { return false; }

 return true;
 }


function random_password() {
# Generates a random 8 character password.
# Not nation-state cryptographically secure but good enough,

 $characters='0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
 $new_password='';
 for($i=0; $i<8; $i++) {
  $index=rand(0,strlen($characters)-1);
  $new_password.=$characters[$index];
  }
 return $new_password;
 }


# ----------------------------------------------------------------------------
# [ Null request handler ]
# Called by main when the action is "show" and the table is "none."  Will
# dispatch or handle according to $GLOBALS["output_format"].
# ----------------------------------------------------------------------------


function null_request() { 
 switch ($GLOBALS["output_format"]) {
  case "text":
   textout("notice","Null request received, did nothing.");
   break;
  case "html":
   if(is_callable("HOMEPAGE")){
    HOMEPAGE();
   }else{
    htmlout("<p>Please select a table.</p>");
   }
  break;
  }
 }


# ----------------------------------------------------------------------------
# [ Row method handling functions ]
# ----------------------------------------------------------------------------


function row_method_action(
 $in_row_method,
 $in_table, $in_target, $in_target_table, $in_PARAMS,
 $in_ini, $in_session
 ) {
 # Dispatch call to row method, forwarding provided data as needed.

 # First, verify $in_table is even supposed to be in the database.
 $tables=tables_from_schemadef(); if(!is_table_known($in_table)){ return; }

 # Also, verify $in_target_table is even supposed to be in the database.
 if(!is_table_known($in_target_table)){ return; }

 # Get metadata of subject and target tables.
 $table_metadata=schema_rowattr($in_table.'/FOR_THIS_APP');
 $target_table_metadata=schema_rowattr($in_target_table.'/FOR_THIS_APP');

 # Get column number of subject row method's target.
 if(isset($table_metadata["each-row-method"])) {
  $row_method_list=Array();
  $row_method_list=explode(';',$table_metadata['each-row-method']);
  $row_method_not_found=true;
  # search for row method in table's list of methods and grab column number
  # when/if we find it.
  foreach($row_method_list as $row_method_data) {
   $row_method_params=Array();
   $row_method_params=explode(',',$row_method_data);
   $row_method_name=$row_method_params[0];
   if($row_method_name!=$in_row_method){ continue; }
   $row_method_not_found=false;
   break;
   }
  if($row_method_not_found) {
   merr(actnam($in_row_method)." is not a row method of ".tblnam($in_table).".","hack");
   return;
   }
  }else{
   merr(tblnam($in_table)." doesn't have any row methods.","hack");
   return;
  }

 # Invoke row method
 $function="ROWMETHOD_".$in_row_method."_".$in_table;
 if(is_callable($function)) {
  $function($in_table,$in_target,$in_target_table,$in_PARAMS,$in_ini,$in_session);
  return;
  } else {
  merr("nothing callable was found that handles '".actnam($in_row_method)."' of ".tblnam($in_table).".","bug");
  return;
  } 
 }


# ----------------------------------------------------------------------------
# Section output start and stop
# ----------------------------------------------------------------------------


function start_output($in_table,$session,$in_action) {
# Begin generating output.
# - For HTML, we'll start the html, body, and main tags, and take care of the
#   head element as well.
# - Javascript content comes from outer code, right before outer code calls
#   finish_output()
 $tbl="none"; if(isset($in_table)and($in_table!="")){ $tbl=$in_table; }

 switch($GLOBALS["output_format"]){
  case "text":
   # -------------------------------------------------------------------------
   # -------------------------------------------------------------------------
   break;
   # -------------------------------------------------------------------------
   # -------------------------------------------------------------------------
  case "html":
   # -------------------------------------------------------------------------
   # -------------------------------------------------------------------------
   htmlout("<!doctype html>");
   htmlout("<html>");
   htmlout("<head>");
   htmlout("<title>lsc.php 20240403</title>");
   style_sheet();
   htmlout("</head>");
   htmlout("<body>");
   htmlout("<main>");

   # App header
   # -------------------------------------------------------------------------
   htmlout("<div id='app-header'>");

   # App header title
   # -------------------------------------------------------------------------

   # Make app header title text red if trace or debug is enabled.
   $alert_style="";
   if((isset($GLOBALS["internal"]["trace"])) or isset($GLOBALS["internal"]["debug"])) {
    $alert_style="color: red;";
    }

   htmloutp("<table><tr><td><h2 style='".$alert_style."'>lsc</h2></td>");
   htmloutp("<td><h2 style='text-align: right;".$alert_style."'>".$GLOBALS["username"]."@".$GLOBALS["hostname"]."</span></h2></td></tr></table>",1);

   # App header / Account bar: shows currently logged in username and account
   # buttons
   # -------------------------------------------------------------------------
   htmlout("<table class='account-bar'>");

   if(!isset($session["uid"])) { 
    # What is displayed if there is not a session is super simple.
    # Account sign up button could be generated here if ever desired.
    htmlout("<td>Not Logged In");
    }

   if(isset($session["uid"])) { 
    # Output currently logged-in username.
    htmloutp("<td style='vertical-align: top;'>".safe4html($session["appuser-uid"]));
     # Handle any relevant session tags.
     if(isset($session["tag_is_superuser"])) { htmloutp("👑"); }
     if(isset($session["tag_upcoming_forced_password_reset"])) { htmloutp("✴️"); }
    htmloutp("</td>",1);
    # Account buttons.
    htmlout("<td style='vertical-align: top; text-align: right;'>");

    # Button to bring up user management form. 
    if(isset($session["tag_is_superuser"])) {
     # Superuser only.
     # Note that if this was hacked to display for non-superusers, it still
     # wouldn't work.
     htmlout("<form class='top1' action='".$GLOBALS["scriptname_out"]."' method=post>");
     htmlout("<input type='hidden' id='action' name='action' value='show_form_user_management'>");
     htmloutp("<button class='appuser-button'");
      # Button doesn't need to work if user is already looking at form.
      if($in_action=="show_form_user_management") { htmloutp(" disabled "); }
     htmloutp(">Admin</button>",1);
     htmlout("</form>");

     htmlout(" ");
     }

    # Button to bring up account management form. 
    htmlout("<form class='top1' action='".$GLOBALS["scriptname_out"]."' method=post>");
    htmlout("<input type='hidden' id='action' name='action' value='show_form_my_account'>");
    htmloutp("<button class='appuser-button'");
     # Button doesn't need to work if user is already looking at form.
     if($in_action=="show_form_my_account") { htmloutp(" disabled "); }
    htmloutp(">Account</button>",1);
    htmlout("</form>");

    htmlout(" ");

    # Button to log out.
    htmlout("<form class='top1' action='".$GLOBALS["scriptname_out"]."' method=post>");
    htmlout("<input type='hidden' id='action' name='action' value='logout'>");
    htmlout("<button class='appuser-button'>Log Out</button>");
    htmlout("</form>");
    }

   htmlout("</td>");
   htmlout("</table>");

   # Toplink box row: links to homepage and tables.
   # -------------------------------------------------------------------------
   htmlout("<table>");
   htmlout("<tr class='toplink-box-row'>");
   htmloutp("<td class='toplink-box ");
   if($tbl==="none") { htmloutp("toplink-selected'"); } else { htmloutp("toplink-not-selected'"); }
   htmloutp("><p><a class='toplink' href='".$GLOBALS["scriptname_out"]."'>home</a></p></td>",1);

   foreach($GLOBALS["schemadef"] as $tblcolname=>$colattrs) {
   if(!str_ends_with($tblcolname,"FOR_THIS_APP")) { continue; }
    $split_tblcolname=Array(); $split_tblcolname=explode('/',$tblcolname);
    $attrs=schema_rowattr($tblcolname);
    if(isset($attrs["toplink"])) {
     htmloutp("<td class='toplink-box ");
     if($split_tblcolname[0]===$tbl) { htmloutp("toplink-selected'"); } else { htmloutp("toplink-not-selected'"); }
     htmloutp("><p><a class='toplink' href='".$GLOBALS["scriptname_out"]."?table=".safe4html($split_tblcolname[0])."'>".safe4html($attrs["toplink"])."</a></p></td>",1);
     }
    }
   htmlout("</tr>");
   htmlout("</table>");

   # -------------------------------------------------------------------------
   htmlout("</div>");
   break; # /case "html";
   # -------------------------------------------------------------------------
   # -------------------------------------------------------------------------
  } # /switch;
 }

function output_messages() {
# Output anything shoved in the message stacks.
# - This would be any errors, notices, and action results generated when
#   processing actions or methods.

 switch ($GLOBALS["output_format"]) { 
  case "text":
   foreach($GLOBALS["outmsgs"]["errors"] as $M1){
    if($M1===""){ continue; }
    textout("error",$M1);
    }
   # Output notices.
   foreach($GLOBALS["outmsgs"]["notices"] as $M1){
    if($M1===""){ continue; }
    textout("notice",$M1);
    }
   # Output any extra goodies if there are any.
   if($GLOBALS["extra_goodies"]!=="") {
    foreach($GLOBALS["extra_goodies"] as $M1) {
     textout("data",$M1);
     }
    }
   break;
  case "html":
   # Output errors
   foreach($GLOBALS["outmsgs"]["errors"] as $M1){
    if($M1===""){ continue; }
    htmlout("<p>⚠️ ".$M1."</p>");
    }
   # Output notices.
   foreach($GLOBALS["outmsgs"]["notices"] as $M1){
    if($M1===""){ continue; }
    htmlout("<p>👀 ".$M1."</p>");
    }
   # Output buttons.
   foreach($GLOBALS["outmsgs"]["buttons"] as $M1){
    if($M1===""){ continue; }
    htmlout("<p>".$M1."</p>");
    }
   # Output any extra goodies if there are any.
   if($GLOBALS["extra_goodies"]!=="") {
    htmlout("<div>");
    htmlout($GLOBALS["extra_goodies"]);
    htmlout("</div>");
    }
   break;
  }
 }

# Functions that start and end panels.
# We use two - a content (left) panel and a history (right) panel.
# ----------------------------------------------------------------------------
function content_panel_start($in_which_table,$in_action) {
 if($GLOBALS["output_format"]!=="html") { return; } # HTML format only.
 htmlout("<div style='width: 70%; margin: 4px 4px 4px 4px; float: left;'>");
 if($in_which_table==="none") { 
  return;
 } else {
  $table_metadata=schema_rowattr($in_which_table.'/FOR_THIS_APP');
  content_top($table_metadata);
  }
 }
function content_panel_start_full($in_which_table,$in_action) {
 if($GLOBALS["output_format"]!=="html") { return; } # HTML format only.
 htmlout("<div style='border-style: solid; border-width: 4px; border-color: white; width: 70%; padding-left: 15%; padding-right: 15%; padding-bottom: 7.5%; padding-top: 3.25%; margin-left: -4px; float: left; background-color: lightgrey;'>");
 if($in_which_table==="none") { 
  return;
 } else {
  $table_metadata=schema_rowattr($in_which_table.'/FOR_THIS_APP');
  content_top($table_metadata);
  }
 }
function content_panel_end() {
 if($GLOBALS["output_format"]!=="html") { return; } # HTML format only.
 htmlout("</div>");
 }
function history_panel_start() {
 if($GLOBALS["output_format"]!=="html") { return; } # HTML format only.
 htmlout("<div style='margin: 4px 4px 4px 4px; overflow: hidden; background-color: #e0e0e0;'>");
 }
function history_panel_end() {
 if($GLOBALS["output_format"]!=="html") { return; } # HTML format only.
 htmlout("</div>");
 }

function content_top($in_table_metadata) {
 if($GLOBALS["output_format"]!=="html") { return; } # HTML format only.
 htmlout("<div>");
 htmlout("<table class='tablinks-title'>");
 if(isset($in_table_metadata["title"])) {
  $htmlout_title=safe4html($in_table_metadata["title"],128);
  htmlout("<tr colspan=2><td><p class='tablinks-title'>".$htmlout_title."</p></td></tr>");
  }
 htmlout("<tr>");
 if(isset($in_table_metadata["new-form-title"])) {
  htmlout("<td>");
  htmlout("<div class=\"tab\"><p class='tablinks-title'>");
  htmloutp("<button class='tablinks active' onclick='open_tab(event,\"view\");'>View</button> ");
  htmloutp("<button class='tablinks' onclick='open_tab(event,\"new\");'>Create New</button>");
  htmloutp("</p></div>");
  htmloutp("</td>",1);
  $GLOBALS["js"].="function open_tab(in_event, tab_name) {\n";
  $GLOBALS["js"].=" var i, tabcontent, tablinks;\n";
  $GLOBALS["js"].=" tabcontent=document.getElementsByClassName('tabcontent');\n";
  $GLOBALS["js"].=" for(i=0; i<tabcontent.length; i++) {\n";
  $GLOBALS["js"].="  tabcontent[i].style.display='none';\n";
  $GLOBALS["js"].=" }\n";
  $GLOBALS["js"].=" tablinks=document.getElementsByClassName('tablinks');\n";
  $GLOBALS["js"].=" for(i=0; i<tablinks.length; i++) {\n";
  $GLOBALS["js"].="  tablinks[i].className=tablinks[i].className.replace(' active', '');\n";
  $GLOBALS["js"].="  }\n";
  $GLOBALS["js"].=" document.getElementById(tab_name).style.display='block';\n";
  $GLOBALS["js"].=" in_event.currentTarget.className+=' active';\n";
  $GLOBALS["js"].=" };\n";
  } else {
  htmlout("<td></td>");
  }
  htmlout("</tr>");
  htmlout("</table>");
  htmlout("</div>");
 }

function finish_output() {
# Finish generating output.
# - For HTML, we'll wrap up our main, body, and html tags.

 switch($GLOBALS["output_format"]){
  case "html":
   htmlout("</main>");
   htmlout("</body>");
   htmlout("</html>");
   # If enabled, add debug messages toward end of HTML output as HTML
   # comments.
   if(isset($GLOBALS["internal"]["debug"])) {
     htmlout("<!--"); htmlout("Debug Messages:");
    foreach($GLOBALS["outmsgs"]["debug"] as $msg) {
     htmlout(safe4html($msg,32768));
     }
     htmlout(" -->");
    }
   # If enabled, add trace messages toward end of HTML output as HTML
   # comments.
   if(isset($GLOBALS["internal"]["trace"])) {
     htmlout("<!--"); htmlout("Trace Messages:");
    foreach($GLOBALS["outmsgs"]["trace"] as $msg) {
     htmlout(safe4html($msg,32768));
     }
     htmlout(" -->");
    }
   break;
  }
 }

# ----------------------------------------------------------------------------
# [ Content output functions - mostly HTML centered with other output formats 
#   kinda tacked on. ]
# ----------------------------------------------------------------------------

function usrmgmt_form_section_start() {
 if($GLOBALS["output_format"]!=="html") { return; }
 htmlout("<div style='display: block;' id='view' class='tabcontent'>");
 htmlout("<table class='non-editable-table'>");
 }
function usrmgmt_form_section_end() {
 if($GLOBALS["output_format"]!=="html") { return; }
 htmlout("</table>");
 htmlout("</div>");
 }
function usrmgmt_form_section_inbetween() {
 if($GLOBALS["output_format"]!=="html") { return; }
 htmlout("<br />");
 }
function usrmgmt_form_usercard($in_array_user_data,$in_is_superuser) {
  htmlout("<table class='non-editable-table'><tr>");
  htmloutp("<td class='form-column-header'>Username</td><td class='form-column-data' colspan=2>");
  htmloutp(safe4html($in_array_user_data["username"],24),1);
  if($in_is_superuser) { htmloutp("👑"); }
  if($in_array_user_data["enabled"]==0) { htmloutp("🚫"); }
  if($in_array_user_data["force_password_reset"]==1) { htmloutp("✳️"); }
  htmloutp("</td>",1);
  htmlout("</tr><tr>");
  htmlout("<td class='form-column-header'>Created</td><td class='form-column-data' colspan=2>".timestamp_to_string($in_array_user_data["created"])."</td>");
  htmlout("</tr><tr>");
  htmlout("<td class='form-column-header'>Last Login</td><td class='form-column-data' colspan=2>".timestamp_to_string($in_array_user_data["last_login_attempt"])."</td>");
  htmlout("</tr></table>");

 # htmlout("<table><tr>");
 # htmlout("<td class='form-column-header'>Enabled?</td><td class='form-column-data'>".$in_array_user_data["enabled"]."</td><td class='form-column-header'>Failed Login Counter</td><td class='form-column-data'>".$in_array_user_data["failed_logins_consec"]."</td>");
 # htmlout("</tr>");
 # htmlout("</table>");
 }

function usrmgmt_admin_form($session) {
# Output user management form.
# Only accessible by superuser.

 if($GLOBALS["output_format"]!=="html") { return; } # HTML format only.
 if(!isset($session["appuser-uid"])) { 
  htmlout("<p>Please log in to view this form.</p>");
  return;
  }
 if(!isset($session["tag_is_superuser"])) {
  htmlout("<p>Account doesn't meet the requirements to view this form.</p>");
  return;
  }
  
  # User Management Admin Form - Title
  htmlout("<div>");
  htmlout("<table class='tablinks-title'>");
  htmlout("<tr><td><p class='tablinks-title'>User Management</p></td></tr>");
  htmlout("</table>");
  htmlout("</div>");
  usrmgmt_form_section_inbetween();

  # User Management Admin Form - Create User
  htmlout("<form action='".$GLOBALS["scriptname"]."' method=post>");
  htmlout("<input type='hidden' id='action' name='action' value='adm_modify_user_table' />");
  htmlout("<input type='hidden' id='action' name='modusertable_action' value='new_user' />");
  usrmgmt_form_section_start();
  htmlout("<tr>");
  htmlout("<td class='form-column-header'><label for='username'>New Account Username</label</td>");
  htmlout("<td class='form-column-data'><input style='width: 98%;' type='text' id='modusertable_new_username' name='modusertable_new_username' required /></td>");
  htmlout("</tr>");
  htmlout("<tr><td colspan=2 class='rowmethod-container'><button class='rowmethod-button'>Create User Account</button></td></tr>");
  htmlout("<tr><td colspan=2 class='rowmethod-container'>Note: New accounts start off disabled.</td></tr>");
  usrmgmt_form_section_end();
  htmlout("</form>");

  usrmgmt_form_section_inbetween();
 
  usrmgmt_form_section_start();

  $appuser_list=read_table_all_rows("appusers");

  foreach($appuser_list as $appuser) {
  htmlout("<form action='".$GLOBALS["scriptname"]."' method=post>");
  htmlout("<input type='hidden' id='action' name='action' value='adm_modify_user_table' />");

  $htmlout_uid=safe4html($appuser["uid"]);
  htmlout("<input type='hidden' id='modusertable_existing_uid' name='modusertable_existing_uid' value='".$htmlout_uid."' />");
   htmlout("<tr>");

   htmlout("<td style='background-color: blue; text-align: right; width: 30%;'>");
   htmlout("<select class='usrmgmt-command-list' name='modusertable_action' id='modusertable_action'>");
    htmlout("<option class='usrmgmt-command' value='' selected='selected'>Select ...</option>");
    htmlout("<option class='usrmgmt-command' value='sessions_delete_all' >Clear All Sessions</option>");
    htmlout("<option class='usrmgmt-command' value='password_expire'	 >Expire Password</option>");
    htmlout("<option class='usrmgmt-command' value='password_reset'	 >Reset Password</option>");
    htmlout("<option class='usrmgmt-command' value='account_disable'	 >Disable</option>");
    htmlout("<option class='usrmgmt-command' value='account_enable'	 >Enable</option>");
    htmlout("<option class='usrmgmt-command' value='account_delete'	 >Permanently Delete</option>");
    htmlout("<option class='usrmgmt-command' value='pass_crown'		 >Make Superuser</option>");

   htmlout("</select>");
   htmlout("<button class='rowmethod-button'>Proceed</button>");
   htmlout("</td>");

   htmlout("<td>");
   $superuser_flag=false;
    if($appuser["uid"]===$session["uid"]) { $superuser_flag=true; }
   usrmgmt_form_usercard($appuser,$superuser_flag);
   htmlout("</td>");

   htmlout("</tr>");
   htmlout("</form>");
   }

  usrmgmt_form_section_end();

 }


function usrmgmt_form($session) { 
# Output account management form.
 if($GLOBALS["output_format"]!=="html") { return; } # HTML format only.

 if(!isset($session["appuser-uid"])) { 
  htmlout("<p>Please log in to view this form.</p>");
  return;
  }

 $sql2="SELECT * FROM appusers WHERE uid = '".$session["uid"]."'";
 $statement2=$GLOBALS["dbo2"]->prepare($sql2);
 $results2=$statement2->execute();
 $row2=$results2->fetchArray(SQLITE3_ASSOC);
 if ((is_bool($row2)) and (!$row2)) {
  htmlout("<p>No user record.</p>");
  return;
  }
 $userinfo=$row2;

 $sql2="SELECT * FROM sessions WHERE uid = '".$session["uid"]."'";
 $statement2=$GLOBALS["dbo2"]->prepare($sql2);
 $results2=$statement2->execute();
 $session_list=Array();
 while($row=$results2->fetchArray(SQLITE3_ASSOC)) { $session_list[]=$row; };

 $is_superuser=isset($session["tag_is_superuser"]);

  htmlout("<form action='".$GLOBALS["scriptname"]."' method=post>");
  htmlout("<input type='hidden' id='action' name='action' value='modify_my_account' />");

  output_table_noneditable_container_start();

  $htmlout_session_appuser_uid=safe4html($session["appuser-uid"],32);
  htmlout("<caption class='form-top'>Account Management (".$htmlout_session_appuser_uid.")</caption>");

  htmlout("<tr><td colspan=6>Created: ".timestamp_to_string($userinfo["created"])."</td></tr>");
  htmloutp("<tr><td colspan=6>");
   if($is_superuser){ htmloutp("👑"); }
  $htmlout_uid=safe4html($userinfo["uid"],64);
  htmloutp("UID: ".$htmlout_uid."</td></tr>",1);
  output_table_noneditable_container_end();

  htmlout("<br \>");

  output_table_noneditable_container_start();
 
  htmlout("<tr><td colspan=6>".count($session_list)." active session(s)</td></tr>");
  htmlout("<tr><td colspan=6><table>");
  foreach($session_list as $session_in_list) {
   htmloutp("<tr><td>- ".timestamp_to_string($session_in_list["created"]));
   if($session_in_list["sid"]===$session["sid"]) { htmloutp(" (this session)"); }
   htmloutp("</td></tr>",1); 
   }
  htmlout("</table></td></tr>");
  output_table_noneditable_container_end();

  htmlout("<br \>");

  output_table_noneditable_container_start();

  htmlout("<tr>");
  htmlout("<td class='form-column-header'><label for='reset_password'>Reset Password (Must Enter Old Password)</label></td>");
  htmlout("<td class='form-column-header'><label for='random_password'>Change Password To A Random Password</label></td>");
  htmlout("<td class='form-column-header'><label for='kill_sessions'>Log Out All Other Sessions</label></td>");
  if(!$is_superuser) {
   htmlout("<td class='form-column-header'><label for='disable_account'>Disable Account</label>");
  } else { 
   htmlout("<td style='color: red;' class='form-column-header'>This account is the superuser account.</td>");
   }
  htmlout("</tr><tr>");
  htmlout("<td><input style='width: 98%;' type='checkbox' id='reset_password' name='reset_password' /></td>");
  htmlout("<td><input style='width: 98%;' type='checkbox' id='random_password' name='random_password' /></td>");
  htmlout("<td><input style='width: 98%;' type='checkbox' id='kill_sessions' name='kill_sessions' /></td>");
  if(!$is_superuser) { 
   htmlout("<td><input style='width: 98%;' type='checkbox' id='disable_account' name='disable_account' /><br /></td>");
   }
  htmlout("</tr>");
  htmlout("<tr><td colspan=6 class='rowmethod-container'><button class='rowmethod-button'>Proceed</button></td></tr>");
  output_table_noneditable_container_end();

 }

function show_form_reset_my_password_form($session) {
# Password reset form.
 if($GLOBALS["output_format"]!=="html") { return; } # HTML format only.

 if(!isset($session["appuser-uid"])) { 
  htmlout("<p>Please log in to view this form.</p>");
  return;
  }

  htmlout("<form action='".$GLOBALS["scriptname"]."' method=post>");
  htmlout("<input type='hidden' id='action' name='action' value='modify_my_password' />");

  output_table_noneditable_container_start();

  htmlout("<caption style='color: red;' class='form-top'>Reset password to continue</caption>");
  htmlout("<tr>");
  htmlout("<td class='form-column-header'><label for='old_password'>Current Password</label</td>");
  htmlout("<td class='form-column-data'><input style='width: 98%;' type='password' id='old_password' name='old_password' required /></td>");
  htmlout("</tr>");
  htmlout("<tr>");
  htmlout("<td class='form-column-header'><label for='new_password'>New Password</label</td>");
  htmlout("<td class='form-column-data'><input style='width: 98%;' type='password' id='new_password' name='new_password' required /></td>");
  htmlout("</tr>");
  htmlout("<tr><td colspan=2 class='rowmethod-container'><button class='rowmethod-button'>Reset Password</button></td></tr>");

  output_table_noneditable_container_end();

  htmlout("</form>");

 }

function login_form() {
# Output login form
 if($GLOBALS["output_format"]!=="html") { return; } # HTML format only.

  htmlout("<form action='".$GLOBALS["scriptname"]."' method=post>");
  htmlout("<input type='hidden' id='action' name='action' value='login' />");

  output_table_noneditable_container_start();

  htmlout("<caption class='form-top'>Please Log In</caption>");
  htmlout("<tr>");
  htmlout("<td class='form-column-header'><label for='username'>Username</label</td>");
  htmlout("<td class='form-column-data'><input style='width: 98%;' type='text' id='username' name='username' required /></td>");
  htmlout("</tr>");
  htmlout("<tr>");
  htmlout("<td class='form-column-header'><label for='username'>Password</label</td>");
  htmlout("<td class='form-column-data'><input style='width: 98%;' type='password' id='password' name='password' required /></td>");
  htmlout("</tr>");
  htmlout("<tr><td colspan=2 class='rowmethod-container'><button class='rowmethod-button'>Log In</button></td></tr>");

  output_table_noneditable_container_end();

 }

function output_log($in_return_link) {
# Output "Action History" table.
 if($GLOBALS["output_format"]!=="html") { return; } # HTML format only.

 htmlout("<table class='non-editable-table'>");
 htmlout("<caption class='non-editable-table-caption'>Recent Actions</caption>");

 if(isset($GLOBALS["timezone"])) { 
  $htmlout_timezone=safe4html($GLOBALS["timezone"]);
  htmlout("<tr><td class='action-history-event'>Time Zone: ".$htmlout_timezone."</td></tr>");
  } else {
  htmlout("<tr><td class='action-history-event'>Dates/Times Are Server Local</td></tr>");
  }

 $loglines=Array(); 
 $loglines=read_table_all_rows("log");

 foreach(array_reverse($loglines) as $logline) {

  htmlout("<tr><td>");
  htmlout("<table class='action-history-container'>");
  htmlout("<tr>");
  htmlout("<td class='action-history-date'>".timestamp_to_string($logline["timestamp"])."</td>");
  htmlout("</tr>");
  htmlout("<tr style='border-color: black; border-style: solid;'>");
  $htmlout_logline=safe4html($logline["eventdesc"],512);
  htmlout("<td class='action-history-event'>".$htmlout_logline."</td>");
  htmlout("</tr>");
  if($logline["button_type"]!="none") {
   $function="BUTTONHTML_".$logline["button_type"];
   if(is_callable($function)) {
    htmlout($function($logline["button_type_target"]));
    } else {
    mdebug("No callable BUTTONHTML method available for button type '".$logline["button_type"]."'.");
    } 
   }

  htmlout("</table>");
  htmlout("</td></tr>");
  }

 if(count($loglines)>0) {
  htmlout("<tr><td class='logbutton-container'>");
  htmlout("<form action='".$in_return_link."' method=post>");
  htmlout("<input class='logbutton' type=submit value='Clear Recent History' />");
  htmlout("<input type='hidden' id='action' name='action' value='clear_logs' />");
  htmlout("</form>");
  htmlout("</td></tr>");
  }
 htmlout("</table>");
 } 


function output_table_noneditable($in_which_table,$in_rows_array) {
# Output a table, not designed for editing.

 # Get a list of columns that are supposed to be in this table, according to
 # the provided schema definition
 $cols=columns_from_schemadef($in_which_table);

 # Table metadata needed.
 $table_metadata=schema_rowattr($in_which_table."/FOR_THIS_APP");

 # Start generating pieces of the table.
 output_table_noneditable_container_start();
 output_table_noneditable_title($table_metadata["title"]);

 # Options flag - set if we need extra space for buttons - buttons such as
 # the delete button or row method buttons.
 $options=false;
 if($GLOBALS["output_format"]==="html") {
  # Options column is an HTML thing only.
  if(isset($table_metadata["allow-delete-by"])){ $options=true; }
  if(isset($table_metadata['each-row-method'])){ $options=true; }
  }

 # Get column headers (rendered as left side of table row)
 $headers=Array();
 $attrs_cols=Array();
 foreach($cols as $col) {
  $attrs_cols[$col]=schema_rowattr($in_which_table.'/'.$col);
  $headers[$col]=$attrs_cols[$col]["form-label"];
  }

 # We're gonna generate some stuff in a bit.
 $deferred=Array();

 # Get uicache data.
 $uicache=read_table_filtered_rows("uicache","objid0",$in_which_table);
 # TODO: handle DB error
 # $uicache_data["objtype"]="rights";
 # $uicache_data["objid0"]=$in_table;
 # $uicache_data["objid1"]=$find_owner_using_this;
 # $uicache_data["objid2"]=$in_array_data["$find_owner_using_this"];
 
 # Process results from query ...
 #

 # Loop through each row of the table ...
 foreach($in_rows_array as $row) {
  #
  # and loop through each column of the row.
  $first_col_flag=true;
  foreach($cols as $col) {
   # Get column attributes from table schema.
   $attrs=$attrs_cols[$col];
   # Skip columns with "dont-show" specified.
   if(isset($attrs["dont-show"])){ unset($headers[$col]); continue; }
   # Skip columns that just hold default values for other tables.
   if(isset($attrs["provides-defaults"])){ unset($headers[$col]); continue; }
   #
   # If this is a confirmation key column then we actually want the user to 
   # provide it when executing a row method.  This means we generate an input
   # field and defer output until the form element is being output later on
   # down.
   if(isset($attrs["is-confirmation-key-for"])){
    $deferred[$attrs["is-confirmation-key-for"]]="<div><input style='text-align: center; width: 80%;' type='text' id='".$col."' name='".$col."' placeholder='".$attrs["confirmation-placeholder"]."'/></div>\n";
    continue;
    }

   # Do we need to use another table to output this data?
   if(!isset($attrs["display-using-other-table"])){
    # Nothing special if not ...
    $data_to_show=make_presentable($row[$col],$attrs["data"]);
    }else{
    # If "display-using-other-table" is defined ...
    # that means we don't want to show the raw data value from the table's
    # row.  The data "points to" data in another table.  So we need to do a
    # select from that table and gather that data to show instead.
     # There are four related attributes that should also be present when we
     # are are used when we're doing this.
     $select_this=$attrs["display-sql-SELECT"];
     $from_this=$attrs["display-sql-FROM"];  
     $where_this=$attrs["display-sql-WHERE"]; 
     $is_this_from_original_table=$attrs["display-sql-IS"]; 
     # Issue the SQL request we need to get the data.
     # Need to use 2nd database object as we are already using the first one.
     # Code outside the calling function should have this object ready.
     #echo $select_this." FROM ".$from_this." WHERE ".$where_this." = '".$row[$is_this_from_original_table];
     $sql2="SELECT ".$select_this." FROM ".$from_this." WHERE ".$where_this." = '".$row[$is_this_from_original_table]."'";
      mtrace("sql2: \"$sql2\"");
     $statement2=$GLOBALS["dbo2"]->prepare($sql2);
     $results2=$statement2->execute();
     $row2=$results2->fetchArray(SQLITE3_ASSOC);
     if ((is_bool($row2)) and (!$row2)) {
      $data_to_show="[Object missing]";
     }else{
      # Pointed-to data may consist of multiple fields.
      # So we have to be able to handle multiple columns here.
      $spacer=''; $assembled='';
      foreach($row2 as $individual) {
       $assembled.=$spacer.$individual; 
       if($spacer==='') { $spacer='; '; }
       }
      $data_to_show=$assembled;
      }
     }

   # Present the data to show from the row's column.
   if($first_col_flag) {
    $first_col_flag=false; 
    } else {
    output_table_noneditable_row_inbetween();
    }
   output_table_noneditable_row($attrs,$headers[$col],@$data_to_show);
   }

  # Handle uicache data here.
  htmlout("</tr>");
  htmlout("<tr>");
  htmlout("<td colspan=2 style='color: red; text-align: right; background-color: pink; font-size: 0.8rem;'>");
  $uicache_first="";
  foreach($uicache as $uicache_item) {
   if($uicache_first!=="") { htmloutp($uicache_first); }
   if($uicache_first==="") { $uicache_first===" | "; }
   if($uicache_item["objid2"]==$row[$uicache_item["objid1"]]) {
    htmloutp(safe4html($uicache_item["uidata"]));
    }
   }
  htmloutp("</td>",1);
  htmlout("</tr>");

  # Handle options column here.
  if($options) {
    htmlout("<td colspan=2 class='rowmethod-container'>");
   if(isset($table_metadata["allow-delete-by"])) {
    $target=$row[$table_metadata["allow-delete-by"]];
    $noun="";
    if(isset($table_metadata["friendly-object-name"])) {
     $noun=" ".$table_metadata["friendly-object-name"];
     }
    htmlout("<form action='".$GLOBALS["scriptname"]."' method=post>");
    htmlout("<input class='rowmethod-button' type=submit value='Delete".$noun."' />");
    htmlout("<input type='hidden' id='action' name='action' value='delete_row' />");
    htmlout("<input type='hidden' id='table' name='table' value='".$in_which_table."' />");
    htmlout("<input type='hidden' id='target' name='target' value='".$target."' />");
    htmlout("</form>");
   }
   if(isset($table_metadata['each-row-method'])) {
    $row_method_list=Array();
    $row_method_list=explode(";",$table_metadata["each-row-method"]);
    foreach($row_method_list as $row_method) {
     $row_method_params=Array();
     $row_method_params=explode(",",$row_method);
     $target=$row[$row_method_params[2]];
     htmlout("<form style='display: inline;' action='".$GLOBALS["scriptname"]."' method=post>");
     htmlout("<input class='rowmethod-button' type=submit value='".$row_method_params[1]."' />");
     htmlout("<input type='hidden' id='action' name='action' value='row_method_action' />");
     htmlout("<input type='hidden' id='row_method' name='row_method' value='".$row_method_params[0]."' />");
     htmlout("<input type='hidden' id='table' name='table' value='".$in_which_table."' />");
     htmlout("<input type='hidden' id='target' name='target' value='".$target."' />");
     if(isset($deferred[$row_method_params[0]])){ htmlout($deferred[$row_method_params[0]]); }
     htmlout("</form>");
     }
    }
   htmlout("</td>");
   }

  # Finish outputting row.
  output_table_noneditable_row_end();
  }

 # Finish outputting table.
 if( !isset($table_metadata["single-row-only"])
  or !isset($table_metadata["single-row-only-empty-message"]) ) {
  output_table_noneditable_bottom(count($in_rows_array)." object(s)");
  }else{
   if(count($in_rows_array)==0){
    output_table_noneditable_bottom($table_metadata["single-row-only-empty-message"]);
    }
   }
  output_table_noneditable_column_names($headers);
  output_table_noneditable_container_end();
 }


function output_table_noneditable_container_start() {
 if($GLOBALS["output_format"]!=="html") { return; }
 htmlout("<div style='display: block;' id='view' class='tabcontent'>");
 htmlout("<table class='non-editable-table'>");
 }
function output_table_noneditable_title($in_title="Objects") {
 switch ($GLOBALS["output_format"]) { 
  case "text":
   textout("table",$in_title);
   break;
  case "html":
   if($in_title!=="") {
    $htmlout_title=safe4html($in_title,128);
    htmlout("<caption class='form-top'>Current ".$htmlout_title."</caption>");
    }
   break;
  }
 }
function output_table_noneditable_row($attrs=Array(),$header,$data="") {
 switch ($GLOBALS["output_format"]) {
  case "text":
   $text="";
   if($data==="") { $text="NO_DATA"; } else { $text="\"".$data."\""; }
   textoutp("table-row",$text);
   break; # /case "text":
  case "html":
   $html="";
   if($data==="") { 
    $html="<span class='no-data'><i>No data</i></span>";
    } else {
    $html=safe4html($data,32768);
    }
   switch (@$attrs["present-width"]) {
    case "full-width": 
     htmlout("<tr>");
     htmlout("<td colspan=2 class='form-column-header-full'>".$header."</td>");
     htmlout("</tr>");
     htmlout("<tr>");
     htmlout("<td colspan=2 class='form-column-data-full'>".$html."</td>");
     htmlout("</tr>");
     break; # /case "full-width":
    default:
     htmlout("<tr>");
     htmlout("<td class='form-column-header'>".$header."</td>");
     htmlout("<td class='form-column-data'>".$html."</td>");
     htmlout("</tr>");
    } # /switch (@$attrs["present-width"])
   break; # /case "html":
  } # /switch ($GLOBALS["output_format"]) 
 }
function output_table_noneditable_row_inbetween() {
 switch ($GLOBALS["output_format"]) {
  case "text":
   textoutp("table-row",",");
   break;
  }
 }
function output_table_noneditable_row_end() {
 if($GLOBALS["output_format"]==="text") { 
  textoutp("table-row","",2);
  return;
  }
 if($GLOBALS["output_format"]!=="html") { return; }
 htmlout("</tr>");
 htmlout("<tr><td colspan=2></td></tr>");
 }
function output_table_noneditable_bottom($message) {
 switch ($GLOBALS["output_format"]) {
  case "text":
   textout("notice",$message);
   break;
  case "html":
   $htmlout_message=safe4html($message,256);
   htmlout("<td colspan=2 style='text-align: right'>".$htmlout_message."</td>");
   break;
  }
 }
function output_table_noneditable_column_names($in_array_column_names) {
 switch ($GLOBALS["output_format"]) {
  case "text":
   $first=true;
   foreach($in_array_column_names as $key=>$colname) {
    if($first) {
     $first=false;
     } else {
     textoutp("table-row-colnames",",");
     }
    $htmlout_colname=safe4html($colname,128);
    textoutp("table-row-colnames","\"".$htmlout_colname."\"");
    }
   textoutp("table-row-colnames","",2);
  }
 }
function output_table_noneditable_container_end() {
 if($GLOBALS["output_format"]!=="html") { return; }
 htmlout("</table>");
 htmlout("</div>");
 }


function output_new_form($in_which_table,$in_rows_count,$in_ini) {
 # get a list of columns that are supposed to be in this table, according to
 # the provided schema definition.
 # A database object ($GLOBALS["dbo"]) is needed to generate lists, if
 # described by the schema.
 if($GLOBALS["output_format"]!=="html") { return; } # HTML format only.

 $cols=columns_from_schemadef($in_which_table);

 # Begin generating our form
 
 # Containing div.
 htmlout("<div style='display: none;' id='new' class='tabcontent'>");

 if($GLOBALS["readonly"]) {
  htmlout("<p>Database is in read-only mode.</p>");
  htmlout("</div>");
  return false;
  }

 # We use metadata expected to be in "{table_name}/FOR THIS APP"
 $table_metadata=schema_rowattr($in_which_table."/FOR_THIS_APP");

 # Table attributes that prevent creating new rows.
 # "single-row-only" ...
 if(isset($table_metadata["single-row-only"]) and $in_rows_count==1){ 
  htmlout("<p>This table can only hold one object. Remove the object in order to create a new one.</p>");
  htmlout("</div>");
  return false;
  }
 # "row-must-exist-in" ...
 if(isset($table_metadata["row-must-exist-in"])) {
  $tmp=Array();
  $tmp=read_table_all_rows($table_metadata["row-must-exist-in"]);
  if(count($tmp)==0) {
   if(isset($table_metadata["must-exist-in-fails-message"])) {
    $htmlout_message=$table_metadata["must-exist-in-fails-message"];
    htmlout("<p>".$htmlout_message."</p>");
    htmlout("</div>");
    return false;
    }
   }
  }

 # Get HTML form validators
 $validators=Array();
 $validators=get_html_form_validators($in_which_table);

 # Holds default values 
 $default_value_providers=Array();
 $default_value_providers_reverse=Array();

 # See if we need to pull in any default values from another table.
 if(isset($table_metadata["defaults-provided-by"])) {
  # All right, we do.
  new_form_defaults_from_table($in_which_table,
			       $table_metadata,
			       $default_value_providers,
			       $default_value_providers_reverse
			       );
  }

 # Form header.
 htmlout("<form action='".$GLOBALS["scriptname"]."' method=post>");
 htmlout("<input type='hidden' id='action' name='action' value='new_row' />");
 htmlout("<input type='hidden' id='table' name='table' value='".$in_which_table."' />");

 htmlout("<table class='non-editable-table'>");
 $htmlout_new_form_title=safe4html($table_metadata["new-form-title"]);
 htmlout("<caption class='form-top'>".$htmlout_new_form_title."</caption>");

 $something_wrong=false;
 #
 # Generate fields for each column.
 #
 foreach($cols as $col) {
  #
  # Get column attributes from provided schema definition.
  $attrs=schema_rowattr($in_which_table."/".$col);
  #
  # Don't generate a form field for columns where the data is application
  # generated.  End user doesn't need to enter data for those.
  if($attrs["injourney"]==="app-generates"){continue;}
  if($attrs["injourney"]==="row-method"){continue;}
  #
  # Generate ID
  $id=$in_which_table."_".$col;
  #
  $hook1="";
  if(isset($table_metadata["defaults-here-keyed-by"]) and $table_metadata["defaults-here-keyed-by"]===$col){
   $hook1.="onChange='populate_defaults();'";
   }
  
  # Generate HTML needed to service "present-width" schema attribute.
  $apply_header="";
  $apply_between="";
  $apply_body="";
  switch (@$attrs["present-width"]) { 
   case "full-width":
    $apply_header="class='form-column-header-new-full' colspan=2";
    $apply_between="</tr>\n<tr>\n";
    $apply_body  ="class='form-column-data-new-full' colspan=2";
    break;
   default:
    $apply_header="class='form-column-header-new'";
    $apply_between="";
    $apply_body  ="class='form-column-data-new'";
   } 
  
  # Start row by emitting label (if "dont-show" flag is not set)
  if (!isset($attrs["dont-show"])) { 
   htmlout("<tr>");
   htmlout("<td ".$apply_header."><label for='".$id."'>".$attrs["form-label"]."</label></td>"); 
   htmlout($apply_between);
   }
  #
  # Emit input element based on injourney attribute of column.
  $injourney_info=parse_out_injourney_info($attrs);
  switch($injourney_info["basemethod"]) {
   case "text": {
    # Populate emitted element with default value, if there is one.
    $tmp="";
    if(isset($attrs["default-value"])) {
     $tmp="value='".safe4html($attrs["default-value"],32768)."'";
     }
    if(isset($attrs["default-value-from-ini"])) {
     $tmp="value='".safe4html($in_ini["defaults"][$attrs["default-value-from-ini"]],32768)."'";
     }
    htmlout("<td ".$apply_body."><input style='width: 98%;".$validators[$col]." ".$hook1." type='text' id='".$id."' name='".$id."' ".$tmp."/></td>");
    break;
    }
   case "list": {
    $list_data=Array();
    # Get list data - according to "sub" method.
    switch($injourney_info["submethod"]) {
     case "othertable": {	# List items comes from another table.
      # If this column must have a unique value in each row, then we should
      # only present values in the list that aren't already used.
      if(isset($attrs["must-be-unique"])) {
       $statement=$GLOBALS["dbo"]->prepare("SELECT ".$col." FROM ".$in_which_table);
       $results=$statement->execute();
       $existing=Array();
       while($row=$results->fetchArray(SQLITE3_ASSOC)) { $existing[]=$row[$col]; }
       $list_data=read_table_all_rows_keyed_cols_minus_existing($injourney_info["table"],$injourney_info["column_target_name"],$injourney_info["column_display_name"],$existing);
       } else {
       # Much simpler if this requirement does not exist, of course.
       $list_data=read_table_all_rows_keyed_cols($injourney_info["table"],$injourney_info["column_target_name"],$injourney_info["column_display_name"]);
       }
      break;
      }
     case "ini": {		# List items come from the ini file data.
      $tmp=$injourney_info["ini-section"];
      $tmp2=$injourney_info["ini-name"];
      foreach ($in_ini[$tmp][$tmp2] as $key=>$value) {
       $list_data["targets"][]=$value;
       $list_data["display_names"][]=$key;
       }
      }
      break;
     case "schema": {		# List items come from schema
      $array_tmp=explode(",",$attrs["this-list"]);
      foreach ($array_tmp as $tmp) {
       $tmp2=explode("=",$tmp);
       $list_data["targets"][]=$tmp2[0];
       $list_data["display_names"][]=$tmp2[1];
       }
      }
      break;
     } # still in case "list" btw
    # If "dont-show" is set for this column, we will convert the list to a
    # hidden input element.  This is desired if the column is a pointer to a
    # single-row-only table.
    if (isset($attrs["dont-show"])) {
     htmlout("<input type='hidden' name='".$id."' id='".$id."' value='".safe4html($list_data["targets"][0],256)."' />");
     }
    # Otherwise emit input element.
    if (!isset($attrs["dont-show"]) and count($list_data["targets"])==0) {
     $something_wrong=true;
     htmlout("<td ".$apply_body."><p>None available.</p></td>");
     }
    if (!isset($attrs["dont-show"]) and count($list_data["targets"])!=0) {
     htmlout("<td ".$apply_body."><select style='width: 100%;' ".$hook1." name='".$id."' id='".$id."'>");
     htmlout("<option value='' disabled selected hidden>(select one)</option>");
     $n=0;
     foreach ($list_data["targets"] as $list_datum) {
      htmlout("<option value='".safe4html($list_datum,256)."'>".safe4html($list_data["display_names"][$n],512)."</option>");
      $n++;
      }
     htmlout("</select></td>");
     }
    break;
    }
   }
   # End row (if "dont-show" flag is clear)
   if (!isset($attrs["dont-show"])) { htmlout("</tr>"); }
  }
 #
 # Finish out form.
 #
 $noun="";
 if(isset($table_metadata["friendly-object-name"])) {
  $noun=" ".safe4html($table_metadata["friendly-object-name"],64);
  }
 if(!$something_wrong) {
  htmlout("<tr><td colspan=2 class='rowmethod-container'><button class='rowmethod-button'>Create new".$noun."</button></td></tr>");
  }else{
  htmlout("<tr><td colspan=2 class='rowmethod-container'>A new ".$noun." can't be created; see above.</td></tr>");
  }
 htmlout("</table>");
 htmlout("</form>");
 htmlout("</div>");
 }


function new_form_defaults_from_table($in_which_table,
				      $in_table_metadata,
				      $out_default_value_providers,
				      $out_default_value_providers_reverse) {
# So what we need now is to get all possible default values in that other
# table.  Then generating Javascript code to make it accessible to other
# Javascript code that will populate the form with defaults, as the end user
# changes the key.

 # So, here's the start of that Javascript - a new array.
 $GLOBALS["js"].="var new_row_defaults = new Array();\nfunction set_defaults() {\n";
 #
 # Now, look through global schema for columns that provide a default value
 $wanted_cols=Array($in_table_metadata["defaults-in-provider-keyed-by"]);
 foreach($GLOBALS["schemadef"] as $key=>$value) {
  # Schema definition lines start with the table name.
  if(!(str_starts_with($key,$in_table_metadata["defaults-provided-by"]))) { continue; }
  # found one ...
  $tmp=Array(); $tmp=explode('/',$key); 
  # We only want columns that provide a default value for the current table
  # ... table we are generating a form for, that is.
  # So, let's look more closely at its schema definition ...
  $tmp_attrs=schema_rowattr($key);
  # Does the table even hold default values?
  if(!isset($tmp_attrs["provides-defaults"])) { continue; }   
  # It does?  Well, does it hold default values for THIS table?
  $deftbl=$tmp_attrs["gives-default-for-table"];
  if($deftbl!=$in_which_table) { continue; }
  # All right, if it does, pile the table/column name on the list of value
  # providers.
  $defcol=$tmp_attrs["gives-default-for-column"];
  $out_default_value_providers[$defcol]=$tmp[1]; 
  # ... just because I don't want to write a for loop to search this
  # associative array later.
  $out_default_value_providers_reverse[$tmp[1]]=$defcol;
  # we also need the value for the database lookup coming up.
  $wanted_cols[]=$tmp[1];
  }
 # OK, time to make a query to that table and gather the default values, and
 # we generate the Javascript that populates the array accordingly.
 $rows_with_default_data_cols=Array();
 $rows_with_default_data_cols=read_table_all_rows_multiple_cols($in_table_metadata["defaults-provided-by"],$wanted_cols);
 foreach($rows_with_default_data_cols as $row_with_default_data_col) {
  foreach($row_with_default_data_col as $key=>$value) {
   if($key===$in_table_metadata["defaults-in-provider-keyed-by"]){
    $GLOBALS["js"].=" new_row_defaults.push( { switcher_value:\"".$value."\", fill_fields:[ ";
    }
   }
  $c="";
  foreach($row_with_default_data_col as $key=>$value) {
   if($key!=$in_table_metadata["defaults-in-provider-keyed-by"]){
    $GLOBALS["js"].=$c."{ name:\"".$in_which_table."_".$out_default_value_providers_reverse[$key]."\", ";
    $GLOBALS["js"].="value:\"".$value."\" }";
    if($c==""){$c=", ";}
    }
   }
  $GLOBALS["js"].=" ] } ); \n";
  }
 $GLOBALS["js"].=" }; set_defaults();\n";
 $GLOBALS["js"].="function populate_defaults() {\n";
 $GLOBALS["js"].=" var x=document.getElementById('".$in_which_table."_".$in_table_metadata["defaults-here-keyed-by"]."');\n";
 $GLOBALS["js"].=" for(const element of new_row_defaults) {\n";
 $GLOBALS["js"].="  if(element.switcher_value===x.options[x.selectedIndex].value) {\n";
 $GLOBALS["js"].="   for(const field of element.fill_fields) {\n";
 $GLOBALS["js"].="     var y=document.getElementById(field.name);\n";
 $GLOBALS["js"].="     y.value=field.value;\n";
 $GLOBALS["js"].="    }\n";
 $GLOBALS["js"].="   }\n";
 $GLOBALS["js"].="  }\n";
 $GLOBALS["js"].=" }; populate_defaults();\n";
 }


# ----------------------------------------------------------------------------
# [ Schema definition parsing functions ]
# ----------------------------------------------------------------------------


function sql_to_make_table_from_schemadef($in_which_table) {
# Generate a SQL statement to create a table, from schema definition.
# Outer code is responsible for actually executing it.
#
 $out_sql="CREATE TABLE ".$in_which_table." (";
 $first_flag=false;
 # Loop through all schema definition keys.
 foreach($GLOBALS["schemadef"] as $tablecol=>$packedattrs) {
  $tmp_array=explode("/",$tablecol);
  # Schema definition keys with FOR_THIS_APP are data for this app, not for
  # the database.
  if($tmp_array[1]==="FOR_THIS_APP"){ continue; }
  # Schema definition keys that don't have FOR_THIS_APP - keep going.
  $tmp_table=$tmp_array[0];
  # Is the current schema definition key concern the table we want?
  if($tmp_table===$in_which_table) {
   # Emit a comma if this isn't the first key we're processing.
   if($first_flag==false){
    $first_flag=true;
   }else{
    $out_sql.=", ";
    }
   $out_sql.=$tmp_array[1];
   # Emit certain SQL depending on what validator attributes say is valid.
   $attrs=Array();
   unpack_attrs($packedattrs,$attrs);
   switch($attrs["type"]) {
    case "str": $out_sql.=" TEXT"; break;
    case "int": $out_sql.=" INTEGER"; break;
    }
   switch($attrs["req"]) {
    case "y": $out_sql.=" NOT NULL"; break;
    }
   }
   if(isset($attrs["must-be-unique"])) { $out_sql.=" UNIQUE"; }
  }
 $out_sql.=" )";
 return $out_sql;
 }


function tables_from_schemadef() {
# Get list of all tables from global schema definition

 static $out_array_tablelist_cached=Array();
 $out_array_tablelist=Array();

 # Loop through all global schema definition key-value pairs.
 if(count($out_array_tablelist_cached)!=0) {
  return $out_array_tablelist_cached;
  }

 foreach($GLOBALS["schemadef"] as $tblcolname=>$colattrs) {
   # Key-value pair format is 'table_name/column_name'.
   $tmp_array=explode('/',$tblcolname);
   $n=$tmp_array[0];
   # Add table name to outgoing array, if we haven't seen it before.
   if(!(in_array($n,$out_array_tablelist))){ $out_array_tablelist[]=$n; }
   }
  return $out_array_tablelist;
 }


function is_table_known($in_table) {
# Returns true or false - is table defined in schema?
# Issues an merr() if not defined, unless table is "none".
 # Don't issue message for "none", but do say we don't know the table.
 if($in_table==="none"){ return false; }
 # Otherwise verify against schema and report accordingly.
 $tables=tables_from_schemadef();
 if(in_array($in_table,$tables)){ return true; }
 merr("Table '".$in_table."' isn't in this database.","hack");
 return false;
 }


function schema_rowattr($schema_row) {
# Unpacks a column's attributes into an associative array and returns that.
# Caches unpacked column data so OK to call over and over for the same column.
 static $unpacked=Array();

 if(isset($unpacked[$schema_row])) {
  return $unpacked[$schema_row];
  }

 $unpacked_line=Array();
 unpack_attrs($GLOBALS["schemadef"][$schema_row],$unpacked_line); 
 $unpacked[$schema_row]=$unpacked_line;
 return $unpacked[$schema_row];
 }


function unpack_attrs($in_schemadef_line,&$out_array_attrs) {
# Unpack a packed validator attribute list from a global schema definition
# line into an associative array.
# - Unpacking runs trim() on the contents.

 $out_array_attrs=Array();
 $tmp_array=explode('/',$in_schemadef_line);
 # Loop through all '/'-separated sections in schema definition key.
 foreach($tmp_array as $tmp) {
  # Each section has a 'name:value' pair that must be further separated.
  $tmp_array_2=explode(':',$tmp);
  if(isset($tmp_array_2[1])){
   $out_array_attrs[$tmp_array_2[0]]=trim($tmp_array_2[1]);
  }else{
   $out_array_attrs[$tmp_array_2[0]]="";
   }
  }

 }


function columns_from_schemadef($in_table) {
# Get list of all columns of a table from schema definition.

 $out_array_columnlist=Array();
 # Loop through all schema definition keys.
 foreach($GLOBALS["schemadef"] as $key=>$value) {
  # Key format is 'table_name/column_name'.
  # Separate it because we just want the table name.
  $tmp_array=explode("/",$key);
  # Key format is 'table_name/column_name'.
  # If the left side matches the table we're wanting, add the right side to
  # the array being output.
  if($tmp_array[1]==="FOR_THIS_APP"){continue;}
  if($tmp_array[0]===$in_table) { $out_array_columnlist[]=$tmp_array[1]; }
  }
  return $out_array_columnlist;
 }


function parse_out_injourney_info($in_attrs) {
# Break down "in journey" data into an associative array.
# - Needs all attributes because some "in journeys" use pointers defined
#   outside of the "injourney:" option.
# - "In journey" refers to who provides the data and how the data is obtained.

 if(!isset($in_attrs["injourney"])) { return Array(); }
 $in_injourney_value=$in_attrs["injourney"];
 $tmp=Array();
 if(str_starts_with($in_injourney_value,"app-generated")) {
  $tmp["basemethod"]='none';
  }
 if(str_starts_with($in_injourney_value,"user-enters-text")) {
  $tmp["basemethod"]='text';
  if(str_starts_with($in_injourney_value,"user-enters-text-for")) {
   $tmp["context"]=substr($in_injourney_value,21);
   }
  }
 if(str_starts_with($in_injourney_value,"user-selects-from")) {
  $tmp["basemethod"]="list";
  if(str_starts_with($in_injourney_value,"user-selects-from-list-in-other-table")) {
   $tmp["submethod"]="othertable";
   $tmp["table"]=$in_attrs["is-pointer-to"];
   $tmp["column_target_name"]=$in_attrs["pointer-links-by"];
   $tmp["column_display_name"]=$in_attrs["shown-by"];
   }
  if(str_starts_with($in_injourney_value,"user-selects-from-ini-list")) {
   $tmp["submethod"]="ini";
   $tmp["ini-section"]=$in_attrs["ini-list-section"];
   $tmp["ini-name"]=$in_attrs["ini-list-array"];
   }
  if(str_starts_with($in_injourney_value,"user-selects-from-this-list")) {
   $tmp["submethod"]="schema";
   }
  }
 if(str_starts_with($in_injourney_value,"row-method")) {
  $tmp["basemethod"]="none";
  }
 return $tmp;
 }


# ----------------------------------------------------------------------------
# [ Handling generated data ]
# ----------------------------------------------------------------------------


function fill_data_array_from_app_generated_injourneys(
 $in_which_table, &$out_array_data, $in_PARAMS,
 $in_ini, $in_session
 ) {
# Goes through $in_PARAMS, looks for query strings whose "in journey" is
# "app-generates" and makes a generator call to get the data.
#
# Both this function and fill_data_array_from_query_string are intended to
# collect data to the same array, $out_array_data (by reference).
#
# Will add errors to $GLOBALS["outmsgs"] if a generator call fails.
# Assumes table exists.

 # Check table metadata ...
 $table_metadata=schema_rowattr($in_which_table.'/FOR_THIS_APP');

 # All right, loop through all columns as they appear in the provided schema
 # definition.  For columns whose data is app generated, we make a generator
 # call and get that data, or report an error if the generator reports a
 # failure.
 $columns=columns_from_schemadef($in_which_table);
 foreach($columns as $col) {
  $attrs=schema_rowattr($in_which_table.'/'.$col);
  # call generator if needed to fill in this value.
  if($attrs["injourney"]==="app-generates") {
   $value='';
   $result=generate_app_value($value,
			      $in_which_table,
			      $col,
			      $attrs,
			      $in_PARAMS,
			      $in_ini,
			      $in_session);
   if(!($result)) {
    merr("Failed.");
    }else{
    $out_array_data[$col]=$value;
    }
   }
  }
 }


function generate_app_value(
 &$returned_value,
 $in_which_table,
 $in_which_col,
 $in_array_col_attrs,
 $in_PARAMS,
 $in_ini,
 $in_session
 ) {
# When inserting a new row, some values are provided by the request and
# others are provided by code - my name for this code is a "generator."
# generate_app_value() will call the generator and forward back the results.
# - Generator name taken from $in_array_col_attrs["data"], this should come
#   from the global schema definition.
# - Built-in generators are handled here.
 
 $method=trim($in_array_col_attrs["data"]);
 # So, let's see if a callable exists.
 $function="GENERATOR_".$method;
 if(is_callable($function)){
  return $function($returned_value,
		   $in_which_table,
		   $in_which_col,
		   $in_array_col_attrs,
	           $in_PARAMS,
		   $in_ini,
		   $in_session
		   ); 
  }
 # otherwise ... check if method is built-in.
 # for example: we can make UUIDs ourselves.
 # notice since we check for a callable, callables can override built-ins.
 # that is on purpose. :)
 switch ($method) {
  case "uuid":
   $returned_value=guidv4();
   return true;
   break;
  }
  $returned_value="no generator in code found for ".$in_array_col_attrs["data"]." data."; 
  flag("bug");
  return false;
 }


# ----------------------------------------------------------------------------
# [ Handling provided data via HTTP request ]
# ----------------------------------------------------------------------------


function fill_data_array_from_query_string(
 $in_which_table, $in_PARAMS, &$out_array_data
 ) {
# Goes through $in_PARAMS, looks for query strings that match the columns in
# $in_which_table, and puts them in $out_array_data.  $in_PARAMS would
# normally just be $_POST or similar.
#
# Will add errors to $GLOBALS["outmsgs"] if a column can't be matched with a query
# string parameter (and the column's "in journey" is not "app-generates").
#
# - Assumes table exists.

 # Check table metadata ...
 # (This is redundant if fill_data_array_from_app_generated_journeys() is
 # called first.  Could be removed.)
 #
 $table_metadata=schema_rowattr($in_which_table.'/FOR_THIS_APP');

 # All right, loop through all columns as they appear in the provided schema
 # definition.  For columns that have a matching key in $in_PARAMS, we copy
 # that data to $out_array_data.
 #
 # If no key is found for a column, and that column's data is not app generated
 # then we report an error.
 # 
 # Yes, fill_data_array_from_app_generated_iourneys() should be called first.
 #
 $columns=columns_from_schemadef($in_which_table);
 # Let's loop through each column, from the provided schema definition.
 foreach($columns as $col) {
  $attrs=schema_rowattr($in_which_table.'/'.$col);
  # Does $in_PARAMS have a key with a value for this column?
  if(isset($in_PARAMS[$in_which_table."_".$col])) {
   $out_array_data[$col]=$in_PARAMS[$in_which_table."_".$col];
   continue;
   }
  # If it does not
  # That may be OK if the "in journey" for that column is app-generated.
  # If it is not app-generated, we need to register an error, though.
  if ($attrs["injourney"]==="row-method") { 
   $out_array_data[$col]='';
   continue;
   }
  if (!(isset($out_array_data[$col]))) {
   if($attrs["injourney"]!="app-generates") {
    merr("query string doesn't contain data for '".$col."'.","bug_or_hack");
    }
   }
  }
 }


function validate_data_array(
$in_which_table, &$out_array_data
 ) {
# Assumes $in_which_table is known to be in the database already.

 # All right, loop through all columns as they appear in the provided schema
 # definition.
 $columns=columns_from_schemadef($in_which_table);
 foreach($columns as $col) {
  $attrs=schema_rowattr($in_which_table.'/'.$col);
  # Only validate columns with populated data.
  if(!isset($out_array_data[$col])) { continue; }
  # Test against validator "req" ...
  if(($out_array_data[$col]==="") and $attrs["req"]==="n") { continue; }
  if(($out_array_data[$col]==="") and $attrs["req"]==="y") {
   merr("Nothing specified for ".colnam($col)." but a value is required.");
   continue;
   }

  # if there is a custom validator for this type, call it.
  $custom_validator="VALIDATOR_".$in_which_table."_".$col;
  if(is_callable($custom_validator)) { 
   $result=$custom_validator($out_array_data[$col]);
   if(!$result) {  
    merr("Invalid data for ".colnam($col).".");
    continue; 
    }
   }

  # Test against validator "type" and related "sub" validators ...
  switch ($attrs["type"]) {
   case "str":
    if(isset($attrs["maxlen"])) {
     if(strlen($out_array_data[$col])>$attrs["maxlen"]) { $ef=true; merr(colnam($col)." is more than ".$attrs["maxlen"]." character(s)."); }
     }
    if(isset($attrs["minlen"])) {
     if(strlen($out_array_data[$col])<$attrs["minlen"]) { $ef=true; merr(colnam($col)." is less than ".$attrs["minlen"]." character(s)."); }
     }
    break;
   case "int":
    if(isset($attrs["maxval"])) {
     if($out_array_data[$col]>$attrs["maxval"]) { $ef=true; merr(colnam($col)." is more than ".$attrs["maxval"]."."); }
     }
    if(isset($attrs["minval"])) {
     if($out_array_data[$col]<$attrs["minval"]) { $ef=true; merr(colnam($col)." is less than ".$attrs["minval"]."."); }
     }
    break;
   }
  # if value was selected from a list, make sure it exists.
  if((str_starts_with($attrs["injourney"],"user-selects-from-available"))) {
   $injourney_info=parse_out_injourney_info($attrs);
   $tmp2=substr($attrs["injourney"],28);
   $tmp_array=explode('!',$tmp2);
   $tmp["table"]=$tmp_array[0];
   $tmp["column_target_name"]=$tmp_array[1];
   $tmp["column_display_name"]=$tmp_array[2];
    if ( ! (does_value_exist_in_col($injourney_info["table"],$injourney_info["column_target_name"],$injourney_info["column_display_name"],$out_array_data[$col]) )) {
    merr("The ".tblnam($in_which_table)." requires that new rows have a valid pointer to a ".colnam($injourney_info["column_target_name"])." in ".tblnam($injourney_info["table"]).", but none was found.");
    }
   }
  }
 }


 function find_and_sanitize_incoming(
 $in_POST=Array(),$in_GET=Array(),&$in_out_SAFE_PARAMS
 ) {
# How this works:
#  *** First:
#  &$in_out_SAFE_PARAMS is an associative array reference..
#  &$in_out_SAFE_PARAMS's keys should be query string parameters you are
#   expecting.
#  This function will loop through &$in_out_SAFE_PARAMS, extract matching keys'
#   values from $in_POST, sanitize them, and then put the sanitized values in
#   &$in_out_SAFE_PARAMS.
#  Errors or problems are reported in $GLOBALS["outmsgs"].
#  Missing values are an error unless the key in &$in_out_SAFE_PARAMS is set
#   to 'optional'.
#  $in_POST should be $_POST.
#  *** Then the above is done for $in_GET, but only for supported parameters.
#  And only if the parameters weren't defined in $in_POST.

 foreach($in_out_SAFE_PARAMS as $key=>$value) {
  if(isset($in_POST[$key])) {
   $in_out_SAFE_PARAMS[$key]=$in_POST[$key];
   sanitize_app_parameter($in_out_SAFE_PARAMS[$key]);
   }else{
   if(!($value="optional")) {
    merr("Missing query string parameter '".$key."'");
    }
   }
  }
 if(isset($in_GET["table"])) {
  if(
     !(isset($in_out_SAFE_PARAMS["table"]))
       or
      ($in_out_SAFE_PARAMS["table"]==="")
       or
      ($in_out_SAFE_PARAMS["table"]==="none")
      ) {
   $in_out_SAFE_PARAMS["table"]=$in_GET["table"];
   sanitize_app_parameter($in_out_SAFE_PARAMS["table"]);
   }
  }
 }


function sanitize_app_parameter(&$parameter) {
# Sanitize incoming "app parameters" - which used by the app itself.  Examples
# would be the "action" query string.
# Will modify or erase $parameter if needed.
 $tmp=trim($parameter);
 # TODO: check for length, illegal characters, etc.
 $parameter=$tmp;
 }


# ----------------------------------------------------------------------------
# [ HTTP "action" and "format" query string ]
# ----------------------------------------------------------------------------


function validate_action($in_qsvar_action) {
# Normalize the incoming 'action' query string, making sure it has only 
# valid characters and is a valid length (32 characters or less).
# - Whitelisting of valid action values can be done here.
# - Default action if action query string is null, blank, or not specified is
#   determined here (currently "show").
# - Also outright replace it and issue mnotice()'s' if certain conditions
#   exist, for safety or administrative reasons.
# set_globals() should be called first.

 if(posix_getuid()==0){
  merr("This application will not process requests when running as uid 0.");
  return "uid_0_muzzle";
  }
 if($GLOBALS["disabled"]){
  mnotice("This application is currently not processing requests.");
  return "disabled";
  }
 if($GLOBALS["readonly"]){
  mnotice("This application is currently in read-only mode.");
  }
 $out_action="show";
 if(isset($in_qsvar_action)) {
  $tmp=trim(strtolower(substr($in_qsvar_action,0,32)));
  $out_action=$tmp;
  }
  return $out_action;
 }


function validate_output_format($in_qsvar_format) { 
# Normalize the incoming 'format' query string, making sure it has only 
# valid characters and is a valid length (16 characters or less).
# - Whitelisting of valid "format" values is done here.
# - Default action of "format" query string is null, blank, or not specified
#   is determined here (currently "html")
# - Will forcibly return "html" and issue an mdebug() for unsupported formats.
# set_globals() should be called first.
 if(isset($in_qsvar_format)) {
  $tmp=trim(strtolower(substr($in_qsvar_format,0,16)));
   if($tmp==="html") { return "html"; }
   if($tmp==="text") { $out_format="text"; return "text"; }
   mdebug("unsupported output format \".$in_qsvar_format.\" requested","hack");
  }
  return "html";
 }


# ----------------------------------------------------------------------------
# [ Table / table form output support ]
# ----------------------------------------------------------------------------


function get_html_form_validators( $in_which_table ) {
# Assumes we accept $in_which_table as known to be in the database already.
 $columns=columns_from_schemadef($in_which_table);
 $out_validators=Array();
 foreach($columns as $col) {
  $attrs=schema_rowattr($in_which_table.'/'.$col);
  $out_validators[$col]="";
  # Validator "req".
  if($attrs["req"]==="y") {
   $out_validators[$col].=" required placeholder='{required}'";
   }
  # Validator "type" and related.
  switch ($attrs["type"]) {
   case "str":
    if(isset($attrs["maxlen"])) { $out_validators[$col].=" maxlength=".$attrs["maxlen"]; }
    if(isset($attrs["minlen"])) { $out_validators[$col].=" minlength=".$attrs["minlen"]; }
    break;
   case "int":
    $out_validators[$col].=" type='number'";
    if(isset($attrs["maxval"])) { $out_validators[$col].=" max=".$attrs["maxval"]; }
    if(isset($attrs["minval"])) { $out_validators[$col].=" min=".$attrs["minval"]; }
    break;
   }
  }
  return $out_validators;
 }


# ----------------------------------------------------------------------------
# [ Presentation support ]
# ----------------------------------------------------------------------------


function set_report_names_for_insert($in_which_table,$in_data_array) {
 $GLOBALS["report"]["target_objectname"]="'".$in_which_table."' object";
 $GLOBALS["report"]["target_instancename"]="instance";
 if(!isset($GLOBALS["schemadef"][$in_which_table."/FOR_THIS_APP"])) { return; }
 $attrs=schema_rowattr($in_which_table."/FOR_THIS_APP");
 if(isset($attrs["friendly-object-name"])) {
  $GLOBALS["report"]["target_objectname"]=$attrs["friendly-object-name"];
  if(isset($in_data_array[@$attrs["instance-friendly-name-is"]])) {
   $GLOBALS["report"]["target_instancename"]=$in_data_array[$attrs["instance-friendly-name-is"]];
   }
  }
 }


function set_report_names_for_delete($in_which_table,$in_delete_target) {
# Returns false if evidence suggests $in_delete_target isn't deletable.

 $GLOBALS["report"]["target_objectname"]="'".$in_which_table."' object";
 $GLOBALS["report"]["target_instancename"]="'".$in_delete_target."'";
 if(!isset($GLOBALS["schemadef"][$in_which_table."/FOR_THIS_APP"])) {
  merr("No metadata for ".tblnam($in_which_table).".","bug");
  return false;
  }
 $attrs=Array();
 $attrs=schema_rowattr($in_which_table."/FOR_THIS_APP");
 if(isset($attrs["friendly-object-name"])) {
  $GLOBALS["report"]["target_objectname"]=$attrs["friendly-object-name"];
  }
 if(!isset($attrs["allow-delete-by"])) { 
  merr("Delete requests involving ".tblnam($in_which_table)." are not processed through this interface. The request was not processed.","hack");
  return false;
  }
 if(isset($attrs["instance-friendly-name-is"])) {
  $gotten_row=Array();
  $result=read_row_expecting_just_one($gotten_row,$in_which_table,$attrs["allow-delete-by"],$in_delete_target);
  if($result===false) {
   $GLOBALS["report"]["target_instancename"]="(Missing Object)";
   mnotice("This was already deleted. If you are confused look at the Action History.");
   return false;
  } else { 
   $GLOBALS["report"]["target_instancename"]=$gotten_row[$attrs["instance-friendly-name-is"]];
   }
  }
  return true;
 }


function safe4html($in_destined_for_html,$in_max_length=256) {
 if(($in_destined_for_html)==="") { return ""; }

 $tmp="";
 if(strlen($in_destined_for_html)>$in_max_length) {
  $tmp=substr($in_destined_for_html,0,$in_max_length)."...";
  } else {
  $tmp=$in_destined_for_html;
  }
 return htmlspecialchars($tmp);
 }


function make_presentable($in_data,$in_type) {
 $out_data="";
 switch ($in_type) {
  case "pid":
   if($GLOBALS["output_format"]==="html") { $out_data="<span class='presenting-pid'>"; }
   $out_data.="PID".$in_data;
   if($GLOBALS["output_format"]==="html") { $out_data.="</span>"; }
   break;
  case "uuid":
   if($GLOBALS["output_format"]==="html") { $out_data="<span class='presenting-uuid'>"; }
   $out_data.="UUID".$in_data;
   if($GLOBALS["output_format"]==="html") { $out_data.="</span>"; }
   break;
  case "date":
   if($in_data==="") { 
    $out_data="[No timestamp]";
   } else {
    $out_data=timestamp_to_string($in_data);
   }
   break;
  default: 
   $out_data=$in_data;
  }
  return $out_data;
 }


function tblnam($in_table_name) {
 return "table '".$in_table_name."'";
 }


function colnam($in_col_name) {
 return "column '".$in_col_name."'";
 }

function actnam($in_action_name) {
 return "action '".$in_action_name."'";
 }


function timestamp_to_string($in_timestamp) {
# * Uses $GLOBALS["tz"].
# Converts timestamp to date('m/d/Y h:i:s a').

 if(!isset($GLOBALS["tz"])) {
  return date('m/d/Y h:i:s a', $in_timestamp);
  } else { 
  # https://stackoverflow.com/questions/12038558/php-timestamp-into-datetime
  # Timezone is IGNORED when using timestamp. WTF.
  $date=date_create('@'.$in_timestamp+$GLOBALS["utc_offset"]);
  return date_format($date,'m/d/Y h:i:s a');
  }
 }


# ----------------------------------------------------------------------------
# [ Filename functions ]
# ----------------------------------------------------------------------------


function path_merge($path1,$path2) {
# Merge two paths, making sure there is only one slash in between.

 $merged=rtrim($path1,"/")."/".ltrim($path2,"/");
 return $merged;
 }


function make_filename_ready($in_string) {
# Take incoming string and make it a well behaved filename.

 if(is_null($in_string)) { return guidv4(); }
 $out_string="";
 for($i=0;$i<strlen($in_string);$i++){
  if(str_contains('\\/?* @$&:;,.><|',$in_string[$i])) {
   $out_string.='_';
   } else { 
   $out_string.=$in_string[$i];
   }
   if($i>40){ break; } # Max 40 chars.
  }
  return $out_string;
 }


# ----------------------------------------------------------------------------
# [ Internal executable blacklist ]
# ----------------------------------------------------------------------------


function is_blacklisted($in_command) {
 mtrace("is_blacklisted(command:$in_command)");

 # Allow processed before deny
 $allow_dirs_starting_with=Array("/etc/lsc/","/opt","/usr/bin","/usr/local/");
 $deny_dirs_specifically=Array("/");
 $deny_dirs_starting_with=Array("/boot/","/dev/","/etc/","/proc/","/root/","/srv/","/sys/","/usr","/var/");
 $deny_exes_specifically=Array(":(){:|:&};:","adduser","addgroup","bash","cat","csh","chmod","chown","chsh","cp","crontab","curl","dash","dd","echo","emacs","fish","halt","ifconfig","ip","iptables","ip6tables","insmod","kexec","LD_PRELOAD","ldd","ls","mv","nano","ps","poweroff","route","rm","rmdir","sh","useradd","wget","vi","vim","zsh");
 $deny_exes_containing=Array("^","mkfs","group","gshadow","passwd");
 # Split $in_command into path and executable.
 $in_command_2=strtolower(trim($in_command));
 $dir="";$exe="";
 if(!str_contains($in_command,"/")) {
  $dir=""; $exe=$in_command;
  }else{
  $split=strrpos($in_command,"/");
  $dir=substr($in_command,0,$split+1);
  $exe=substr($in_command,$split+1);
  }
 # Directory must begin with a slash.
 if(!str_starts_with($dir,"/")) {
  merr("The full path of executables must be specified (must begin with a '/'.");
  return true;
  }
 # No double dots after slashes or dollar signs allowed.
 $not_ok=false;
 $slash_mode=false;
 for($i=0;$i<strlen($dir);$i++) {
  if($slash_mode) {
   if(($dir[$i]===".") and ($dir[$i-1]===".")) {
    merr("Executable paths containing two dots '/../' are blacklisted.");
    $not_ok=true;
    break;
    }
   if($dir[$i]!==".") { $slash_mode=false; }
   } 
  if($dir[$i]==="$") {
   merr("Executable paths with dollar signs ('$') are blacklisted.");
   $not_ok=true;
   break;
   }
  if($dir[$i]==="/") {
   $slash_mode=true;
   }
  }
 if($not_ok) { return true; }
 # test dirs
 # if dir starts with allowed dir, it's OK
 $dir_tested_ok=false;
 foreach($allow_dirs_starting_with as $allowed_dir) {
  if(str_starts_with($dir,$allowed_dir)) {
   $dir_tested_ok=true;
   break;
   }
  }
 if(!$dir_tested_ok) {
  foreach($deny_dirs_specifically as $denied_dir) {
   if($dir===$denied_dir) {
    merr("executable blocked - executables in '".$denied_dir."' are blacklisted");
    return true;
    }
   foreach($deny_dirs_starting_with as $denied_dir) {
    if(str_starts_with($dir,$denied_dir)) {
     merr("executable blocked - executables in directories starting with '".$denied_dir."' are blacklisted");
     return true;
     }
    }
   }
  }
 # test exe
 foreach($deny_exes_specifically as $denied_exe) {
  if($exe===$denied_exe) {
   merr("executable blocked - executables named '".$denied_exe."' are blacklisted");
   return true;
   }
  }
 foreach($deny_exes_containing as $denied_exe) {
  if(str_contains($exe,$denied_exe)) {
   merr("executable blocked - executable names containing '".$denied_exe."' are blacklisted");
   return true;
   }
  }
 return false;
 }


# ----------------------------------------------------------------------------
# [ Database access checking functions ]
# ----------------------------------------------------------------------------


function is_dbo_created() {
 # Simple function to determine if a database object has been created yet or
 # not (basically, did we open the database yet).
 if(!(isset($GLOBALS["dbo"]))){return false;}
 if($GLOBALS["dbo"]===""){return false;}
 return true;
 }


function bounce_single_row_only($in_which_table) {
# Check to see if table already has a row.
# If it does, issue an error, otherwise do nothing.
# - Assumes table is known
 $table_metadata=schema_rowattr($in_which_table.'/FOR_THIS_APP');
 if(!(isset($table_metadata["single-row-only"]))){ return; }
 $counted_rows=0;
 $statement=$GLOBALS["dbo"]->prepare("SELECT * FROM ".$in_which_table);
 $results=$statement->execute();
 while($row=$results->fetchArray()) { $counted_rows++; }
 if($counted_rows!=0){
  merr("Table ".tblnam($in_which_table)." already has a row (and can only have just one)");
  }
 }


function bounce_readonly($in_action) {
# merr() adds to $GLOBALS["outmsgs"]["errors"], and if that array has more
# than 0 elements, outer code is responsible for checking that.

 if($GLOBALS["readonly"]) {
  merr("application is in read-only mode, ".actnam($in_action)." won't be executed.");
  }
 }


function bounce_no_toplink($in_which_table) {
# Tables without a "toplink" table scheaa attribute are assumed to not want to
# be modified or viewed externally.

 if($in_which_table==="") { return; }
 if($in_which_table==="none") { return; }
 $table_metadata=schema_rowattr($in_which_table.'/FOR_THIS_APP');
 if(!isset($table_metadata["toplink"])) {
  merr("Requests involving this table from this interface are not accepted.","hack");
  }
 }


# ----------------------------------------------------------------------------
# [ Database read and write functions (high-level) ]
# ----------------------------------------------------------------------------


function do_erase_upon_clear_logs() {
# Deletes all tables with "erase-upon-clear-logs" table attribute.
# These would be tables linked with things in logs (e.g. buttons).

 $tables=tables_from_schemadef();
  foreach($tables as $this_table) {
   $this_table_metadata=schema_rowattr($this_table."/FOR_THIS_APP");
   if((isset($this_table_metadata["erase-upon-clear-logs"]))) {
   delete_all_rows_bypass_schema($this_table);
   }
  }
 }


function open_database($in_filename) {
 mtrace("DB op: open_database($in_filename)");
# Open database (modifies $GLOBALS["dbo"]).
# We used to check and initialize the database every time it was opened,
# but now we just open it.
 $GLOBALS["dbo"]=new SQLite3($in_filename);
 }


function create_missing_tables() {
# Also checks database for tables and creates them if missing.
# Indirectly uses $GLOBALS["schemadef"] via tables_from_schemadef()
# Uses $GLOBALS["outmsgs"]

 # These are tables used internally that are not part of the schema.
 # If these happen to conflict with any defined in the schema, the schema
 # will be ignored.
 $nonschema_table_sql=Array(
  "internal"	=> "CREATE TABLE internal (
			 nlog INTEGER NOT NULL 
			,superuser_uid TEXT NOT NULL
			);",
  "log"		=> "CREATE TABLE log (
			 id INTEGER NOT NULL PRIMARY KEY
			,source TEXT NOT NULL
			,eventdesc TEXT NOT NULL
			,event TEXT NOT NULL
			,timestamp TEXT NOT NULL
			,offer_event_view TEXT NOT NULL
			,button_type TEXT NOT NULL
			,button_type_target TEXT NOT NULL
			);",
  "backref"	=> "CREATE TABLE backref (
			 id INTEGER NOT NULL PRIMARY KEY
			,to_table TEXT NOT NULL
			,to_key_col_name TEXT NOT NULL
			,to_key_col_value TEXT_NOT_NULL
			,from_table TEXT NOT NULL
			,from_key_col_name TEXT NOT NULL
			,from_key_col_value TEXT NOT NULL
			);",
  "sessions"	=> "CREATE TABLE sessions (
			 sid TEXT NOT NULL PRIMARY KEY
			,uid TEXT NOT NULL
			,created TEXT NOT NULL
			,raw_session_tags TEXT
			);",
  "appusers"	=> "CREATE TABLE appusers (
			 uid TEXT NOT NULL PRIMARY KEY
			,username TEXT NOT NULL UNIQUE
			,password TEXT NOT NULL
			,created TEXT NOT NULL
			,force_password_reset INTEGER NOT NULL
			,enabled INTEGER NOT NULL
			,failed_logins_consec INTEGER NOT NULL
			,last_login_attempt TEXT NOT NULL
			);",
  "apprights"	=> "CREATE TABLE apprights (
			 uid TEXT NOT NULL
			,target_table_name TEXT NOT NULL
			,target_row_identifier TEXT NOT NULL
			,owns INTEGER NOT NULL
			);",
  "uicache"	=> "CREATE TABLE uicache (
			 objtype TEXT NOT NULL
			,objid0 TEXT NOT NULL
			,objid1 TEXT NOT NULL
			,objid2 TEXT NOT NULL
			,uidata TEXT NOT NULL
			);"
 );
  
 # We need to check the database for missing tables and create ones that are
 # missing.  If this is a newly created database file (happens automtically if
 # it did not exist), then no tables will be there and we have to create all
 # of them.

 # Get list of tables from schema.
 $required_tables=tables_from_schemadef();
 # Add nonschema tables to our list above.
 foreach($nonschema_table_sql as $nonschema_table_name=>$value) {
  $required_tables[]=$nonschema_table_name;
  }

 # turn our list into an associative array.
 $found_tables=Array();
 foreach($required_tables as $required_table) {
  $found_table[$required_table]=false;
  };

 # Now, let's look at the database and see what's missing.
 $missing_tables=array();
 $sql="SELECT * FROM sqlite_master WHERE type='table'";
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $results=$statement->execute();

 while($row=$results->fetchArray()){
  if(in_array($row["name"],$required_tables)) { $found_table[$row["name"]]=true; continue; }
  }

 $init_internal_table=false;
 $init_appusers_table=false;

 # Now, create any missing tables if needed.
 $txn_needed=false;
 foreach($found_table as $missing_table=>$it_was_found_earlier) {
   if ($it_was_found_earlier) { continue; }
   # Only start a transaction if we have a table to make
   if (!$txn_needed) { $txn_needed=true; begin_sql_transaction(); }
   mtrace("database is missing table \"".$missing_table."\", going to add");
  if(isset($nonschema_table_sql[$missing_table])) {
   $sql=$nonschema_table_sql[$missing_table];
  }else{
   $sql=sql_to_make_table_from_schemadef($missing_table);
   mtrace("sql: \"$sql\"");
   }
  if($missing_table==="internal") { $init_internal_table=true; }
  if($missing_table==="appusers") { $init_appusers_table=true; }
  $result=$GLOBALS["dbo"]->exec($sql);
   if(any_db_error()) { end_any_sql_transaction(); return; }
  }

  # Initialize the internal table if needed.
  if($init_internal_table) {
   mtrace("initializing internal table");
   $init_data=Array( "nlog"			=> 0
		    ,"superuser_uid"		=> "unassigned"
		   );
   insert_row("internal",$init_data);
    if(any_db_error()) { end_any_sql_transaction(); return; }
   }

  # Initialize the users table if needed.
  if($init_appusers_table) { 
   mtrace("initializing users table");
   $init_data=Array( "uid"			=> "new_superuser"
		    ,"username"			=> "admin"
		    ,"password"			=> hash("sha512","admin")
		    ,"created"			=> time()
		    ,"force_password_reset"	=> 1
		    ,"enabled"			=> 1
		    ,"failed_logins_consec"	=> 0
		    ,"last_login_attempt"	=> 0
		    );
   # NOTE: The uid value "new_superuser" will trigger authenticate() to
   # create a new uid, and set internal.superuser_uid and this uid to it.
   insert_row("appusers",$init_data);
    if(any_db_error()) { end_any_sql_transaction(); return; }
   }

 # $txn_needed not set if we didn't start a new sql transaction above.
 # ... not set if no missing tables.
 if($txn_needed) {
  $GLOBALS["sqltxn_commit"]=true; # successful at this point.
  end_any_sql_transaction(); 
  } else {
  mtrace("no missing tables");
 }

 }


function begin_sql_transaction() {
# Start SQL transaction.  Sets $GLOBALS[""sqltxn_commit"]" flag to false.
 mtrace("DB op: begin_sql_transaction()");

 # We can't do anything if database isn't open.
 if(!isset($GLOBALS["dbo"])){
  mtrace("begin_sql_transaction: database not open");
  return false;
  }
 $sql="BEGIN TRANSACTION";
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare("BEGIN TRANSACTION");
 $results=$statement->execute();
 $GLOBALS["sqltxn_commit"]=false;
  mtrace("sqltxn_commit set to false");
 return true;
 }


function end_any_sql_transaction() {
# Ends any SQL transaction if there is one.
# $GLOBALS["sqltxn_commit"]--if set--means a transaction exists.
# If it's true, transaction is ended with a COMMIT.
# If it's false, transaction is ended with a ROLLBACK.
 mtrace("DB op: end_any_sql_transaction()");

 # Nothing to do if database wasn't ever opened.
 if(!isset($GLOBALS["dbo"])){
  mtrace("end_any_sql_transaction: database not open");
  return false;
  }
 # Nothing to do if a transaction wasn't started.
 if(!isset($GLOBALS["sqltxn_commit"])) {
  mtrace("end_any_sql_transaction: sqltxn_commit not set");
  return false;
  }
 $sql="";
 if($GLOBALS["sqltxn_commit"]) {
  $sql="COMMIT";
  } else {
  $sql="ROLLBACK";
  }
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $results=$statement->execute();
 unset($GLOBALS["sqltxn_commit"]);
  mtrace("sqltxn_commit unset");
 }


function log_entry( $in_session
		   ,$in_source
		   ,$in_eventdesc
		   ,$in_eventbody
		   ,$offer_event_view=false
		   ,$button_type="none"
		   ,$button_type_target="none"
		   ) {
# Adds an entry to the log (a.k.a. "action history").
# Rotates an entry out if it's full. Rotating out includes making sure
# anythimg in the trashcan that has a button on the log is removed as well.
#
# Returns true if no database error occured, false if one did.
 mtrace("log_entry(source:$in_source, eventdesc:$in_eventdesc, eventbody:$in_eventbody, offer_event_view:$offer_event_view, button_type:$button_type, button_type_target:$button_type_target)");

 # We can't do anything if database isn't open.
 if(!isset($GLOBALS["dbo"])){ return false; }
 # Add it to the log table.
 $log_entry=Array( "source"			=> $in_source
		  ,"eventdesc"			=> $in_eventdesc
		  ,"event"			=> $in_eventbody
		  ,"timestamp"			=> time()
		  ,"offer_event_view"		=> $offer_event_view
		  ,"button_type"		=> $button_type
		  ,"button_type_target"		=> $button_type_target
		  );
 insert_row("log",$log_entry);
 if(any_db_error()){ return false; }
 $i1=Array(); $i1=read_table_all_rows("internal");
 $i=$i1[0];

 # If there are 10 lines in the log ...
 if($i["nlog"]>9) {
  # ... oldest log line will fall off.

  # Before we kick it out, we have to read it ...
  $sql="SELECT * FROM log WHERE rowid = (SELECT MIN(rowid) FROM LOG)";
   mtrace("sql: \"$sql\"");
  $statement=$GLOBALS["dbo"]->prepare($sql);
  $results=$statement->execute();
  if(any_db_error()){ return false; }
  $row=$results->fetchArray(SQLITE3_ASSOC); # TODO: test >1 row
  # and check if the log entry has any buttons.
  if($row["button_type"]!=="none") {
   # if it does, call BUTTONFALLOFF method to clean it up.
   $function="BUTTONFALLOFF_".$row["button_type"];
   if(is_callable($function)) {
    $function($in_session,$row["button_type_target"]);
    } else { 
    mdebug("No callable BUTTONFALLOFF method available for button type '".$row["button_type"]."'.");
    }
   } 
  # Now remove the oldest log entry row.
  $sql="DELETE FROM log WHERE rowid = (SELECT MIN(rowid) FROM LOG)";
   mtrace("sql: \"$sql\"");
  $statement=$GLOBALS["dbo"]->prepare($sql);
  $results=$statement->execute();
  if(any_db_error()){ return false; }
  }

 # If there are not 10 lines in the log, we just increment the count.
 if($i["nlog"]<=9) {
  $i["nlog"]++;
  update_row("internal",$i,"rowid",1);
  if(any_db_error()){ return false; }
  }

 return true;
 }


function check_unique($in_which_table,$in_array_in_data) {
 mtrace("DB op: check_unique(table:$in_which_table, array_in_data:array)");

 $tblattrs=schema_rowattr($in_which_table."/FOR_THIS_APP");
 if(!isset($tblattrs["friendly-object-name"])) {
  $friendly_object_name="item here";
  } else { 
  $friendly_object_name=$tblattrs["friendly-object-name"];
  }
 $columns=columns_from_schemadef($in_which_table);
 $result=true;
 foreach($columns as $col) {
  $attrs=schema_rowattr($in_which_table.'/'.$col);
  # Only validate columns with populated data.
  if(isset($attrs["must-be-unique"])) {
   $throwaway="";
   $exists_already=read_row_expecting_just_one($throwaway,$in_which_table,$col,$in_array_in_data[$col]);
   if($exists_already) {
    merr("Another ".$tblattrs["friendly-object-name"]." already has the ".$col." '".$in_array_in_data[$col]."'.");
    $result=false;
    }
   }
  }
 return $result;
 }


function make_backrefs_for_new_row($in_which_table,$in_array_in_data) {
# Creates a backref if needed.
# Backrefs are considered needed if the table's metadata specifies the
# following attributes: 
# - "allow-delete-by"
# - "backref-by"
 mtrace("DB op: make_backrefs_for_new_row(table:$in_which_table, array_in_data:array)");

 $tblattrs=schema_rowattr($in_which_table."/FOR_THIS_APP");
 foreach($GLOBALS["schemadef"] as $tblcolname=>$colattrs) {
  $split_tblcolname=Array(); $split_tblcolname=explode('/',$tblcolname);
  if($split_tblcolname[0]!==$in_which_table) { continue; }
  $attrs=schema_rowattr($tblcolname);
  if(isset($attrs["is-pointer-to"])) {
   $backref_by="rowid"; # Default backref-by column
   if(isset($tblattrs["allow-delete-by"])) { $backref_by=$tblattrs["allow-delete-by"]; }
   if(isset($tblattrs["backref-by"])) { $backref_by=$tblattrs["backref-by"]; }
   $new_backref=Array( "to_table"		=> $attrs["is-pointer-to"]
		      ,"to_key_col_name"	=> $attrs["pointer-links-by"]
		      ,"to_key_col_value"	=> $in_array_in_data[$split_tblcolname[1]]
		      ,"from_table"		=> $in_which_table
		      ,"from_key_col_name"	=> $backref_by
		      ,"from_key_col_value"	=> $in_array_in_data[$backref_by]
		      );
   insert_row("backref",$new_backref);
   }
  }
 }


function delete_backref($in_pointing_table,$in_keyed_col_name,$in_keyed_col_value) {
# Deletes a backref specified by a column.
 mtrace("DB op: delete_backref(pointing_table:$in_pointing_table, keyed_col_name:$in_keyed_col_name, keyed_col_value:$in_keyed_col_value)");

 if(!isset($GLOBALS["dbo"])){ return false; } # bounce if db not open
 $sql="DELETE FROM backref WHERE from_table = :in_pointing_table AND from_key_col_name = :in_keyed_col_name AND from_key_col_value = :in_keyed_col_value";
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $statement->bindValue(":in_pointing_table",$in_pointing_table,SQLITE3_TEXT);
  mtrace("parameters: :in_pointing_table=$in_pointing_table");
 $statement->bindValue(":in_keyed_col_name",$in_keyed_col_name,SQLITE3_TEXT);
  mtrace("parameters: :in_keyed_col_name=$in_keyed_col_name");
 $statement->bindValue(":in_keyed_col_value",$in_keyed_col_value,SQLITE3_TEXT);
  mtrace("parameters: :in_keyed_col_value=$in_keyed_col_value");
 $results=$statement->execute();
 }


function being_pointed_to($in_pointed_table,$in_keyed_col_name,$in_keyed_col_value) {
# Checks the database to find out if a backref exists.
 mtrace("DB op: being_pointed_to(pointed_table:$in_pointed_table, keyed_col_name:$in_keyed_col_name, keyed_col_value:$in_keyed_col_value)");

 if(!isset($GLOBALS["dbo"])){ return false; } # bounce if db not open
 $sql="SELECT * FROM backref WHERE to_table = :in_pointed_table AND to_key_col_name = :in_keyed_col_name AND to_key_col_value = :in_keyed_col_value";
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $statement->bindValue(":in_pointed_table",$in_pointed_table);
  mtrace("parameters: :in_pointed_table=$in_pointed_table");
 $statement->bindValue(":in_keyed_col_name",$in_keyed_col_name);
  mtrace("parameters: :in_keyed_col_name=$in_keyed_col_name");
 $statement->bindValue(":in_keyed_col_value",$in_keyed_col_value);
  mtrace("parameters: :in_keyed_col_value=$in_keyed_col_value");
 $results=$statement->execute();
 $row=$results->fetchArray();
 # all we are interested in is if we found something or not.
 if(is_bool($row)) {
  if(!($row)) { mtrace("returning false"); return false; }
  }
  mtrace("returning true");
 return true;
 }


function read_table_all_rows_keyed_cols (
 $in_which_table, $in_which_col_target_name, $in_which_col_display_name
 ) {
# Assumes $in_which_table exists in database.
# Get a target-name column pair from a table in the database.
# Actually, two columns - a "target" (key to identify something modifiable)
# and a display name (contains user-presentable name of the target).
# Indirectly uses $GLOBALS["schemadef"] via columns_from_schemadef()
 mtrace("DB op: read_table_all_rows_keyed_cols(table:$in_which_table, which_col_target_name:$in_which_col_target_name, which_col_display_name:$in_which_col_display_name)");

 $out_columns_t=Array();
 $out_columns_d=Array();
 # bounce if dbo wasn't created (database never opened)
 if(!(is_dbo_created())) {
  merr("read_table_all_rows_keyed_cols('".$in_which_table."','".$in_which_col_target_name."','".$in_which_col_display_name."') was called and is_dbo_created returned false","bug");
  $out_columns=Array("targets"=>$out_columns_t,"display_names"=>$out_columns_d);
  return $out_columns;
  }
 # otherwise lets make a query
 $expected_cols=Array();
 $expected_cols=columns_from_schemadef($in_which_table);
 $sql="SELECT ".$in_which_col_target_name.",".$in_which_col_display_name." FROM ".$in_which_table;
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $results=$statement->execute();
 while($row=$results->fetchArray()) {
  $out_columns_t[]=$row[$in_which_col_target_name];
  # $in_which_col_display_name can have multiple columns separated by commas
  # we support this.
  # so that means we need to do some stitchin'
  $commaseparated=explode(',',$in_which_col_display_name);
  $spacer=''; $assembled='';
  foreach($commaseparated as $individual) {
   $assembled.=$spacer.$row[$individual];
   if($spacer==='') { $spacer='; '; }
   }
  $out_columns_d[]=$assembled;
  }
 $out_columns=Array("targets"=>$out_columns_t,"display_names"=>$out_columns_d);
  mtrace("returning associative array with ".count($out_columns)." key(s)");
 return $out_columns;
 }


function read_table_all_rows_keyed_cols_minus_existing (
 $in_which_table, $in_which_col_target_name, $in_which_col_display_name, $in_existing
 ) {
 # Get a target-name column pair from a table in the database.
 # Actually, two columns - a "target" (key to identify something modifiable)
 # and a display name (contains user-presentable name of the target).
 # Indirectly uses $GLOBALS["schemadef"] via columns_from_schemadef()
  mtrace("DB op: read_table_all_rows_keyed_cols_minus_existing(table:$in_which_table, which_col_target_name:$in_which_col_target_name, which_col_display_name:$in_which_col_display_name, existing:Array");

 $out_columns_t=Array();
 $out_columns_d=Array();
 # bounce if dbo wasn't created (database never opened)
 if(!(is_dbo_created())) {
  merr("read_table_all_rows_keyed_cols_minus_existing('".$in_which_table."','".$in_which_col_target_name."','".$in_which_col_display_name."','".$in_existing."') was called and is_dbo_created returned false","bug");
  $out_columns=Array("targets"=>$out_columns_t,"display_names"=>$out_columns_d);
  return $out_columns;
  }
 # otherwise lets make a query
 $expected_cols=Array();
 $expected_cols=columns_from_schemadef($in_which_table);
 $sql="SELECT ".$in_which_col_target_name.",".$in_which_col_display_name." FROM ".$in_which_table;
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $results=$statement->execute();
 while($row=$results->fetchArray()) {
  if(in_array($row[$in_which_col_target_name],$in_existing)) { continue; }
  $out_columns_t[]=$row[$in_which_col_target_name];
  # $in_which_col_display_name can have multiple columns separated by commas
  # we support this.
  # so that means we need to do some stitchin'
  $commaseparated=explode(',',$in_which_col_display_name);
  $spacer=''; $assembled='';
  foreach($commaseparated as $individual) {
   $assembled.=$spacer.$row[$individual];
   if($spacer==='') { $spacer='; '; }
   }
  $out_columns_d[]=$assembled;
  }
 $out_columns=Array("targets"=>$out_columns_t,"display_names"=>$out_columns_d);
  mtrace("returning associative array with ".count($out_columns)." key(s)");
 return $out_columns;
 }


function delete_row($in_which_table, $in_target) {
 # Delete row(s) from a table where provided target matches the
 # "allow-delete-by" attribute in the virtual FOR_THIS_APP column.
 # - If there is no "allow-delete-by" defined for that table, an error will
 # be reported and nothing will be deleted.
 # TODO: Report database errors in $GLOBALS["outmsgs"]["errors"]
 #
 # Uses $GLOBALS["dbo"], $GLOBALS["schemadef"], $GLOBALS["outmsgs"]
  mtrace("DB op: delete_row(table:$in_which_table, target:$in_target)");

 $table_metadata=schema_rowattr($in_which_table."/FOR_THIS_APP");
 if(!(isset($table_metadata["allow-delete-by"]))) {
  merr("'".$in_which_table."' doesn't allow rows to be deleted.","hack");
  return false;
  }
 $columns=columns_from_schemadef($in_which_table);
 $allow_delete_columnname=$table_metadata["allow-delete-by"];

 # $in_which_table must match to_table
 # $allow_delete_columnname must match to_key_col_name
 # $in_target must match to_key_col_value

 # Bounce if there are backrefs pointing to this row.
 if(being_pointed_to($in_which_table,$allow_delete_columnname,$in_target)) {
  merr("Another object is using data from this object and must be deleted first.");
  return false;
  }

 # Cleanup any backrefs we set on other rows
 foreach($columns as $col) {
  $attrs=schema_rowattr($in_which_table.'/'.$col);
  if(isset($attrs['is-pointer-to'])) {
   delete_backref($in_which_table,$allow_delete_columnname,$in_target);
   }
  }
 
 $sql="DELETE FROM ".$in_which_table." WHERE ".$allow_delete_columnname." = :in_target";
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $statement->bindValue(":in_target",$in_target,SQLITE3_TEXT);
  mtrace("parameters: in_target=\"$in_target\"");
 $results=$statement->execute();
 }


function intvar($in_variable_name) {
 $tmp=Array();
 if(read_internal_table($tmp)===false) { return ""; };
 if(!isset($tmp[$in_variable_name])) { return ""; }; 
 return $tmp[$in_variable_name];
 }


function read_internal_table(&$out_internal_table_row) {
 $tmp=Array();
 $tmp=read_table_all_rows("internal");
 if(any_db_error()) { 
  merr("Database error: Unable to read internal table");
  $out_internal_table_row=Array();
  return false;
  }
 if(is_bool($tmp)) {
  $out_internal_table_row=Array();
  return false;
  }
 $out_internal_table_row=$tmp[0];
 return true;
 }

# ----------------------------------------------------------------------------
# [ Database read and write functions (low-level) ]
# ----------------------------------------------------------------------------


function any_db_error() {
# Checks if there is a database error.
# If there is no error, returns false.
# If there is, it will also:
# - set $GLOBALS["sqltxn_commit"] to false which will make
#   end_any_sql_transaction() do a rollback when called,
# - issues an merr() reporting the error.
# - return true.
# This function enables generators and row methods to simply do this to handle
# database errors:
#   if(any_db_error()) { return false; }

 if($GLOBALS["dbo"]->lastErrorCode()==0) { return false; }
 # Report database error.
 merr("Database error: ".$GLOBALS["dbo"]->lastErrorMsg());
 # Indicate we need a rollback if there is an error.
 $GLOBALS["sqltxn_commit"]=false;
 return true;
 }


function open_database_2($in_filename) {
# Open 2nd handle to database, read only.
# This won't create any tables.  This function shouldn't be called unless
# open_database() was called first anyway.
  mtrace("DB op: open_database_2($in_filename)");

 $GLOBALS["dbo2"]=new SQLite3($in_filename,SQLITE3_OPEN_READONLY);
 }


function read_row_expecting_just_one(&$receiving, $in_which_table, $in_select_col, $in_select_colvalue) {
# Does not consult schema.
# Assumes $in_which_table exists in database.
# Gets all columns of one row in a table.
# Row selected by $in_select_col and $in_select_colvalue.
# Issues an error if query does not return exactly one row.
 mtrace("DB op: read_row_expecting_just_one(ref:receiving, table:$in_which_table, select_col:$in_select_col, select_colvalue:$in_select_colvalue)");

 # bounce if dbo wasn't created (database never opened)
 $out_row=Array();
 $out_row=read_table_filtered_rows($in_which_table, $in_select_col, $in_select_colvalue);
 if(count($out_row)>1) {
  merr("Multiple rows in ".tblnam($in_target_table)." have the value '".$in_select_colvalue."' in ".colname($in_select_col),"bad_db");
  $receiving=Array(); 
   mtrace("returning false (not exactly 1 row)");
  return false;
  }
 if(!isset($out_row[0])) {
  mtrace("sql call returned a string and not an array; returning false and setting receiving reference to empty array"); 
  $receiving=Array();
  return false;
  }
 $receiving=$out_row[0];
  mtrace("returning true, setting receiving reference to the found row"); 
 return true;
 }


function read_table_all_rows($in_which_table) {
# Does not consult schema.
# Assumes $in_which_table exists in database.
# Gets all columns of all rows of a table.
 mtrace("DB op: read_table_all_rows(table:$in_which_table)");

 # bounce if dbo wasn't created (database never opened)
 $out_rows=Array();
 if(!(is_dbo_created())) {
  merr("read_table_all_rows('".$in_which_table."') was called and is_dbo_created returned false","bug");
  return $out_rows;
  }
 $sql="SELECT * FROM ".$in_which_table;
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $results=$statement->execute();
 while($row=$results->fetchArray(SQLITE3_ASSOC)) {
  $out_rows[]=$row;
  }
  mtrace("returning ".count($out_rows)." row(s)");
 return $out_rows;
 }


function read_table_all_rows_multiple_cols($in_which_table, $in_array_which_cols) {
# Does not consult schema.
# Assumes $in_which_table exists in database.
# Gets desired columns of all rows in a table.
 mtrace("DB op: read_table_all_rows_multiple_cols(table:$in_which_table, array_which_cols:array)");

 # bounce if dbo wasn't created (database never opened)
 $out_rows=Array();
 if(!(is_dbo_created())){
  merr("read_table_all_rows_multiple_cols('".$in_which_table.",".$in_array_which_rows.") was called and is_dbo_created returned false","bug");
  return $out_row;
  }
 $cscols="";
 foreach($in_array_which_cols as $col) {
  if ($cscols!="") { $cscols.=","; }
  $cscols.=$col;
  }
 $sql="SELECT ".$cscols." FROM ".$in_which_table;
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $results=$statement->execute();
 while($row=$results->fetchArray(SQLITE3_ASSOC)) {
  $out_rows[]=$row;
  }
  mtrace("returning ".count($out_rows)." row(s)");
 return $out_rows;
 }


function does_value_exist_in_col(
 $in_which_table, $in_which_col_target_name, $in_which_col_display_name, $in_value
 ) {
# Does not consult schema.
# Assumes $in_which_table exists in database.
# Checks if anything exists in a table's column identified by a target-name
# column pair.
 mtrace("DB op: does_value_exist_in_col(table:$in_which_table, which_col_target_name:$in_which_col_target_name, which_col_display_name:$in_which_col_display_name, value:$in_value)");

 # bounce if dbo wasn't created (database never opened)
 if(!(is_dbo_created())){
  merr("does_value_exist_in_col('".$in_which_table."','".$in_which_col_target_name."','".$in_which_col_display_name."','".$in_value."') was called and is_dbo_created returned false","bug");
  return false;
  }
 # perform database lookup
 $sql="SELECT ".$in_which_col_target_name.",".$in_which_col_display_name." FROM ".$in_which_table." WHERE ".$in_which_col_target_name." = :x";
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $statement->bindValue(":x",$in_value);
  mtrace("parameters: x=\"$in_target\"");
 $results=$statement->execute();
 $row=$results->fetchArray();
 # all we are interested in is if we found something or not.
 if(is_bool($row)) {
  if(!($row)) { mtrace("returning false"); return false; }
  }
  mtrace("returning true");
 return true;
 } 


function read_table_one_row_keyed_cols (
# NOT SURE IF NEEDED
 $in_which_table, $in_wanted_col, $in_which_keyed_col_name, $in_which_keyed_col_value,
 ) { 
 mtrace("DB op: read_table_one_row_keyed_cols(table:$in_which_table, wanted_col:$in_wanted_col, which_keyed_col_name:$in_which_keyed_col_name which_keyed_col_value:$in_which_keyed_col_value)");

 $expected_cols=columns_from_schemadef($in_which_table);
 $sql="SELECT ".$in_wanted_col." FROM ".$in_which_table." WHERE ".$in_which_keyed_col_name." = :in_which_keyed_col_value";
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $statement->bindValue(":in_which_keyed_col_value",$in_which_keyed_col_value);
  mtrace("parameters: in_which_keyed_col_value=\"$in_which_keyed_col_value\"");
 $results=$statement->execute();
 $row=$results->fetchArray();
  mtrace("returning \"$row[0]\"");
 return $row[0];
 }


function update_row(
 $in_which_table, $in_array_data, $in_key_column, $in_key_value
 ) {
# Does not consult schema.
# Assumes $in_which_table exists in database.
# Updates a row identified by value $in_key_value in column $in_key_column.
 mtrace("DB op: update_row(table:$in_which_table, data:array, key:$in_key_column, value:$in_key_value)");

 $dbo_execute_params_array=Array();
 $where_text="";
 $set_text=""; 
 foreach($in_array_data as $key=>$value) {
  if($set_text!=="") { $set_text.=", "; }
   $set_text.=$key." = :".$key;
   $dbo_execute_params_array[":".$key]=$value;
  }
 $where_text.=$in_key_column." = :x";
 $dbo_execute_params_array[":x"]=$in_key_value;
 $sql="UPDATE ".$in_which_table." SET ".$set_text." WHERE ".$where_text;
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 foreach($dbo_execute_params_array as $key=>$value) {
  $statement->bindValue($key,$value,SQLITE3_TEXT);
  mtrace("parameters: $key=\"$value\"");
  }
 $results=$statement->execute();
 }


function insert_row($in_which_table, $in_array_data) { 
# Does not consult schema.
# Assumes $in_which_table exists in database.
 mtrace("DB op: insert_row(table:$in_which_table, data:array)");

 $dbo_execute_params_array=Array();
 $insert_text="("; $values_text="(";
 $first_flag=false;
 foreach($in_array_data as $key=>$value) {
  if($first_flag==true){
   $insert_text.=','; $values_text.=',';
   } else {
   $first_flag=true;
   }
   $insert_text.=$key;
   $values_text.=":".$key;
   $dbo_execute_params_array[":".$key]=$value;
  }
 $insert_text.=")"; $values_text.=")";

 $sql="INSERT INTO ".$in_which_table.$insert_text." VALUES".$values_text;
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 foreach($dbo_execute_params_array as $key=>$value) {
  $statement->bindValue($key,$value,SQLITE3_TEXT);
  mtrace("parameters: $key=\"$value\"");
  }
 $results=$statement->execute();
 if($GLOBALS["dbo"]->lastErrorCode()==0) { mtrace("returning true (successful)"); return true; }
  mtrace("returning false (unsuccessful)");
 return false;
 }


function read_table_filtered_rows(
 $in_which_table, $in_select_col, $in_select_colvalue,
 ) {
# Does not consult schema.
# Assumes $in_which_table exists in database.
# Reads row(s) from a table where provided target is found in provided column.
 mtrace("DB op: read_table_filtered_rows(table:$in_which_table, col:$in_select_col, colvalue:$in_select_colvalue)");

 $out_rows=Array();
 $sql="SELECT * FROM ".$in_which_table." WHERE ".$in_select_col." = :x";
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $statement->bindValue(":x",$in_select_colvalue);
  mtrace("parameters: in_select_colvalue=\"".$in_select_colvalue."\"");
 $results=$statement->execute();
 while($row=$results->fetchArray(SQLITE3_ASSOC)) { $out_rows[]=$row; }
  mtrace("returning ".count($out_rows)." row(s)");
 return $out_rows;
 }


function delete_all_rows_bypass_schema($in_which_table) {
# Does not consult schema.
# Assumes $in_which_table exists in database.
# Deletes all rows in table.
 mtrace("DB op: delete_all_rows_bypass_schema(table:$in_which_table)");

 $sql="DELETE FROM ".$in_which_table;
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $results=$statement->execute();
 }


function delete_row_bypass_schema(
 $in_which_table, $in_allow_delete, $in_target, $in_backref=true
 ) {
# Does not consult schema.
# Assumes $in_which_table exists in database.
# Delete row(s) from the database where provided target matches the column
# specified.
# - Calls delete_backref to clean up any back references if specified.
# - Intended to be called by generators or row methods that need to manipulate
#   the database as a side effect instead of in direct service to the incoming
#   request.
 mtrace("DB op: delete_row_bypass_schema(table:$in_which_table, allow_delete:$in_allow_delete, target:$in_target, backref:$in_backref)");

 if($in_backref) { 
  delete_backref($in_which_table,$in_allow_delete,$in_target);
  }
# $table_metadata=Array();
 $sql="DELETE FROM ".$in_which_table." WHERE ".$in_allow_delete." = :in_target";
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $statement->bindValue(":in_target",$in_target,SQLITE3_TEXT);
  mtrace("parameters: in_target=\"$in_target\"");
 $results=$statement->execute();
 }


# ----------------------------------------------------------------------------
# [ Output buffer related ]
# ----------------------------------------------------------------------------


function output_buffer_setup($in_format) {
# Allocate arrays and do anything else needed for the output format.
 switch ($in_format) {
  case "text": 
   $GLOBALS["output_buffer"]["text"]=Array();
   $GLOBALS["output_buffer"]["text"]["error"]=Array();
   $GLOBALS["output_buffer"]["text"]["notice"]=Array();
   $GLOBALS["output_buffer"]["text"]["data"]=Array();
   $GLOBALS["output_buffer"]["text"]["table"]=Array();
   $GLOBALS["output_buffer"]["text"]["table-row-colnames"]=Array();
   $GLOBALS["output_buffer"]["text"]["table-row"]=Array();
   break;
  case "html":
   $GLOBALS["output_buffer"]["html"]=Array();
   # code...
   break;
 }
}


function textoutp($out_label,$out_string,$mode=0) {
# Collect partial line output for format "text" for adding to buffer when
# done generating the line.
# - Mode 0 appends text to current line.
# - Mode 1 appens text and ends the the current line with a textout() flush.
# - Mode 2 just ends the current line and flushes to textout().

 static $current_line;
 switch ($mode) { 
  case 0:
   $current_line.=$out_string;
   break;
  case 1:
   $current_line.=$out_string;
  case 2:
   textout($out_label,$current_line);
   $current_line="";
   break;
  }
 }


function textout($out_label,$out_string) {
# Adds $out_string to output buffer array, under the given $out_label.

 if($out_string==="") { return; }
 $GLOBALS["output_buffer"]["text"][$out_label][]=$out_string; 
 }


function htmloutp($out_string,$mode=0) {
 static $current_line;
 $current_line.=$out_string;
 switch ($mode) {
  case 0:
   return;
   break;
  case 1:
   htmlout($current_line);
   $current_line="";
   break;
  }
 }


function htmlout($out_line) {
 if($out_line==="") { return; }
 $GLOBALS["output_buffer"]["html"][]=$out_line."\n";
 }


# ----------------------------------------------------------------------------
# [ Error reporting, status checking, and notification ]
# ----------------------------------------------------------------------------


function any_errors(){
# Very simple - returns true if there are any pending error messages.

 if(count($GLOBALS["outmsgs"]["errors"])!=0) { return true; }
 return false;
 }


# ----------------------------------------------------------------------------
# Functions that add messages to any of the message stacks.
#
# Outer code that handles output will check message stack and output messages
# there in a format suitable for the type.
#
# Presence of anything in the "errors" stack (which is
# $GLOBALS["outmsgs"]["errors"]) means an error ocurred.
# ----------------------------------------------------------------------------


function mdebug($in_msg_text) {
# Debug messages.

 # Only if enabled - see set_globals().
 if(!isset($GLOBALS["internal"]["debug"])) { return; }

 $GLOBALS["outmsgs"]["debug"][]=ucfirst($in_msg_text).".";
 }


function mnotice($in_msg_text) {
# Notices.

 $GLOBALS["outmsgs"]["notices"][]=ucfirst($in_msg_text)."."; 
 }


function mbutton($in_button_html_text) {
# HTML buttons - output with the rest of the result report messages.
# Allows end user to issue a quick action from the result page if it makes
# sense for the app to provide one.

 $GLOBALS["outmsgs"]["buttons"][]=$in_button_html_text;
 }


function flag($in_flags) {
# Set appropriate global variable according to provided flag tags.

 if($in_flags="bug" or $in_flags="bug_or_hack"){$GLOBALS["suspect_bug"]=true;}
 if($in_flags="hack" or $in_flags="bug_or_hack"){$GLOBALS["suspect_hack"]=true;}
 if($in_flags="bad_db"){$GLOBALS["bad_db"]=true;}
 }


function merr($in_msg_text,$in_flags="") {
# Add error message to the pile of pending messages.
# Also calls flag() above.
# - If this array has any items, some error occurred and a failure should be
# reported.
# - any_errors() is used to check this conveniently.
 $GLOBALS["outmsgs"]["errors"][]=ucfirst($in_msg_text)."."; 
 if($in_flags!=""){flag($in_flags);}
 }


function mtrace($in_msg_text,$in_flags="") {
# Add trace message to the pile of pending messages.

 # Only if enabled - see set_globals().
 if(!isset($GLOBALS["internal"]["trace"])) { return; }

 if($in_flags==="") {
  $GLOBALS["outmsgs"]["trace"][]=$in_msg_text;
  } else {
  $GLOBALS["outmsgs"]["trace"][]="[".$in_flags."] ".$in_msg_text;
  }
#  echo "<p>mtrace: $in_msg_text</p>";
 }


function report_and_log_new_sql_txn($in_success,
			$in_session=Array("uid"=>"0","appuser-uid"=>"! EMPTY SESSION !"),
   			$in_eventdesc,$in_eventbody,
			$offer_event_view=false,
			$button_type="none", $button_type_target="none"
			) {
# 1. Issues a notice or error depending on first parameter which indicates
#    whether something succeeded (true) or failed (false).
# 2. Writes $in_eventdesc, $in_eventbody to the log.
# 3. Ends current SQL transaction and begins a new one before it makes a
#    call to log_entry().

 end_any_sql_transaction();
 begin_sql_transaction();

 if($in_success) { mnotice($in_eventdesc); }else{ merr($in_eventdesc); }
 $log_writing_result=log_entry( $in_session
			       ,$in_session["uid"]
			       ,"[".$in_session["appuser-uid"]."] ".$in_eventdesc
			       ,$in_eventbody
			       ,$offer_event_view
		               ,$button_type
			       ,$button_type_target
			       );

 $GLOBALS["sqltxn_commit"]=$log_writing_result;
 }


function quietly_log_new_sql_txn($in_ignored,
			$in_session=Array("uid"=>"0","appuser-uid"=>"unknown"),
   			$in_eventdesc,$in_eventbody,
			$offer_event_view=false,
			$button_type="none", $button_type_target="none"
			) {
# Like report_and_log_new_sql_txn() above but doesn't mnotice() or merr().

 end_any_sql_transaction();
 begin_sql_transaction();

 $log_writing_result=log_entry( $in_session
			       ,$in_session["uid"]
			       ,"[".$in_session["appuser-uid"]."] ".$in_eventdesc
			       ,$in_eventbody
			       ,$offer_event_view
		               ,$button_type
			       ,$button_type_target
			       );

 $GLOBALS["sqltxn_commit"]=$log_writing_result;
 }


# ----------------------------------------------------------------------------
# [ Initialization-related ]
# ----------------------------------------------------------------------------


function set_globals($in_user="",$in_hostname="",$in_this_script_name="") {
# So many functions use these I just said screw it and made it global.

 $GLOBALS["extra_goodies"]="";	# Additional output emitted after reporting
				# results of a command (inapplicable for
				# "show" method.)

 $GLOBALS["js"]="";		# Javascript that will be emitted within
 				# <script></script> tags.

 $GLOBALS["dbo"]=""; 		# Database interface object.

 $GLOBALS["dbo2"]=""; 		# 2nd database interface object (read only).

 # This array is the "message stack" - functions will add messages here which
 # are later emitted when appropriate.
 $GLOBALS["outmsgs"]="";		# Holds messages to be emitted.
 # Types of messages that can be array_pushed() to the "stack":
 $GLOBALS["outmsgs"]=Array("errors"=>Array(),
			   "notices"=>Array(),
			   "buttons"=>Array(),
			   "debug"=>Array(),
			   "trace"=>Array());

 # "Suspect" flags - set if something is encountered that could be suspicious.
 $GLOBALS["suspect_hack_flag"]=false;
 $GLOBALS["suspect_bug"]=false;
 $GLOBALS["bad_db"]=false;

 # Behavior flags:
 $GLOBALS["readonly"]=false;
 $GLOBALS["disabled"]=false;

 # Used to generate log messages.
 $GLOBALS["report"]=Array();

 # Check if the "disabled" file exists and set $GLOBALS["disabled"] if that is
 # the case.
 do {
  if(file_exists("/etc/lsc/disabled")) { $GLOBALS["disabled"]=true; break; }
  if(file_exists("/etc/lsc/$in_user/disabled")) { $GLOBALS["disabled"]=true; }
  } while(false);
 
 # Check if the "readonly" file exists and set $GLOBALS["readonly"] if that is
 # the case.
 do {
  if(file_exists("/etc/lsc/readonly")) { $GLOBALS["readonly"]=true; break; }
  if(file_exists("/etc/lsc/$in_user/readonly")) { $GLOBALS["disabled"]=true; }
  } while(false);

 # Other things controlled by file presence.
 if(file_exists("/etc/lsc/trace")) { $GLOBALS["internal"]["trace"]=true; }
 if(file_exists("/etc/lsc/debug")) { $GLOBALS["internal"]["debug"]=true; }

 $GLOBALS["username"]=$in_user;
 $GLOBALS["hostname"]=$in_hostname;
 $GLOBALS["scriptname"]=$in_this_script_name;
 # Just in case someone tries to inject HTML by renaming the script file.
 $GLOBALS["scriptname_out"]=safe4html($in_this_script_name,256);
 }


function ingest_ini($in_ini_filename) {
# Reads the .INI file (containing configuration) with parse_ini_file().
# Does some checks, raising an error with merr() if needed.
 if(!file_exists($in_ini_filename)) {
  merr("Welcome! There's no .ini file yet. To continue, please create an .ini file at <code>$in_ini_filename</code>.");
  return Array();
  }
 $out_ini=parse_ini_file($in_ini_filename,true);
 if(is_bool($out_ini) and (!$out_ini)) {
  merr("Unable to read .ini file.");
  return Array();
  }
 if(@$out_ini["readonly"]==="yes") { $GLOBALS["readonly"]=true; }
 if(isset($out_ini["general"]["timezone"])) { 
  $result=date_default_timezone_set($out_ini["general"]["timezone"]);
  if($result) {
   $GLOBALS["utc_offset"]=date('Z');
   $GLOBALS["timezone"]=$out_ini["general"]["timezone"];
   }
  }
 mtrace(".ini file read (\"".$in_ini_filename."\")");
 return $out_ini;
 }


# ----------------------------------------------------------------------------
# [ Code I borrowed from somewhere else ]
# ----------------------------------------------------------------------------


# from https://gist.github.com/lorenzos/1711e81a9162320fde20
# ----------------------------------------------------------------------------
	/**
	 * Slightly modified version of http://www.geekality.net/2011/05/28/php-tail-tackling-large-files/
	 * @author Torleif Berger, Lorenzo Stanco
	 * @link http://stackoverflow.com/a/15025877/995958
	 * @license http://creativecommons.org/licenses/by/3.0/
	 */
	function tailCustom($filepath, $lines = 1, $adaptive = true) {

		// Open file
		$f = @fopen($filepath, "rb");
		if ($f === false) return false;

		// Sets buffer size, according to the number of lines to retrieve.
		// This gives a performance boost when reading a few lines from the file.
		if (!$adaptive) $buffer = 4096;
		else $buffer = ($lines < 2 ? 64 : ($lines < 10 ? 512 : 4096));

		// Jump to last character
		fseek($f, -1, SEEK_END);

		// Read it and adjust line number if necessary
		// (Otherwise the result would be wrong if file doesn't end with a blank line)
		if (fread($f, 1) != "\n") $lines -= 1;
		
		// Start reading
		$output = '';
		$chunk = '';

		// While we would like more
		while (ftell($f) > 0 && $lines >= 0) {

			// Figure out how far back we should jump
			$seek = min(ftell($f), $buffer);

			// Do the jump (backwards, relative to where we are)
			fseek($f, -$seek, SEEK_CUR);

			// Read a chunk and prepend it to our output
			$output = ($chunk = fread($f, $seek)) . $output;

			// Jump back to where we started reading
			fseek($f, -mb_strlen($chunk, '8bit'), SEEK_CUR);

			// Decrease our line counter
			$lines -= substr_count($chunk, "\n");

		}

		// While we have too many lines
		// (Because of buffer size we might have read too many)
		while ($lines++ < 0) {

			// Find first newline and remove all text before that
			$output = substr($output, strpos($output, "\n") + 1);

		}

		// Close file and return
		fclose($f);
		return trim($output);

	}
# ----------------------------------------------------------------------------

# from https://www.uuidgenerator.net/dev-corner/php
# ----------------------------------------------------------------------------
function guidv4($data = null) {
    // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
    $data = $data ?? random_bytes(16);
    assert(strlen($data) == 16);

    // Set version to 0100
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // Set bits 6-7 to 10
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    // Output the 36 character UUID.
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
# ----------------------------------------------------------------------------

# ----------------------------------------------------------------------------
# [ Style sheet ]
# ----------------------------------------------------------------------------


function style_sheet() {
# Spit out inlined style sheet. 
# Minified version of normalize.css and concrete.css
# https://necolas.github.io/normalize.css/8.0.1/normalize.css
# https://unpkg.com/concrete.css@2.1.1/concrete.css

 htmlout("<style>");
 htmlout("html{line-height:1.15;-webkit-text-size-adjust:100%}body{margin:0}main{display:block}h1{font-size:2em;margin:0.67em 0}hr{box-sizing:content-box;height:0;overflow:visible}pre{font-family:monospace, monospace;font-size:1em}a{background-color:transparent}abbr[title]{border-bottom:none;text-decoration:underline;text-decoration:underline dotted}b,strong{font-weight:bolder}code,kbd,samp{font-family:monospace, monospace;font-size:1em}small{font-size:80%}sub,sup{font-size:75%;line-height:0;position:relative;vertical-align:baseline}sub{bottom:-0.25em}sup{top:-0.5em}img{border-style:none}button,input,optgroup,select,textarea{font-family:inherit;font-size:100%;line-height:1.15;margin:0;}button,input{overflow:visible}button,select{text-transform:none}button,[type='button'],[type='reset'],[type='submit']{-webkit-appearance:button}button::-moz-focus-inner,[type='button']::-moz-focus-inner,[type='reset']::-moz-focus-inner,[type='submit']::-moz-focus-inner{border-style:none;padding:0}button:-moz-focusring,[type='button']:-moz-focusring,[type='reset']:-moz-focusring,[type='submit']:-moz-focusring{outline:1px dotted ButtonText}fieldset{padding:0.35em 0.75em 0.625em}legend{box-sizing:border-box;color:inherit;display:table;max-width:100%;padding:0;white-space:normal}progress{vertical-align:baseline}textarea{overflow:auto}[type='checkbox'],[type='radio']{box-sizing:border-box;padding:0}[type='number']::-webkit-inner-spin-button,[type='number']::-webkit-outer-spin-button{height:auto}[type='search']{-webkit-appearance:textfield;outline-offset:-2px}[type='search']::-webkit-search-decoration{-webkit-appearance:none}::-webkit-file-upload-button{-webkit-appearance:button;font:inherit}details{display:block}summary{display:list-item}template{display:none}[hidden]{display:none}");
 htmlout("html * { font-family: Arial, Helvetica, sans-serif; font-size: 1.25rem; }");
 htmlout("body { max-width: 700px; margin: 0 auto; }");
 htmlout("table { width: 100%; table-layout: fixed; overflow-wrap: break-word; }");
 htmlout(".tablinks-title { padding: 0px; margin: 0px; text-align: center; background-color: blue; color: white; }");

 htmlout("tr.toplink-box-row { line-height: 0.8rem; }");
 htmlout("td.toplink-box { padding: 4px; 4px; 4px; 4px; border-style: solid; border-color: black; border-width: 1px; text-align: center; width: auto; }");
 htmlout("a.toplink { font-size: 1rem; font-weight: bold; font-variant-caps: small-caps; }");
 htmlout(".toplink-selected { background-color: white; }");
 htmlout(".toplink-not-selected { background-color: lightgrey; }");
 htmlout(".tablinks { background-color: lightgrey; border: solid; border-width: 1px; margin: 2px 2px 2px 2px; width: 40%; }");
 htmlout(".tablinks:active { color: white; }");
 htmlout(".tablinks.active { background-color: white; }");
 htmlout("form.top1 { display: inline; margin: 0px 0px 0px 0px; padding: 0px 0px 0px 0px; }");
 htmlout(".account-bar { color: lightgrey; font-size: 0.9rem; width: 100%; margin: 0px 0px 0px 0px; padding: 0px 0px 0px 0px; }");
 htmlout(".appuser-button { font-size: 0.9rem; width: 100px; color: darkblue; background-color: lightgrey; border: solid; border-width: 1px; border-color: darkgrey; margin: 0px 0px 0px 0px; padding: 0px 0px 0px 0px; }");
 htmlout(".appuser-button:active { color: white; }");
 htmlout(".logbutton-container { padding: 1px; margin: 1px; text-align: right; background-color: blue; color: darkgrey; }");

 htmlout(".logbutton { font-size: 1rem; background-color: lightgrey; border: solid; border-width: 1px; margin: 2px 2px 2px 2px; }");
 htmlout(".logbutton:active { color: white; }");

 htmlout(".rowmethod-container { padding: 1px; margin: 1px; text-align: right; background-color: blue; color: darkgrey; }");
 htmlout(".rowmethod-button { background-color: lightgrey; border: solid; border-width: 1px; margin: 2px 2px 2px 2px; }");
 htmlout(".rowmethod-button:active { color: white; }");
 htmlout(".stdout { font-family: monospace; font-size: 0.9rem; width: 100%; }");
 htmlout(".stdout-top { font-style: italic; margin: 0px 0px 0px 0px; padding: 16px 0px 0px 0px; }");
 htmlout(".tt { font-family: monospace; }");
 htmlout(".form-column-header          { font-size: 1rem; width: 30%; padding: 0px 0px 0px 0px; vertical-align: middle; background-color: #c0c0c0; border: 1px solid #ffffff; }");
 htmlout(".form-column-header-full     { font-size: 1rem; width: 100%; padding: 0px 0px 0px 0px; vertical-align: middle; background-color: #c0c0c0; border: 1px solid #ffffff; }");
 htmlout(".form-column-data            { font-size: 1rem; padding: 0px; vertical-align: middle; }");
 htmlout(".form-column-data-full       { font-size: 1rem; width: 100%; padding: 0px; vertical-align: middle; }");

 htmlout(".form-column-header-new      { font-size: 1rem; width: 30%; padding: 0px 0px 0px 0px; vertical-align: middle; background-color: #c0c0c0; border: 1px solid #ffffff; }");
 htmlout(".form-column-header-new-full { font-size: 1rem; width: 100%; padding: 0px 0px 0px 0px; vertical-align: middle; background-color: #c0c0c0; border: 1px solid #ffffff; }");
 htmlout("label { font-size: 1rem; }");
 htmlout(".form-column-data-new        { font-size: 1rem; padding: 0px 0px 0px 0px; vertical-align: middle; }");
 htmlout(".form-column-data-new-full   { font-size: 1rem; padding: 0px 0px 0px 0px; vertical-align: middle; }");
 htmlout(".non-editable-table { padding: 2px; border-style: solid; border-width: 1px; border-color: black; word-break: break-word; }");
 htmlout("caption.non-editable-table-caption { background-color: #c0c0c0; }");
 htmlout("caption.form-top { text-align: left; font-style: italic; padding: 16px 0px 0px 0px; }");
 htmlout(".return-link { text-align: right; }");
 htmlout(".presenting-pid { border-style: solid; border-width: 1px; padding-left: 5px; padding-right: 5px; font-size: 0.8rem; font-family: monospace; }");
 htmlout(".presenting-uuid { border-style: solid; border-width: 1px; padding-left: 5px; padding-right: 5px; font-size: 0.8rem; font-family: monospace; }");
 htmlout(".no-data { color: #c0c0c0; }");
 htmlout(".action-history-date { text-align: right; padding: 0px 0px 1px 0px; margin: 0px; font-size: 12px; background-color: #c0c0c0; }");
 htmlout(".action-history-event { padding: 1px; margin: 0px; font-size: 12px; }");
 htmlout(".action-history-container { background-color: white; padding: 0px; margin: 0px; word-break: break-word; }");
 htmlout(".usrmgmt-command-list { max-width: 100%; font-size: 0.8rem; height: 2rem; }");
 htmlout("option.usrmgmt-command { overflow: hidden; max-width: 100%; word-wrap: normal; white-space: normal; }");
 htmlout("</style>");
 }


# ----------------------------------------------------------------------------
# == = = = = = = = = = = = = = END SUBROUTINES  = = = = = = = = = = = = = = ==
# ----------------------------------------------------------------------------


# ----------------------------------------------------------------------------
# == = = = = = = = = = = = Master Schema Definition = = = = = = = = = = = = ==
# - Defines tables and columns.
# - Virtual column name "FOR_THIS_APP" defines some things for the entire
#   table.
# - Data for validation and methods are included as well as data constraints.
# ----------------------------------------------------------------------------

function set_schemadef() {
$GLOBALS["schemadef"]=Array(
 'conf/FOR_THIS_APP'		=>  'title:Configuration'
				   .'/new-form-title:				Configuration'
				   .'/allow-delete-by:				uuid'
				   .'/single-row-only'
				   .'/single-row-only-empty-message:No configuration currently defined'
		            	    	   .'/friendly-object-name:		    configuration'
			                 	   .'/instance-friendly-name-is:	uuid'
			        	           .'/toplink:				        configure'
					   .'/owner-identified-by:		uuid'
			                	   ,
 'conf/uuid'		        	=>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:app-generates/form-label:UUID/dont-show',
 'conf/basefilepath'	    	=>  'req:y/type:str/data:file/minlen:   1/maxlen:   1024/injourney:user-enters-text-for-localdir/form-label:Base File Path/default-value-from-ini:homedir/present-width:full-width',
 'conf/stdoutfilepath'	    	=>  'req:y/type:str/data:file/minlen:   1/maxlen:   1024/injourney:user-enters-text-for-localdir/form-label:stdout File Path/default-value-from-ini:stdoutdir/present-width:full-width',
 # ---------------------------------------------------------------------------
 'rpmap/FOR_THIS_APP'			=>  'title:Locations (Port and Prefix)'
					   .'/new-form-title:			Define A Port and Prefix Combination'
					   .'/allow-delete-by:			uuid'
					   .'/row-must-exist-in:		conf'
					   .'/must-exist-in-fails-message:	You can&apos;t define a location until you create a configuration.'
	            		    	   .'/friendly-object-name:		location'
	            		    	   .'/instance-friendly-name-is:	number'
	            	    		   .'/toplink:				        locations'
					   .'/owner-identified-by:		number'
	            			       ,
 'rpmap/pointer_to_conf'	    =>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:user-selects-from-list-in-other-table/form-label:Under Configuration/dont-show'.
				                    '/is-pointer-to:conf/pointer-links-by:uuid/shown-by:basefilepath',
 'rpmap/uuid'			        =>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:app-generates/form-label:UUID/dont-show',
 'rpmap/number'		        	=>  'req:y/type:int/data:port/minval:1024/maxval:  65535/injourney:user-enters-text-for-number/form-label:TCP&#47;IP Port Number/must-be-unique',
 'rpmap/urlprefix'	         	=>  'req:y/type:str/data:url /minlen:   1/maxlen:   1024/injourney:user-enters-text-for-urlprefix/form-label:URL Prefix',
 # ---------------------------------------------------------------------------
 'defined/FOR_THIS_APP'			=>  'title:Services'
		         		   .'/new-form-title:			Define A Service'
		           		   .'/allow-delete-by:			uuid'
	            		  	   .'/row-must-exist-in:		conf'
					   .'/must-exist-in-fails-message:	You can&apos;t create a service until you create a configuration.'
					   .'/friendly-object-name:		service'
					   .'/instance-friendly-name-is:	name'
					   .'/toplink:				services'
					   .'/owner-identified-by:		uuid'
		            		,
 'defined/pointer_to_conf'		=>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:user-selects-from-list-in-other-table/form-label:Under Configuration/dont-show'.
					    '/is-pointer-to:conf/pointer-links-by:uuid/shown-by:basefilepath',
 'defined/uuid'				=>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:app-generates/form-label:UUID/dont-show',
 'defined/name'				=>  'req:y/type:str/data:name/minlen:   1/maxlen:     50/injourney:user-enters-text/form-label:Service Name',
 'defined/description'			=>  'req:n/type:str/data:name/minlen:   0/maxlen:   4000/injourney:user-enters-text/form-label:Service Description',
 'defined/command'			=>  'req:y/type:str/data:cmd /minlen:   1/maxlen:    500/injourney:user-selects-from-ini-list/form-label:Command/present-width:full-width'.
					    '/ini-list-section:whitelist/ini-list-array:bin',
 'defined/arguments'			=>  'req:n/type:str/data:name/minlen:   0/maxlen:   4000/injourney:user-enters-text/form-label:Command Arguments (With Substitution Points)/present-width:full-width',
 'defined/defaultfile'			=>  'req:n/type:str/data:file/minlen:   1/maxlen:   1024/injourney:user-enters-text/form-label:Default File (blank for none) {LOCALFILE}'.
					    '/provides-defaults/gives-default-for-table:running/gives-default-for-column:localfile',
 'defined/defaultrpmap'			=>  'req:n/type:int/data:port/minval:1024/maxval:  65535/injourney:user-selects-from-list-in-other-table/form-label:Port And URL Prefix Combo'.
					    '/provides-defaults/gives-default-for-table:running/gives-default-for-column:rpmap'.
					    '/is-pointer-to:rpmap/pointer-links-by:number/shown-by:number,urlprefix',
 # ---------------------------------------------------------------------------
 'running/FOR_THIS_APP'			=>  'title:Processes (Started Services)'
				           .'/new-form-title:			Start A Service'
				           .'/table-method:check,Check All Processes'
				           .'/each-row-method:stdout,View output,uuid;stop,Stop Process,uuid;check,Check If Still Running,uuid'
				           .'/row-must-exist-in:		defined'
				           .'/must-exist-in-fails-message:	No defined services to start.'
				           .'/defaults-provided-by:		defined'
				           .'/defaults-in-provider-keyed-by:	uuid'
				           .'/defaults-here-keyed-by:		pointer_to_defined'
				           .'/friendly-object-name:		process'
				           .'/instance-friendly-name-is:	pid'
				           .'/backref-by:			uuid'
				           .'/toplink:				processes'
					   .'/owner-identified-by:		uuid'
				           ,
 'running/uuid'				=>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:app-generates/form-label:UUID/dont-show',
 'running/pointer_to_defined'   =>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:user-selects-from-list-in-other-table/form-label:Instance Of'.
			            	        '/is-pointer-to:defined/pointer-links-by:uuid/shown-by:name'.
				                    '/display-using-other-table/display-sql-SELECT:name/display-sql-FROM:defined/display-sql-WHERE:uuid/display-sql-IS:pointer_to_defined',
 'running/pid'		    	    =>  'req:y/type:int/data:pid /minval:   2/maxval:4194304/injourney:app-generates/form-label:Process ID',
 'running/localfile'		    =>  'req:n/type:str/data:file/minlen:   1/maxlen:   1024/injourney:user-enters-text-for-localfile/form-label:Local File {LOCALFILE}/present-width:full-width',
 'running/rpmap'		        =>  'req:n/type:int/data:uuid/minlen:  36/maxlen:     36/injourney:user-selects-from-list-in-other-table/form-label:Location (Port and URL Prefix)/must-be-unique'.
	   			                    '/is-pointer-to:rpmap/pointer-links-by:number/shown-by:number,urlprefix'.
				                    '/display-using-other-table/display-sql-SELECT:number,urlprefix/display-sql-FROM:rpmap/display-sql-WHERE:number/display-sql-IS:rpmap',
 'running/lastchecked'	    	=>  'req:n/type:str/data:date/minlen:   0/maxlen:       0/injourney:row-method/form-label:Last Checked',
 # ---------------------------------------------------------------------------
# 'maint/FOR_THIS_APP'	    	=>  'title:Maintenance Command Requests'
#				                   .'/new-form-title:			    Maintenance Request'
#			                	   .'/allow-delete-by:			    uuid'
#			                	   .'/single-row-only'
#		            	    	   .'/single-row-only-empty-message:No active maintenance request.'
#			                	   .'/friendly-object-name:		    maintenance request'
#			                	   .'/instance-friendly-name-is:	request'
#		            	    	   .'/each-row-method:execute,Execute maintenance request,uuid'
#		            	    	   .'/toplink:				        MR'
#					   .'/owner-identified-by:		uuid'
#			                	   ,
# 'maint/uuid'	       	    	=>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:app-generates/form-label:MRN',
# 'maint/request'	        	=>  'req:y/type:str/data:name/minlen:   1/maxlen:      64/injourney:user-selects-from-this-list/form-label:Action/this-list:deldb=Delete Database',
# 'maint/mkey'		         	=>  'req:y/type:str/data:name/minlen:  16/maxlen:    256/injourney:user-enters-text/form-label:Enter A Confirmation Password'.
#				                    '/is-confirmation-key-for:execute/confirmation-placeholder:Enter Confirmation Password',
 # ---------------------------------------------------------------------------
 'trashcan/FOR_THIS_APP'		=>  'title:Recycle Bin'
			               	   .'/allow-delete-by:			uuid'
			               	   .'/each-row-method:restart,Restart This Process,uuid'
			               	   .'/friendly-object-name:		previously started process'
			               	   .'/instance-friendly-name-is:	previously started process'
					   .'/owner-identified-by:		uuid'
			            	   .'/erase-upon-clear-logs'
			            	   ,
 'trashcan/uuid'			=>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:app-generates/form-label:UUID/dont-show',
 'trashcan/pointer_to_defined'		=>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:user-selects-from-list-in-other-table/form-label:Instance Of'.
				            '/is-pointer-to:defined/pointer-links-by:uuid/shown-by:name'.
				            '/display-using-other-table/display-sql-SELECT:name/display-sql-FROM:defined/display-sql-WHERE:uuid/display-sql-IS:pointer_to_defined',
 'trashcan/localfile'			=>  'req:n/type:str/data:file/minlen:   1/maxlen:   1024/injourney:user-enters-text-for-localfile/form-label:Local File {LOCALFILE}',
 'trashcan/rpmap'		        =>  'req:n/type:int/data:uuid/minlen:  36/maxlen:     36/injourney:user-selects-from-list-in-other-table/form-label:Port And URL Prefix Combo'.
					    '/is-pointer-to:rpmap/pointer-links-by:number/shown-by:number,urlprefix'.
					    '/display-using-other-table/display-sql-SELECT:number,urlprefix/display-sql-FROM:rpmap/display-sql-WHERE:number/display-sql-IS:rpmap',
 );
}


# ----------------------------------------------------------------------------
# == = = = = = = = = = = End Master Schema Definition = = = = = = = = = = = ==
# ----------------------------------------------------------------------------


# ----------------------------------------------------------------------------
# == = = = = = = = = = = = = = = CALLABLES  = = = = = = = = = = = = = = = = ==
# ----------------------------------------------------------------------------

# Section Index:
# - HOMEPAGE()
# - GENERATOR_xxx()
# - ROWMETHOD_xxx()
# - BUTTONHTML_xxx()
# - BUTTONFALLOFF_xxx()
# - VALIDATOR_xxx()

function HOMEPAGE() {
# Called via null_request() when the action is "show", the table is "none.",
# and $GLOBALS["output_format"] is "html".
# Uses $GLOBALS["username"].

 # Basically, a friendly, standard introduction.
 htmlout("<p>This is <span class='tt'>lsc</span> - Lawrence's Service Controller.</p>");
 htmlout("<p><span class='tt'>lsc</span> allows you to define <i>services</i>, and once defined, start and stop them.</p>");
 htmlout("<p>A service is a command that takes a filename, URL prefix, and/or a port number as part of its arguments.  These types of commands typically serve files over your network or the Internet and may be something you wish to conveniently start and stop remotely.</p>");
 htmlout("<p>If this is a new instance, you will first need to whitelist your desired executables in <code>lsc</code>'s .INI file (<span class='tt'>/etc/lsc/".$GLOBALS["username"]."/lsc.ini</span>).  Then create a configuration.  Next, you'll want to define some locations, a service or two, and after that, you can start and stop them from this interface.</p>");
 htmlout("<p><span class='tt'>lsc</span> as shipped will not run as root, can only launch executables that are whitelisted, will not run setuid/setgid executables, and can only manage services it has started that are running under the same local user account as itself.  There is also an internal blacklist of executable names <span class='tt'>lsc</span> will refuse to run, such as <span class='tt'>/bin/rm</span>.</p>");
 }

# ----------------------------------------------------------------------------
# [ === GENERATOR functions ]
# - When the user wants to create a new row, column data for that table can be
#   supplied by the user, or generated by the app.
# - For columns that are app generated, generators are called to supply the
#   needed data.
# - Generators may also make the app perform an action.
# - If a generator returns false, it is assumed something went wrong and a new
#   database row is not created.
# - The value that the generator is giving back to be put in the database is
#   in &$returned_value passed by reference.
# (See function generate_app_value().)
#
# GENERATOR_xxx parameters:
# - &$returned_value: generator function needs to set this, this will be the
#   value added in the column, IF the function returns true.
# - $in_table: table containing row/column needing new value.
# - $in_col: column needing new value.
# - $in_array_col_attrs: column attributes from global schema definition.
# - $in_PARAMS.
# - $in_session
# ----------------------------------------------------------------------------


function GENERATOR_pid (
 &$returned_value,
 $in_table, $in_col, $in_array_col_attrs, $in_PARAMS, $in_ini, $in_session
 ) {
 # Generating a pid = creating a new process.
 # https://stackoverflow.com/questions/9445815/how-to-background-a-process-via-proc-open-and-have-access-to-stdin

 # Resolve reference to defined process.
 $pdefined=$in_PARAMS["running_pointer_to_defined"];
 $rdefined=Array();
 if(!read_row_expecting_just_one($rdefined,"defined","uuid",$pdefined)) { return false; }

 # Defined process points to an rpmap.
 # Resolve reference to rpmap - needed to get port and urlprefix.
 $prpmap=$in_PARAMS["running_rpmap"];
 $rrpmap=Array();
 if(!read_row_expecting_just_one($rrpmap,"rpmap","number",$prpmap)) { return false; }

 # Defined process also points to a conf.
 # Resolve reference to conf - needed to get stdout path.
 $pconf=$rdefined["pointer_to_conf"];
 $rconf=Array();
 if(!read_row_expecting_just_one($rconf,"conf","uuid",$pconf)) { return false; }

 # Start building the command.  It begins with, well, the command.
 $cmd=$rdefined["command"];

 # Start building the arguments.
 $args="";

 # Doing that involves expanding substitution points in arguments.
 $nbrackets=0; $firstflag=false;
 $errorflag=false;

 $chopped=explode("{",$rdefined["arguments"]); # split stuff up based on {'s initially.
 foreach($chopped as $choppedmore) {
  # stuff BEFORE the first { is stuff we want to keep as part of the command.
  if(!$firstflag) { $firstflag=true; $args.=$choppedmore; continue; }
  # Each substring should have a } somewhere in it, otherwise there is not one
  # } for each {.
  if(!str_contains($choppedmore,'}')) {
   merr("Argument text is missing an ending '}'."); $errorflag=true;
   }
  # split the substring on the }.
  # Stuff before the } is part of the substitutor. 
  # Stuff after gets tacked on the command.
  $to_bracket=explode('}',$choppedmore); $nbrackets++;
  # Do the actual substitution.
  switch (trim(strtoupper($to_bracket[0]))) {
   case "LOCALFILE":	$args.=$in_PARAMS["running_localfile"]; break;
   case "URLPREFIX":	$args.=$rrpmap["urlprefix"]; break;
   case "PORT":		$args.=$in_PARAMS["running_rpmap"]; break;
   case "";
    merr("Argument text has a pair of brackets with nothing inbetween.");
    $errorflag=true;
    break;
   default:
    merr("Unknown substitutor '".$to_bracket[0]."'.");
    $errorflag=true;
   }
  $args.=$to_bracket[1];
  }
 # Any errors processing the substitutions, we exit with an error.
 if ($errorflag) { return false; }
 
 # Check executable against blacklist.
 # Each time, every time. You never know.
 $tmp=explode(" ",$cmd);
 if(is_blacklisted($tmp[0])) { return false; }
 # Check executable suid/sgid bits.
 # Each time, every time. You never know.
 $mode=fileperms($tmp[0]);
 if(is_bool($mode)) {
  report_and_log_new_sql_txn(false,$in_session,"Process not started; unable to check executable permissions","");
  return false;
  }
 if(($mode & 06000)!=0) {
  report_and_log_new_sql_txn(false,$in_session,"Process not started; executable has suid or sgid bit set","");
  return false;
  }

 # This is a "backchannel" flag set by ROWMETHOD_restart_trashcan to indicate
 # we are actually restarting a process.
 # If we are doing that we want to say "restart" instead of "start".
 $re="";
 if(isset($in_PARAMS["restart"])) { $re="re"; }

 # Assemble stdoutpath (path from config, filename from service definition).
 $stdoutpath=path_merge(trim($rconf["stdoutfilepath"]),trim(make_filename_ready($rdefined["name"])."-stdout.txt"));

 # We might immediately "remove" the row (actually, not even create it) if the
 # launch is not successful.  So, we'll need these ...
 $removing=false; $removing_info="";

 # Ok, let's launch!
 $pidArr=Array();
 $exec_result=exec(sprintf("%s > %s 2>&1 & echo $!", trim($cmd." ".$args), $stdoutpath), $pidArr);
 # Result:
 if($exec_result==false) {
  $removing=true; $removing_info=$rdefined["name"]." not ".$re."started (exec failed)";
  }
 if(!(isset($pidArr[0]))) {
  $removing=true; $removing_info=$rdefined["name"]." not ".$re."started (no PID returned from exec)";
  }
 
 # Make sure it doesn't die right away.
 sleep(2);
 if(!posix_getpgid($pidArr[0])) {
  $removing=true; $removing_info="PID ".$pidArr[0]." was started, but then died right away.";
  }

 $GLOBALS["extra_goodies"].="<div class='stdout-top'>Last 50 lines of captured <tt>stdout</tt>:</div>\n";
 $GLOBALS["extra_goodies"].="<textarea rows=25 class='stdout'>";
 $GLOBALS["extra_goodies"].=tailCustom($stdoutpath,50);
 $GLOBALS["extra_goodies"].="</textarea>";

 if(!$removing) {
  $returned_value=$pidArr[0]; 
  report_and_log_new_sql_txn(true,$in_session,$rdefined["name"]." ".$re."started - PID ".$pidArr[0],"");
  return true;
  }

 if($removing) {
  $trash["uuid"]=guidv4();
  $trash["pointer_to_defined"]=$pdefined;
  $trash["localfile"]=$in_PARAMS["running_localfile"];
  $trash["rpmap"]=$prpmap;
  # below not needed, never created.
  #delete_row_bypass_schema($in_target_table,"uuid",$in_target);
  insert_row("trashcan",$trash);
  if(any_db_error()) { return false; }
  claim_rights($in_session,"trashcan",$trash);
  if(any_db_error()) { return false; }
  $GLOBALS["sqltxn_commit"]=true;
  report_and_log_new_sql_txn(true,$in_session,$removing_info,"",false,"restart",$trash["uuid"]);
  mbutton(BUTTONHTML_restart($trash["uuid"]));
  }

 }

# ----------------------------------------------------------------------------
# [ === ROW METHOD HANDLER functions ]
# - Row methods are specified in a table's FOR_THIS_APP virtual column.
# ----------------------------------------------------------------------------

#function ROWMETHOD_execute_maint(
# $in_table, $in_target, $in_target_table, $in_PARAMS, $in_ini, $in_session
# ) {
#
# # Resolve reference to maintenance request
# $rrequest=Array();
# if(!read_row_expecting_just_one($rrequest,$in_table,"uuid",$in_target)) { return false; }
#
# # Check if keys match, bounce if they don't.
# if($in_PARAMS["mkey"]!==$rrequest["mkey"]) {
#  merr("Confirmation key is wrong.  The request was not executed.");
#  mnotice("If you forgot the confirmation key, delete your request and try again.");
#  return false; 
#  }
#
# $not_implemented=false; $close=false;
# switch ($rrequest["request"]) {
#  case "deldb":
#   # TODO: Actually do this.
#   # mnotice("Database deleted.  It will be automatically recreated the next time the app is accessed.");
#   $not_implemented=true;
#   $close=true;
#   break;
#  default:
#   merr("Unrecognized request '".$rrequest["request"]."'.  The request was not executed.","hack");
#   $close=false;
#  }
#
# if($not_implemented) {
#  mnotice("'".$rrequest["request"]."' isn't implemented yet.  Nothing was done.");
#  }
#
# if($close) {
#  delete_row_bypass_schema($in_table,"uuid",$in_target);
#  if(any_db_error()) { return false; }
#  mnotice("closing request '".make_presentable($rrequest["uuid"],"uuid")."'.");
#  }
#
# }


function ROWMETHOD_check_running( 
 $in_table, $in_target, $in_target_table, $in_PARAMS, $in_ini, $in_session
 ) {

 # Resolve request to running process.
 $rrunning=Array();
 if(!read_row_expecting_just_one($rrunning,$in_target_table,"uuid",$in_target)) { return false; }

 # Running process points to a defined process.
 # Resolve reference to defined process - we need its name which lives there. 
 $name="Unknown Service"; $rdefined=Array();
 if(read_row_expecting_just_one($rdefined,"defined","uuid",$rrunning["pointer_to_defined"])) {
  $name=$rdefined["name"];
  }
 $pid=$rrunning["pid"];

 $removing=false; $removing_info="";
 
 $update_data['lastchecked']=time();
 update_row($in_target_table,$update_data,"uuid",$in_target);
 
 if(posix_getpgid($pid)) { 
  report_and_log_new_sql_txn("true",$in_session,"PID ".$pid." is still running","");
  }else{
  $removing=true; $removing_info="PID ".$pid." is no longer running";
  }

 if($removing) {
  $trash["uuid"]=guidv4();
  $trash["pointer_to_defined"]=$rrunning["pointer_to_defined"];
  $trash["localfile"]=$rrunning["localfile"];
  $trash["rpmap"]=$rrunning["rpmap"];
  delete_row_bypass_schema($in_target_table,"uuid",$in_target);
  if(any_db_error()) { return false; }
  insert_row("trashcan",$trash);
  if(any_db_error()) { return false; }
  claim_rights($in_session,"trashcan",$trash);
  if(any_db_error()) { return false; }
  $GLOBALS["sqltxn_commit"]=true;
  report_and_log_new_sql_txn(true,$in_session,$removing_info,"",false,"restart",$trash["uuid"]);
  mbutton(BUTTONHTML_restart($trash["uuid"]));
  } 

 }


function ROWMETHOD_stop_running(
 $in_table, $in_target, $in_target_table, $in_PARAMS, $in_ini, $in_session
 ) {

 # Resolve reference to running process.
 $rrunning=Array();
 if(!read_row_expecting_just_one($rrunning,$in_target_table,"uuid",$in_target)) { return false; }

 # Running process points to a defined process.
 # Resolve reference to defined - we need its name which lives there.
 $name="Unknown Service"; $rdefined=Array();
 if(read_row_expecting_just_one($rdefined,"defined","uuid",$rrunning["pointer_to_defined"])) {
  $name=$rdefined["name"];
  }
 $pid=$rrunning["pid"];

 $removing=false; $removing_info="";

 if(posix_getpgid($pid)) {
  posix_kill($pid,15);
  sleep(2);
  if(posix_getpgid($pid)) {
   posix_kill($pid,9);
   }
  if(posix_getpgid($pid)) {
   report_and_log_new_sql_txn(false,$in_session,$name." not stopped - ".$pid." didn't respond to SIGINT or SIGKILL","");
   }else{
   $removing=true; $removing_info=$name." stopped";
   }
  }else{
  $removing=true; $removing_info=$pid." (".$name.") is not running anymore; removing PID from table";
  }

 if($removing) {
  $trash["uuid"]=guidv4();
  $trash["pointer_to_defined"]=$rrunning["pointer_to_defined"];
  $trash["localfile"]=$rrunning["localfile"];
  $trash["rpmap"]=$rrunning["rpmap"];
  delete_row_bypass_schema($in_target_table,"uuid",$in_target);
  if(any_db_error()) { return false; }
  insert_row("trashcan",$trash);
  if(any_db_error()) { return false; }
  claim_rights($in_session,"trashcan",$trash);
  if(any_db_error()) { return false; }
  $GLOBALS["sqltxn_commit"]=true;
  report_and_log_new_sql_txn(true,$in_session,$removing_info,"",false,"restart",$trash["uuid"]);
  mbutton(BUTTONHTML_restart($trash["uuid"]));
  } 

 }


function ROWMETHOD_stdout_running (
 $in_table, $in_target, $in_target_table, $in_PARAMS, $in_ini, $in_session
 ) {

 # Resolve reference to running process.
 $rrunning=Array();
 if(!read_row_expecting_just_one($rrunning,$in_target_table,"uuid",$in_target)) { return false; }

 # Running process also points to a defined process.
 # Resolve that too...
 $pdefined=$rrunning["pointer_to_defined"];
 $rdefined=Array();
 if(!read_row_expecting_just_one($rdefined,"defined","uuid",$pdefined)) { return false; }

 # Defined process also points to a conf.
 # We really need that.
 $pconf=$rdefined["pointer_to_conf"];
 $rconf=Array(); 
 if(!read_row_expecting_just_one($rconf,"conf","uuid",$pconf)) { return false; }

 # Assemble stdoutpath (path from config, filename from service definition).
 $stdoutpath=path_merge(trim($rconf["stdoutfilepath"]),trim(make_filename_ready($rdefined["name"])."-stdout.txt"));

 $GLOBALS["extra_goodies"].="<div class='stdout-top'>Last 50 lines of captured <tt>stdout</tt>:</div>\n";
 $GLOBALS["extra_goodies"].="<textarea rows=25 class='stdout'>";
 $GLOBALS["extra_goodies"].=tailCustom($stdoutpath,50);
 $GLOBALS["extra_goodies"].="</textarea>";

 return true;
 }


function ROWMETHOD_restart_trashcan (
 $in_table, $in_target, $in_target_table, $in_PARAMS, $in_ini, $in_session
 ) {
 # Not invocable directly from the interface - but indirectly through buttons in
 # the action history.

 
 # Resolve reference to piece of trash.
 $rpiece_of_trash=Array();
 if(!read_row_expecting_just_one($rpiece_of_trash,$in_target_table,"uuid",$in_target)) { 
  mnotice("Stopped process '".$in_target."' not found; it may have already been restarted or the history was cleared."); 
  return false;
  }
 
 # Restarting a process from the trash - we're gonna use the same API that a
 # POST request would.  So we'll supply the necessary data.
 $fakePOST["running_pointer_to_defined"]=$rpiece_of_trash["pointer_to_defined"];
 $fakePOST["running_localfile"]=$rpiece_of_trash["localfile"];
 $fakePOST["running_rpmap"]=$rpiece_of_trash["rpmap"]; 
 # $fakePOST["restart"] ...
 # This is a "backchannel" to tell GENERATOR_pid() to output "restarted"
 # instead of "started."
 $fakePOST["restart"]="true";

 $corpse=Array(); # ARRAY_IN_DATA() 

 # look for buttons referring to this trash item.
 $log_line=Array();
 read_row_expecting_just_one($log_line,"log","button_type_target",$in_target);
 if(any_db_error()) { return false; }
 $log_line["button_type"]="restarted";
 $log_line["button_type_target"]="none";
 update_row("log",$log_line,"button_type_target",$in_target); 

 # remove process (the piece of trash) from the trash
 delete_row_bypass_schema($in_target_table,"uuid",$in_target,false);
 if(any_db_error()) { return false; }
 # waive rights on it too
 waive_rights($in_session,"trashcan",$in_target); 
 if(any_db_error()) { return false; }

 # most of this is the same steps from the "new_row" action handler.
 do {
  # the "fill_data" fnctions will take data in $fakePOST[] and fill in
  # $corpse[] ...
  fill_data_array_from_query_string("running",$fakePOST,$corpse);
  # GENERATOR_pid() for the corpse dispatched by the below call.
  fill_data_array_from_app_generated_injourneys("running",$corpse,$fakePOST,$in_ini,$in_session);
   if(any_errors()) { break; }
  # Corpse should be reanimated at this point.
  set_report_names_for_insert("running",$corpse);
  make_backrefs_for_new_row("running",$corpse);
  if(any_db_error()) { return false; }
  insert_row("running",$corpse);
  if(any_db_error()) { return false; }
  } while (false); 
  return true;

 }

# ----------------------------------------------------------------------------
# [ === BUTTONHTML functions ]
# - Emit HTML to render button when one exists in the action history.
#   Normally called by output_log().
# ----------------------------------------------------------------------------

function BUTTONHTML_restart($in_button_type_target) {
 $outstr="\n <tr><td class='logbutton-container'><form action='".$GLOBALS["scriptname"]."' method=post>"
        ."\n <input class='logbutton' type=submit value='Restart Process' />"
        ."\n <input type='hidden' id='action' name='action' value='row_method_action' />"
        ."\n <input type='hidden' id='row_method' name='row_method' value='restart' />"
        ."\n <input type='hidden' id='table' name='table' value='trashcan' />"
        ."\n <input type='hidden' id='target' name='target' value='".$in_button_type_target."' />"
        ."\n <input type='hidden' id='return_to' name='return_to' value='running' />"
        ."\n </form></td></tr>\n"
	;
 return $outstr;
 }

function BUTTONHTML_restarted($in_button_type_target) {
 return "\n <tr><td class='logbutton-container'><p class='logbutton'>Restarted above</p></td></tr>\n";
 }

# ----------------------------------------------------------------------------
# [ === BUTTONFALLOFF functions ]
# - The action history only holds so many items. 
#   When an item in the action history table ("log") is deleted, and that
#   log entry has a button, this is called to provide an opportunity to clean
#   it up.
# - Note: When the entire log is cleared, "erase-upon-clear-log" can handle
#   the cleanup for any tables referenced by buttons in the "log" table.
# ----------------------------------------------------------------------------

function BUTTONFALLOFF_restart($in_session,$in_button_type_target) {
 delete_row_bypass_schema("trashcan","uuid",$in_button_type_target,false);
 waive_rights($in_session,"trashcan",$in_button_type_target);
 }

function BUTTONFALLOFF_restarted($in_session,$in_button_type_target) {
 delete_row_bypass_schema("trashcan","uuid",$in_button_type_target,false);
 waive_rights($in_session,"trashcan",$in_button_type_target);
 }


# ----------------------------------------------------------------------------
# [ === VALIDATOR functions ]
# - Can be provided for any row_column combination.  Will be called when new
#   row data is being validated.  Expected to return true (OK) or false (bad).
# ----------------------------------------------------------------------------

function VALIDATOR_defined_arguments ( $in_data ) {
 $nbrackets=0; $firstflag=false;
 $chopped=explode("{",$in_data);
 foreach($chopped as $choppedmore) { 
  if(!$firstflag) { $firstflag=true; continue; }
  if(!str_contains($choppedmore,'}')) {
   merr("Argument text is missing an ending '}'."); return false;
   }
  $to_bracket=explode('}',$choppedmore); $nbrackets++;
  switch (trim(strtoupper($to_bracket[0]))) {
   case "LOCALFILE":
   case "URLPREFIX":
   case "PORT":
    break; 
   case "";
    merr("Argument text has a pair of brackets with nothing inbetween."); return false;
    break;
   default:
    merr("Unknown substitutor '".$to_bracket[0]."'.");
   } 
  }
 
 return true;
 }


# ----------------------------------------------------------------------------
# == = = = = = = = = = = = = =  END CALLABLES = = = = = = = = = = = = = = = ==
# ----------------------------------------------------------------------------


?>
