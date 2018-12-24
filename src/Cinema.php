<?php

namespace tjdsneto\JCNetCrawler;

use Closure;
use DOMElement;
use Goutte\Client;

class Cinema
{
    protected $knownAttrs = [
        [
            'slug' => 'genre',
            'label' => 'Gênero:',
        ],
        [
            'slug' => 'pg_rate',
            'label' => 'Classificação:',
        ],
        [
            'slug' => 'director',
            'label' => 'Direção:',
        ],
        [
            'slug' => 'cast',
            'label' => 'Elenco:',
        ],
        [
            'slug' => 'duration',
            'label' => 'Duração:',
        ],
    ];

    public function getMovies(): array
    {
        $client = new Client();
        $crawler = $client->request('GET', 'https://www.jcnet.com.br/cinema/');

        $selector = 'body > table > tr:nth-child(1) > td:nth-child(2) > table > tr:nth-child(2) > td > table';

        $movies = $crawler->filter($selector)->each(Closure::fromCallable([$this, 'parseMovie']));

        return $movies;
    }

    private function clearText(string $txt): string
    {
        $txt = str_replace("\\u00a0", "", $txt);
        $txt = str_replace(chr(194) . chr(160), "", $txt);
        return trim($txt);
    }

    public function parseMovie($movieNode)
    {
        $attrs = [];
        $schedule = [];

        $contentNode = $movieNode->filter('tr:nth-child(2) > td:nth-child(2)');

        foreach ($contentNode->filter('p') as $nodeKey => $infoNode) {
            switch (true) {
                case ($nodeKey === 0):
                    $attrs = $this->extractAttributes($infoNode);
                    break;
                case ($nodeKey > 1):
                    $schedule[] = $this->extractScheduleInfo($infoNode);
                    break;
            }
        }

        $parsedSchedule = $this->parseRawSchedule($schedule);

        return [
            'title' => $movieNode->filter('tr:nth-child(1) > td:nth-child(1)')->text(),
            'description' => trim($contentNode->getNode(0)->childNodes[0]->textContent),
            'imageURL' => sprintf(
                'https://www.jcnet.com.br/%s',
                $movieNode->filter('tr:nth-child(2) > td:nth-child(1) > img')->attr('src')
            ),
            'trailerLink' => $movieNode->filter('tr:nth-child(2) > td:nth-child(1) > a')->attr('href'),
            'genre' => $attrs['genre'] ?? 'not set',
            'duration' => $attrs['duration'] ?? 'not set',
            'cast' => $attrs['cast'] ?? 'not set',
            'director' => $attrs['director'] ?? 'not set',
            'pg_rate' => $attrs['pg_rate'] ?? 'not set',
            'raw_schedule' => $schedule,
            'parsed_schedule' => $this->formatSchedule($parsedSchedule),
        ];
    }

    public function extractAttributes(DOMElement $attrsNode): array
    {
        $attrs = [];
        $attrNodes = $attrsNode->childNodes;
        foreach ($attrNodes as $key => $attrNode) {
            $label = $this->clearText($attrNode->textContent);

            $knownAttr = collect($this->knownAttrs)->filter(function ($knownAttr) use ($label) {
                return $knownAttr['label'] === $label;
            })->first();

            if ($knownAttr) {
                $attrs[$knownAttr['slug']] = $attrNodes[$key + 1]->textContent;
            }
        }
        return $attrs;
    }

