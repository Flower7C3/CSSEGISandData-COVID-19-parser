<?php
error_reporting(E_ALL);
ini_set('display_errors', true);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/gclient.php';

class Address
{
    public static $sheetId = '102OzMvTnrmnsqftzJe1NdXUSkH70wb4OL-QmoekiYy4';
    private $regions = [];

    public function __construct()
    {
        $client = getClient();
        $service = new Google_Service_Sheets($client);

        $regionsData = $service->spreadsheets_values->get(self::$sheetId, 'regions!A4:B');
        foreach ($regionsData as $row) {
            $regionName = mb_strtolower($row[0]);
            $regionKey = mb_strtolower($row[1]);
            $this->regions[$regionKey] = [
                'name' => $regionName,
                'cities' => [],
            ];
        }
        $citiesData = $service->spreadsheets_values->get(self::$sheetId, 'cities!A2:B');
        foreach ($citiesData as list($cityName, $regionKey)) {
            $this->regions[$regionKey]['cities'][] = $cityName;
        }
    }

    public function getRegions()
    {
        return $this->regions;
    }

    public function getRegionNames()
    {
        $regionNames = [];
        foreach ($this->regions as $key => $row) {
            $regionNames[$key] = $row['name'];
        }
        return $regionNames;
    }

    public function findRegionAndCountFromText($row, $text, $i)
    {
        $count = null;
        $regionKey = null;
        $regionName = null;
        preg_match_all("'([\d]{+})(.*)'", $row, $matches);
        if (isset($matches[1][0], $matches[2][0])) {
            $count = (string)trim($matches[1][0]) ?: 1;
            $regionKey = trim($matches[2][0]);
        } else {
            preg_match_all("'(.*) \(([\d]+)\)'", $row, $matches);
            if (isset($matches[1][0], $matches[2][0])) {
                $count = (string)trim($matches[2][0]) ?: 1;
                $regionKey = trim($matches[1][0]);
            }
        }
        if (isset($this->regions[$regionKey])) {
            $regionName = $this->regions[$regionKey]['name'];
        }
        return [$regionName, $count];
    }


    /**
     * @param $row
     * @return array
     */
    public function findRegionAndCityFromText($row, $text, $i)
    {
        preg_match_all("/([\d]+)([--])([MK]) (.*)/u", $row, $matches);
        if (!isset($matches[4][0])) {
            if (isset($text[$i + 1])) {
                $row = $text[$i + 1];
                return $this->findRegionAndCityFromText($row, $text, $i + 1);
            }
            return [null, null];
        }
        $cityText = $matches[4][0];
        preg_match_all("/(.*?) \((.*?)\)/u", $cityText, $matches2);
        if (!empty($matches2[2][0])) {
            $cityName = $matches2[1][0];
            $regionKey = $matches2[2][0];
            $regionName = $this->regions[$regionKey]['name'];
            return [$regionName, $cityName];
        }
        foreach ($this->regions as $regionKey => $regionData) {
            foreach ($regionData['cities'] as $cityName) {
                if ($cityName === $cityText) {
                    $regionName = $regionData['name'];
                    return [$regionName, $cityName];
                }
            }
        }
        return [null, null];
    }


}

class DataParser
{
    const SENTENCE_CONFIRMED = 'pozytywnym wynikiem testów laboratoryjnych|potwierdzone (przypadki|przypadków) zakażenia koronawirusem|potwierdzonych przypadków zakażenia|potwierdzone przypadki zakażenia';
    const SENTENCE_DEADLY = 'Jednocześnie z przykrością informujemy|Z przykrością informujemy o śmierci|Z powodu COVID-19|Z powodu współistnienia COVID-19 z innymi schorzeniami zmarło|Z powodu współistnienia COVID-19 z innymi schorzeniami zmarły';
    const SENTENCE_TOTAL = 'W sumie liczba zakażonych koronawirusem|Liczba zakażonych koronawirusem';
    const SENTENCE_HOSPITALISED = 'liczba zajętych łóżek COVID-19';
    const SENTENCE_RESPORATORS = 'liczba zajętych respiratorów';
    const SENTENCE_QUARANTINE = 'liczba osób objętych kwarantanną';
    const SENTENCE_️EPIDEMIOLOGICAL_SURVEILLANCE = 'liczba osób objętych nadzorem sanitarno-epidemiologicznym';
    const SENTENCE_HEALED = 'liczba osób, które wyzdrowiały';

