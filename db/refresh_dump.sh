#!/bin/bash
db=zeferis
dbuser=root
tables=( data_handler )

if [ "$1" = '' ]
then
    echo "o hi, pass the database password as an argument plx. thx.";
    exit 2;
fi

mysqldump -d -u $dbuser --password=$1 $db > ddl.sql

for table in  ${tables[@]}
do
    mysqldump -t -c -u $dbuser --password=$1 $db $table > data/${table}.sql
done
