<?php

/*
-------------------------------------
-- missing maxspeed tag
-------------------------------------

check important streets for existence of a maxspeed tag

the maxspeed tag is not mandatory, _but_ it is very helpful
for routing purposes
This check doesn't produce errors, only notices

no error entries are generated for short pieces of ways,
for bridges or tunnels or roundabouts
*/

query("
	INSERT INTO _tmp_errors (error_type, object_type, object_id, msgid, last_checked)
	SELECT $error_type, CAST('way' AS type_object_type),
	w.id, 'missing maxspeed tag', NOW()
	FROM ways w
	WHERE EXISTS (
		SELECT way_id
		FROM way_tags wt
		WHERE wt.way_id=w.id AND wt.k='highway' AND
		wt.v IN ('motorway', 'trunk', 'primary', 'secondary')
	) AND NOT EXISTS (
		SELECT way_id
		FROM way_tags wt
		WHERE wt.way_id=w.id AND
		(wt.k='maxspeed' OR
		wt.k='maxspeed:forward' OR
		wt.k='maxspeed:backward' OR
		(wt.k='junction' AND wt.v='roundabout') OR
		(wt.k='bridge' AND wt.v NOT IN ('no', 'false', '0')) OR
		(wt.k='tunnel' AND wt.v NOT IN ('no', 'false', '0')))
	) AND ST_Length(w.geom)>50
", $db1);

?>