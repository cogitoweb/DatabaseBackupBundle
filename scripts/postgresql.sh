#!/bin/bash
#date +%Y%m%d%H%M%S > test.txt

HOST=
PORT=
USERNAME=
PASSWORD=
DATABASE=

parse_args()
{
	local arg
	
	while getopts h:p:u:P::d: arg ; do
		case ${arg} in
			h)
				HOST=${OPTARG}
				;;
			p)
				PORT=${OPTARG}
				;;
			u)
				USERNAME=${OPTARG}
				;;
			P)
				PASSWORD=${OPTARG}
				;;
			d)
				DATABASE=${OPTARG}
				;;
			*)
				exit 1
		esac
    done
}

md5()
{
	# Work in temp dir
	cd "$TEMP"
	
	# Use md5 of current timestamp as temp filename
	FILENAME=$(date +%s | md5sum | awk '{ print $1 }')
	
	# Allow no-password connections only
	if [ -n "$PASSWORD" ]; then
		exit 1
	fi
	
	# Dump database schema to a temp file
	pg_dump --host "$HOST" --port "$PORT" --username "$USERNAME" --no-password --schema-only --file "$FILENAME" "$DATABASE"
	
	# Get md5 of schema
	MD5=$(md5sum "$FILENAME" | awk '{ print $1 }')
	
	# Delete temp file
	rm "$FILENAME"
	
	# Return md5
	echo -n "$MD5"
}

case "$1" in
	md5)
		parse_args ${@:2}
		$1
		;;
	*)
		echo "Usage: $0 md5|exec"
		exit 1
esac
exit 0