    private $hasError = false;
    /** @var string */
    private $streamUrl;
    /** @var DateTime */
    private $streamDateTime;
    /** @var string */
    private $streamText;
    /** @var array */
    private $response = [];
    /** @var array */
    private $dataGroups = [
        'cases_stats' => [
            'type' => 'auto',
            'fields' => [
                'confirmed' => [
                    'title' => '🤒confirmed',
                    'style' => 'danger',
                ],
                'healed' => [
                    'title' => '🥳healed',
                    'style' => 'success',
                ],
                'recovered' => [
                    'title' => '🤕recovered',
                    'style' => 'success',
                ],
                'deadly_covid' => [
                    'title' => '🦠deadly COVID-19',
                    'style' => 'warning',
                ],
                '️deadly_other' => [
                    'title' => '☠️️deadly other',
                    'style' => 'warning',
                ],
            ],
        ],
        'other_stats' => [
            'type' => 'mixed',
            'fields' => [
                'healed_other' => [
                    'title' => '🥳healed other total',
                    'type' => 'number',
                    'map' => [
                        'cases_stats-healed-total',
                        'cases_stats-healed-other',
                    ],
                    'calc' => [
                        'total' => 'raw!V2'
                    ],
                ],
            ],
        ],
        'prevention_stats' => [
            'type' => 'simple',
            'fields' => [
                'hospitalized' => [
                    'title' => '🏥hospitalized',
                    'type' => 'number',
                ],
                'ventilators' => [
                    'title' => '🤿number of ventilators used',
                    'type' => 'number',
                ],
                'quarantine_in_country' => [
                    'title' => '🏠quarantine in country',
                    'type' => 'number',
                ],
                'quarantine_after_returning_to_country' => [
                    'title' => '🏠quarantine after returning to country',
                    'type' => 'hidden',
                ],
                '️epidemiological_surveillance' => [
                    'title' => '☣️epidemiological surveillance',
                    'type' => 'number',
                ],
            ],
        ],
        'diagnostic_stats' => [
            'type' => 'regions',
            'fields' => [
                'diagnostics' => [
                    'title' => '🔬diagnostics',
                    'type' => 'number',
                ],
            ],
        ],
        'diagnostic_stats2' => [
            'type' => 'simple',
            'fields' => [
                'diagnosed_persons' => [
                    'title' => '🧐diagnosed persons',
                    'type' => 'number',
                ],
            ],
        ],
        'diagnostic_stats3' => [
            'type' => 'simple',
            'fields' => [
                'negative_test_results' => [
                    'title' => '🆗negative test results',
                    'type' => 'hidden',
                ],
                'positive_test_results' => [
                    'title' => '🛑positive test results',
                    'type' => 'number',
                ],
            ],
        ],
        'diagnostic_stats4' => [
            'type' => 'simple',
            'fields' => [
                'new_positive_test_results' => [
                    'title' => '📈new positive test results',
                    'type' => 'hidden',
                ],
                'doctors_orders' => [
                    'title' => '👨‍doctors orders',
                    'type' => 'number',
                ],
            ],
        ],
    ];

    private $address;

    public function __construct()
    {
        $this->address = new Address();

    }

    /**
     * @param $request
     */
    public function parseRequest($request = [])
    {
        if (isset($request['url'], $request['datetime'], $request['cases'])) {
            $this->streamUrl = $request['url'];
            $this->prepareResponseGroups($request);
            if (empty($request['datetime']) || empty($request['cases'])) {
                if (!empty($this->streamUrl)) {
                    $this->downloadTweetAsStream();
                }
            } else {
                if (!empty($request['datetime'])) {
                    $this->streamDateTime = new DateTime(implode(' ', $request['datetime']));
                }
                $this->cleanupStream($request['cases']);
            }
        }
    }

