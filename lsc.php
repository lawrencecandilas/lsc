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
#   setting the action to 'uid-0-muzzle' if this happens to be running as
#   root.
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
if($ACTION!="uid-0-muzzle") {
 $ini_file="/etc/lsc/".$user."/lsc.ini";
 }

# What table are we working on?
# - "none" is a default value, will be overriden by "table" value in $_GET[]
# or $_POST[] later, and if it's not, then we handle that specially.
$table="none";

# ----------------------------------------------------------------------------
# Execute action.
# Everything is centered around the database so any action will be in terms
# of that database. Things that are not database operations must be expressed
# as side effects in GENERATOR_ or ROWMETHOD_ functions.
# The only exception made is "clear_log" which simply clears the action
# history.
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
  validate_data_array($P["table"],$ARRAY_IN_DATA);
   if(any_errors()) { break; }
  bounce_single_row_only($P["table"]);
   if(any_errors()) { break; }
  fill_data_array_from_app_generated_injourneys($P["table"],$ARRAY_IN_DATA,$_POST,$ini,$my_name);
   if(any_errors()) { break; }
  $result=check_unique($P["table"],$ARRAY_IN_DATA);
  if(!$result) { break; }
  set_report_names_for_insert($P["table"],$ARRAY_IN_DATA);
  make_backrefs_for_new_row($P["table"],$ARRAY_IN_DATA);
   if(any_errors()) { break; }
  insert_row($P["table"],$ARRAY_IN_DATA);
   if(any_errors()) { break; }
  $o=$GLOBALS["report"]["target_objectname"]." '".$GLOBALS["report"]["target_instancename"]."'";
   if(any_errors()) {
    report_and_log_new_sql_txn(false,"Error creating new ".$o,"Failed to create new ".$o."."); 
    break;
    }else{
    $GLOBALS["sqltxn_commit"]=true;
    report_and_log_new_sql_txn(true,"new ".$o." created",""); 
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
  $deleteable=set_report_names_for_delete($P["table"],$P["target"]);
   if(any_errors()) { break; }
   if(!$deleteable) { break; }
  delete_row($P["table"],$P["target"]);
  $o=$GLOBALS["report"]["target_objectname"]." '".$GLOBALS["report"]["target_instancename"]."'";
   if(any_errors()) {
    break;
    }else{
    $GLOBALS["sqltxn_commit"]=true;
    report_and_log_new_sql_txn(true,"Deleted ".$o,"");
    }
  break;
 # ---------------------------------------------------------------------------
 case "row_method_actio":
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
  if($P["target_table"]==="optional") { $P["target_table"]=$P["table"]; }
  $GLOBALS["sqltxn_commit"]=true;
   # Row methods are responsible for clearing $GLOBALS["sqltx_commit"] if 
   # writes should not be committed. It's assumed most row method actions will
   # at least want to write to the log even in the case of failure.
  row_method_action($P["row_method"],
		    $P["table"],$P["target"],$P["target_table"],
		    $_POST,$ini);
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
  delete_all_rows_bypass_schema("log");
   if(any_errors()) { break; }
  do_erase_upon_clear_logs();
   if(any_errors()) { break; }
  update_row("internal",Array("nlog"=>0),"rowid",1);
   if(any_errors()) {
    report_and_log_new_sql_txn(false,"Clearing action history failed.","");
    break;
    }else{
    $GLOBALS["sqltxn_commit"]=true;
    report_and_log_new_sql_txn(true,"Action history cleared.","");
    }
  break;
 # ---------------------------------------------------------------------------
 case "disabled":
 # ---------------------------------------------------------------------------
  # validate_action() already issued a message at this point.
  # fall through
 # ---------------------------------------------------------------------------
 case "uid-0-muzzle":
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

start_output($P["table"]);

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
  break; 
 case "delete_row":
  content_panel_start($table,$ACTION);
   output_messages();
   content_panel_end();
  history_panel_start();
  htmlout("<p class='return-link'><a href='".$return_link."'>[OK]</a></p>");
  break; 
 case "row_method_actio":
 case "clear_logs":
  content_panel_start($table,$ACTION);
   output_messages();
   content_panel_end();
  history_panel_start();
  htmlout("<p class='return-link'><a href='".$return_link."'>[OK]</a></p>");
  break; 
 case "show":
  if($table=="none") { null_request(); break; }
  if(any_errors()) { 
   content_panel_start($table,$ACTION);
    output_messages();
    content_panel_end();
   history_panel_start();
   htmlout("<p class='return-link'><a href='".$my_name."'>[Home]</a></p>");
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
  break;
 default:
  content_panel_start($table,$ACTION);
   output_messages();
   content_panel_end();
 }
history_panel_end();

# Output any generated Javascript.
# - Javascript is used to populate default values in form fields when the
#   a list item that provides default values changes selection.
htmlout("<script type='text/javascript'>");
htmlout($GLOBALS["js"]);
htmlout("</script>\n");

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
 $in_ini
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
  $function($in_table,$in_target,$in_target_table,$in_PARAMS,$in_ini);
  return;
  } else {
  merr("nothing callable was found that handles '".actnam($in_row_method)."' of ".tblnam($in_table).".","bug");
  return;
  } 
 }


