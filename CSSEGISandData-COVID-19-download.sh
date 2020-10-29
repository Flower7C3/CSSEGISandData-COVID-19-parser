#!/usr/bin/env bash

cd $(dirname $0)

download_file() {
    local remote_file_path="https://raw.githubusercontent.com/CSSEGISandData/COVID-19/$1"
    local local_file_path=$2
    local date_format=$3
    current_date=$(date +${date_format})
    if [[ "Darwin" == "$(uname)" ]]; then
        file_date=$(stat -f "%Sm" -t "${date_format}" "$local_file_path")
    else
        file_date=$(date -d @$(stat -c "%Y" "$local_file_path") +${date_format})
    fi
    if [[ "$file_date" -lt "$current_date" ]]; then
        printf "Download $remote_file_path"
        curl -sL $remote_file_path >$local_file_path
        printf " [OK]\n"
    fi
}

series=(confirmed recovered deaths)
for serie in "${series[@]}"; do
    download_file "master/csse_covid_19_data/csse_covid_19_time_series/time_series_covid19_${serie}_global.csv" "data/time_series_${serie}_global.csv" "%Y%m%d"
done
download_file "master/csse_covid_19_data/UID_ISO_FIPS_LookUp_Table.csv" "data/UID_ISO_FIPS_LookUp_Table.csv" "%Y%m%d%H"
#download_file "master/csse_covid_19_data/csse_covid_19_daily_reports/$(date -v-1d +%m-%d-%Y).csv" "data/daily_reports.csv" "%Y%m%d%H"
download_file "web-data/data/cases_country.csv" "data/cases_country.csv" "%Y%m%d%H"

if [[ "Darwin" == "$(uname)" ]]; then
    php CSSEGISandData-COVID-19-parse.php
else
    php74 CSSEGISandData-COVID-19-parse.php
fi

echo "Done :)"
