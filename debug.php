<?php

require 'vendor/autoload.php';

use tjdsneto\JCNetCrawler\Cinema;

// print_r((new Cinema())->parseWeekDays('quinta a segunda, quarta'));
// print_r((new Cinema())->parseWeekDays('(quinta, sexta, sábado e domingo)'));

// print_r((new Cinema())->parseRawScheduleEntry('Sala 5: 21h15 (quinta, sexta, sábado e domingo)'));
// print_r((new Cinema())->parseRawScheduleEntry('Sala 4: 12h30, 14h45, 17h15 (exceto segunda, 24-12), 19h45 (exceto segunda, 24-12) e 22h (exceto segunda, 24-12)'));
// print_r((new Cinema())->parseRawScheduleEntry('Sala 4: 13h30 – 16h15 – 19h – 22h quinta a domingo; 13h20 e 16h segunda; 22h50 – 23h25 terça e quarta'));
print_r((new Cinema())->parseRawScheduleEntry('Sala 4: 14h30 – 16h40 terça, 25-12; 13h05 – 15h10 – 17h15 quarta'));
