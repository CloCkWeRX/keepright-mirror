-- add SRID into spatial_ref_sys table
DELETE FROM spatial_ref_sys WHERE srid = 900913;
INSERT INTO spatial_ref_sys (srid, auth_name, auth_srid, srtext, proj4text) VALUES 
(900913,'EPSG',900913,'PROJCS["WGS84 / Simple Mercator",GEOGCS["WGS 84",
DATUM["WGS_1984",SPHEROID["WGS_1984", 6378137.0, 298.257223563]],PRIMEM["Greenwich", 0.0],
UNIT["degree", 0.017453292519943295],AXIS["Longitude", EAST],AXIS["Latitude", NORTH]],
PROJECTION["Mercator_1SP_Google"],PARAMETER["latitude_of_origin", 0.0],
PARAMETER["central_meridian", 0.0],PARAMETER["scale_factor", 1.0],PARAMETER["false_easting", 0.0],
PARAMETER["false_northing", 0.0],UNIT["m", 1.0],AXIS["x", EAST],
AXIS["y", NORTH],AUTHORITY["EPSG","900913"]]',
'+proj=merc +a=6378137 +b=6378137 +lat_ts=0.0 +lon_0=0.0 +x_0=0.0 +y_0=0 +k=1.0 +units=m +nadgrids=@null +no_defs');


-- Drop all primary keys and indexes to improve load speed.
ALTER TABLE nodes DROP CONSTRAINT pk_nodes;
ALTER TABLE ways DROP CONSTRAINT pk_ways;
ALTER TABLE way_nodes DROP CONSTRAINT pk_way_nodes;
ALTER TABLE relations DROP CONSTRAINT pk_relations;

DROP INDEX IF EXISTS idx_node_tags_node_id;
DROP INDEX IF EXISTS idx_nodes_geom;
DROP INDEX IF EXISTS idx_way_tags_way_id;
DROP INDEX IF EXISTS idx_way_nodes_node_id;
DROP INDEX IF EXISTS idx_relation_tags_relation_id;
DROP INDEX IF EXISTS idx_ways_bbox;

DROP INDEX IF EXISTS idx_relations_member_id;
DROP INDEX IF EXISTS idx_relations_member_role;
DROP INDEX IF EXISTS idx_relations_member_type;

DROP INDEX IF EXISTS idx_node_tags_k;
DROP INDEX IF EXISTS idx_node_tags_v;
DROP INDEX IF EXISTS idx_way_tags_k;
DROP INDEX IF EXISTS idx_way_tags_v;
DROP INDEX IF EXISTS idx_relation_tags_k;
DROP INDEX IF EXISTS idx_relation_tags_v;

SELECT DropGeometryColumn('ways', 'bbox');
SELECT DropGeometryColumn('ways', 'geom');

-- Import the table data from the data files using the fast COPY method.
\copy nodes FROM 'pgimport/nodes_sorted.txt' WITH NULL AS 'NULL'
\copy node_tags FROM 'pgimport/node_tags.txt' WITH NULL AS 'NULL'
\copy ways FROM 'pgimport/ways.txt' WITH NULL AS 'NULL'
\copy way_tags FROM 'pgimport/way_tags.txt' WITH NULL AS 'NULL'
\copy way_nodes FROM 'pgimport/way_nodes2.txt' WITH NULL AS 'NULL'
\copy relations FROM 'pgimport/relations.txt' WITH NULL AS 'NULL'
\copy relation_tags FROM 'pgimport/relation_tags.txt' WITH NULL AS 'NULL'
\copy relation_members FROM 'pgimport/relation_members.txt' WITH NULL AS 'NULL'

-- Add the primary keys and indexes back again (except the way bbox index).
ALTER TABLE ONLY nodes ADD CONSTRAINT pk_nodes PRIMARY KEY (id);
ALTER TABLE ONLY ways ADD CONSTRAINT pk_ways PRIMARY KEY (id);
ALTER TABLE ONLY way_nodes ADD CONSTRAINT pk_way_nodes PRIMARY KEY (way_id, sequence_id);
ALTER TABLE ONLY relations ADD CONSTRAINT pk_relations PRIMARY KEY (id);
CREATE INDEX idx_node_tags_node_id ON node_tags USING btree (node_id);
CREATE INDEX idx_nodes_geom ON nodes USING gist (geom);
CREATE INDEX idx_way_tags_way_id ON way_tags USING btree (way_id);
CREATE INDEX idx_way_nodes_node_id ON way_nodes USING btree (node_id);
CREATE INDEX idx_relation_tags_relation_id ON relation_tags USING btree (relation_id);
CREATE INDEX idx_relations_member_id ON relation_members USING btree (member_id);
CREATE INDEX idx_relations_member_role ON relation_members USING btree (member_role);
CREATE INDEX idx_relations_member_type ON relation_members USING btree (member_type);
