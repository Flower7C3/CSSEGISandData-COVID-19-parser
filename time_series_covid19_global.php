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
define('SERIE_DEADLY', 'deaths');
define('SERIE_ACTIVE', 'active');
$series = [
    SERIE_CONFIRMED,
    SERIE_RECOVERED,
    SERIE_DEADLY,
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
$zeroPercent = $zero . '%';

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
    global $gDataCases, $zero, $zeroPercent;
    if (empty($colName)) {
        $colName = $value;
    }
    $rowKey = getRowId($rowName);
    $colKey = getColId($colName);
    $gDataCases[$rowKey][$colKey] = (!empty($value) && $zero !== $value && $zeroPercent !== $value) ? $value : '';
}

function gdataGetCase($rowName, $colName)
{
    global $gDataCases;
    $rowKey = getRowId($rowName);
    $colKey = getColId($colName);
    return $gDataCases[$rowKey][$colKey] ?: 0;
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
    $nameColId = $colNameToId['Combined_Key'];
    $nameValue = trim($rowData[$nameColId]);
    $populationColId = $colNameToId['Population'];
    $populationValue = trim($rowData[$populationColId]);
    if (
        empty($nameValue)
        ||
        (!empty($countriesWhiteList) && !in_array($nameValue, $countriesWhiteList, true))
    ) {
        continue;
    }
    $countriesList[$nameValue] = [
        'name' => $nameValue,
        'population' => (int)$populationValue,
    ];
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
    for ($colId = 4; $colId < $rowSize; $colId++) {
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
    $deadlyTotalColId = getColId($countryName . ' ' . SERIE_DEADLY . ' ' . 'total');
    $activeColTotalName = $countryName . ' ' . 'active' . ' ' . 'total';
    $recoveredPercentageColName = $countryName . ' ' . SERIE_RECOVERED . ' percent';
    $deadlyPercentageColName = $countryName . ' ' . SERIE_DEADLY . ' percent';
    $activePercentageColName = $countryName . ' ' . SERIE_ACTIVE . ' percent';
    $confirmedDailyColName = $countryName . ' ' . SERIE_CONFIRMED . ' daily';
    $recoveredDailyColName = $countryName . ' ' . SERIE_RECOVERED . ' daily';
    $deadlyDailyColName = $countryName . ' ' . SERIE_DEADLY . ' daily';
    $confirmedPopulationColName = $countryName . ' ' . SERIE_CONFIRMED . ' 1M';
    $recoveredPopulationColName = $countryName . ' ' . SERIE_RECOVERED . ' 1M';
    $deadlyPopulationColName = $countryName . ' ' . SERIE_DEADLY . ' 1M';
    $activePopulationColName = $countryName . ' ' . SERIE_ACTIVE . ' 1M';
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
    foreach ($dates as $date) {
        $dateRowId = getRowId($date);
        $confirmedValueToday = gdataGetCase($dateRowId, $confirmedTotalColId);
        $confirmedValueYesterday = ($previousDateRowId > 0) ? gdataGetCase($previousDateRowId, $confirmedTotalColId) : 0;
        $recoveredValueToday = gdataGetCase($dateRowId, $recoveredTotalColId);
        $recoveredValueYesterday = ($previousDateRowId > 0) ? gdataGetCase($previousDateRowId, $recoveredTotalColId) : 0;
        $deadlyValueToday = gdataGetCase($dateRowId, $deadlyTotalColId);
        $deadlyValueYesterday = ($previousDateRowId > 0) ? gdataGetCase($previousDateRowId, $deadlyTotalColId) : 0;
        # active
        $activeValueToday = (int)($confirmedValueToday - $recoveredValueToday - $deadlyValueToday);
        gdataSetCase($activeValueToday, $date, $activeColTotalName);
        # percentage
        $recoveredPercentage = number_format(($confirmedValueToday > 0) ? ($recoveredValueToday / $confirmedValueToday * 100) : 0, '0', ',', '') . '%';
        gdataSetCase($recoveredPercentage, $date, $recoveredPercentageColName);
        $deadlyPercentage = number_format(($confirmedValueToday > 0) ? ($deadlyValueToday / $confirmedValueToday * 100) : 0, '0', ',', '') . '%';
        gdataSetCase($deadlyPercentage, $date, $deadlyPercentageColName);
        $activePercentage = number_format(($confirmedValueToday > 0) ? ($activeValueToday / $confirmedValueToday * 100) : 0, '0', ',', '') . '%';
        gdataSetCase($activePercentage, $date, $activePercentageColName);
        # daily
        $confirmedDailyValue = (int)($confirmedValueToday - $confirmedValueYesterday);
        gdataSetCase($confirmedDailyValue, $date, $confirmedDailyColName);
        $recoveredDailyValue = (int)($recoveredValueToday - $recoveredValueYesterday);
        gdataSetCase($recoveredDailyValue, $date, $recoveredDailyColName);
        $deadlyDailyValue = (int)($deadlyValueToday - $deadlyValueYesterday);
        gdataSetCase($deadlyDailyValue, $date, $deadlyDailyColName);
        # population
        $confirmedPopulationValue = number_format($confirmedValueToday / $populationValue / 100 * 1000000, '8', ',', '');
        gdataSetCase($confirmedPopulationValue, $date, $confirmedPopulationColName);
        $recoveredPopulationValue = number_format($recoveredValueToday / $populationValue / 100 * 1000000, '8', ',', '');
        gdataSetCase($recoveredPopulationValue, $date, $recoveredPopulationColName);
        $deadlyPopulationValue = number_format($deadlyValueToday / $populationValue / 100 * 1000000, '8', ',', '');
        gdataSetCase($deadlyPopulationValue, $date, $deadlyPopulationColName);
        $activePopulationValue = number_format($activeValueToday / $populationValue / 100 * 1000000, '8', ',', '');
        gdataSetCase($activePopulationValue, $date, $activePopulationColName);
        $previousDateRowId = $dateRowId;
    }
    $countriesList[$countryKey]['confirmed total'] = !empty($confirmedValueToday) ? $confirmedValueToday : '';
    $countriesList[$countryKey]['recovered total'] = !empty($recoveredValueToday) ? $recoveredValueToday : '';
    $countriesList[$countryKey]['deadly total'] = !empty($deadlyValueToday) ? $deadlyValueToday : '';
    $countriesList[$countryKey]['active total'] = !empty($activeValueToday) ? $activeValueToday : '';
    $countriesList[$countryKey]['confirmed 1M'] = !empty($confirmedPopulationValue) && $zero !== $confirmedPopulationValue ? $confirmedPopulationValue : '';
    $countriesList[$countryKey]['recovered 1M'] = !empty($recoveredPopulationValue) && $zero !== $recoveredPopulationValue ? $recoveredPopulationValue : '';
    $countriesList[$countryKey]['deadly 1M'] = !empty($deadlyPopulationValue) && $zero !== $deadlyPopulationValue ? $deadlyPopulationValue : '';
    $countriesList[$countryKey]['active 1M'] = !empty($activePopulationValue) && $zero !== $activePopulationValue ? $activePopulationValue : '';
    printf(' [OK]' . PHP_EOL);
}

printf('Recalculate countries');
$gDataCountriesList = [];
$header = [];
foreach ($countriesList as $countryData) {
    if (empty($header)) {
        foreach ($countryData as $colName => $value) {
            $header[] = $colName;
        }
        $gDataCountriesList[] = $header;
    }
    $row = [];
    foreach ($countryData as $value) {
        $row[] = $value;
    }
    $gDataCountriesList[] = $row;
}
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