# ----------------------------------------------------------------------------
# Section output start and stop
# ----------------------------------------------------------------------------


function start_output($in_table) {
# Begin generating output.
# - For HTML, we'll start the html, body, and main tags, and take care of the
#   head element as well.
# - Javascript content comes from outer code, right before outer code calls
#   finish_output()
 $tbl="none"; if(isset($in_table)and($in_table!="")){ $tbl=$in_table; }

 switch($GLOBALS["output_format"]){
  case "text":
   break;
  case "html":
   htmlout("<!doctype html>");
   htmlout("<html>");
   htmlout("<head>");
   htmlout("<title>lsc.php 20240327</title>");
   style_sheet();
   htmlout("</head>");
   htmlout("<body>");
   htmlout("<main>");
   htmlout("<div id='app-header'>");
   htmlout("<table><tr><td><h2>lsc</h2></td><td><h2 style='text-align: right'>".$GLOBALS["username"]."@".$GLOBALS["hostname"]."</span></h2></td></tr></table>");
   htmlout("<table>");

   htmlout("<tr class='toplink-box-row'>");
   htmloutp("<td class='toplink-box ");
   if($tbl==="none") { htmloutp("toplink-selected'"); } else { htmloutp("toplink-not-selected'"); }
   htmloutp("><p><a class='toplink' href='".$GLOBALS["scriptname"]."'>home</a></p></td>",1);

   foreach($GLOBALS["schemadef"] as $tblcolname=>$colattrs) {
   if(!str_ends_with($tblcolname,"FOR_THIS_APP")) { continue; }
    $split_tblcolname=Array(); $split_tblcolname=explode('/',$tblcolname);
    $attrs=schema_rowattr($tblcolname);
    if(isset($attrs["toplink"])) {
     htmloutp("<td class='toplink-box ");
     if($split_tblcolname[0]===$tbl) { htmloutp("toplink-selected'"); } else { htmloutp("toplink-not-selected'"); }
     htmloutp("><p><a class='toplink' href='".$GLOBALS["scriptname"]."?table=".$split_tblcolname[0]."'>".$attrs["toplink"]."</a></p></td>",1);
     }
    }
   htmlout("</tr>");

   htmlout("</table>");
   htmlout("</div>");
   break; # /case "html";
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
  htmlout("<tr colspan=2><td><p class='tablinks-title'>".$in_table_metadata["title"]."</p></td></tr>");
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
   if(@$GLOBALS["output_debug_msgs"]) {
     htmlout("<!--"); htmlout("Debug Messages:");
    foreach($GLOBALS["outmsgs"]["debug"] as $msg) {
     htmlout($msg);
     }
     htmlout(" -->");
    }
   if(@$GLOBALS["output_trace_msgs"]) {
     htmlout("<!--"); htmlout("Trace Messages:");
    foreach($GLOBALS["outmsgs"]["trace"] as $msg) {
     htmlout($msg);
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

function output_log($in_return_link) {
# Output "Action History" table.
 if($GLOBALS["output_format"]!=="html") { return; } # HTML format only.

 htmlout("<table class='non-editable-table'>");
 htmlout("<caption class='non-editable-table-caption'>Recent Actions</caption>");
 if(isset($GLOBALS["timezone"])) { 
  htmlout("<tr><td class='action-history-event'>Time Zone: ".$GLOBALS["timezone"]."</td></tr>");
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
  htmlout("<td class='action-history-event'>".$logline["eventdesc"]."</td>");
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
     htmlout("<form action='".$GLOBALS["scriptname"]."' method=post>");
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
    htmlout("<caption class='form-top'>Current ".$in_title."</caption>");
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
    $html=$data;
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
   htmlout("<td colspan=2 style='text-align: right'>".$message."</td>");
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
    textoutp("table-row-colnames","\"".$colname."\"");
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
    htmlout("<p>".$table_metadata["must-exist-in-fails-message"]."</p>");
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
 htmlout("<caption class='form-top'>".$table_metadata["new-form-title"]."</caption>");

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
     $tmp="value='".$attrs["default-value"]."'";
     }
    if(isset($attrs["default-value-from-ini"])) {
     $tmp="value='".$in_ini["defaults"][$attrs["default-value-from-ini"]]."'";
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
     htmlout("<input type='hidden' name='".$id."' id='".$id."' value='".$list_data["targets"][0]."' />");
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
      htmlout("<option value='".$list_datum."'>".$list_data["display_names"][$n]."</option>");
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
  $noun=" ".$table_metadata["friendly-object-name"];
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
 $in_ini
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
			      $in_which_table,$col,$attrs,
			      $in_PARAMS,
			      $in_ini
			      );
   if(!($result)) {
    merr("Failed.");
    }else{
    $out_array_data[$col]=$value;
    }
   }
  }
 }


function generate_app_value(
 &$returned_value, $in_which_table, $in_which_col,
 $in_array_col_attrs,
 $in_PARAMS,
 $in_ini
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
		   $in_which_table,$in_which_col,$in_array_col_attrs,$in_PARAMS,
		   $in_ini
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
# valid characters and is a valid length (16 characters or less).
# - Whitelisting of valid action values can be done here.
# - Default action if action query string is null, blank, or not specified is
#   determined here (currently "show").
# - Also outright replace it and issue mnotice()'s' if certain conditions
#   exist, for safety or administrative reasons.
# set_globals() should be called first.
 if(posix_getuid()==0){
  merr("This application will not process requests when running as uid 0.");
  return "uid-0-muzzle";
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
  $tmp=trim(strtolower(substr($in_qsvar_action,0,16)));
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


function make_presentable($in_data,$in_type) {
 $out_data="";
 switch ($in_type) {
  case "pid":
   if($GLOBALS["output_format"]==="html") { $out_data="<span class='presenting-pid'>"; }
   $out_data.="PID ".$in_data;
   if($GLOBALS["output_format"]==="html") { $out_data.="</span>"; }
   break;
  case "uuid":
   if($GLOBALS["output_format"]==="html") { $out_data="<span class='presenting-uuid'>"; }
   $out_data.="UUID ".$in_data;
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
# Open database (modifies $GLOBALS["dbo"]).
# Also checks database for tables and creates them if missing.
# Indirectly uses $GLOBALS["schemadef"] via tables_from_schemadef()
# Uses $GLOBALS["outmsgs"]

 # These are tables used internally that are not part of the schema.
 # If these happen to conflict with any defined in the schema, the schema
 # will be ignored.
 mtrace("DB op: open_database($in_filename)");
 $nonschema_table_sql=Array(
  "internal"	=>"CREATE TABLE internal (nlog INTEGER NOT NULL, lockedwithkey TEXT);",
  "log"		=>"CREATE TABLE log (id INTEGER NOT NULL PRIMARY KEY, source TEXT NOT NULL, eventdesc TEXT NOT NULL, event TEXT NOT NULL, timestamp TEXT NOT NULL, offer_event_view TEXT NOT NULL, button_type TEXT NOT NULL, button_type_target TEXT NOT NULL);",
  "backref"	=>"CREATE TABLE backref (id INTEGER NOT NULL PRIMARY KEY, to_table TEXT NOT NULL, to_key_col_name TEXT NOT NULL, to_key_col_value TEXT_NOT_NULL, from_table TEXT NOT NULL, from_key_col_name TEXT NOT NULL, from_key_col_value TEXT NOT NULL);"
 );
  
 $GLOBALS["dbo"]=new SQLite3($in_filename);
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

 # Now, let's look at the database and see what's missing.
 $missing_tables=array();
 $sql="SELECT * FROM sqlite_master WHERE type='table'";
  mtrace("sql: \"$sql\"");
 $statement=$GLOBALS["dbo"]->prepare($sql);
 $results=$statement->execute();

 foreach($required_tables as $required_table) {
  $found=false;
  while($row=$results->fetchArray()){
   if(($row["name"]===$required_table)){ $found=true; break; }
   }
  if(!$found) {
   mtrace("database is missing table \"$required_table\", going to add");
   $missing_tables[]=$required_table;
   };
  }

 $init_internal_table=false;

 # Now, create any missing tables if needed.
 if(count($missing_tables)>0) {
  begin_sql_transaction(); 
  foreach($missing_tables as $missing_table) {
   if(isset($nonschema_table_sql[$missing_table])) {
    $sql=$nonschema_table_sql[$missing_table];
   }else{
    $sql=sql_to_make_table_from_schemadef($missing_table);
    mtrace("sql: \"$sql\"");
    }
   if($missing_table==="internal") { $init_internal_table=true; }
   $result=$GLOBALS["dbo"]->exec($sql);
    if(any_db_error()) { end_any_sql_transaction(); return; }
   }

  # Initialize the internal table if needed.
  if($init_internal_table) {
   mtrace("initializing internal table");
   $init_data=Array("nlog"=>0);
   insert_row("internal",$init_data);
    if(any_db_error()) { end_any_sql_transaction(); return; }
   log_entry("app","database created","A new, empty database has been created.");
    if(any_db_error()) { end_any_sql_transaction(); return; }
   }
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


function log_entry($in_source,$in_eventdesc,$in_eventbody,$offer_event_view=false,$button_type="none",$button_type_target="none") {
# Adds an entry to the log (a.k.a. "action history").
# Rotates an entry out if it's full. Rotating out includes making sure
# anythimg in the trashcan that has a button on the log is removed as well.
#
# Returns true if no database error occured, false if one did.
 mtrace("log_entry(source:$in_source, eventdesc:$in_eventdesc, eventbody:$in_eventbody, offer_event_view:$offer_event_view, button_type:$button_type, button_type_target:$button_type_target)");

 # We can't do anything if database isn't open.
 if(!isset($GLOBALS["dbo"])){ return false; }
 # Add it to the log table.
 $log_entry=Array( "source"=>$in_source
		  ,"eventdesc"=>$in_eventdesc
		  ,"event"=>$in_eventbody
		  ,"timestamp"=>time()
		  ,"offer_event_view"=>$offer_event_view
		  ,"button_type"=>$button_type
		  ,"button_type_target"=>$button_type_target
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
    $function($row["button_type_target"]);
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
   $new_backref=Array("to_table"=>$attrs["is-pointer-to"],
		      "to_key_col_name"=>$attrs["pointer-links-by"],
		      "to_key_col_value"=>$in_array_in_data[$split_tblcolname[1]],
		      "from_table"=>$in_which_table,
		      "from_key_col_name"=>$backref_by,
		      "from_key_col_value"=>$in_array_in_data[$backref_by]
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
 merr("Database error: ".$GLOBALS["dbo"]->$lastErrorMsg);
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
 if(!isset($out_row[0])) { $receiving=""; return false; }
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

 $GLOBALS["outmsgs"]["debug"][]=$in_msg_text;
 }


function mnotice($in_msg_text) {
# Notices.

 $GLOBALS["outmsgs"]["notices"][]=$in_msg_text; 
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
# Error messages.
# Also calls flag() above.

 $GLOBALS["outmsgs"]["errors"][]=$in_msg_text; 
 if($in_flags!=""){flag($in_flags);}
 }


function mtrace($in_msg_text,$in_flags="") {
# Trace messages.

 if($in_flags==="") {
  $GLOBALS["outmsgs"]["trace"][]=$in_msg_text;
  } else {
  $GLOBALS["outmsgs"]["trace"][]="[".$in_flags."] ".$in_msg_text;
  }
 }


function report_and_log_new_sql_txn($in_success,
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
 $log_writing_result=log_entry("app",
	   $in_eventdesc,$in_eventbody,
	   $offer_event_view,$button_type,$button_type_target);

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

 $GLOBALS["username"]=$in_user;
 $GLOBALS["hostname"]=$in_hostname;
 $GLOBALS["scriptname"]=$in_this_script_name;
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
 'conf/FOR_THIS_APP'	    	=>  'title:Configuration'
		            	    	   .'/new-form-title:			    Configuration'
		            	    	   .'/allow-delete-by:			    uuid'
		            	    	   .'/single-row-only'
		            	    	   .'/single-row-only-empty-message:No configuration currently defined'
		            	    	   .'/friendly-object-name:		    configuration'
			                 	   .'/instance-friendly-name-is:	uuid'
			        	           .'/toplink:				        configure'
			                	   ,
 'conf/uuid'		        	=>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:app-generates/form-label:UUID/dont-show',
 'conf/basefilepath'	    	=>  'req:y/type:str/data:file/minlen:   1/maxlen:   1024/injourney:user-enters-text-for-localdir/form-label:Base File Path/default-value-from-ini:homedir/present-width:full-width',
 'conf/stdoutfilepath'	    	=>  'req:y/type:str/data:file/minlen:   1/maxlen:   1024/injourney:user-enters-text-for-localdir/form-label:stdout File Path/default-value-from-ini:stdoutdir/present-width:full-width',
 # ---------------------------------------------------------------------------
 'rpmap/FOR_THIS_APP'	    	=>  'title:Locations (Port and Prefix)'
			                  	   .'/new-form-title:			    Define A Port and Prefix Combination'
		                		   .'/allow-delete-by:		    	uuid'
		                		   .'/row-must-exist-in:	    	conf'
	            	    		   .'/must-exist-in-fails-message:	You can&apos;t define a location until you create a configuration.'
	            		    	   .'/friendly-object-name:		    location'
	            		    	   .'/instance-friendly-name-is:	number'
	            	    		   .'/toplink:				        locations'
	            			       ,
 'rpmap/pointer_to_conf'	    =>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:user-selects-from-list-in-other-table/form-label:Under Configuration/dont-show'.
				                    '/is-pointer-to:conf/pointer-links-by:uuid/shown-by:basefilepath',
 'rpmap/uuid'			        =>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:app-generates/form-label:UUID/dont-show',
 'rpmap/number'		        	=>  'req:y/type:int/data:port/minval:1024/maxval:  65535/injourney:user-enters-text-for-number/form-label:TCP&#47;IP Port Number/must-be-unique',
 'rpmap/urlprefix'	         	=>  'req:y/type:str/data:url /minlen:   1/maxlen:   1024/injourney:user-enters-text-for-urlprefix/form-label:URL Prefix',
 # ---------------------------------------------------------------------------
 'defined/FOR_THIS_APP'		    =>  'title:Services'
		            	    	   .'/new-form-title:			    Define A Service'
		            		       .'/allow-delete-by:			    uuid'
	            		    	   .'/row-must-exist-in:	    	conf'
		            	    	   .'/must-exist-in-fails-message:	You can&apos;t create a service until you create a configuration.'
		            		       .'/friendly-object-name:		    service'
		            		       .'/instance-friendly-name-is:	name'
		            		       .'/toplink:				        services'
		            		       ,
 'defined/pointer_to_conf'	    =>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:user-selects-from-list-in-other-table/form-label:Under Configuration/dont-show'.
			            	        '/is-pointer-to:conf/pointer-links-by:uuid/shown-by:basefilepath',
 'defined/uuid'		    	    =>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:app-generates/form-label:UUID/dont-show',
 'defined/name'		    	    =>  'req:y/type:str/data:name/minlen:   1/maxlen:     50/injourney:user-enters-text/form-label:Service Name',
 'defined/description'	    	=>  'req:n/type:str/data:name/minlen:   0/maxlen:   4000/injourney:user-enters-text/form-label:Service Description',
 'defined/command'	    	    =>  'req:y/type:str/data:cmd /minlen:   1/maxlen:    500/injourney:user-selects-from-ini-list/form-label:Command/present-width:full-width'.
				                    '/ini-list-section:whitelist/ini-list-array:bin',
 'defined/arguments'		    =>  'req:n/type:str/data:name/minlen:   0/maxlen:   4000/injourney:user-enters-text/form-label:Command Arguments (With Substitution Points)/present-width:full-width',
 'defined/defaultfile'	    	=>  'req:n/type:str/data:file/minlen:   1/maxlen:   1024/injourney:user-enters-text/form-label:Default File (blank for none) {LOCALFILE}'.
				                    '/provides-defaults/gives-default-for-table:running/gives-default-for-column:localfile',
 'defined/defaultrpmap'		    =>  'req:n/type:int/data:port/minval:1024/maxval:  65535/injourney:user-selects-from-list-in-other-table/form-label:Port And URL Prefix Combo'.
			                	    '/provides-defaults/gives-default-for-table:running/gives-default-for-column:rpmap'.
			            	        '/is-pointer-to:rpmap/pointer-links-by:number/shown-by:number,urlprefix',
 # ---------------------------------------------------------------------------
 'running/FOR_THIS_APP'		    =>  'title:Processes (Started Services)'
				                   .'/new-form-title:		    	Start A Service'
				                   .'/table-method:check,Check All Processes'
				                   .'/each-row-method:stdout,View output,uuid;stop,Stop Process,uuid;check,Check If Still Running,uuid'
				                   .'/row-must-exist-in:		        defined'
				                   .'/must-exist-in-fails-message:	No defined services to start.'
				                   .'/defaults-provided-by:	    	defined'
				                   .'/defaults-in-provider-keyed-by:	uuid'
				                   .'/defaults-here-keyed-by:		pointer_to_defined'
				                   .'/friendly-object-name:	    	process'
				                   .'/instance-friendly-name-is: 	pid'
				                   .'/backref-by:		        	uuid'
				                   .'/toplink:			        	processes'
				                   ,
 'running/uuid'			        =>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:app-generates/form-label:UUID/dont-show',
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
 'maint/FOR_THIS_APP'	    	=>  'title:Maintenance Command Requests'
				                   .'/new-form-title:			    Maintenance Request'
			                	   .'/allow-delete-by:			    uuid'
			                	   .'/single-row-only'
		            	    	   .'/single-row-only-empty-message:No active maintenance request.'
			                	   .'/friendly-object-name:		    maintenance request'
			                	   .'/instance-friendly-name-is:	request'
		            	    	   .'/each-row-method:execute,Execute maintenance request,uuid'
		            	    	   .'/toplink:				        MR'
			                	   ,
 'maint/uuid'	       	    	=>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:app-generates/form-label:MRN',
 'maint/request'	        	=>  'req:y/type:str/data:name/minlen:   1/maxlen:      64/injourney:user-selects-from-this-list/form-label:Action/this-list:deldb=Delete Database',
 'maint/mkey'		         	=>  'req:y/type:str/data:name/minlen:  16/maxlen:    256/injourney:user-enters-text/form-label:Enter A Confirmation Password'.
				                    '/is-confirmation-key-for:execute/confirmation-placeholder:Enter Confirmation Password',
 # ---------------------------------------------------------------------------
 'trashcan/FOR_THIS_APP'	    =>  'title:Recycle Bin'
			                	   .'/allow-delete-by:			    uuid'
			                	   .'/each-row-method:restart,Restart This Process,uuid'
			                	   .'/friendly-object-name:		    previous process'
			                	   .'/instance-friendly-name-is:	previous process'
			            	       .'/erase-upon-clear-logs'
			            	       ,
 'trashcan/uuid'	    	    =>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:app-generates/form-label:UUID/dont-show',
 'trashcan/pointer_to_defined'	=>  'req:y/type:str/data:uuid/minlen:  36/maxlen:     36/injourney:user-selects-from-list-in-other-table/form-label:Instance Of'.
				                    '/is-pointer-to:defined/pointer-links-by:uuid/shown-by:name'.
				                    '/display-using-other-table/display-sql-SELECT:name/display-sql-FROM:defined/display-sql-WHERE:uuid/display-sql-IS:pointer_to_defined',
 'trashcan/localfile'	    	=>  'req:n/type:str/data:file/minlen:   1/maxlen:   1024/injourney:user-enters-text-for-localfile/form-label:Local File {LOCALFILE}',
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
# - $in_PARAMS:
# ----------------------------------------------------------------------------


function GENERATOR_pid (
 &$returned_value, $in_table, $in_col, $in_array_col_attrs, $in_PARAMS, $in_ini
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
  report_and_log_new_sql_txn_new_sql_txn(false,"Unable to check executable permissions. Process was not started.","");
  return false;
  }
 if(($mode & 06000)!=0) {
  report_and_log_new_sql_txn(false,"Executable has suid or sgid bit set. Process was not started.","");
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
  $removing=true; $removing_info=make_presentable($pidArr[0],"pid")." was started, but then died right away.";
  }

 $GLOBALS["extra_goodies"].="<div class='stdout-top'>Last 50 lines of captured <tt>stdout</tt>:</div>\n";
 $GLOBALS["extra_goodies"].="<textarea rows=25 class='stdout'>";
 $GLOBALS["extra_goodies"].=tailCustom($stdoutpath,50);
 $GLOBALS["extra_goodies"].="</textarea>";

 if(!$removing) {
  $returned_value=$pidArr[0]; 
  report_and_log_new_sql_txn(true,$rdefined["name"]." ".$re."started ".make_presentable($pidArr[0],"pid"),"");
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
  report_and_log_new_sql_txn(true,$removing_info,"",false,"restart",$trash["uuid"]);
  mbutton(BUTTONHTML_restart($trash["uuid"]));
  }

 }

# ----------------------------------------------------------------------------
# [ === ROW METHOD HANDLER functions ]
# - Row methods are specified in a table's FOR_THIS_APP virtual column.
# ----------------------------------------------------------------------------

function ROWMETHOD_execute_maint(
 $in_table, $in_target, $in_target_table, $in_PARAMS, $in_ini
 ) {

 # Resolve reference to maintenance request
 $rrequest=Array();
 if(!read_row_expecting_just_one($rrequest,$in_table,"uuid",$in_target)) { return false; }

 # Check if keys match, bounce if they don't.
 if($in_PARAMS["mkey"]!==$rrequest["mkey"]) {
  merr("Confirmation key is wrong.  The request was not executed.");
  mnotice("If you forgot the confirmation key, delete your request and try again.");
  return false; 
  }

 $not_implemented=false; $close=false;
 switch ($rrequest["request"]) {
  case "deldb":
   # TODO: Actually do this.
   # mnotice("Database deleted.  It will be automatically recreated the next time the app is accessed.");
   $not_implemented=true;
   $close=true;
   break;
  default:
   merr("Unrecognized request '".$rrequest["request"]."'.  The request was not executed.","hack");
   $close=false;
  }

 if($not_implemented) {
  mnotice("'".$rrequest["request"]."' isn't implemented yet.  Nothing was done.");
  }

 if($close) {
  delete_row_bypass_schema($in_table,"uuid",$in_target);
  if(any_db_error()) { return false; }
  mnotice("closing request '".make_presentable($rrequest["uuid"],"uuid")."'.");
  }

 }


function ROWMETHOD_check_running( 
 $in_table, $in_target, $in_target_table, $in_PARAMS, $in_ini
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
  report_and_log_new_sql_txn("true",make_presentable($pid,"pid")." is still running.","");
  }else{
  $removing=true; $removing_info=make_presentable($pid,"pid")." is no longer running.";
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
  report_and_log_new_sql_txn(true,$removing_info,"",false,"restart",$trash["uuid"]);
  mbutton(BUTTONHTML_restart($trash["uuid"]));
  } 

 }


function ROWMETHOD_stop_running(
 $in_table, $in_target, $in_target_table, $in_PARAMS, $in_ini
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
   report_and_log_new_sql_txn(false,$name." not stopped - ".$pid." didn't respond to SIGINT or SIGKILL.","");
   }else{
   $removing=true; $removing_info=$name." stopped";
   }
  }else{
  $removing=true; $removing_info=$pid." (".$name.") is not running anymore; removing PID from table.";
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
  report_and_log_new_sql_txn(true,$removing_info,"",false,"restart",$trash["uuid"]);
  mbutton(BUTTONHTML_restart($trash["uuid"]));
  } 

 }


function ROWMETHOD_stdout_running (
 $in_table, $in_target, $in_target_table, $in_PARAMS,
 $in_ini
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
 $in_table, $in_target, $in_target_table, $in_PARAMS,
 $in_ini
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

 # most of this is the same steps from the "new_row" action handler.
 do {
  # the "fill_data" fnctions will take data in $fakePOST[] and fill in
  # $corpse[] ...
  fill_data_array_from_query_string("running",$fakePOST,$corpse);
  # GENERATOR_pid() for the corpse dispatched by the below call.
  fill_data_array_from_app_generated_injourneys("running",$corpse,$fakePOST,$in_ini);
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

function BUTTONFALLOFF_restart($in_button_type_target) {
 delete_row_bypass_schema("trashcan","uuid",$in_button_type_target,false);
 }

function BUTTONFALLOFF_restarted($in_button_type_target) {
 delete_row_bypass_schema("trashcan","uuid",$in_button_type_target,false);
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