    private function prepareResponseGroups($request = [])
    {
        foreach ($this->dataGroups as $groupKey => $groupInfo) {
            if ('auto' === $groupInfo['type']) {
                foreach ($groupInfo['fields'] as $fieldKey => $fieldInfo) {
                    $this->response['group']['data'][$groupKey][$fieldKey]['total'] = 0;
                    foreach ($this->address->getRegionNames() as $regionName) {
                        $this->response['group']['data'][$groupKey][$fieldKey][$regionName] = 0;
                    }
                }
            } else if ('regions' === $groupInfo['type']) {
                foreach ($groupInfo['fields'] as $fieldKey => $fieldInfo) {
                    $this->response['group']['data'][$groupKey][$fieldKey]['total'] = $request[$fieldKey] ?: null;
                    foreach ($this->address->getRegionNames() as $regionName) {
                        $this->response['group']['data'][$groupKey][$fieldKey][$regionName] = 0;
                    }
                }
            } elseif ('mixed' === $groupInfo['type']) {
                foreach ($groupInfo['fields'] as $fieldKey => $fieldName) {
                    $this->response['group']['data'][$groupKey][$fieldKey] = $request[$fieldKey] ?: null;
                }
            } elseif ('simple' === $groupInfo['type']) {
                foreach ($groupInfo['fields'] as $fieldKey => $fieldName) {
                    $this->response['group']['data'][$groupKey][$fieldKey] = $request[$fieldKey] ?: null;
                }
            }
        }
    }

    /**
     * download tweet
     */
    private function downloadTweetAsStream()
    {
        if (preg_match("'https://twitter.com/([A-Za-z0-9_-]+)/status/([\d]+)(.*)'", $this->streamUrl)) {
            $username = preg_replace("'https://twitter.com/([A-Za-z0-9_-]+)/status/([\d]+)(.*)'", '$1', $this->streamUrl);
            $url = preg_replace("'https://twitter.com/([A-Za-z0-9_-]+)/status/([\d]+)(.*)'", 'https://mobile.twitter.com/$1/status/$2', $this->streamUrl);
            $html = file_get_contents($url);
            $dom = new DOMDocument;
            $dom->loadHTML($html);
            $finder = new DomXPath($dom);
            $metadata = $finder->query("//*[contains(@class, 'main-tweet')]//*[contains(@class, 'metadata')]");
            if ($metadata->count() > 0) {
                $datetimeString = $metadata->item(0)->textContent;
                $datetimeString = str_replace(['-'], '', $datetimeString);
                $this->streamDateTime = (new DateTime($datetimeString, new DateTimeZone('PDT')))->setTimezone(new DateTimeZone('CET'));
            }
            $mainTweet = $finder->query("//*[contains(@class, 'main-tweet')]//*[contains(@class, 'tweet-text')]");
            $replies = $finder->query("//*[contains(@class, 'timeline')]//*[contains(@class, 'tweet')][contains(@href, '$username')]//*[contains(@class, 'tweet-text')]");
            $stream = '';
            foreach ($mainTweet as $node) {
                $stream .= $node->textContent;
            }
            foreach ($replies as $node) {
                $stream .= $node->textContent;
            }
            $this->cleanupStream($stream);
        }
    }

