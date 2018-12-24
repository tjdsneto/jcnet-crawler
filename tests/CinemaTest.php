<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use tjdsneto\JCNetCrawler\Cinema;

class CinemaTest extends TestCase
{
    public function testGetMoviesIsReturningAnArray(): void
    {
        $this->assertInternalType('array', (new Cinema())->getMovies());
    }

    public function testGetMoviesResultIsNotEmpty(): void
    {
        $this->assertNotEmpty((new Cinema())->getMovies());
    }

    public function testListedWeekDaysAreBeingParsedCorrectly(): void
    {
        $this->assertEquals([0, 1, 2], (new Cinema())->parseWeekDays('domingo, segunda e terça'));
    }

    public function testRangeOfWeekDaysAreBeingParsedCorrectly(): void
    {
        $this->assertEquals([0, 1, 2], (new Cinema())->parseWeekDays('domingo a terça'));
        $this->assertEquals([0, 1, 2], (new Cinema())->parseWeekDays('domingo a terça-feira'));
        $this->assertEquals([0, 1, 2], (new Cinema())->parseWeekDays('(domingo a terça)'));
        $this->assertEquals([0, 1, 2], (new Cinema())->parseWeekDays('(domingo a terça-feira)'));
    }

    public function testWeekDaysExceptionsAreBeingParsedCorrectly(): void
    {
        $this->assertEquals([1, 2, 3, 4, 5, 6], (new Cinema())->parseWeekDays('exceto domingo'));
        $this->assertEquals([1, 2, 3, 4, 5, 6], (new Cinema())->parseWeekDays('(exceto domingo)'));
    }

    public function testRangeOfWeekDaysWithExceptionsAreBeingParsedCorrectly(): void
    {
        $this->assertEquals([0, 1, 3, 4, 5], (new Cinema())->parseWeekDays('domingo a sexta exceto terça'));
        $this->assertEquals([0, 1, 3, 4, 5], (new Cinema())->parseWeekDays('(domingo a sexta exceto terça)'));
    }

    public function testListAndRangeOfWeekDaysAreBeingParsedCorrectly(): void
    {
        $this->assertEquals([0, 1, 3, 4, 5, 6], (new Cinema())->parseWeekDays('quinta a segunda, quarta'));
        $this->assertEquals([0, 1, 3, 4, 5, 6], (new Cinema())->parseWeekDays('(quinta a segunda, quarta)'));
    }

    public function testScheduleEntriesAreBeingParsedCorrectly(): void
    {
        $testExamples = [
            [
                'raw' => 'Sala 5: 21h15 (quinta, sexta, sábado e domingo)',
                'expected_results' => [
                    [
                        'weekdays' => [0, 4, 5, 6],
                        'schedule' => ['21h15']
                    ]
                ],
            ],
            [
                'raw' => 'Sala 1: 13h10 (quinta a segunda, quarta); 14h50 (terça)',
                'expected_results' => [
                    [
                        'weekdays' => [0, 1, 3, 4, 5, 6],
                        'schedule' => ['13h10']
                    ],
                    [
                        'weekdays' => [2],
                        'schedule' => ['14h50']
                    ]
                ],
            ],
            [
                'raw' => 'Sala 4: 14h30 – 16h40 terça, 25-12; 13h05 – 15h10 – 17h15 quarta',
                'expected_results' => [
                    [
                        'weekdays' => [2],
                        'schedule' => ['14h30', '16h40']
                    ],
                    [
                        'weekdays' => [3],
                        'schedule' => ['13h05', '15h10', '17h15']
                    ]
                ],
            ],
            [
                'raw' => 'Sala 1: 13h, 15h, 17h e 19h (quinta, sexta, sábado e domingo); 14h e 16h (segunda, 24-12)',
                'expected_results' => [
                    [
                        'weekdays' => [0, 4, 5, 6],
                        'schedule' => ['13h', '15h', '17h', '19h']
                    ],
                    [
                        'weekdays' => [1],
                        'schedule' => ['14h', '16h']
                    ]
                ],
            ],
            [
                'raw' => 'Sala 4: 12h30, 14h45, 17h15 (exceto segunda, 24-12), 19h45 (exceto segunda, 24-12) e 22h (exceto segunda, 24-12)',
                'expected_results' => [
                    [
                        'weekdays' => [0, 2, 3, 4, 5, 6],
                        'schedule' => ['12h30', '14h45', '17h15']
                    ],
                    [
                        'weekdays' => [0, 2, 3, 4, 5, 6],
                        'schedule' => ['19h45']
                    ],
                    [
                        'weekdays' => [0, 2, 3, 4, 5, 6],
                        'schedule' => ['22h']
                    ]
                ],
            ],
            [
                'raw' => 'Sala 4: 13h30 – 16h15 – 19h – 22h quinta a domingo; 13h20 e 16h segunda; 22h50 – 23h25 terça e quarta',
                'expected_results' => [
                    [
                        'weekdays' => [0, 4, 5, 6],
                        'schedule' => ['13h30', '16h15', '19h', '22h']
                    ],
                    [
                        'weekdays' => [1],
                        'schedule' => ['13h20', '16h']
                    ],
                    [
                        'weekdays' => [2, 3],
                        'schedule' => ['22h50', '23h25']
                    ]
                ],
            ],
            [
                'raw' => 'Sala 2: 13h – 15h30 – 18h00 – 20:30 quinta a domingo, quarta; 13h – 15h30 segunda; 15h30 – 18h – 20h30 terça',
                'expected_results' => [
                    [
                        'weekdays' => [0, 3, 4, 5, 6],
                        'schedule' => ['13h', '15h30', '18h00', '20:30']
                    ],
                    [
                        'weekdays' => [1],
                        'schedule' => ['13h', '15h30']
                    ],
                    [
                        'weekdays' => [2],
                        'schedule' => ['15h30', '18h', '20h30']
                    ]
                ],
            ],
        ];

        $cinema = new Cinema();
        foreach ($testExamples as $testExample) {
            $actualResults = $cinema->parseRawScheduleEntry($testExample['raw']);
            $this->assertEquals(count($testExample['expected_results']), count($actualResults), 'Different count results for: \'' . $testExample['raw'] . '\'');

            foreach ($testExample['expected_results'] as $key => $expectedResult) {
                $this->assertEquals($expectedResult['weekdays'], $actualResults[$key]['weekdays'], 'Different weekdays for: \'' . $testExample['raw'] . '\'');
                $this->assertEquals($expectedResult['schedule'], $actualResults[$key]['schedule'], 'Different schedules for: \'' . $testExample['raw'] . '\'');
            }
        }
    }
}
