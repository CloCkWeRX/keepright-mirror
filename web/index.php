<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html>
  <head>
    <title>data consistency checks for OSM</title>
  </head>
  <body>

<img src="keepright.png" alt="keep-right logo">data consistency checks for <a href="http://www.openstreetmap.org">OSM</a><hr>

These pages show checks that are run on a local excerpt database filled with OSM data.
<br><br>


<a href="report_map.php?db=osm_EU&zoom=14&lat=48.20808&lon=16.37221">Data Checks for Europe</a> (Starting point Vienna, Austria)<br>

<a href="http://keepright.x10hosting.com" target="_blank">Australian Keepright partner site</a><br>

<a href="report_map.php?db=osm_XA&zoom=14&lat=30.039&lon=31.25345">Data Checks for Africa</a> (Starting point Cairo, Egypt)<br>

<a href="report_map.php?db=osm_CA&zoom=12&lat=46.79923&lon=-71.19432">Data Checks for Canada</a> (Starting point Québec)<br>

<a href="http://keepright.ipax.at/report_map.php?db=osm_US&zoom=12&lat=39.95356&lon=-75.12364">Data Checks for USA</a> (Starting point Philadelphia, PA)<br>

<a href="report_map.php?db=osm_XG&zoom=11&lat=18.61093&lon=-69.9473">Data Checks for Central America</a> (Starting point Santo Domingo, Republica Dominicana)<br>

<a href="report_map.php?db=osm_XC&zoom=14&lat=-23.58791&lon=-46.65713">Data Checks for South America</a> (Starting point São Paulo, Brazil)<br>

<a href="report_map.php?db=osm_XD&zoom=14&lat=35.68051&lon=139.76404">Data Checks for Asia</a> (Starting point Tokio, Japan)<br>
<br>
<a href="report.php">Europe Data Checks as ugly list</a><br>


<br>
Developers have a look at the <a href="http://apps.sourceforge.net/trac/keepright/">sourceforge</a> site providing svn access to the sources.

<h3>logfile</h3>

<h4>2009-11-16</h4>
The German errors table is now fixed with a complete run based on yesterday&apos;s planet file. Thank you, Norbert for telling me about the problem!

<h4>2009-11-15</h4>
To all the German users:<br>
The last database update went wrong and you will see only a fraction of the number of errors as you would expect. I have to re-run the checking to correct this so please be patient until Monday evening or Tuesday.<br>Sorry und nix f&uuml;r ungut!

<h4>2009-10-01</h4>
I&apos;m proud to announce that finally the first run for The United States of America is finished. Keepright now covers all of the planet!<br>
The procedure took 12 days to complete, so please be patient for the next update to come in (almost) two weeks.

<h4>2009-09-27</h4>
I&apos;m proud to announce that Canada and Central America are now part of the checking process schedule. Please find the links on top of the page and have fun!


<h4>2009-09-21</h4>
After a break there is again an update for the error tables. The delay was caused by restructuring work on the scripts wich are necessary for checking US states in the future. European countries are now checked individually, the results are put together into one big results table afterwards.<br>If you find any strange errors, especially near country borders, I would like to know!<br>
Thank you for your patience.<br>
<b>&lt;edit&gt;</b>you <i>will</i> find strange errors near the country borders. In fact there are issues with floating islands, ways without nodes and maybe more checks that are caused by truncated ways. I'm looking for a solution for this. Please be careful near country borders in the meantime! Thank you, Armin, for telling me.<b>&lt;/edit&gt;</b>


<br><br>For archeologists: <a href="logs.php">Old log entries</a> have moved.


<br><br>
<h4>a few words on the new comment feature</h4>
Please give a comment that helps me improve the check routines if you find a false-positive. Don't confuse the comment box with an editing feature. This is not potlatch! You cannot add missing tags via keepright!
<br><br>
Please note that in order to make the JOSM link work, JOSM must already be running when you hit the JOSM-link. Furthermore you have to enable the &apos;remote control&apos;-plugin in JOSMs options.

<h3>Currently the following checking procedures are implemented</h3>
<?php

require('webconfig.inc.php');
require('helpers.inc.php');

$db1=mysqli_connect($db_host, $db_user, $db_pass, $db_name);
mysqli_query($db1, "SET SESSION wait_timeout=60");

$result=query("
	SELECT error_type, error_name, error_description
	FROM $error_types_name 
	WHERE error_type=10*FLOOR(error_type/10)
	ORDER BY error_type
	", $db1, false);
while ($row = mysqli_fetch_array($result, MYSQL_ASSOC)) {
	echo '<p><b>' . $row['error_name'] . '</b><br>' . $row['error_description'] . '</p>';
}

mysqli_free_result($result);
mysqli_close($db1);
?>



If you find an error in my errors lists I would definitely like to hear about it!<br>


<h3>Impressum</h3>
This work is done without commercial background, just for my personal pleasure. I would be very happy if it was helpful for the OSM Project.<br><br>
If you like to contact me, my mailbox at the austrian server of gmx is labelled keepright.
Please understand that I will not always be able to immediately respond to your mail on weekdays.

</body>
</html>