    /**
     * @param $stream
     */
    private function cleanupStream($stream)
    {
        # parse lines
        $lines = explode("\n", $stream);
        $linesAmount = count($lines);
        for ($lineNo = 0; $lineNo < $linesAmount; $lineNo++) {
            $line = $lines[$lineNo];
            $line = trim($line);
            if (preg_match('/(Polubień|Polubienia|Podane dalej)/iu', $line)) {
                unset($lines[$lineNo]);
                unset($lines[$lineNo - 1]);
            } else if (empty($line) || preg_match('/·/u', $line) || strlen($line) < 10) {
                unset($lines[$lineNo]);
            } else {
                $lines[$lineNo] = $line;
            }
        }

        # convert lines to sentences
        $stream = implode(' ', $lines);
        # cleanup stream
        $stream = str_replace(['@MZ_GOV_PL', 'Ministerstwo Zdrowia', 'W odpowiedzi do',], '', $stream);
        $stream = str_replace(':', '.', $stream);
        $stream = preg_replace("'\([\d]/[\d]\) '", '', $stream);
        $this->streamText = $stream;
        $this->parseSentences();
    }

    /**
     *
     */
    private function parseSentences()
    {
        $cases_text = $this->streamText;
        $cases_text = str_replace(['mieszkaniec woj. ', 'mieszkanka woj. ', 'mieszkaniec ', 'mieszkanka ', 'woj.', 'województwa'], '', $cases_text);
        $cases_text = str_replace([
            'Maków Maz.',
            'Wysokie Maz.',
            'Tomaszów Lub.',
            'Gorzów Wlk.',
        ], [
            'Maków Mazowiecki',
            'Wysokie Mazowieckie',
            'Tomaszów Lubelski',
            'Gorzów Wielkopolski',
        ], $cases_text);
        $cases_text = str_replace(';', '.', $cases_text);
        $sentences = explode('.', $cases_text);
        $sentencesAmount = count($sentences);
        for ($sentenceNo = 0; $sentenceNo < $sentencesAmount; $sentenceNo++) {
            $sentence = trim($sentences[$sentenceNo]);
            if ($this->isSentenceOfConfirmed($sentence) || $this->isSentenceOfDeadly($sentence) || $this->isSentenceOfTotal($sentence)) {
                if ($this->isSentenceOfConfirmed($sentence)) {
                    if (!preg_match("':'", $sentence)) {
                        $sentenceNo++;
                        $sentence = $sentences[$sentenceNo];
                    }
                    $sentence = $this->extractConfirmed($sentence);
                }
                if ($this->isSentenceOfDeadly($sentence)) {
                    if (!preg_match("':'", $sentence)) {
                        $sentenceNo++;
                        $sentence = $sentences[$sentenceNo];
                    }
                    $sentence = $this->extractDeadly($sentence);
                }
            } elseif ($this->isSentenceOfStats($sentence)) {
                $sentence = $this->extractStats($sentence);
            } else {
                $sentence = null;
            }
            if (empty($sentence)) {
                unset($sentences[$sentenceNo]);
            } else {
                $sentences[$sentenceNo] = $sentence;
            }
        }
        $this->response['sentences'] = $sentences;
        $this->buildRawResponse();
    }

    public function isSentenceOfConfirmed($sentence)
    {
        return preg_match('/(' . self::SENTENCE_CONFIRMED . ')/', $sentence);
    }

    public function isSentenceOfDeadly($sentence)
    {
        return preg_match('/(' . self::SENTENCE_DEADLY . ')/u', $sentence);
    }

    public function isSentenceOfTotal($sentence)
    {
        return preg_match('/(' . self::SENTENCE_TOTAL . ')/u', $sentence);
    }

    /**
     * @param $sentence
     * @return string
     */
    private function extractConfirmed($sentence)
    {
        $sentence = substr_replace($sentence, '', 0, strpos($sentence, ':'));
        $sentence = str_replace([' oraz ', ' i '], ',', $sentence);
        $sentence = str_replace(['woj.', 'osób ', 'osoby ', 'osobie ', 'po ', 'jednej', ' z ', '.', ':',], '', $sentence);
        $text = explode(',', $sentence);
        if (!empty($sentence) && !empty($text)) {
            foreach ($text as $i => $row) {
                $row = trim($row);
                list($regionName, $count) = $this->address->findRegionAndCountFromText($row, $text, $i);
                if (isset($this->response['group']['data']['cases_stats']['confirmed'][$regionName])) {
                    $text[$i] = $count . ' (' . $regionName . ')';
                    $this->response['group']['data']['cases_stats']['confirmed']['total'] += $count;
                    $this->response['group']['data']['cases_stats']['confirmed'][$regionName] = $count;
                } else {
                    $text[$i] = '<span class="blink">' . $row . ' (!!!)</span>';
                    $this->hasError = true;
                }
            }
        }
        return implode(', ', $text);
    }

