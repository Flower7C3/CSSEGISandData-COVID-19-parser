<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/gclient.php';

date_default_timezone_set('UTC');

if (php_sapi_name() != 'cli') {
    throw new Exception('This application must be run on the command line.');
}


################################################################################
# CONFIG
################################################################################
$spreadSheetId = '14CfaNb8091RZneIXP02pdBjZu7NttWHcM2NOKQiPgNs';
define('SERIE_CONFIRMED', 'confirmed');
define('SERIE_RECOVERED', 'recovered');
define('SERIE_DEATHS', 'deaths');
define('SERIE_ACTIVE', 'active');
define('SERIES_FIRST_VALUE_COL_ID', 4);
$series = [
    SERIE_CONFIRMED,
    SERIE_RECOVERED,
    SERIE_DEATHS,
];
$countriesWhiteList = [
    'Portugal',
    'Spain',
    'Andorra',
    'France',
    'Monaco',
    'Belgium',
    'Netherlands',
    'Luxembourg',
    'Germany',
    'Switzerland',
    'Austria',
    'Liechtenstein',

    'Italy',
    'San Marino',
    'Vatican City',
    'Malta',

    'Ireland',
    'United Kingdom',
    'Iceland',
    'Denmark',
    'Norway',
    'Sweden',
    'Finland',

    'Estonia',
    'Latvia',
    'Lithuania',
    'Belarus',
    'Ukraine',
    'Poland',
    'Czechia',
    'Slovakia',
    'Hungary',
    'Romania',
    'Moldova',
    'Slovenia',
    'Croatia',
    'Bosnia and Herzegovina',
    'Serbia',
    'Montenegro',
    'Kosovo',
    'Bulgaria',

    'North Macedonia',
    'Albania',
    'Greece',
    'Cyprus',

//    'Turkey',
//    'Russia',
];

################################################################################
# DATA
################################################################################
$gDataCases = [];
$dates = [];
$mappingRowToId = [];
$mappingColToId = [];
$countriesList = [];
$zero = number_format('0', '8', ',', '');

################################################################################
function getRowId($name)
{
    if (is_int($name)) {
        return $name;
    }
    global $mappingRowToId;
    if (!isset($mappingRowToId[$name])) {
        $mappingRowToId[$name] = count($mappingRowToId);
    }
    return $mappingRowToId[$name];
}

function getColId($name)
{
    if (is_int($name)) {
        return $name;
    }
    global $mappingColToId;
    if (!isset($mappingColToId[$name])) {
        $mappingColToId[$name] = count($mappingColToId);
    }
    return $mappingColToId[$name];
}

function gdataSetCase($value, $rowName = 'head', $colName = null)
{
    global $gDataCases;
    if (empty($colName)) {
        $colName = $value;
    }
    $rowKey = getRowId($rowName);
    $colKey = getColId($colName);
    $gDataCases[$rowKey][$colKey] = valueOrEmpty($value);
}

function gdataGetCase($rowName, $colName)
{
    global $gDataCases;
    $rowKey = getRowId($rowName);
    $colKey = getColId($colName);
    return $gDataCases[$rowKey][$colKey] ?: 0;
}

function valueOrEmpty($value)
{
    global $zero;
    $test = str_replace('%', '', $value);
    if (empty($test) || $zero === $test) {
        return '';
    }
    return $value;
}

function getCellValue($rowData, $colNameToId, $colName)
{
    if (!isset($colNameToId[$colName], $rowData[$colNameToId[$colName]])) {
        return '';
    }
    return trim($rowData[$colNameToId[$colName]]);
}

function parseCsvFile($filename)
{
    $stream = file_get_contents('data/' . $filename);
    $rows = explode("\n", $stream);
    $row = str_getcsv($rows[0]);
    $colNameToId = [];
    # PARSE HEAD
    foreach ($row as $colId => $colName) {
        $colName = trim($colName);
        $colNameToId[$colName] = $colId;
    }
    unset($rows[0]);
    return [$rows, $colNameToId];
}

