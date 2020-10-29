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
$sourceFileNamePattern = "data/time_series_covid19_%s_global.csv";

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
    if (empty($colNameToId[$colName]) || empty($rowData[$colNameToId[$colName]])) {
        return null;
    }
    return trim($rowData[$colNameToId[$colName]]);
}

################################################################################
# PROCESS COUNTRIES
################################################################################
printf('Parse countries');
$stream = file_get_contents('data/UID_ISO_FIPS_LookUp_Table.csv');
$rows = explode("\n", $stream);
$rowsCount = count($rows);
$row = str_getcsv($rows[0]);
$rowSize = count($row);
$colNameToId = [];
# PARSE HEAD
for ($colId = 0; $colId < $rowSize; $colId++) {
    $value = trim($row[$colId]);
    $colNameToId[$value] = $colId;
}
# PARSE ROWS
for ($rowId = 1; $rowId < $rowsCount; $rowId++) {
    $rowData = str_getcsv($rows[$rowId]);
    $nameValue = getCellValue($rowData, $colNameToId, 'Combined_Key');
    if (
        empty($nameValue)
        ||
        (!empty($countriesWhiteList) && !in_array($nameValue, $countriesWhiteList, true))
    ) {
        continue;
    }
    $countriesList[$nameValue] = [
        'name' => $nameValue,
        'population' => (int)getCellValue($rowData, $colNameToId, 'Population'),
        'confirmed total' => null,
        'recovered total' => null,
        'deaths total' => null,
        'active total' => null,
        'recovered percent' => null,
        'deaths percent' => null,
        'active percent' => null,
        'confirmed in population' => null,
        'recovered in population' => null,
        'deaths in population' => null,
        'active in population' => null,
        'first case at' => null,
        'last update at' => null,
        'updated at' => date('Y-m-d H:i:s'),
        'incidence rate' => null,
        'case-fatality ratio' => null,
    ];
}
printf(' [OK]' . PHP_EOL);

################################################################################
# PROCESS COUNTRIES
################################################################################
printf('Parse daily report');
$stream = file_get_contents('data/daily_reports.csv');
$rows = explode("\n", $stream);
$rowsCount = count($rows);
$row = str_getcsv($rows[0]);
$rowSize = count($row);
$colNameToId = [];
# PARSE HEAD
for ($colId = 0; $colId < $rowSize; $colId++) {
    $value = trim($row[$colId]);
    $colNameToId[$value] = $colId;
}
# PARSE ROWS
for ($rowId = 1; $rowId < $rowsCount; $rowId++) {
    $rowData = str_getcsv($rows[$rowId]);
    $nameValue = getCellValue($rowData, $colNameToId, 'Country_Region');
    if (
        empty($nameValue)
        ||
        (!empty($countriesWhiteList) && !in_array($nameValue, $countriesWhiteList, true))
    ) {
        continue;
    }
    $countriesList[$nameValue]['last update at'] = getCellValue($rowData, $colNameToId, 'Last_Update');
    $countriesList[$nameValue]['confirmed total'] += getCellValue($rowData, $colNameToId, 'Confirmed');
    $countriesList[$nameValue]['recovered total'] += getCellValue($rowData, $colNameToId, 'Recovered');
    $countriesList[$nameValue]['deaths total'] += getCellValue($rowData, $colNameToId, 'Deaths');
    $countriesList[$nameValue]['active total'] = getCellValue($rowData, $colNameToId, 'Active');
//    $countriesList[$nameValue]['incidence rate'] = str_replace('.', '.', getCellValue($rowData, $colNameToId, 'Incidence_Rate'));
//    $countriesList[$nameValue]['case-fatality ratio'] = str_replace('.', '.', getCellValue($rowData, $colNameToId, 'Case-Fatality_Ratio'));
}
printf(' [OK]' . PHP_EOL);