    /**
     * @param $sentence
     * @return string
     */
    private function extractDeadly($sentence)
    {
        $sentence = substr_replace($sentence, '', 0, strpos($sentence, ':'));
        $sentence = str_replace([' oraz ', ' i ', 'która miała choroby współistniejące'], ',', $sentence);
        $sentence = str_replace(['osób ', 'osoby ', 'osobie ', 'po ', 'jednej', ' z ', '.', ':', 'mieszkaniec woj. ', 'mieszkanka woj. ', 'woj.',], '', $sentence);
        $sentence = preg_replace('!\s+!', ' ', $sentence);
        $text = explode(',', $sentence);
//        if (!empty($sentence) && !empty($text)) {
//            foreach ($text as $i => $row) {
//                $row = trim($row);
//                if (empty($row)) {
//                    unset($text[$i]);
//                    continue;
//                }
//                list($regionName, $cityName) = $this->address->findRegionAndCityFromText($row, $text, $i);
//                if (isset($this->response['group']['data']['cases_stats']['deadly_covid'][$regionName])) {
//                    $text[$i] = str_replace($cityName, $cityName . ' (' . $regionName . ')', $row);
//                    $this->response['group']['data']['cases_stats']['deadly_covid']['total']++;
//                    $this->response['group']['data']['cases_stats']['deadly_covid'][$regionName]++;
//                } else {
//                    $text[$i] = '<span class="blink">' . $row . ' (!!!)</span>';
//                    $this->hasError = true;
//                }
//            }
//        }
        return implode(', ', $text);
    }

    public function isSentenceOfStats($sentence)
    {
        return preg_match('/(' . implode('|', [
                self::SENTENCE_HOSPITALISED,
                self::SENTENCE_RESPORATORS,
                self::SENTENCE_QUARANTINE,
                self::SENTENCE_️EPIDEMIOLOGICAL_SURVEILLANCE,
                self::SENTENCE_HEALED,
            ]) . ')/u', $sentence);
    }

    private function extractStats($sentence)
    {
        $value = $sentence;
        $value = str_replace([
            self::SENTENCE_HOSPITALISED,
            self::SENTENCE_RESPORATORS,
            self::SENTENCE_QUARANTINE,
            self::SENTENCE_️EPIDEMIOLOGICAL_SURVEILLANCE,
            self::SENTENCE_HEALED,
        ], '', $value);
        $value = str_replace([' ', '-',], '', $value);
        if (preg_match('/' . self::SENTENCE_HOSPITALISED . '/u', $sentence)) {
            $this->response['group']['data']['prevention_stats']['hospitalized'] = $value;
        }
        if (preg_match('/' . self::SENTENCE_QUARANTINE . '/u', $sentence)) {
            $this->response['group']['data']['prevention_stats']['quarantine_in_country'] = $value;
        }
        if (preg_match('/' . self::SENTENCE_️EPIDEMIOLOGICAL_SURVEILLANCE . '/u', $sentence)) {
            $this->response['group']['data']['prevention_stats']['️epidemiological_surveillance'] = $value;
        }
        if (preg_match('/' . self::SENTENCE_HEALED . '/u', $sentence)) {
            $this->response['group']['data']['other_stats']['healed_other'] = $value;
        }
        return $sentence;
    }

