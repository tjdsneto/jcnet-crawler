<?php

namespace tjdsneto\JCNetCrawler;

use Goutte\Client;

class Cinema
{
    public function getMovies() : array
    {
        $movies = [];

        $knownAttrs = collect([
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
        ]);

        function clearText($txt)
        {
            $txt = str_replace("\\u00a0", "", $txt);
            $txt = str_replace(chr(194) . chr(160), "", $txt);
            return trim($txt);
        }

        $client = new Client();

        $crawler = $client->request('GET', 'https://www.jcnet.com.br/cinema/');

        $selector = 'body > table > tr:nth-child(1) > td:nth-child(2) > table > tr:nth-child(2) > td > table';

        $crawler->filter($selector)->each(function ($node) use (&$movies, $knownAttrs) {
            $info = $node->filter('tr:nth-child(2) > td:nth-child(2)');

            $extraInfo = [];

            $generalInfoNodes = $info->filter('p')->getNode(0)->childNodes;
            foreach ($generalInfoNodes as $key => $generalInfoNode) {
                $label = clearText($generalInfoNode->textContent);

                $knownInfo = $knownAttrs->filter(function ($info) use ($label) {
                    return $info['label'] === $label;
                })->first();

                if ($knownInfo) {
                    $extraInfo[$knownInfo['slug']] = $generalInfoNodes[$key + 1]->textContent;
                }
            }

            $rawSchedules = [];
            $info->filter('p')->each(function ($node, $i) use (&$rawSchedules) {
                if ($i > 1) {
                    $rawSchedules[] = $this->getRawSchedule($node->getNode(0));
                }
            });

            $parsedSchedule = $this->parseRawSchedule($rawSchedules);

            $movies[] = [
                'title' => $node->filter('tr:nth-child(1) > td:nth-child(1)')->text(),
                'description' => trim($info->getNode(0)->childNodes[0]->textContent),
                'imageURL' => sprintf(
                    'https://www.jcnet.com.br/%s',
                    $node->filter('tr:nth-child(2) > td:nth-child(1) > img')->attr('src')
                ),
                'trailerLink' => $node->filter('tr:nth-child(2) > td:nth-child(1) > a')->attr('href'),
                'genre' => $extraInfo['genre'] ?? 'not set',
                'duration' => $extraInfo['duration'] ?? 'not set',
                'cast' => $extraInfo['cast'] ?? 'not set',
                'director' => $extraInfo['director'] ?? 'not set',
                'pg_rate' => $extraInfo['pg_rate'] ?? 'not set',
                'week_number' => 1,
                'raw_schedule' => $rawSchedules,
                'raw_schedule_2' => $parsedSchedule,
                'parsed_schedule' => $this->formatSchedule($parsedSchedule),
            ];
        });

        return $movies;
    }

    private function getRawSchedule($schNode) : array
    {
        $movieTheather = $schNode->childNodes[0]->textContent;

        $schedule = [];
        foreach ($schNode->childNodes as $childNode) {
            $content = trim($childNode->textContent);
            if (empty($childNode->textContent)) {
                continue;
            }
            if (in_array(strtolower($content), ['legendado', 'dublado'])) {
                $schedule[] = [
                    'audio_subs' => $content,
                    '3d' => false,
                    'schedule' => [],
                ];
            }
            if (starts_with($content, 'Sala')) {
                if (empty($schedule)) {
                    $schedule[] = [
                        'audio_subs' => 'Voz original',
                        '3d' => false,
                        'schedule' => [],
                    ];
                }
                $lastKey = count($schedule) - 1;
                $schedule[$lastKey]['schedule'][] = $content;
                $schedule[$lastKey]['3d'] = str_contains($content, '3D');
            }
        }

        return [
            'movie_theather' => $movieTheather,
            'schedule' => $schedule,
        ];
    }

    private function parseWeekDays($schedule = '') : array
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

        $negative = false;
        if (str_contains($schedule, 'exceto') || empty($schedule)) {
            $negative = true;
        }

        $scheduleWeekDays = [];
        $allWeekDays = array_keys($weekDays);

        if (preg_match('/\((.*) a (.*)\)/U', $schedule, $rangeMatch)) {
            $rangeStart = $rangeMatch[1];
            $rangeEnd = $rangeMatch[2];
            $key = 0;
            while (1) {
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
            }
            return array_values($scheduleWeekDays);
        }

        $schedule = trim($schedule, '()');
        $schedule = array_filter(preg_split('/( e )|(\s)|(,)/', $schedule));

        foreach ($schedule as $scheduleTime) {
            foreach ($weekDays as $weekKey => $weekDayDays) {
                if (in_array($scheduleTime, $weekDayDays)) {
                    $scheduleWeekDays[] = $weekKey;
                }
            }
        }

        if ($negative) {
            $scheduleWeekDays = array_diff($allWeekDays, $scheduleWeekDays);
        }

        return array_values($scheduleWeekDays);
    }

    private function parseRawSchedule($rawSchedule) : array
    {
        return collect($rawSchedule)->map(function ($theaterInfo) {
            return [
                'theather' => $theaterInfo['movie_theather'],
                'schedule' => collect($theaterInfo['schedule'])->map(function ($schedule) {
                    return [
                        'audio_subs' => $schedule['audio_subs'],
                        '3d' => $schedule['3d'],
                        'schedule' => collect($schedule['schedule'])->map(function ($schedule) {
                            $scheduleTimes = [];
                            $matchSchedule = [];
                            preg_match_all('/\d{1,2}h\d{0,2}/', $schedule, $scheduleTimes);
                            preg_match_all('/(.*) (\(.*\))/U', $schedule, $matchSchedule);
                            
                            $scheduleTimes = $scheduleTimes[0];
                            
                            $groupedSchedule = [];
                            if (!empty($matchSchedule)) {
                                $groupedSchedule = collect($matchSchedule[0])->map(function ($match, $key) use ($matchSchedule) {
                                    $scheduleTimes = [];
                                    preg_match_all('/\d{1,2}h\d{0,2}/', $matchSchedule[1][$key], $scheduleTimes);
                                    return [
                                        'group' => $this->parseWeekDays($matchSchedule[2][$key]),
                                        'schedule' => $scheduleTimes[0],
                                    ];
                                }, $this)->toArray();
                            }

                            $dailyGroup = [];
                            foreach ($scheduleTimes as $scheduleTime) {
                                foreach ($groupedSchedule as $group) {
                                    if (in_array($scheduleTime, $group['schedule'])) {
                                        continue 2;
                                    }
                                }
                                $dailyGroup[] = $scheduleTime;
                            }

                            if (!empty($dailyGroup)) {
                                $groupedSchedule[] = [
                                    'group' => $this->parseWeekDays(),
                                    'schedule' => $dailyGroup,
                                ];
                            }
                            return $groupedSchedule;
                        }, $this)->flatten(1)
                    ];
                }, $this),
            ];
        })->toArray();
    }

    private function formatSchedule($parsedSchedule) : array
    {
        return collect($parsedSchedule)->map(function ($schedule) {
            $theather = $schedule['theather'];
            return collect($schedule['schedule'])->map(function ($schedule) use ($theather) {
                $audioSubs = $schedule['audio_subs'];
                $is3D = $schedule['3d'];
                return collect($schedule['schedule'])->map(function ($schedule) use ($theather, $audioSubs, $is3D) {
                    $weekDays = $schedule['group'];
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