function isCountryOnWhiteList($countryName)
{
    global $countriesWhiteList;
    return !(empty($countryName) || !(!empty($countriesWhiteList) && in_array($countryName, $countriesWhiteList, true)));
}

function formatInt($number)
{
    return (int)$number;
}

function formatFloat($number, $suffix = '')
{
    return number_format($number, 12, ',', '') . $suffix;
}

function formatPercent($number)
{
    return formatFloat($number, '%');
}

################################################################################
# PROCESS COUNTRIES
################################################################################
printf('Parse countries');
list($rows, $colNameToId) = parseCsvFile('UID_ISO_FIPS_LookUp_Table.csv');
# PARSE ROWS
foreach ($rows as $row) {
    $rowData = str_getcsv($row);
    $nameValue = getCellValue($rowData, $colNameToId, 'Combined_Key');
    if (!isCountryOnWhiteList($nameValue)) {
        continue;
    }
    $countriesList[$nameValue] = [
        'name' => $nameValue,
        'population' => formatInt(getCellValue($rowData, $colNameToId, 'Population')),
        'confirmed total' => '',
        'recovered total' => '',
        'deaths total' => '',
        'active total' => '',
        'recovered percent' => '',
        'deaths percent (mortality rate)' => '',
        'active percent' => '',
        'confirmed in population' => '',
        'recovered in population' => '',
        'deaths in population' => '',
        'active in population' => '',
        'incident rate' => '',
        'active 1 of n' => '',
        'people tested' => '',
        'people hospitalized' => '',
        'first case at' => '',
        'last update at' => '',
    ];
}
printf(' [OK]' . PHP_EOL);

################################################################################
# PROCESS COUNTRIES
################################################################################
printf('Parse daily report');
list($rows, $colNameToId) = parseCsvFile('cases_country.csv');
foreach ($rows as $row) {
    $rowData = str_getcsv($row);
    $nameValue = getCellValue($rowData, $colNameToId, 'Country_Region');
    if (!isCountryOnWhiteList($nameValue)) {
        continue;
    }
    # date
    $countriesList[$nameValue]['last update at'] = (new DateTime(getCellValue($rowData, $colNameToId, 'Last_Update'), new DateTimeZone('UTC')))->format('r');
    # values
    $countriesList[$nameValue]['confirmed total'] = formatInt(getCellValue($rowData, $colNameToId, 'Confirmed'));
    $countriesList[$nameValue]['recovered total'] = formatInt(getCellValue($rowData, $colNameToId, 'Recovered'));
    $countriesList[$nameValue]['deaths total'] = formatInt(getCellValue($rowData, $colNameToId, 'Deaths'));
    $countriesList[$nameValue]['active total'] = formatInt(getCellValue($rowData, $colNameToId, 'Active'));
    # percentage of active
    $countriesList[$nameValue]['recovered percent'] = formatPercent(($countriesList[$nameValue]['active total'] > 0) ? ($countriesList[$nameValue]['recovered total'] / $countriesList[$nameValue]['confirmed total'] * 100) : 0);
    $countriesList[$nameValue]['deaths percent (mortality rate)'] = formatPercent(getCellValue($rowData, $colNameToId, 'Mortality_Rate'));
    $countriesList[$nameValue]['active percent'] = formatPercent(($countriesList[$nameValue]['active total'] > 0) ? ($countriesList[$nameValue]['active total'] / $countriesList[$nameValue]['confirmed total'] * 100) : 0);
    # percentage of population
    $countriesList[$nameValue]['incident rate'] = formatFloat(getCellValue($rowData, $colNameToId, 'Incident_Rate'));
    $countriesList[$nameValue]['confirmed in population'] = formatPercent($countriesList[$nameValue]['incident rate'] / 1000);
    $countriesList[$nameValue]['recovered in population'] = formatPercent($countriesList[$nameValue]['recovered total'] / $countriesList[$nameValue]['population'] * 100);
    $countriesList[$nameValue]['deaths in population'] = formatPercent($countriesList[$nameValue]['deaths total'] / $countriesList[$nameValue]['population'] * 100);
    $countriesList[$nameValue]['active in population'] = formatPercent($countriesList[$nameValue]['active total'] / $countriesList[$nameValue]['population'] * 100);
    $countriesList[$nameValue]['active 1 of n'] = $countriesList[$nameValue]['population'] / $countriesList[$nameValue]['active total'];
    # other
    $countriesList[$nameValue]['people tested'] = getCellValue($rowData, $colNameToId, 'People_Tested');
    $countriesList[$nameValue]['people hospitalized'] = getCellValue($rowData, $colNameToId, 'People_Hospitalized');
}
printf(' [OK]' . PHP_EOL);

