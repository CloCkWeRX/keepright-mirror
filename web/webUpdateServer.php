<?php
/*
this script does the complete job of updating the web presentation
with new data on the webserver
Call this script from your client pc using webUpdateClient.php

* authenticate session
* upload dump files
* toggle tables (rename _old to _shadow and empty it)
* load dump files
* toggle tables (rename error_view to error_view_old and _shadow to error_view)
* update updated_[schema] file (date of last site update)
* re-open temporarily ignored errors
*/

ini_set('session.use_cookies', false);	// never use cookies
ini_set('session.gc_maxlifetime', 1800);// 30 minutes as max. session lifetime

require('webconfig.inc.php');
require('helpers.inc.php');
require('BufferedInserter_MySQL.php');

session_start();

$user=$USERS[$_GET['username']];

// enforce people to change their password
if (strlen($_GET['username'])>0)
if ($user['password']=="shhh!" || strlen($user['password'])==0) {
	echo "Password not yet configured. Please change your password in webconfig.inc.php";
	exit;
}

// handle calls that don't provide a session id (the first call for each session)
if (empty($_SESSION['authorized']) && empty($_GET['response'])) {

	// create a new challenge (a random value)
	if (empty($_SESSION['challenge'])) $_SESSION['challenge'] = md5(rand(1e5,1e12));
	echo "not authorized\n";
	echo $_SESSION['challenge'] . "\n";
	echo htmlspecialchars(session_id()) . "\n";
}

// handle login calls
if ($_SESSION['authorized'] !== true && !empty($_GET['response']) && !empty($_GET['username']))  {

	// check authenticity of response
	if ($_GET['response'] === md5($_GET['username'] . $_SESSION['challenge'] . $user['password'])) {
		$_SESSION['authorized']=true;
		$_SESSION['username']=$_GET['username'];
		echo "OK welcome!\n";
	} else {
		echo "invalid response\n";
		$_SESSION['challenge'] = md5(rand(1e5,1e12));	// make a new challenge. People should not be able to have as many tries as they want.
		echo $_SESSION['challenge'] . "\n";
		echo htmlspecialchars(session_id()) . "\n";
	}
}

$schema=addslashes($_GET['schema']);

// handle commands for logged in users
if ($_SESSION['authorized']===true) {

	if ($_GET['cmd'] == 'update') {

		if (!permissions($USERS[$_SESSION['username']], $schema)) {
			die("you are not authorized to access schema $schema\n");
		}

		$error_view_filename=escapeshellarg($_GET['error_view_filename']);
		if (!file_exists(substr($error_view_filename, 1, -1))) {
			echo "$error_view_filename does not exist on web server\n";
			exit;
		}

		$db1=mysqli_connect($db_host, $db_user, $db_pass, $db_name);

		toggle_tables1($db1, $schema);
		load_dump($db1, $error_view_filename, 'error_view', $schema);
		toggle_tables2($db1, $schema);
		reopen_errors($db1, $schema);

		// set_updated_date
		write_file("updated_$schema", addslashes($_GET['updated_date']));

		mysqli_close($db1);
	}

	if ($_GET['cmd'] == 'export_comments') {
		$db1=mysqli_connect($db_host, $db_user, $db_pass, $db_name);
		export_comments($db1);
		mysqli_close($db1);
	}

	if ($_GET['cmd'] == 'logout') {
		logout();
	}
}


function logout() {
	echo "logout.\n";
	// Unset all of the session variables and destroy the session.
	global $_SESSION;
	$_SESSION = array();
	session_destroy();
	echo "session closed.\n";
}

// check if a given schema name is found in the users permissions array
// which is configured in $USERS in webconfig.inc.php
function permissions($user, $schema) {

	if (in_array('%', $user['schemata'], true))
		return true;			// privileges for any schema
	else
		return (in_array($schema, $user['schemata'], true));	// a given schema
}

