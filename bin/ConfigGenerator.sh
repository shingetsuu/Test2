#!/bin/env bash
DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
CONFIG=${DIR}/../config/config.users.local.php
CSVFILE=${DIR}/../runtime/names.csv
wget -O /tmp/names.tmp 'https://docs.google.com/spreadsheets/d/1mCW3WxGYGOUZhc2H3DkAKMTKqF2eNK1HuIBv3cH5PbA/export?format=csv&gid=307095716'

sed 's/\r$//' /tmp/names.tmp > ${CSVFILE}

jiraNames=($(cut -d ',' -f1 ${CSVFILE}))
slackNames=($(cut -d ',' -f2 ${CSVFILE}))

rm /tmp/names.tmp

printf "<?php\n" > ${CONFIG}
printf "return [\n" >> ${CONFIG}
printf "\t'users' => [\n" >> ${CONFIG}

namesCount=${#jiraNames[@]}

for (( i=1; i<namesCount; i++))
do
    printf "\t\t'${jiraNames[$i]}' => [\n\t\t\t'slack' => '${slackNames[$i]}',\n\t\t],\n" >> ${CONFIG}
done
printf "\t],\n" >> ${CONFIG}
printf "];" >> ${CONFIG}