#!/bin/bash
#
# script for updating a local open streetmap database
#
# this tool uses osmosis (http://wiki.openstreetmap.org/index.php/Osmosis)
# osmosis can be downloaded from http://gweb.bretth.com/osmosis-latest.tar.gz
# a schema definition for the database is part of the osmosis source distribution
#
# this script will download a dump file, insert it into the database, assemble some
# helper tables used for error checks and finally run the error checks on the database
#
# start this script out of the checks directory
# a copy of the config file will be created in your home directory
# to save it from svn updates
#
# exit status is 0 for success, 1 on error or when there is nothing to do
# the script will exit on the first database where there is nothing to do
#
# written by Harald Kleiner, May 2008
#

USERCONFIG=$HOME/keepright.config
###########################
# Copy the default config to the users home directory
# which should be edited to match enviornment
###########################
if [ ! -f "$USERCONFIG" ]; then
    if [ ! -f ../config/config ]; then
        echo ""
        echo "The default config file is not in current directory"
        echo "Was updateDB.sh started from checks directory?"
        echo ""
        exit 1
    fi
    cp ../config/config $USERCONFIG
    echo ""
    echo "This is the first time you have run updateDB.sh"
    echo "Edit the file $USERCONFIG as required then run this again"
    echo ""
    exit 1
fi

# import config file
. $USERCONFIG
###########################
# Check config settings match the system
###########################
if [ ! -f "$CHECKSDIR/updateDB.sh" ]; then
    echo ""
    echo "Cannot find file $CHECKSDIR/updateDB.sh - is PREFIX in config correct"
    echo ""
    exit 1
fi

FILE="0"
SORTOPTIONS="--temporary-directory=$TMPDIR"