// create a dump file containing all comments
function export_comments($db1) {
	global $comments_name;
	$fname=$comments_name . '.txt';
	$f = fopen($fname, 'w');

	if ($f) {
		$result=query("
			SELECT `schema`, error_id, state, comment, timestamp
			FROM $comments_name
			WHERE `schema` IS NOT NULL AND `schema` != \"\"
			ORDER BY `schema`, error_id
		", $db1, false);

		while ($row = mysqli_fetch_assoc($result)) {
			fwrite($f, $row['schema'] ."\t". $row['error_id'] ."\t". $row['state'] ."\t". strtr($row['comment'], array("\t"=>" ", "\r\n"=>"<br>", "\n"=>"<br>")) ."\t". $row['timestamp'] . "\n");
		}

		mysqli_free_result($result);
		fclose($f);
		system("bzip2 --force $fname");
	}
}

// ensure there is an error_view_osmXX_shadow table for inserting records
function toggle_tables1($db1, $schema){
	global $error_types_name, $comments_name, $comments_historic_name;

	echo "setting up table structures and toggling tables\n";
	query("
		CREATE TABLE IF NOT EXISTS $comments_name (
		`schema` varchar(6) NOT NULL DEFAULT '',
		error_id int(11) NOT NULL,
		state enum('ignore_temporarily','ignore') default NULL,
		`comment` text,
		`timestamp` timestamp NOT NULL default CURRENT_TIMESTAMP,
		ip varchar(255) default NULL,
		user_agent varchar(255) default NULL,
		UNIQUE schema_error_id (`schema`, error_id)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8;
	", $db1, false);
	query("
		CREATE TABLE IF NOT EXISTS $comments_historic_name (
		`schema` varchar(6) NOT NULL DEFAULT '',
		error_id int(11) NOT NULL,
		state enum('ignore_temporarily','ignore') default NULL,
		`comment` text,
		`timestamp` timestamp NOT NULL default CURRENT_TIMESTAMP,
		ip varchar(255) default NULL,
		user_agent varchar(255) default NULL,
		UNIQUE schema_error_id (`schema`, error_id)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8;
	", $db1, false);
	query("
		CREATE TABLE IF NOT EXISTS $error_types_name (
		error_type int(11) NOT NULL,
		error_name varchar(100) NOT NULL,
		error_description text NOT NULL,
		PRIMARY KEY  (error_type)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8;
	", $db1, false);
	query("
		CREATE TABLE IF NOT EXISTS error_view_{$schema}_old (
		`schema` varchar(6) NOT NULL DEFAULT '',
		error_id int(11) NOT NULL,
		error_type int(11) NOT NULL,
		error_name varchar(100) NOT NULL,
		object_type enum('node','way','relation') NOT NULL,
		object_id bigint(64) NOT NULL,
		state enum('new','cleared','ignored','reopened') NOT NULL,
		description text NOT NULL,
		first_occurrence datetime NOT NULL,
		last_checked datetime NOT NULL,
		object_timestamp datetime NOT NULL,
		lat int(11) NOT NULL,
		lon int(11) NOT NULL,
		UNIQUE schema_error_id (`schema`, error_id),
		KEY lat (lat),
		KEY lon (lon),
		KEY error_type (error_type)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8;
	", $db1, false);
	query("
		CREATE TABLE IF NOT EXISTS error_view_{$schema} (
		`schema` varchar(6) NOT NULL DEFAULT '',
		error_id int(11) NOT NULL,
		error_type int(11) NOT NULL,
		error_name varchar(100) NOT NULL,
		object_type enum('node','way','relation') NOT NULL,
		object_id bigint(64) NOT NULL,
		state enum('new','cleared','ignored','reopened') NOT NULL,
		description text NOT NULL,
		first_occurrence datetime NOT NULL,
		last_checked datetime NOT NULL,
		object_timestamp datetime NOT NULL,
		lat int(11) NOT NULL,
		lon int(11) NOT NULL,
		UNIQUE schema_error_id (`schema`, error_id),
		KEY lat (lat),
		KEY lon (lon),
		KEY error_type (error_type)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8;
	", $db1, false);

	foreach (array("error_view_{$schema}_old", "error_view_{$schema}") as $tbl) {
		add_column_if_not_exists($db1, $tbl, 'msgid', 'TEXT');
		add_column_if_not_exists($db1, $tbl, 'txt1', 'TEXT');
		add_column_if_not_exists($db1, $tbl, 'txt2', 'TEXT');
		add_column_if_not_exists($db1, $tbl, 'txt3', 'TEXT');
		add_column_if_not_exists($db1, $tbl, 'txt4', 'TEXT');
		add_column_if_not_exists($db1, $tbl, 'txt5', 'TEXT');
	}

	query("DROP TABLE IF EXISTS error_view_{$schema}_shadow", $db1);
	query("RENAME TABLE error_view_{$schema}_old TO error_view_{$schema}_shadow", $db1);
	query("ALTER TABLE error_view_{$schema}_shadow DISABLE KEYS", $db1);
	query("TRUNCATE error_view_{$schema}_shadow", $db1);

	echo "done.\n";
}


// adds a column to a table if it not already exists
function add_column_if_not_exists($db, $table, $column, $attribs) {
     $column_exists = false;

     $rows = query("SHOW COLUMNS FROM `$table` WHERE Field='$column'", $db, false);
     while($c = mysqli_fetch_assoc($rows)){
         if($c['Field'] == $column){
             $column_exists = true;
             break;
         }
     }

     if(!$column_exists){
         query("ALTER TABLE `$table` ADD `$column` $attribs", $db, false);
     }
 }

// adds an index to a table if it not already exists
function add_index_if_not_exists($db, $table, $keyname, $column, $attrib='') {

	if(!index_exists($db, $table, $keyname)){
		query("CREATE $attrib INDEX `$keyname` ON `$table` ($column)", $db, false);
	}
}

// drop an index if exists
function drop_index_if_exists($db, $table, $keyname) {

	if(index_exists($db, $table, $keyname)){
		query("DROP INDEX `$keyname` ON `$table`", $db, false);
	}
}

// check if an index exists
function index_exists($db, $table, $keyname) {

	$rows = query("SHOW INDEX FROM `$table` WHERE Key_name='$keyname'", $db, false);
	while($c = mysqli_fetch_assoc($rows)){
		if($c['Key_name'] == $keyname){
			mysqli_free_result($rows);
			return true;
		}
	}
	mysqli_free_result($rows);
	return false;
}


// switch _shadow table to main table, rename main table to _old
function toggle_tables2($db1, $schema){
	echo "toggling back tables\n";

	query("ALTER TABLE error_view_{$schema}_shadow ENABLE KEYS", $db1);
	query("DROP TABLE IF EXISTS error_view_{$schema}_old", $db1);

	// uncomment the following line to save old error_view tables
	// after updating. comment out to save space on the web DB
	//query("RENAME TABLE error_view_{$schema} TO error_view_{$schema}_old", $db1);

	query("DROP TABLE IF EXISTS error_view_{$schema}", $db1);
	query("RENAME TABLE error_view_{$schema}_shadow TO error_view_{$schema}", $db1);

	echo "done.\n";
}

function empty_error_types_table($db1){
	global $error_types_name;
	query("
		TRUNCATE $error_types_name
	", $db1);

	echo "done.\n";
}

// overwrite $filename with $content
function write_file($filename, $content) {

	if (!$handle = fopen($filename, 'w')) {
		echo "Cannot open file ($filename)";
		exit;
	}

	if (fwrite($handle, $content) === FALSE) {
		echo "Cannot write to file ($filename)";
		exit;
	}
	fclose($handle);

	echo "done.\n";
}


// update temporarily ignored errors to open again
// if the ignore state was set before the object was edited
// i.e. if the version of the object after the edit was checked.
// lets assume a crace time of maximum two hours between state timestamp
// and object timestamp (users needn't edit the objects at the same
// time as they set the state in keepright.
// do this only if the error is still open in the newest error_view
function reopen_errors($db1, $schema) {
	global $comments_name;

	echo "reopening errors not solved by this update\n";

	$sql="
		UPDATE $comments_name c inner join error_view_$schema ev using (`schema`, error_id)
		SET c.state=null,
		c.comment=CONCAT(\"[error still open, \", CURDATE(), \"] \", c.comment)
		WHERE ev.`schema`='$schema' AND c.state='ignore_temporarily' AND
		ev.state<>'cleared' AND
		c.timestamp<DATE_ADD(ev.object_timestamp, INTERVAL 2 HOUR)
	";
	query($sql, $db1);
	echo "\ndone.\n";
}


// load a dump file from the local webspace
// dump file may be plain text or .bz2 compressed
// file format has to be tab-separated text
// just the way you receive from SELECT INTO OUTFILE
function load_dump($db1, $filename, $destination, $schema) {
	global $db_host, $db_user, $db_pass, $db_name, $error_types_name;

	switch ($destination) {
		case "error_types": $tbl=$error_types_name; break;
		case "error_view": $tbl="error_view_{$schema}_shadow"; break;
		default: die('invalid load dump destination: ' . $destination);
	}
	echo "loading dump into $destination (table name is $tbl)\n";

	$fifodir=ini_get('upload_tmp_dir');
	if (strlen($fifodir)==0) $fifodir=sys_get_temp_dir();

	$fifoname=tempnam($fifodir, 'keepright');
	echo "creating fifo file $fifoname\n";
	unlink($fifoname);

	// create a fifo, unzip contents of the dump into fifo
	// and make mysql read from there to do a LOAD DATA INFILE

	posix_mkfifo($fifoname, 0666) or die("Couldn't create fifo.");
	echo "reading dump file $filename\n";

	// remember: $filename is shellescaped and has apos around it!
	if (substr(trim($filename), -5, 4)=='.bz2') {
		$CAT='bzcat';
	} else {
		$CAT='cat';
	}

	system("($CAT $filename > $fifoname) >/dev/null &");	// must run in the background

	system("mysql -h$db_host -u$db_user -p$db_pass -e \"LOAD DATA LOCAL INFILE '$fifoname' INTO TABLE $tbl\" $db_name");

	unlink($fifoname);

	// now check if only schemas were inserted that were given in the command line
	if ($destination=='error_view')
		query("DELETE FROM $tbl WHERE `schema` <> '$schema'", $db1, false);


	echo "done.\n";
}

?>