################################################################################
# PROCESS CASES
################################################################################
foreach ($series as $serie) {
    printf('Parse %s cases', $serie);
    $countriesCases = [];
    list($rows, $colNameToId) = parseCsvFile(sprintf("time_series_%s_global.csv", $serie));
    foreach ($colNameToId as $colName => $colId) {
        if ($colId >= SERIES_FIRST_VALUE_COL_ID) {
            $dates[$colId] = (new DateTime($colName))->format('Y-m-d');
        }
    }

    # PARSE ROWS
    foreach ($rows as $row) {
        $rowData = str_getcsv($row);
        $nameValue = getCellValue($rowData, $colNameToId, 'Country/Region');
        $countryCases = [];
        if (isset($countriesCases[$nameValue])) {
            $countryCases = $countriesCases[$nameValue];
        }
        if (!isCountryOnWhiteList($nameValue)) {
            continue;
        }

        # PARSE COLUMNS
        foreach ($rowData as $colId => $cellValue) {
            if ($colId >= SERIES_FIRST_VALUE_COL_ID) {
                $date = $dates[$colId];
                $value = $rowData[$colId];
                if (isset($countriesCases[$nameValue][$date])) {
                    $value += $countriesCases[$nameValue][$date];
                }
                $countryCases[$date] = $value;
            }
        }
        $countriesCases[$nameValue] = $countryCases;
    }

    # PREPARE OUTPUT
    printf('Set dates in %s case', $serie);
    $colName = 'date';
    gdataSetCase($colName);
    foreach ($dates as $date) {
        gdataSetCase($date, $date, $colName);
    }
    printf(' [OK]' . PHP_EOL);

    printf('Set values in %s case', $serie);
    foreach ($countriesCases as $countryName => $cases) {
        $colName = $countryName . ' ' . $serie . ' ' . 'total';
        gdataSetCase($colName);
        foreach ($cases as $date => $value) {
            gdataSetCase((int)$value, $date, $colName);
        }
    }
    printf(' [OK]' . PHP_EOL);
}