for i do	# loop all given parameter values


	#choose the right parameters as specified in config file
	eval 'URL=${URL_'"${i}"'}'
	eval 'FILE=${FILE_'"${i}"'}'
	eval 'MAIN_DB_NAME=${MAIN_DB_NAME_'"${i}"'}'
	eval 'SCHEMA=${MAIN_SCHEMA_NAME_'"${i}"'}'
	eval 'CAT=${CAT_'"${i}"'}'
	eval 'MIN_SIZE=${MIN_SIZE_'"${i}"'}'

	if [ "$FILE" != "0" ]; then


		echo "--------------------"
		echo "processing file $FILE for database $MAIN_DB_NAME schema $SCHEMA"
		echo "--------------------"

		PGHOST="$MAIN_DB_HOST"
		export PGHOST

		PGDATABASE="$MAIN_DB_NAME"
		export PGDATABASE

		PGUSER="$MAIN_DB_USER"
		export PGUSER

		PGPASSWORD="$MAIN_DB_PASS"
		export PGPASSWORD

		# if SCHEMA config option exists, change the search_path to given schema
		# psql will read and use content of the environment variable PGOPTIONS
		if [ "$SCHEMA" ]; then
			PGOPTIONS="--search_path=$SCHEMA"
		else
			PGOPTIONS="--search_path=public"
		fi
		export PGOPTIONS

		# check if connect to database is possible
		psql -c "SELECT error_id FROM public.errors LIMIT 1" > /dev/null 2>&1
                if [ $? != 0 ]; then
			# there was an error, so create the db

			echo "`date` * creating the database $MAIN_DB_NAME"
			# create fresh database and activate PL/PGSQL
			createdb -E UTF8 "$MAIN_DB_NAME"
			createlang plpgsql "$MAIN_DB_NAME"

			if [ "$SCHEMA" ]; then
				psql -c "CREATE SCHEMA $SCHEMA"
			fi

			# Activate GIS
			psql -f /usr/share/postgresql-8.3-postgis/lwpostgis.sql > /dev/null 2>&1

			psql -c "ALTER TABLE geometry_columns OWNER TO $MAIN_DB_USER; ALTER TABLE spatial_ref_sys OWNER TO $MAIN_DB_USER;"

			# create tables
			psql -f $PREFIX/planet/pgsql_simple_schema.sql

			# create schema info table
			psql -c "DROP TABLE IF EXISTS schema_info; CREATE TABLE schema_info (version integer NOT NULL); INSERT INTO schema_info VALUES (1);"
		fi

		echo "`date` * preparing table structures"
		#cd "$CHECKSDIR"
		php prepare_tablestructure.php "$i"



                if [ "$KEEP_OSM" = "0" ]; then
			echo "`date` * downloading osm file"

			if [ "$URL" ]; then
				# there's just one planet file configured with URL_XY=...

				wget --progress=dot:mega --output-document "$TMPDIR/$FILE" "$URL"

			else
				# there are multiple planet files configured with URL_XY1=..., URLXY2=...
				# download them all and unite them

				COUNTER=1
				eval 'URL=${URL_'"${i}"''"${COUNTER}"'}'
				UNITE_CMD="java -jar osmosis.jar"

				# download files as long as URL_XY# parameters exist
				while [ "$URL" ]; do

					wget --progress=dot:mega --output-document "$TMPDIR/${FILE}_$COUNTER" "$URL"
					UNITE_CMD="${UNITE_CMD} --rx $TMPDIR/${FILE}_$COUNTER  compressionMethod=bzip2 "

					COUNTER=$[$COUNTER+1]
					eval 'URL=${URL_'"${i}"''"${COUNTER}"'}'

				done

				# add the merge commands to the osmosis command line (one merge less than files because of stack principle!)
				while [ $COUNTER -gt 2 ]; do
					UNITE_CMD="${UNITE_CMD} --merge"
					COUNTER=$[$COUNTER-1]
				done
				UNITE_CMD="${UNITE_CMD} --wx $TMPDIR/$FILE"

				# execute unite command
				cd "$TMPDIR"
				eval "$UNITE_CMD"
				cd "$CHECKSDIR"
			fi


                else
			echo "Using previous downloaded $TMPDIR/$FILE"
			echo "--------------------"
                fi

                if [ ! -f "$TMPDIR/$FILE" ]; then
			echo "The download file $TMPDIR/$FILE is not present"
			exit 1
                fi
		# Verify the size of file > MIN_SIZE kilobytes
                SIZE=`ls -alk $TMPDIR/$FILE | awk '{print $5}'`
                if [  $SIZE -lt $MIN_SIZE ]; then
                    echo "The download file $TMPDIR/$FILE is too small (filesize $SIZE less than $MIN_SIZE)"
                    exit 1
                fi

		# check if the planet file has changed.
		# if not, we can exit at this point

                # If the sum file does not exist then first time
                # and then can be processed
                if [ ! -f "$TMPDIR/sum-last_${i}" ]; then
			echo "XXXX" > "$TMPDIR/sum-last_${i}"
                fi

                # sum the current file
                cksum "$TMPDIR/$FILE" > "$TMPDIR/sum-current_${i}"

                # see if they are the same
                cmp --silent "$TMPDIR/sum-current_${i}" "$TMPDIR/sum-last_${i}"

                if [ $? != 0 ]; then
			echo File "$TMPDIR/$FILE" is changed
			FILE_CHANGED="1"
		else
			FILE_CHANGED="0"
		fi

		if [ "$KEEP_OSM" = "1" -o "$FILE_CHANGED" = "1" ]; then
			# this file will be the last file next time
			cksum "$TMPDIR/$FILE" > "$TMPDIR/sum-last_${i}"

			echo "`date` * truncating database"

			psql -c "TRUNCATE node_tags, way_tags, relation_tags, relation_members, relations, way_nodes, ways, nodes"

			echo "`date` * converting osm file into database dumps"
			cd "$TMPDIR"

			"$CAT" "$TMPDIR/$FILE" | java -jar osmosis.jar -p pl --read-xml file=/dev/stdin --pl

			echo "`date` * joining way_nodes and node coordinates"
			sort "$SORTOPTIONS" -n -k 2 pgimport/way_nodes.txt > pgimport/way_nodes_sorted.txt
			rm pgimport/way_nodes.txt
			sort "$SORTOPTIONS" -n -k 1 pgimport/nodes.txt > pgimport/nodes_sorted.txt
			rm pgimport/nodes.txt
			join -t "	" -e NULL -a 1 -1 2 -o 1.1,0,1.3,2.5,2.6,2.7,2.8 pgimport/way_nodes_sorted.txt pgimport/nodes_sorted.txt > pgimport/way_nodes2.txt
			rm pgimport/way_nodes_sorted.txt

			echo "`date` * joining ways with coordinates of first and last node"
			sort "$SORTOPTIONS" -t "	" -n -k 4 pgimport/ways.txt > pgimport/ways_sorted.txt
			rm pgimport/ways.txt
			join -t "	" -e NULL -a 1 -1 4 -o 1.1,1.2,1.3,0,1.5,2.5,2.6,2.7,2.8,1.6 pgimport/ways_sorted.txt pgimport/nodes_sorted.txt > pgimport/ways2.txt
			sort "$SORTOPTIONS" -t "	" -n -k 5 pgimport/ways2.txt > pgimport/ways_sorted.txt
			rm pgimport/ways2.txt
			join -t "	" -e NULL -1 5 -o 1.1,1.2,1.3,1.4,0,1.6,1.7,1.8,1.9,2.5,2.6,2.7,2.8,1.10 pgimport/ways_sorted.txt pgimport/nodes_sorted.txt > pgimport/ways.txt
			rm pgimport/ways_sorted.txt

			echo "`date` * loading database dumps"
			psql -f "$PSQL_LOAD_SCRIPT"
			cd "$CHECKSDIR"

			PGPASSWORD="shhh!"
			export PGPASSWORD


			cd "$CHECKSDIR"
			echo "`date` * preparing helper tables and columns"
			php prepare_helpertables.php "$i"

			echo "`date` * preparing country helper table"
			php prepare_countries.php "$i"

			echo "`date` * running the checks"
			php run-checks.php "$i"

			if [ "$MAIN_DB_NAME" != "osm_EU" -a "$MAIN_DB_NAME" != "osm_US" ]; then

				php export_errors.php "$i"

				if [ "$CREATE_DUMPS" = "1" ]; then
					./updateWebDB.sh --full "$i"
				else
					./updateWebDB.sh "$i"
				fi
			fi
			cd "$CHECKSDIR"
			echo "`date` * ready."

		else
			echo "File $TMPDIR/$FILE unchanged, nothing to do."
			exit 1
		fi
	fi

done

if [ "$FILE" = "0" ]; then
	echo "unknown country code"
	echo "usage: \"./updateDB.sh AT DE\""
	echo "will download and install Austrian and German planet dump "
	echo "you have to configure new country codes in the config file "
	echo "if you want to add new ones except the existing codes AT, DE, EU "
	exit 1
fi

exit 0

