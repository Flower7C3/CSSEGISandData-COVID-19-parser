#!/usr/bin/env bash

cd $(dirname $0)

download_file() {
    local remote_file_path="https://raw.githubusercontent.com/CSSEGISandData/COVID-19/master/csse_covid_19_data/$1"
    local local_file_path=$2
    current_date=$(date +%Y%m%d)
    if [[ "Darwin" == "$(uname)" ]]; then
        file_date=$(stat -f "%Sm" -t "%Y%m%d" "$local_file_path")
    else
        file_date=$(date -d @$(stat -c "%Y" "$local_file_path") +%Y%m%d)
    fi
    if [[ "$file_date" -lt "$current_date" ]]; then
        printf "Download $remote_file_path"
        curl -sL $remote_file_path >$local_file_path
        printf " [OK]\n"
    fi
}

series=(confirmed recovered deaths)
for serie in "${series[@]}"; do
    serie_file_name="time_series_covid19_${serie}_global.csv"
    download_file "csse_covid_19_time_series/$serie_file_name" "data/${serie_file_name}"
done
countries_file_name="UID_ISO_FIPS_LookUp_Table.csv"
download_file "$countries_file_name" "data/${countries_file_name}"

if [[ "Darwin" == "$(uname)" ]]; then
    php time_series_covid19_global.php
else
    php74 time_series_covid19_global.php
fi

echo "Done :)"