################################################################################
# MATH OPERATIONS
################################################################################
foreach ($countriesList as $countryKey => $countryData) {
    $countryName = $countryData['name'];
    $populationValue = $countryData['population'];
    printf('Count active cases for %s', $countryName);
    $confirmedTotalColId = getColId($countryName . ' ' . SERIE_CONFIRMED . ' ' . 'total');
    $recoveredTotalColId = getColId($countryName . ' ' . SERIE_RECOVERED . ' ' . 'total');
    $deadlyTotalColId = getColId($countryName . ' ' . SERIE_DEATHS . ' ' . 'total');
    $activeColTotalName = $countryName . ' ' . 'active' . ' ' . 'total';
    $recoveredPercentageColName = $countryName . ' ' . SERIE_RECOVERED . ' percent';
    $deadlyPercentageColName = $countryName . ' ' . SERIE_DEATHS . ' percent';
    $activePercentageColName = $countryName . ' ' . SERIE_ACTIVE . ' percent';
    $confirmedDailyColName = $countryName . ' ' . SERIE_CONFIRMED . ' daily';
    $recoveredDailyColName = $countryName . ' ' . SERIE_RECOVERED . ' daily';
    $deadlyDailyColName = $countryName . ' ' . SERIE_DEATHS . ' daily';
    $confirmedPopulationColName = $countryName . ' ' . SERIE_CONFIRMED . ' in population';
    $recoveredPopulationColName = $countryName . ' ' . SERIE_RECOVERED . ' in population';
    $deadlyPopulationColName = $countryName . ' ' . SERIE_DEATHS . ' in population';
    $activePopulationColName = $countryName . ' ' . SERIE_ACTIVE . ' in population';
    $activeOneOfEnColName = $countryName . ' active 1 of n';
    gdataSetCase($activeColTotalName);
    gdataSetCase($recoveredPercentageColName);
    gdataSetCase($deadlyPercentageColName);
    gdataSetCase($activePercentageColName);
    gdataSetCase($confirmedDailyColName);
    gdataSetCase($recoveredDailyColName);
    gdataSetCase($deadlyDailyColName);
    gdataSetCase($confirmedPopulationColName);
    gdataSetCase($recoveredPopulationColName);
    gdataSetCase($deadlyPopulationColName);
    gdataSetCase($activePopulationColName);
    gdataSetCase($activeOneOfEnColName);
    $previousDateRowId = 0;
    $firstCaseDate = '';
    foreach ($dates as $date) {
        $dateRowId = getRowId($date);
        $confirmedValueToday = gdataGetCase($dateRowId, $confirmedTotalColId);
        $confirmedValueYesterday = ($previousDateRowId > 0) ? gdataGetCase($previousDateRowId, $confirmedTotalColId) : 0;
        $recoveredValueToday = gdataGetCase($dateRowId, $recoveredTotalColId);
        $recoveredValueYesterday = ($previousDateRowId > 0) ? gdataGetCase($previousDateRowId, $recoveredTotalColId) : 0;
        $deadlyValueToday = gdataGetCase($dateRowId, $deadlyTotalColId);
        $deadlyValueYesterday = ($previousDateRowId > 0) ? gdataGetCase($previousDateRowId, $deadlyTotalColId) : 0;
        if (empty($firstCaseDate) && !empty($confirmedValueToday)) {
            $firstCaseDate = $date;
        }
        # active
        $activeValueToday = formatInt($confirmedValueToday - $recoveredValueToday - $deadlyValueToday);
        gdataSetCase($activeValueToday, $date, $activeColTotalName);
        # percentage
        $recoveredPercentage = formatPercent(($confirmedValueToday > 0) ? ($recoveredValueToday / $confirmedValueToday * 100) : 0);
        gdataSetCase($recoveredPercentage, $date, $recoveredPercentageColName);
        $deadlyPercentage = formatPercent(($confirmedValueToday > 0) ? ($deadlyValueToday / $confirmedValueToday * 100) : 0);
        gdataSetCase($deadlyPercentage, $date, $deadlyPercentageColName);
        $activePercentage = formatPercent(($confirmedValueToday > 0) ? ($activeValueToday / $confirmedValueToday * 100) : 0);
        gdataSetCase($activePercentage, $date, $activePercentageColName);
        # daily
        $confirmedDailyValue = formatInt($confirmedValueToday - $confirmedValueYesterday);
        gdataSetCase($confirmedDailyValue, $date, $confirmedDailyColName);
        $recoveredDailyValue = formatInt($recoveredValueToday - $recoveredValueYesterday);
        gdataSetCase($recoveredDailyValue, $date, $recoveredDailyColName);
        $deadlyDailyValue = formatInt($deadlyValueToday - $deadlyValueYesterday);
        gdataSetCase($deadlyDailyValue, $date, $deadlyDailyColName);
        # population
        $confirmedPopulationValue = formatPercent($confirmedValueToday / $populationValue * 100);
        gdataSetCase($confirmedPopulationValue, $date, $confirmedPopulationColName);
        $recoveredPopulationValue = formatPercent($recoveredValueToday / $populationValue * 100);
        gdataSetCase($recoveredPopulationValue, $date, $recoveredPopulationColName);
        $deadlyPopulationValue = formatPercent($deadlyValueToday / $populationValue * 100);
        gdataSetCase($deadlyPopulationValue, $date, $deadlyPopulationColName);
        $activePopulationValue = formatPercent($activeValueToday / $populationValue * 100);
        gdataSetCase($activePopulationValue, $date, $activePopulationColName);
        $activeOneOfEnValue = formatFloat(empty($activeValueToday) ? 0 : $populationValue / $activeValueToday);
        gdataSetCase($activeOneOfEnValue, $date, $activeOneOfEnColName);
        $previousDateRowId = $dateRowId;
    }
    $countriesList[$countryKey]['first case at'] = $firstCaseDate;
    printf(' [OK]' . PHP_EOL);
}

