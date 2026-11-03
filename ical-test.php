<?php
// Test unique (CLI) : lecture d'un vrai calendrier iCal public depuis le serveur
if (PHP_SAPI !== 'cli') exit;
require '/home/stayinri/public_html/app/inc/bootstrap.php';
require '/home/stayinri/public_html/app/inc/booking.php';
echo date('Y-m-d H:i'), ' PHP ', PHP_VERSION, ' curl=', function_exists('curl_init') ? 'oui' : 'non', ' schema=', q("SELECT v FROM settings WHERE k='schema_version'")->fetchColumn(), "\n";
[$t, $e] = ical_fetch('https://calendar.google.com/calendar/ical/fr.french%23holiday%40group.v.calendar.google.com/public/basic.ics');
echo $e ?? ('LECTURE OK : ' . count(ical_parse($t)) . ' evenements'), "\n";