    public function extractScheduleInfo(DOMElement $schNode): array
    {
        $movieTheather = $schNode->childNodes[0]->textContent;

        $schedule = [];

        /**
         *   EXEMPLO:
         * 
         *   Dublado
         *   Sala 2: 13h; e 19h (exceto segunda-feira)

         *   Legendado
         *   Sala 2: 16h (exceto segunda, 24-12) e 21h45 (exceto segunda,
         *   24-12)
         * 
         *  <p>
         *      <strong class="fontAzul">Cinépolis</strong>
                <br>
                <strong>Dublado</strong>
                <br>
                Sala 2: 13h; e 19h (exceto segunda-feira)
                <br>
                <br>
                <strong>Legendado<br></strong>
                Sala 2: 16h (exceto segunda, 24-12) e 21h45 (exceto segunda,
                <br>
                24-12)
                <br>
                <br> 
                <a href="javascript:scrollToAnchor('cinepolis')" class="font12">Valores do Cinépolis</a>
            </p>
         */

        foreach ($schNode->childNodes as $childNode) {
            $content = $this->clearText($childNode->textContent);

            if (empty($content)) {
                continue;
            }

            if (in_array(strtolower($content), ['legendado', 'dublado'])) {
                $schedule[] = [
                    'audio_subs' => $content,
                    '3d' => false,
                    'schedule' => [],
                ];
            }

            if (strpos($content, 'Sala') === 0) {
                if (empty($schedule)) {
                    $schedule[] = [
                        'audio_subs' => 'Voz original',
                        '3d' => false,
                        'schedule' => [],
                    ];
                }
                $lastKey = count($schedule) - 1;
                $schedule[$lastKey]['schedule'][] = $content;
                $schedule[$lastKey]['3d'] = strpos($content, '3D') !== false;
            }
        }

        return [
            'movie_theather' => $movieTheather,
            'schedule' => array_values(array_filter($schedule)),
        ];
    }

    private function parseRawSchedule(array $rawSchedule): array
    {
        return collect($rawSchedule)->map(function ($theaterInfo) {
            return [
                'theather' => $theaterInfo['movie_theather'],
                'schedule' => collect($theaterInfo['schedule'])->map(function ($schedule) {
                    return [
                        'audio_subs' => $schedule['audio_subs'],
                        '3d' => $schedule['3d'],
                        'schedule' => collect($schedule['schedule'])
                            ->map(Closure::fromCallable([$this, 'parseRawScheduleEntry']), $this)
                            ->flatten(1),
                    ];
                }, $this),
            ];
        })->toArray();
    }

    public function parseRawScheduleEntry(string $schedule): array
    {
        /**
         * Examples:
         *
         * Sala 5: 21h15 (quinta, sexta, sábado e domingo)
         * Sala 1: 13h10 (quinta a segunda, quarta); 14h50 (terça)
         * Sala 4: 14h30 – 16h40 terça, 25-12; 13h05 – 15h10 – 17h15 quarta
         * Sala 1: 13h, 15h, 17h e 19h (quinta, sexta, sábado e domingo); 14h e 16h (segunda, 24-12)
         * Sala 4: 12h30, 14h45, 17h15 (exceto segunda, 24-12), 19h45 (exceto segunda, 24-12) e 22h (exceto segunda, 24-12)
         * Sala 4: 13h30 – 16h15 – 19h – 22h quinta a domingo; 13h20 e 16h segunda; 22h50 – 23h25 terça e quarta
         * Sala 2: 13h – 15h30 – 18h00 – 20:30 quinta a domingo, quarta; 13h – 15h30 segunda; 15h30 – 18h – 20h30 terça
         *
         */

        $scheduleTimes = [];
        $matchSchedule = [];

        // Remove dates from schedule
        $schedule = preg_replace('/\d{1,2}-\d{1,2}?/U', '', $schedule);

        preg_match_all('/\d{1,2}(h\d{0,2}|:\d{2})/', $schedule, $scheduleTimes);
        preg_match_all('/([\dh -–:]*) \(?([\sa-zçá,-]*?)(;|\z|\)|$)/U', $schedule, $matchSchedule);

        $allScheduleTimes = $scheduleTimes[0];

        $groupedSchedule = [];
        if (!empty($matchSchedule)) {
            $groupedSchedule = collect($matchSchedule[0])->map(function ($match, $key) use ($matchSchedule) {
                $scheduleTimes = [];
                preg_match_all('/\d{1,2}(h\d{0,2}|:\d{2})/', $matchSchedule[1][$key], $scheduleTimes);
                return [
                    'weekdays' => $this->parseWeekDays($matchSchedule[2][$key]),
                    'schedule' => $scheduleTimes[0],
                ];
            }, $this)->toArray();
        }

        $dailyGroup = [];
        foreach ($allScheduleTimes as $scheduleTime) {
            foreach ($groupedSchedule as $group) {
                if (in_array($scheduleTime, $group['schedule'])) {
                    continue 2;
                }
            }
            $dailyGroup[] = $scheduleTime;
        }

        if (!empty($dailyGroup)) {
            $groupedSchedule[] = [
                'weekdays' => $this->parseWeekDays(),
                'schedule' => $dailyGroup,
            ];
        }
        return $groupedSchedule;
    }

