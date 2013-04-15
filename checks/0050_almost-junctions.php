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

// minimum distance between a not connected end of a way and any other segment
// nearby. ways coming closer than min_distance to the end of another way are
// considered to be almost-junctions. specified in meters.
// The value of 10 is chosen because most streets are approximately 10 meters
// wide and people draw them close enough that they _seem_ to be connected
global $check0050_min_distance;
$check0050_min_distance=10;

query("DROP TABLE IF EXISTS _tmp_ways", $db1, false);

// tmp_ways will contain all highways with their first and last node id
// as well as a linestring of all nodes and the bounding box
query("
	CREATE TABLE _tmp_ways (
	way_id bigint NOT NULL,
	first_node_id bigint,
	last_node_id bigint,
	layer text DEFAULT '0',
	PRIMARY KEY (way_id)
	)
", $db1);
query("SELECT AddGeometryColumn('_tmp_ways', 'geom', 4326, 'LINESTRING', 2)", $db1, false);

// find any highway-tagged way
query("
	INSERT INTO _tmp_ways (way_id, first_node_id, last_node_id, geom)
	SELECT w.id, w.first_node_id, w.last_node_id, w.geom
	FROM ways AS w
	WHERE w.geom IS NOT NULL AND EXISTS (
		SELECT way_id
		FROM way_tags AS t
		WHERE t.way_id=w.id AND t.k='highway'
	)
", $db1);


query("CREATE INDEX idx_tmp_ways_geom ON _tmp_ways USING gist (geom)", $db1);
query("CREATE INDEX idx_tmp_ways_first_node_id ON _tmp_ways (first_node_id)", $db1, false);
query("CREATE INDEX idx_tmp_ways_last_node_id ON _tmp_ways (last_node_id)", $db1, false);
query("ANALYZE _tmp_ways", $db1);

find_layer_values('_tmp_ways', 'way_id', 'layer', $db1);


// find the first and last node of given ways

// _tmp_end_nodes will store first- or last-nodes of given ways that are not connected to any other way
query("DROP TABLE IF EXISTS _tmp_end_nodes", $db1, false);
query("
	CREATE TABLE _tmp_end_nodes (
	way_id bigint NOT NULL,
	node_id bigint NOT NULL,
	x double precision,
	y double precision,
	layer text DEFAULT '0',
	PRIMARY KEY (node_id))
", $db1);
query("SELECT AddGeometryColumn('_tmp_end_nodes', 'geom', 4326, 'POINT', 2)", $db1);


// find first nodes that are end-nodes (that are found in way_nodes just once)
query("
	INSERT INTO _tmp_end_nodes (way_id, node_id, layer)
	SELECT w.way_id, w.first_node_id, w.layer
	FROM _tmp_ways w INNER JOIN way_nodes wn ON w.first_node_id=wn.node_id
	GROUP BY w.way_id, w.first_node_id, w.layer
	HAVING COUNT(wn.way_id)=1
", $db1);
// find last nodes that are end-nodes (that are found in way_nodes just once)
// lacking the INSERT IGNORE syntax in postgres we need to exclude nodes
// that already exist in _tmp_end_nodes (subquery)
query("
	INSERT INTO _tmp_end_nodes (way_id, node_id, layer)
	SELECT w.way_id, w.last_node_id, w.layer
	FROM _tmp_ways w INNER JOIN way_nodes wn ON w.last_node_id=wn.node_id
	WHERE NOT EXISTS (
		SELECT 1 FROM _tmp_end_nodes AS tmp
		WHERE tmp.node_id=w.last_node_id
	)
	GROUP BY w.way_id, w.last_node_id, w.layer
	HAVING COUNT(wn.way_id)=1
", $db1);
query("ANALYZE _tmp_end_nodes", $db1);


// now remove nodes that have noexit=yes or turning_circle tags
// as they are marked as dead ends of roads intentionally
// plus: remove end-nodes tagged as some amenity
// people connect post boxes with the nearest highway using
// a short way that would otherwise fall into this check
// these nodes can easily be ignored because a telephone
// will never stand in the middle of a crossing
query("
	DELETE FROM _tmp_end_nodes en
	WHERE en.node_id IN (
		SELECT node_id
		FROM node_tags AS t
		WHERE (
			(t.k='noexit' AND t.v IN ('yes', 'true', '1')) OR
			(t.k='highway' AND t.v='turning_circle') OR
			(t.k='highway' AND t.v='bus_stop') OR
			t.k='amenity'
		)
	)
", $db1);

// now remove ways that have noexit=yes
// as they are marked as dead ends of roads intentionally
// there is a risk of missing dead ended ways if they
// lack a junction on their entry node. These
// should be found by the islands check
query("
	DELETE FROM _tmp_end_nodes en
	WHERE en.way_id IN (
		SELECT way_id
		FROM way_tags AS t
		WHERE t.k='noexit' AND t.v IN ('yes', 'true', '1')
	)
", $db1);


// retrieve the x/y values
query("
	UPDATE _tmp_end_nodes en
	SET geom=n.geom, x=n.x, y=n.y
	FROM nodes n
	WHERE en.node_id=n.id
", $db1);
query("CREATE INDEX idx_tmp_end_nodes_geom ON _tmp_end_nodes USING gist (geom)", $db1);
query("ANALYZE _tmp_end_nodes", $db1);


/////////////////////////////////////////////////////////////////////////////////////

query("DROP TABLE IF EXISTS _tmp_barriers", $db1, false);

// find all barriers
query("
	CREATE TABLE _tmp_barriers (
	way_id bigint NOT NULL,
	layer text DEFAULT '0',
	PRIMARY KEY (way_id)
	)
", $db1);
query("SELECT AddGeometryColumn('_tmp_barriers', 'geom', 4326, 'LINESTRING', 2)", $db1, false);

// find any barrier-tagged way
query("
	INSERT INTO _tmp_barriers (way_id, geom)
	SELECT w.id, w.geom
	FROM ways AS w
	WHERE w.geom IS NOT NULL AND EXISTS (
		SELECT way_id
		FROM way_tags AS t
		WHERE t.way_id=w.id AND t.k='barrier'
	)
", $db1);


query("CREATE INDEX idx_tmp_barriers_geom ON _tmp_barriers USING gist (geom)", $db1);
query("ANALYZE _tmp_barriers", $db1);

find_layer_values('_tmp_barriers', 'way_id', 'layer', $db1);


/////////////////////////////////////////////////////////////////////////////////////


// join end_nodes and ways on "node inside bounding box of way"
query("DROP TABLE IF EXISTS _tmp_error_candidates", $db1, false);
query("
	CREATE TABLE _tmp_error_candidates (
		way_id bigint NOT NULL,
		node_id bigint NOT NULL,
		node_x double precision NOT NULL,
		node_y double precision NOT NULL,
		nearby_way_id bigint NOT NULL,
		distance double precision NOT NULL
	)
", $db1, false);


// end-node near way on the same layer
// but not intersecting any barrier
$result=query("
	INSERT INTO _tmp_error_candidates (way_id, node_id, node_x, node_y, nearby_way_id, distance)
	SELECT en.way_id, en.node_id, en.x AS node_x, en.y AS node_y, w.way_id AS nearby_way_id, ST_distance(w.geom, en.geom) AS distance
	FROM _tmp_end_nodes en, _tmp_ways w
	WHERE 
	ST_DWithin(w.geom, en.geom, {$check0050_min_distance}) AND
	en.way_id<>w.way_id AND
	en.layer=w.layer AND
	NOT EXISTS (
		SELECT 1
		FROM _tmp_barriers b
		WHERE b.layer=en.layer AND
			ST_Intersects(b.geom, ST_ShortestLine(w.geom, en.geom))
	)
", $db1);


// end-node near another end-node on a different layer
// even though they reside on different layers, it seems likely they were meant to be connected
// ("end-node near another end-node on the same layer" is covered by the first query)
$result=query("
	INSERT INTO _tmp_error_candidates (way_id, node_id, node_x, node_y, nearby_way_id, distance)
	SELECT en1.way_id, en1.node_id, en1.x AS node_x, en1.y AS node_y, en2.way_id AS nearby_way_id, ST_distance(en2.geom, en1.geom) AS distance
	FROM _tmp_end_nodes en1, _tmp_end_nodes en2
	WHERE ST_DWithin(en1.geom, en2.geom, {$check0050_min_distance}) AND
	en1.way_id<>en2.way_id AND
	en1.layer<>en2.layer AND
	NOT EXISTS (
		SELECT 1
		FROM _tmp_barriers b
		WHERE b.layer IN (en1.layer, en2.layer) AND
			ST_Intersects(b.geom, ST_ShortestLine(en1.geom, en2.geom))
	)
", $db1);



query("CREATE INDEX idx_tmp_error_candidates_way_id ON _tmp_error_candidates (way_id)", $db1);
query("CREATE INDEX idx_tmp_error_candidates_nearby_way_id ON _tmp_error_candidates (nearby_way_id)", $db1);
query("CREATE INDEX idx_tmp_error_candidates_node ON _tmp_error_candidates (node_id, distance)", $db1);
query("ANALYZE _tmp_error_candidates", $db1);


query("DROP TABLE IF EXISTS _tmp_error_candidates2", $db1, false);
query("
	CREATE TABLE _tmp_error_candidates2 (
		ID serial NOT NULL PRIMARY KEY,
		node_id bigint NOT NULL,
		nearby_way_id bigint NOT NULL,
		distance double precision NOT NULL
	)
", $db1, false);


// exclude error candidates that are connected by a way

// this is a very simplified lookup to find out if two ways
// are connected __directly__ (they share a common node)
// and if the connection is not more than 3*$check0050_min_distance away from
// the node under test
// this is used to avoid false-positives on last-segments of ways
// that are shorter than our threshold. They shall not be checked
// against ways to which they ARE connected.
query("
	INSERT INTO _tmp_error_candidates2 (node_id, nearby_way_id, distance)
	SELECT node_id, nearby_way_id, distance
	FROM _tmp_error_candidates C
	WHERE NOT EXISTS (

		SELECT 1
		FROM 
			(SELECT 1 FROM way_nodes WHERE way_id=C.nearby_way_id) wn1 
		INNER JOIN 
			(SELECT 1 FROM way_nodes WHERE way_id=C.way_id) wn2 
		USING (node_id)
		WHERE (wn1.x-C.node_x) ^ 2 + (wn1.y-C.node_y) ^ 2 <= (3*$check0050_min_distance) ^ 2

	)
", $db1);


// in case of multiple candidates per node report the one with minimum distance only 
query("
	INSERT INTO _tmp_errors (error_type, object_type, object_id, msgid, txt1, last_checked)
	SELECT $error_type, 'node', C.node_id, 
		'This node is very close but not connected to way #$1', C.nearby_way_id, NOW()
	FROM _tmp_error_candidates2 C
	WHERE ID=(

		SELECT ID
		FROM _tmp_error_candidates2 T
		WHERE T.node_id=C.node_id
		ORDER BY distance
		LIMIT 1
	)
", $db1);



print_index_usage($db1);


query("DROP TABLE IF EXISTS _tmp_ways", $db1, false);
query("DROP TABLE IF EXISTS _tmp_end_nodes", $db1, false);
query("DROP TABLE IF EXISTS _tmp_error_candidates", $db1, false);
query("DROP TABLE IF EXISTS _tmp_error_candidates2", $db1, false);
query("DROP TABLE IF EXISTS _tmp_barriers", $db1, false);


?>