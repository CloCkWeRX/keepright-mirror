<?php


/*
inspired by maplint points of interest are reported if they lack a name (or operator) tag

NAME required for
place_of_worship, pub, restaurant, school, university, hospital, library, theatre, courthouse

NAME or OPERATOR required for
bank, fast_food, cinema, pharmacy, cafe, fuel
*/


query("
	CREATE OR REPLACE VIEW _tmp_points_of_interest AS
	SELECT node_id, MIN(v) as v
	FROM node_tags
	WHERE k='amenity' AND v IN ('place_of_worship', 'pub', 'restaurant', 'school', 'university', 'hospital', 'library', 'theatre', 'courthouse')
	GROUP BY node_id
", $db1);
query("
	INSERT INTO _tmp_errors(error_type, object_type, object_id, description, last_checked)
	SELECT $error_type, 'node', node_id, 'This node is tagged as ' || v || ' and therefore needs a name tag', NOW()
	FROM _tmp_points_of_interest b
	WHERE NOT EXISTS( SELECT nt.node_id FROM node_tags nt WHERE nt.node_id=b.node_id AND nt.k='name' )
", $db1);


query("
	CREATE OR REPLACE VIEW _tmp_points_of_interest AS
	SELECT node_id, MIN(v) as v
	FROM node_tags
	WHERE k='amenity' AND v IN ('bank', 'cinema', 'pharmacy', 'cafe', 'fast_food', 'fuel')
	GROUP BY node_id
", $db1);
query("
	INSERT INTO _tmp_errors(error_type, object_type, object_id, description, last_checked)
	SELECT $error_type, 'node', node_id, 'This node is tagged as ' || v || ' and therefore needs a name tag or an operator tag', NOW()
	FROM _tmp_points_of_interest b
	WHERE NOT EXISTS( SELECT nt.node_id FROM node_tags nt WHERE nt.node_id=b.node_id AND nt.k IN ('name', 'operator'))
", $db1);


query("DROP VIEW _tmp_points_of_interest", $db1);
?>