    /**
     *
     */
    private function buildRawResponse()
    {
        if ($this->hasError) {
            return false;
        }
        $client = getClient();
        $service = new Google_Service_Sheets($client);
        # grab textarea data
        $textarea = [];
        $textarea['datetime'] = $this->getStreamDateTime();
        $textarea['url'] = $this->getStreamUrl();
        $textarea['text'] = $this->getStreamText();
        foreach ($this->dataGroups as $groupKey => $groupInfo) {
            if ('auto' === $groupInfo['type']) {
                foreach ($groupInfo['fields'] as $fieldKey => $fieldInfo) {
                    foreach ($this->response['group']['data'][$groupKey][$fieldKey] as $regionName => $count) {
                        $textarea[$groupKey . '-' . $fieldKey . '-' . $regionName] = $count ? (int)$count : '';
                    }
                }
            } elseif ('regions' === $groupInfo['type']) {
                foreach ($groupInfo['fields'] as $fieldKey => $fieldInfo) {
                    foreach ($this->response['group']['data'][$groupKey][$fieldKey] as $regionName => $count) {
                        $textarea[$groupKey . '-' . $fieldKey . '-' . $regionName] = $count ? (int)$count : '';
                    }
                }
            } elseif ('simple' === $groupInfo['type']) {
                foreach ($groupInfo['fields'] as $fieldKey => $fieldName) {
                    $textarea[$groupKey . '-' . $fieldKey] = $this->response['group']['data'][$groupKey][$fieldKey] ? (int)$this->response['group']['data'][$groupKey][$fieldKey] : '';
                }
            }
        }
        foreach ($this->dataGroups as $groupKey => $groupInfo) {
            if ('mixed' === $groupInfo['type']) {
                foreach ($groupInfo['fields'] as $fieldKey => $fieldInfo) {
                    $value = $this->response['group']['data'][$groupKey][$fieldKey] ? (int)$this->response['group']['data'][$groupKey][$fieldKey] : '';
                    if (!empty($value) && isset($fieldInfo['calc']['total'])) {
                        $sheetData = $service->spreadsheets_values->get(Address::$sheetId, $fieldInfo['calc']['total']);
                        $total = preg_replace('/[\D]/', '', $sheetData['values'][0][0]);
                        $value -= $total;
                    }
                    foreach ($fieldInfo['map'] as $map) {
                        $textarea[$map] = $value;
                    }
                }
            }
        }
        $this->response['textarea'] = $textarea;
    }

    public function getStreamDateTime()
    {
        return $this->streamDateTime ? $this->streamDateTime->format('Y-m-d H:i:s') : null;
    }

    public function getStreamUrl()
    {
        return $this->streamUrl;
    }

    public function getStreamText()
    {
        return trim($this->streamText);
    }

    public function getStreamDate()
    {
        return $this->streamDateTime ? $this->streamDateTime->format('Y-m-d') : null;
    }

    public function getStreamTime()
    {
        return $this->streamDateTime ? $this->streamDateTime->format('H:i') : null;
    }

    public function getGroups()
    {
        return $this->dataGroups;
    }

    public function getGroupFields($groupName)
    {
        if (!isset($this->dataGroups[$groupName])) {
            throw new Exception('Config group fields not found!');
        }
        return $this->dataGroups[$groupName]['fields'];
    }

    public function hasResponse()
    {
        return empty($this->response) ? false : true;
    }

    public function hasSentences()
    {
        return empty($this->response['sentences']) ? false : true;
    }

    public function getSentences()
    {
        return isset($this->response['sentences']) ? $this->response['sentences'] : null;
    }

    public function hasGroupsData()
    {
        return empty($this->response['group']['data']) ? false : true;
    }

    public function getResponseField($groupKey, $fieldKey)
    {
        if (!isset($this->response['group']['data'][$groupKey][$fieldKey])) {
            throw new Exception('Response group field not found!');
        }
        return $this->response['group']['data'][$groupKey][$fieldKey];
    }

    public function hasRawData()
    {
        return empty($this->response['textarea']) ? false : true;
    }

    public function getRawData()
    {
        return isset($this->response['textarea']) ? implode("\t", $this->response['textarea']) : null;
    }

