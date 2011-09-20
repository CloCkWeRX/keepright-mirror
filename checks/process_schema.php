<?php

// script for processing a single database schema

//
// usage:
// php process_schema.php starting-schema
// do the complete job of processing for a single rectangular
// area of the world: create or update the database,
// update the corresponding planet file, run the checks
// publish the results on the web server

require('helpers.php');
require('config.php');
require('BufferedInserter.php');

require('prepareDB.php');
require('updateDB.php');
require('run-checks.php');
require('planet.php');
require('export_errors.php');
require('webUpdateClient.php');



if ($argc==2) $schema=$argv[1]; else {
	logger('process_schema.php: no schema parameter specified', KR_ERROR);
	exit(1);
}



//create_authfile() will be needed in the future when osmosis accesses the database
//create_authfile();

prepareDB($schema);
updateDB($schema);
run_checks($schema);
export_errors($schema);
upload_errors('--remote', '--upload_errors', $schema);

if (!$config['keep_database_after_processing']) dropSchema($schema);


?>