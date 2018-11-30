<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/vars.php';

use React\EventLoop\Factory;
use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\Drivers\Slack\SlackRTMDriver;

// Load driver
DriverManager::loadDriver(SlackRTMDriver::class);

const GENDER = 'sex';
const MALE = 'M';
const FEMALE = 'F';
const MAN_FACTOR = 0.58;
const WOMAN_FACTOR = 0.49;
const MAN_METAC = 0.015;
const WOMAN_METAC = 0.017;
const ZERO_WITH_ROUNDING_TOLERANCE = 0.005;

const ALC_IN_BLOOD = 'gr';
const NUMBER_OF_STANDARD_DRINKS = 'st';
const USER_WEIGHT = 'kg';
const LAST_CALCD = 'lastcalculated';
const HELP_TEXT = "Aseta ensin sukupuoli ja massa ja ryhdy juomaan.
NAINEN
MIES
{kg}kg

olut {dl}
aolut {dl}
siideri {dl}
lonkero {dl}
viini {cl}
tiukka
jekku
kossu
juoma {dl} {pros}

TILASTO
OMA
";
const NO_ONE_IS_DRUNK = "Kukaan ei ole humalassa.";

$loop = Factory::create();
$botman = BotManFactory::createForRTM(['slack' => ['token' => SLACK_TOKEN,]], $loop);

// array of users to gather
// sex, kg, gr, lastcalculated
$users = loadUsers();
// load from local file
$last_tilasto = (time() - STAT_RATE);


/**
 * save local cache file
 */
function saveUsers($users)
{
  file_put_contents(LOCAL_FILE, serialize($users));
}


/**
 * load local cache file
 */
function loadUsers()
{
  $users = array();
  if (is_file(LOCAL_FILE)) {
    $f = file_get_contents(LOCAL_FILE);
    $users = unserialize($f);
    if (!is_array($users)) {
      $users = array();
    }
  }
  return $users;
}

/**
 * calculate number of portions drank
 */
function calculate($bot, $pros, $quantity)
{
  global $users;
  $name = $bot->getUser()->getUsername();
  
  if (!isset($users[$name][USER_WEIGHT])) {
    $bot->reply('Aseta massasi käyttäen `{kg}kg`-komentoa.');
    return;
  }

  if (!isset($users[$name][GENDER])) {
    $bot->reply('Aseta sukupuolesi käyttäen `NAINEN`- tai `MIES`-komentoa.');
    return;
  }

  if (!isset($users[$name][ALC_IN_BLOOD])) {
    $users[$name][ALC_IN_BLOOD] = 0;
  }
  if (!isset($users[$name][NUMBER_OF_STANDARD_DRINKS])) {
    $users[$name][NUMBER_OF_STANDARD_DRINKS] = 0;
  }
  // zero the starting time
  if (!isset($users[$name][LAST_CALCD]) || $users[$name][NUMBER_OF_STANDARD_DRINKS] == 0) {
    $users[$name][LAST_CALCD] = time();
  }
  
  $users[$name][NUMBER_OF_STANDARD_DRINKS] = $users[$name][NUMBER_OF_STANDARD_DRINKS] + (($pros * $quantity/1.25)/12);
  $users[$name][ALC_IN_BLOOD] = $users[$name][ALC_IN_BLOOD] + (($pros * $quantity)/1.25);
  
  $bot->reply('Annoksia juotu '.round($users[$name][NUMBER_OF_STANDARD_DRINKS],2)."");
  
  saveUsers($users);
  // timeToPostTheStats($bot);
}

/**
 * checker to check is it time to post the stats
 */
function timeToPostTheStats($bot) {
  global $last_tilasto;
  if (time() - $last_tilasto > STAT_RATE) {
    $last_tilasto = time();
    promilles($bot, false);
  }
}

/**
 * https://en.wikipedia.org/wiki/Blood_alcohol_content
 */
function calculatePromilles($nbr_of_standard_drinks, $kg, $sex, $started)
{
  $factor = ($sex == FEMALE) ? WOMAN_FACTOR : MAN_FACTOR;
  $metabolism_constant = ($sex == FEMALE) ? WOMAN_METAC : MAN_METAC;
  
  $promilles = (((0.806 * $nbr_of_standard_drinks * 1.2) / ($factor * $kg)) - ($metabolism_constant * $started)) * 10;
  return $promilles;
}

/**
 * updates the stats
 */