    public function saveToGoogle($sheetName = 'raw')
    {
        if (!isset($_REQUEST['save']) || empty($this->response['textarea']) || $this->hasError) {
            return null;
        }
        $client = getClient();
        $service = new Google_Service_Sheets($client);
        $sheetData = $service->spreadsheets_values->get(Address::$sheetId, $sheetName . '!A:ZZ');
        $rowId = 0;
        foreach ($sheetData as $rowId => $rowData) {
            if (empty($rowData)) {
                break;
            }
        }
        # SAVE OUTPUT
        $body = new Google_Service_Sheets_ValueRange([
            'values' => [
                array_values($this->response['textarea'])
            ]
        ]);
        $params = [
            'valueInputOption' => 'USER_ENTERED',
        ];
        $result = $service->spreadsheets_values->append(Address::$sheetId, $sheetName . '!A' . ++$rowId . ':ZZ', $body, $params);
        return $result->getUpdates()->getUpdatedRange();
    }
}

$parser = new DataParser();
$parser->parseRequest($_REQUEST);

?>
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <meta name="robots" content="noindex">
        <title>info</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.4.1/css/bootstrap.min.css" integrity="sha256-L/W5Wfqfa0sdBNIKN9cG6QA5F2qx4qICmU2VgLruv9Y=" crossorigin="anonymous"/>
        <style type="text/css">
            @-webkit-keyframes blinker {
                from {
                    opacity: 1.0;
                }
                to {
                    opacity: 0.0;
                }
            }

            .blink {
                text-decoration: blink;
                -webkit-animation-name: blinker;
                -webkit-animation-duration: 0.6s;
                -webkit-animation-iteration-count: infinite;
                -webkit-animation-timing-function: ease-in-out;
                -webkit-animation-direction: alternate;
            }
        </style>
    </head>
    <body>
        <form method="post" autocomplete="off">
            <section class="container-fluid">
                <h1>Info</h1>
                <div class="card border-primary mb-4">
                    <div class="card-body">
                        <div class="input-group mb-2">
                            <div class="input-group-prepend">
                                <span class="input-group-text">🌍</span>
                            </div>
                            <input type="url" name="url" placeholder="post url address" class="form-control" value="<?= $parser->getStreamUrl() ?>">
                        </div>
                        <div class="input-group mb-2">
                            <div class="input-group-prepend">
                                <span class="input-group-text">📅</span>
                            </div>
                            <input type="date" name="datetime[date]" placeholder="post date" class="form-control" value="<?= $parser->getStreamDate() ?>">
                            <div class="input-group-prepend">
                                <span class="input-group-text">⏰</span>
                            </div>
                            <input type="time" name="datetime[time]" placeholder="post time" class="form-control" value="<?= $parser->getStreamTime() ?>">
                        </div>
                        <div class="input-group mb-2">
                            <div class="input-group-prepend">
                                <span class="input-group-text">ℹ️</span>
                            </div>
                            <textarea name="cases" placeholder="cases info" class="form-control" style="resize:none;" rows="10"><?= $parser->getStreamText() ?></textarea>
                        </div>
                        <?php foreach ($parser->getGroups() as $groupKey => $groupInfo) { ?>
                            <?php if ('auto' === $groupInfo['type']) {
                                continue;
                            } ?>
                            <div class="input-group mb-2">
                                <?php foreach ($groupInfo['fields'] as $fieldKey => $fieldInfo) { ?>
                                    <?php if ('hidden' !== $fieldInfo['type']) { ?>
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><?= mb_substr($fieldInfo['title'], 0, 1) ?>️</span>
                                        </div>
                                    <?php } ?>
                                    <input type="<?php echo $fieldInfo['type']; ?>" min="0" name="<?= $fieldKey ?>" placeholder="<?= mb_substr($fieldInfo['title'], 1) ?>" class="form-control" value="<?= @$_REQUEST[$fieldKey] ?>">
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                    <div class="card-footer">
                        <input type="submit" class="btn btn-primary" value="📩 Submit form">
                    </div>
                </div>
                <?php if ($parser->hasResponse()) { ?>
                    <?php if ($saved = $parser->saveToGoogle()) { ?>
                        <div class="card border-success mb-4">
                            <div class="card-body">
                                Data saved to <?php echo $saved; ?>
                            </div>
                            <div class="card-footer">
                                <a href="parser.php" class="btn btn-warning">🗑 Reset form</a>
                            </div>
                        </div>
                    <?php } elseif ($parser->hasSentences() || $parser->hasGroupsData() || $parser->hasRawData()) { ?>
                        <div class="card border-secondary mb-4">
                            <?php if ($parser->getSentences()) { ?>
                                <h5 class="card-header">
                                    Formatted data
                                </h5>
                                <ul class="list-group list-group-flush">
                                    <?php $extraClass = null; ?>
                                    <?php foreach ($parser->getSentences() as $sentence) { ?>
                                        <?php
                                        if ($parser->isSentenceOfConfirmed($sentence)) {
                                            $extraClass = 'text-danger';
                                        }
                                        if ($parser->isSentenceOfDeadly($sentence)) {
                                            $extraClass = 'text-warning';
                                        }
                                        if ($parser->isSentenceOfTotal($sentence)) {
                                            $extraClass = 'text-info';
                                        }
                                        ?>
                                        <li class="list-group-item <?= $extraClass ?>">
                                            <?= $sentence ?>
                                        </li>
                                    <?php } ?>
                                </ul>
                            <?php } ?>
                            <?php if ($parser->hasGroupsData()) { ?>
                                <h5 class="card-header">
                                    Detailed data
                                </h5>
                                <div class="card-body">
                                    <div class="card-deck">
                                        <?php foreach ($parser->getGroupFields('cases_stats') as $fieldKey => $fieldInfo) { ?>
                                            <?php $fieldData = $parser->getResponseField('cases_stats', $fieldKey); ?>
                                            <?php if (empty($fieldData['total'])) {
                                                continue;
                                            } ?>
                                            <div class="card border-<?= $fieldInfo['style'] ?>">
                                                <h5 class="card-header text-<?= $fieldInfo['style'] ?>">
                                                    <?= $fieldInfo['title'] ?>
                                                    <span class="badge badge-primary badge-pill"><?= $fieldData['total'] ?></span>
                                                </h5>
                                                <div class="card-body">
                                                    <ul>
                                                        <?php foreach ($fieldData as $regionName => $count) {
                                                            if ('total' !== $regionName) { ?>
                                                                <li>
                                                                    <?= $regionName ?>
                                                                    <span class="badge badge-info badge-pill"><?= ($count ?: '') ?></span>
                                                                </li>
                                                            <?php }
                                                        } ?>
                                                    </ul>
                                                </div>
                                            </div>
                                        <?php } ?>
                                    </div>
                                </div>
                            <?php } ?>
                            <?php if ($parser->hasRawData()) { ?>
                                <h5 class="card-header">
                                    Raw data
                                </h5>
                                <div class="card-body">
                                    <textarea id="raw_data" readonly class="form-control" style="resize:none;" rows="4"><?= $parser->getRawData() ?></textarea>
                                </div>
                                <div class="card-footer">
                                    <!--                                    <input type="button" class="btn btn-secondary" id="copy" value="⧉ Copy raw data">-->
                                    <!--                                    &nbsp;-->
                                    <input type="submit" name="save" class="btn btn-primary" value="💾 Save raw data">
                                    <script type="text/javascript">
                                        document.getElementById("copy").onclick = function () {
                                            document.getElementById("raw_data").select();
                                            document.execCommand('copy');
                                        }
                                    </script>
                                </div>
                            <?php } ?>
                        </div>
                    <?php } ?>
                <?php } ?>
            </section>
        </form>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.slim.min.js" integrity="sha256-pasqAKBDmFT4eHoN2ndd6lN370kFiGUFyTiUHWhU7k8=" crossorigin="anonymous"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.4.1/js/bootstrap.min.js" integrity="sha256-WqU1JavFxSAMcLP2WIOI+GB2zWmShMI82mTpLDcqFUg=" crossorigin="anonymous"></script>
    </body>
</html>
