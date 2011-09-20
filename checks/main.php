<?php

// keepright main entrance script

// script for updating multiple keepright databases
//
// usage:
// php main.php [starting-schema]
// make keepright check one or more databases and upload the results.
// the script runs in a loop until a file with name $config['stop_indicator'] is found.
// optionally provide a schema name where to start. This is useful
// for restarting at a given position in the loop
//

require('helpers.php');
require('../config/config.php');


// leave program if not all prerequisites are fulfilled
if (check_prerequisites()) exit(1);

// TODO: eliminate every single "echo" statement and replace it with logger()-calls



if ($argc==2) $startschema=$argv[1]; else $startschema=0;

$firstrun=true;
$processed_a_schema=false;

foreach ($schemas as $schema=>$schema_cfg) {

	if (($schema_cfg['user'] == $config['account']['user']) &&
		(!$firstrun || $schema==$startschema || $startschema==0)) {

		// update source code from svn
		system('cd "' . $config['base_dir'] . '" && svn up');

		logger("processing schema $schema");

		system('php "' . $config['base_dir'] . '/checks/process_schema.php" "' . $schema . '" >> "' . $config['main_logfile'] . '" 2>&1');


		$firstrun=false;
		$processed_a_schema=true;

	}

	if (file_exists($config['stop_indicator'])) {
		logger('stopping keepright as requested');
		exit;
	}
}



if (!$processed_a_schema) {
	logger('Didn\'t find a single schema to process. Stopping keepright.');
	exit;
}


// restart yourself
// this clumsy way of building an infinite loop allows for swithing to
// a new version of the running code in case of svn updates
system('(php ' . $config['base_dir'] . '/checks/main.php &) >> "' . $config['main_logfile'] . '"');

?>