printf('Recalculate countries');
$gDataCountriesList = [];
$header = [];
//$europe = [];
foreach ($countriesList as $countryData) {
    if (empty($header)) {
        foreach ($countryData as $colName => $value) {
            $header[] = $colName;
        }
        $gDataCountriesList[] = $header;
    }
    $row = [];
    foreach ($countryData as $key => $value) {
        $row[] = $value;
//        if(!isset($europe[$key])){
//            $europe[$key] = 0;
//        }
//        $europe[$key] += $value;
    }
    $gDataCountriesList[] = $row;
}
//$row = [];
//$europe['name'] = 'Europe';
//$europe['recovered percent'] = formatPercent(($europe['confirmed total'] > 0) ? ($europe['recovered total'] / $europe['confirmed total'] * 100) : 0);
//$europe['deaths percent'] = formatPercent(($europe['confirmed total'] > 0) ? ($europe['deaths total'] / $europe['confirmed total'] * 100) : 0);
//$europe['active percent'] = formatPercent(($europe['confirmed total'] > 0) ? ($europe['active total'] / $europe['confirmed total'] * 100) : 0);
//$europe['confirmed in population'] = formatPercent($europe['confirmed total'] / $europe['population'] * 100);
//$europe['recovered in population'] = formatPercent($europe['recovered total'] / $europe['population'] * 100);
//$europe['deaths in population'] = formatPercent($europe['deaths total'] / $europe['population'] * 100);
//$europe['active in population'] = formatPercent($europe['active total'] / $europe['population'] * 100);
//foreach ($europe as $value) {
//    $row[] = $value;
//}
//$gDataCountriesList[] = $row;
printf(' [OK]' . PHP_EOL);


################################################################################
# SAVE OUTPUT
################################################################################
$params = [
    'valueInputOption' => 'USER_ENTERED',
];
$client = getClient();
$service = new Google_Service_Sheets($client);

printf('Clear data');
$result = $service->spreadsheets_values->clear($spreadSheetId, 'data!A1:ZZ1000', new Google_Service_Sheets_ClearValuesRequest());
printf(' [%d]' . PHP_EOL, $result->getClearedRange());

printf('Clear countries');
$result = $service->spreadsheets_values->clear($spreadSheetId, 'countries list!A1:Z100', new Google_Service_Sheets_ClearValuesRequest());
printf(' [%d]' . PHP_EOL, $result->getClearedRange());

printf('Save data');
$result = $service->spreadsheets_values->update($spreadSheetId, 'data!A1', new Google_Service_Sheets_ValueRange([
    'values' => $gDataCases
]), $params);
printf(' [%d]' . PHP_EOL, $result->getUpdatedCells());

printf('Save countries');
$result = $service->spreadsheets_values->update($spreadSheetId, 'countries list!A1', new Google_Service_Sheets_ValueRange([
    'values' => $gDataCountriesList
]), $params);
printf(' [%d]' . PHP_EOL, $result->getUpdatedCells());