################################################################################
# PROCESS CASES
################################################################################
foreach ($series as $serie) {
    printf('Parse %s cases', $serie);
    $sourceFileName = sprintf($sourceFileNamePattern, $serie);

    $countriesCases = [];

    # READ INPUT
    $stream = file_get_contents($sourceFileName);
    $rows = explode("\n", $stream);
    $rowsCount = count($rows);
    $row = str_getcsv($rows[0]);
    $rowSize = count($row);

    # PARSE HEAD
    for ($colId = SERIES_FIRST_VALUE_COL_ID; $colId < $rowSize; $colId++) {
        $dates[$colId] = (new DateTime($row[$colId]))->format('Y-m-d');
    }

    # PARSE ROWS
    for ($rowId = 1; $rowId < $rowsCount; $rowId++) {
        $row = str_getcsv($rows[$rowId]);
        $rowSize = count($row);
        if (empty($rowSize)) {
            continue;
        }
        list($regionName, $countryName) = $row;
        $countryCases = [];
        $name = '';
        if (!empty($countryName)) {
            $name = $countryName;
        }
        if (!empty($regionName)) {
            $name = $regionName;
            if (!empty($countryName)) {
                $name .= ' in ' . $countryName . '';
            }
        }
        if (isset($countriesCases[$name])) {
            $countryCases = $countriesCases[$name];
        }
        if (
            empty($name)
            ||
            (!empty($countriesWhiteList) && !array_key_exists($name, $countriesList))
        ) {
            continue;
        }

        # PARSE COLUMNS
        for ($colId = 4; $colId < $rowSize; $colId++) {
            $date = $dates[$colId];
            $value = $row[$colId];
            if (isset($countriesCases[$name][$date])) {
                $value += $countriesCases[$name][$date];
            }
            $countryCases[$date] = $value;
        }
        $countriesCases[$name] = $countryCases;
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
    $previousDateRowId = 0;
    $firstCaseDate = null;
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
        $activeValueToday = (int)($confirmedValueToday - $recoveredValueToday - $deadlyValueToday);
        gdataSetCase($activeValueToday, $date, $activeColTotalName);
        # percentage
        $recoveredPercentage = number_format(($confirmedValueToday > 0) ? ($recoveredValueToday / $confirmedValueToday * 100) : 0, '8', ',', '') . '%';
        gdataSetCase($recoveredPercentage, $date, $recoveredPercentageColName);
        $deadlyPercentage = number_format(($confirmedValueToday > 0) ? ($deadlyValueToday / $confirmedValueToday * 100) : 0, '8', ',', '') . '%';
        gdataSetCase($deadlyPercentage, $date, $deadlyPercentageColName);
        $activePercentage = number_format(($confirmedValueToday > 0) ? ($activeValueToday / $confirmedValueToday * 100) : 0, '8', ',', '') . '%';
        gdataSetCase($activePercentage, $date, $activePercentageColName);
        # daily
        $confirmedDailyValue = (int)($confirmedValueToday - $confirmedValueYesterday);
        gdataSetCase($confirmedDailyValue, $date, $confirmedDailyColName);
        $recoveredDailyValue = (int)($recoveredValueToday - $recoveredValueYesterday);
        gdataSetCase($recoveredDailyValue, $date, $recoveredDailyColName);
        $deadlyDailyValue = (int)($deadlyValueToday - $deadlyValueYesterday);
        gdataSetCase($deadlyDailyValue, $date, $deadlyDailyColName);
        # population
        $confirmedPopulationValue = number_format($confirmedValueToday / $populationValue * 100, '8', ',', '') . '%';
        gdataSetCase($confirmedPopulationValue, $date, $confirmedPopulationColName);
        $recoveredPopulationValue = number_format($recoveredValueToday / $populationValue * 100, '8', ',', '') . '%';
        gdataSetCase($recoveredPopulationValue, $date, $recoveredPopulationColName);
        $deadlyPopulationValue = number_format($deadlyValueToday / $populationValue * 100, '8', ',', '') . '%';
        gdataSetCase($deadlyPopulationValue, $date, $deadlyPopulationColName);
        $activePopulationValue = number_format($activeValueToday / $populationValue * 100, '8', ',', '') . '%';
        gdataSetCase($activePopulationValue, $date, $activePopulationColName);
        $previousDateRowId = $dateRowId;
    }
    $countriesList[$countryKey]['confirmed total'] = valueOrEmpty($confirmedValueToday);
    $countriesList[$countryKey]['recovered total'] = valueOrEmpty($recoveredValueToday);
    $countriesList[$countryKey]['deaths total'] = valueOrEmpty($deadlyValueToday);
    $countriesList[$countryKey]['active total'] = valueOrEmpty($activeValueToday);
    $countriesList[$countryKey]['recovered percent'] = valueOrEmpty($recoveredPercentage);
    $countriesList[$countryKey]['deaths percent'] = valueOrEmpty($deadlyPercentage);
    $countriesList[$countryKey]['active percent'] = valueOrEmpty($activePercentage);
    $countriesList[$countryKey]['confirmed in population'] = valueOrEmpty($confirmedPopulationValue);
    $countriesList[$countryKey]['recovered in population'] = valueOrEmpty($recoveredPopulationValue);
    $countriesList[$countryKey]['deaths in population'] = valueOrEmpty($deadlyPopulationValue);
    $countriesList[$countryKey]['active in population'] = valueOrEmpty($activePopulationValue);
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
//$europe['recovered percent'] = number_format(($europe['confirmed total'] > 0) ? ($europe['recovered total'] / $europe['confirmed total'] * 100) : 0, '8', ',', '') . '%';
//$europe['deaths percent'] = number_format(($europe['confirmed total'] > 0) ? ($europe['deaths total'] / $europe['confirmed total'] * 100) : 0, '8', ',', '') . '%';
//$europe['active percent'] = number_format(($europe['confirmed total'] > 0) ? ($europe['active total'] / $europe['confirmed total'] * 100) : 0, '8', ',', '') . '%';
//$europe['confirmed in population'] = number_format($europe['confirmed total'] / $europe['population'] * 100, '8', ',', '') . '%';
//$europe['recovered in population'] = number_format($europe['recovered total'] / $europe['population'] * 100, '8', ',', '') . '%';
//$europe['deaths in population'] = number_format($europe['deaths total'] / $europe['population'] * 100, '8', ',', '') . '%';
//$europe['active in population'] = number_format($europe['active total'] / $europe['population'] * 100, '8', ',', '') . '%';
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

