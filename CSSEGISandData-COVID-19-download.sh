#!/usr/bin/env bash

cd $(dirname $0)

download_file() {
    local remote_file_path="https://raw.githubusercontent.com/CSSEGISandData/COVID-19/$1"
    local local_file_path=$2
    printf "Download $remote_file_path"
    curl -sL -o "$local_file_path" -z "$local_file_path" "$remote_file_path"
    printf " [OK]\n"
}

series=(confirmed recovered deaths)
for serie in "${series[@]}"; do
    download_file "master/csse_covid_19_data/csse_covid_19_time_series/time_series_covid19_${serie}_global.csv" "data/time_series_${serie}_global.csv"
done
download_file "master/csse_covid_19_data/UID_ISO_FIPS_LookUp_Table.csv" "data/UID_ISO_FIPS_LookUp_Table.csv"
#download_file "master/csse_covid_19_data/csse_covid_19_daily_reports/$(date -v-1d +%m-%d-%Y).csv" "data/daily_reports.csv"
download_file "web-data/data/cases_country.csv" "data/cases_country.csv"

if [[ "Darwin" == "$(uname)" ]]; then
    php CSSEGISandData-COVID-19-parse.php
else
    php74 CSSEGISandData-COVID-19-parse.php
fi

echo "Done :)"
