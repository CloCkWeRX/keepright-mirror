<?php

/*
-------------------------------------
-- almost-junctions
-------------------------------------

ways that end somewhere, where just some meters are missing for a junction with the next way
(these almost-junctions will almost always occur not with another node, but with a way-segment just passing by)

algorithm:
find not-connected endpoints of highways.
find ways whose bouding box contains the end node
note ways that come closer than min_distance to the endpoint
*/



query("DROP TABLE IF EXISTS _tmp_ways", $db1);

// tmp_ways will contain all highways with their first and last node id
// as well as a linestring of all nodes and the bounding box
query("
	CREATE TABLE _tmp_ways (
	way_id bigint NOT NULL,
	first_node_id bigint,
	last_node_id bigint,
	PRIMARY KEY (way_id)
	)
", $db1);
query("SELECT AddGeometryColumn('_tmp_ways', 'geom', 4326, 'LINESTRING', 2)", $db1, false);
query("SELECT AddGeometryColumn('_tmp_ways', 'bbox', 4326, 'POLYGON', 2)", $db1, false);

// find any highway-tagged way
query("
	INSERT INTO _tmp_ways (way_id, first_node_id, last_node_id, geom, bbox)
	SELECT w.id, w.first_node_id, w.last_node_id, w.geom, w.bbox
	FROM ways AS w
	WHERE w.geom IS NOT NULL AND EXISTS (
		SELECT way_id
		FROM way_tags AS t
		WHERE t.way_id=w.id AND t.k='highway'
	)
", $db1);


query("CREATE INDEX idx_tmp_ways_first_node_id ON _tmp_ways (first_node_id)", $db1, false);
query("CREATE INDEX idx_tmp_ways_last_node_id ON _tmp_ways (last_node_id)", $db1, false);
query("CREATE INDEX idx_tmp_ways_bbox ON _tmp_ways USING gist (bbox);", $db1);
query("ANALYZE _tmp_ways", $db1);



// find the first and last node of given ways

// _tmp_end_nodes will store first- or last-nodes of given ways that are not connected to any other way
query("DROP TABLE IF EXISTS _tmp_end_nodes", $db1);
query("
	CREATE TABLE _tmp_end_nodes (
	way_id bigint NOT NULL,
	node_id bigint NOT NULL,
	x double precision,
	y double precision,
	PRIMARY KEY (node_id))
", $db1);
query("SELECT AddGeometryColumn('_tmp_end_nodes', 'geom', 4326, 'POINT', 2)", $db1);
add_insert_ignore_rule('_tmp_end_nodes', 'node_id', $db1);



// find first nodes that are end-nodes (that are found in way_nodes just once)
query("
	INSERT INTO _tmp_end_nodes (way_id, node_id)
	SELECT w.way_id, w.first_node_id
	FROM _tmp_ways w INNER JOIN way_nodes wn ON w.first_node_id=wn.node_id
	GROUP BY w.way_id, w.first_node_id
	HAVING COUNT(wn.way_id)=1
", $db1);
// find last nodes that are end-nodes (that are found in way_nodes just once)
// lacking the INSERT IGNORE syntax in postgres we need to exclude nodes
// that already exist in _tmp_end_nodes (subquery)
query("
	INSERT INTO _tmp_end_nodes (way_id, node_id)
	SELECT w.way_id, w.last_node_id
	FROM _tmp_ways w INNER JOIN way_nodes wn ON w.last_node_id=wn.node_id
	WHERE NOT EXISTS (
		SELECT * FROM _tmp_end_nodes AS tmp
		WHERE tmp.node_id=w.last_node_id
	)
	GROUP BY w.way_id, w.last_node_id
	HAVING COUNT(wn.way_id)=1
", $db1);
query("ANALYZE _tmp_end_nodes", $db1);

// now remove nodes that have noexit=yes tags
// as they are marked as dead ends of roads intentionally
// plus: remove end-nodes tagged as some amenity
// people connect post boxes with the nearest highway using
// a short way that would otherwise fall into this check
// these nodes can easily be ignored because a telephone
// will never stand in the middle of a crossing
query("
	DELETE FROM _tmp_end_nodes en
	WHERE EXISTS (
		SELECT node_id
		FROM node_tags AS t
		WHERE t.node_id=en.node_id AND (
			(t.k='noexit' AND t.v IN ('yes', 'true', '1')) OR
			(t.k='highway' AND t.v='bus_stop') OR
			t.k='amenity'
		)
	)
", $db1);


// retrieve the x/y values
query("
	UPDATE _tmp_end_nodes en
	SET geom=n.geom, x=n.x, y=n.y
	FROM nodes n
	WHERE en.node_id=n.id
", $db1);
query("CREATE INDEX idx_tmp_end_nodes_geom ON _tmp_end_nodes USING gist (geom);", $db1);
query("ANALYZE _tmp_end_nodes", $db1);



/////////////////////////////////////////////////////////////////////////////////////
$bi=new BufferedInserter('_tmp_errors', $db4);


// join end_nodes and ways on "node inside bounding box of way"
$result=query("
	SELECT en.way_id, en.node_id, en.x AS node_x, en.y AS node_y, w.way_id AS nearby_way_id, ST_distance(w.geom, en.geom) AS distance
	FROM _tmp_end_nodes en, _tmp_ways w
	WHERE w.bbox && en.geom AND
	ST_DWithin(w.geom, en.geom, {$check0050_min_distance}) AND
	en.way_id<>w.way_id
	ORDER BY en.node_id, distance
", $db1);


// examine nodes that are close to ways in more detail:
// there are short final segments on dead ended roads that would end
// up as false-positives.
$last_node_id=-1;
while ($row=pg_fetch_array($result)) {

	if ($row['node_id'] == $last_node_id) continue;
	if (connected_directly($row['nearby_way_id'], $row['way_id'], $row['node_x'], $row['node_y'], $db3)) {

		//echo "way #{$row['nearby_way_id']} gets skipped\n";
		continue;

	} else {
		//echo "way #{$row['nearby_way_id']} is too close\n";
		$bi->insert("$error_type\tnode\t{$row['node_id']}\tThis node is very close but not connected to way #{$row['nearby_way_id']}\tNOW()\t\\N\t\\N");

	}
	$last_node_id=$row['node_id'];
}
pg_free_result($result);
$bi->flush_buffer();

query("DROP TABLE IF EXISTS _tmp_ways", $db1);
query("DROP TABLE IF EXISTS _tmp_end_nodes", $db1);



// this function does a very simplified lookup to find out if two ways
// are connected __directly__ (they share a common node)
// and if the connection is not more than 3*$check0050_min_distance away from
// the node under test
// this is used to avoid false-positives on last-segments of ways
// that are shorter than our threshold. They shall not be checked
// against ways to which they ARE connected.
function connected_directly($way_id1, $way_id2, $NuT_x, $NuT_y, $db) {
	global $check0050_min_distance;
	$conn=false;

	$result=query("
		SELECT COUNT(*) AS c
		FROM way_nodes wn1 INNER JOIN way_nodes wn2 USING (node_id)
		WHERE wn1.way_id=$way_id1 AND wn2.way_id=$way_id2 AND 
		(wn1.x-($NuT_x)) ^ 2 + (wn1.y-($NuT_y)) ^ 2 <= (3*$check0050_min_distance) ^ 2
	", $db, false);

	while ($row=pg_fetch_array($result, NULL, PGSQL_ASSOC)) {
		$conn=$row['c']>0;
	}
	pg_free_result($result);
	return $conn;
}

?>