function promilles($bot, $force = false)
{
  global $users;
  $now = time();
  $stats = array();
  $someone_was_drunk = isAnyoneDrunk($users);
  if (!empty($users)) {
    foreach ($users AS $name => $u) {
      if (isset($u[USER_WEIGHT]) && $u[USER_WEIGHT] > 0) {
        $hours_past = (($now - $u[LAST_CALCD])/3600);
        
        $promills = calculatePromilles(
            $users[$name][NUMBER_OF_STANDARD_DRINKS],
            $users[$name][USER_WEIGHT],
            $users[$name][GENDER],
            $hours_past);

        // user has no grams, dont show his/her result, just update data to 0
        if ($promills < ZERO_WITH_ROUNDING_TOLERANCE) {
          $users[$name][LAST_CALCD] = time();
          $users[$name][ALC_IN_BLOOD] = 0;
          $users[$name][NUMBER_OF_STANDARD_DRINKS] = 0;
        } else { // append to array
          $stats[$name] = $promills;
        }
      }
    }
    saveUsers($users);
    if (empty($stats)) {
      if ($force || $someone_was_drunk) {
        $bot->say(NO_ONE_IS_DRUNK, POST_STATS_TO_CHANNEL);
      }
      // $bot->reply('Kukaan ei ole humalassa.'); // debug
    } else {
      statsToChannel($stats, $bot);
    }
  } else if ($force) {
    $bot->say(NO_ONE_IS_DRUNK, POST_STATS_TO_CHANNEL);
    // $bot->reply('Kukaan ei ole humalassa.'); // debug
  }
}

/**
 * ownStats
 */
function ownStats($bot)
{
  global $users;
  $now = time();
  $name = $bot->getUser()->getUsername();
  $u = $users[$name];
  
  $hours_past = (($now - $u[LAST_CALCD])/3600);
  $promills = calculatePromilles(
            $users[$name][NUMBER_OF_STANDARD_DRINKS],
            $users[$name][USER_WEIGHT],
            $users[$name][GENDER],
            $hours_past);

  if ($promills < 0) {
      $users[$name][LAST_CALCD] = time();
      $users[$name][ALC_IN_BLOOD] = 0;
      $users[$name][NUMBER_OF_STANDARD_DRINKS] = 0;
      $promills = 0;
  }
  saveUsers($users);
  if ($promills == 0) {
    $bot->reply('Olet selvinpäin.');
  } else {
    $bot->reply('Promillesi '.round($promills,2).'‰');
  }
}

function isAnyoneDrunk($users)
{
  foreach ($users AS $user) {
    if (isset($user[ALC_IN_BLOOD]) && 
        $user[ALC_IN_BLOOD] > 0) {
      return true;
    }
  }
  return false;
}

function setGender($bot, $gender)
{
  global $users;
  $u = $bot->getUser()->getUsername();
  $users[$u][GENDER] = $gender;
  $bot->reply('Sukupuolesi '.$users[$u][GENDER]);
  saveUsers($users);
}

function statsToChannel($stats, $bot)
{
  $stat = "";
  arsort($stats);
  foreach ($stats AS $n => $p) {
    $stat .= formatDrunkRow($n, $p);
  }
  $bot->say($stat, POST_STATS_TO_CHANNEL);
}

function formatDrunkRow($name, $promilles)
{
  $statisticRow = $name." ".round($promilles, 2)."‰";

  if ($promilles >= 1.0) {
    // Yes, I know this should be configurable but this is not configurable.
    $statisticRow .= " :during-work:";
  }

  return $statisticRow."\n";
}

// The commands

$botman->hears('{kg}kg', function($bot, $kg) {
    global $users;
    $u = $bot->getUser()->getUsername();
    $users[$u][USER_WEIGHT] = (int) $kg;
    $bot->reply('Massasi '.$kg." kg");
    saveUsers($users);
//    timeToPostTheStats($bot);
});

$botman->hears('NAINEN', function($bot) {
    $users = setGender($bot, FEMALE);
});

$botman->hears('MIES', function($bot) {
    $users = setGender($bot, MALE);
});

$botman->hears('APUA', function($bot) {
    $bot->reply(HELP_TEXT);
//    timeToPostTheStats($bot);
});

$botman->hears('olut {dl}', function ($bot, $dl) { calculate($bot, 4.5, $dl); });
$botman->hears('aolut {dl}', function ($bot, $dl) { calculate($bot, 5.2, $dl); });
$botman->hears('siideri {dl}', function ($bot, $dl) { calculate($bot, 4.5, $dl); });
$botman->hears('lonkero {dl}', function ($bot, $dl) { calculate($bot, 5.5, $dl); });
$botman->hears('juoma {dl} {pros}', function ($bot, $pros, $dl) { calculate($bot, $pros, $dl); });
$botman->hears('viini {cl}', function ($bot, $cl) { calculate($bot, 12, ($cl/10)); });
$botman->hears('tiukka', function ($bot) { calculate($bot, 40, 0.4); });
$botman->hears('jekku', function ($bot) { calculate($bot, 35, 0.4); });
$botman->hears('kossu', function ($bot) { calculate($bot, 38, 0.4); });
$botman->hears('camparisoda', function ($bot) { calculate($bot, 10, 0.98); });
// also command that can post the stats before hand
$botman->hears('TILASTO', function ($bot) { promilles($bot, true); });
$botman->hears('OMA', function ($bot) { ownStats($bot); });

$loop->run();