    public function parseWeekDays(string $schedule = ''): array
    {
        $weekDays = [
            ['domingo'],
            ['segunda', 'segunda-feira', 'segundafeira'],
            ['terça', 'terça-feira', 'terçafeira'],
            ['quarta', 'quarta-feira', 'quartafeira'],
            ['quinta', 'quinta-feira', 'quintafeira'],
            ['sexta', 'sexta-feira', 'sextafeira'],
            ['sábado'],
        ];

        $allWeekDays = array_keys($weekDays);
        $scheduleWeekDays = [];

        // Se não especifíca os dias da semana é porque deve ser para todos os dias
        if (empty($schedule)) {
            return $allWeekDays;
        }

        if (preg_match('/exceto ([a-zçá-]*)/', $schedule, $exceptMatch)) {
            $weekDaysExceptions = $this->parseWeekDays($exceptMatch[1]);
            
            $schedule = trim(preg_replace('/exceto (.*)/', '', $schedule), ' ()');
            $scheduleWeekDays = $this->parseWeekDays($schedule);

            if (!empty($weekDaysExceptions)) {
                $scheduleWeekDays = array_diff($scheduleWeekDays, $weekDaysExceptions);
                return array_values(array_unique($scheduleWeekDays));
            }
        }
        
        $schedule = trim($schedule, ' ()');
        $schedule = array_filter(preg_split('/( e )|(,)/', $schedule));

        foreach ($schedule as $scheduleTime) {
            $scheduleTime = trim($scheduleTime);
            if (preg_match('/\(?([a-zçá-]*) a ([a-zçá-]*)\)?/', $scheduleTime, $rangeMatch)) {
                $rangeStart = trim($rangeMatch[1], ' ()');
                $rangeEnd = trim($rangeMatch[2], ' ()');
                $key = 0;
                $loop = 0;
                while ($loop < 9) {
                    $weekDayDays = $weekDays[$allWeekDays[$key]];
                    if (in_array($rangeStart, $weekDayDays)) {
                        $scheduleWeekDays[] = $allWeekDays[$key];
                    } elseif (!empty($scheduleWeekDays) && in_array($rangeEnd, $weekDayDays)) {
                        $scheduleWeekDays[] = $allWeekDays[$key];
                        break;
                    } elseif (!empty($scheduleWeekDays)) {
                        $scheduleWeekDays[] = $allWeekDays[$key];
                    }
                    $key++;
    
                    if ($key >= count($allWeekDays)) {
                        $key = 0;
                    }
                    $loop++;
                }
                continue;
            }
            foreach ($weekDays as $weekKey => $weekDayDays) {
                if (in_array($scheduleTime, $weekDayDays)) {
                    $scheduleWeekDays[] = $weekKey;
                }
            }
        }

        $scheduleWeekDays = array_values(array_unique($scheduleWeekDays));
        sort($scheduleWeekDays);

        return $scheduleWeekDays;
    }

    private function formatSchedule(array $parsedSchedule): array
    {
        return collect($parsedSchedule)->map(function ($schedule) {
            $theather = $schedule['theather'];
            return collect($schedule['schedule'])->map(function ($schedule) use ($theather) {
                $audioSubs = $schedule['audio_subs'];
                $is3D = $schedule['3d'];
                return collect($schedule['schedule'])->map(function ($schedule) use ($theather, $audioSubs, $is3D) {
                    $weekDays = $schedule['weekdays'];
                    $scheduleTimes = $schedule['schedule'];
                    $scheduleEntries = [];
                    foreach ($weekDays as $weekDay) {
                        foreach ($scheduleTimes as $scheduleTime) {
                            $scheduleEntries[] = [
                                'theather' => $theather,
                                'audio_subs' => $audioSubs,
                                '3d' => $is3D,
                                'time' => $scheduleTime,
                                'week_day' => $weekDay,
                            ];
                        }
                    }
                    return $scheduleEntries;
                })->flatten(1);
            })->flatten(1);
        })->flatten(1)->sortBy(function ($scheduleEntry) {
            return $scheduleEntry['week_day'] . '_' . $scheduleEntry['time'];
        })->values()->toArray();
    }
}
