<?php
require 'vendor/autoload.php';
require 'vars.php';

use React\EventLoop\Factory;
use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\Drivers\Slack\SlackRTMDriver;

// Load driver
DriverManager::loadDriver(SlackRTMDriver::class);

const MALE = 'M';
const FEMALE = 'F';
const ALC_IN_BLOOD = 'gr';
const USER_WEIGHT = 'kg';
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
";

$loop = Factory::create();
$botman = BotManFactory::createForRTM(['slack' => ['token' => SLACK_TOKEN,]], $loop);

// array of users to gather
// sex, kg, gr, lastcalculated
$users = loadUsers();
// load from local file
$last_tilasto = time();


/**
 * save local cache file
 */
function saveUsers()
{
  global $users;
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
 * adds grams of alcohol to your blood
 */
function calculate($bot, $pros, $quantity)
{
  global $users;
  $u = $bot->getUser()->getUsername();
  
  if (!isset($users[$u]["lastcalculated"])) {
    $users[$u]["lastcalculated"] = time();
  }
  if (!isset($users[$u][ALC_IN_BLOOD])) {
    $users[$u][ALC_IN_BLOOD] = 0;
  }
  
  $users[$u][ALC_IN_BLOOD] += ($pros * $quantity);
  $bot->reply('Grammoja veressä '.$users[$u][ALC_IN_BLOOD]);
  saveUsers();
  timeToPostTheStats($bot);
}

/**
 * checker to check is it time to post the stats
 */
function timeToPostTheStats($bot) {
  global $last_tilasto;
  if (time() - $last_tilasto > STAT_RATE) {
    $last_tilasto = time();
    promilles($bot);
  }
}

/**
 * https://fi.wikipedia.org/wiki/Veri 
 */
function amountOfBlood($kg)
{
  return $kg*0.07;
}

/**
 * updates the stats
 */
function promilles($bot)
{
  global $users;
  $now = time();
  $stats = array();
  if (!empty($users)) {
    foreach ($users AS $name => $u) {
      if (isset($u[USER_WEIGHT])) {
        $hours_past = (($now - $u["lastcalculated"])/3600);
        $users[$name]["lastcalculated"] = time();
        
        $factor = ($u["sex"] == FEMALE) ? WOMAN_FACTOR : 1;
        $blood = amountOfBlood($u[USER_WEIGHT]);
        // body burns 1 gram of alcohol for every 10 kilos every hour
        $users[$name][ALC_IN_BLOOD] -= ($f*($u[USER_WEIGHT]/10)*$hours_past);
        
        $promills = $u[ALC_IN_BLOOD]/$blood/10;
        // user has no grams, dont show his/her result
        if ($users[$name][ALC_IN_BLOOD] < 0) {
          $users[$name][ALC_IN_BLOOD] = 0;
        } else { // append to array
          $stats[$name] = $promills;
        }
      }
    }
    saveUsers();
    if (empty($stats)) {
      $bot->say('Kukaan ei ole humalassa.', POST_STATS_TO_CHANNEL);
      // $bot->reply('Kukaan ei ole humalassa.'); // debug
    } else {
      $stat = "";
      arsort($stats);
      foreach ($stats AS $n => $p) {
        $stat .= $n." ".round($p, 2)."‰\n";
      }
      $bot->say($stat, POST_STATS_TO_CHANNEL);
      // $bot->reply($stat); // debug
    }
  } else {
    $bot->say('Kukaan ei ole humalassa.', POST_STATS_TO_CHANNEL);    
    // $bot->reply('Kukaan ei ole humalassa.'); // debug
  }
}

// The commands

$botman->hears('{kg}kg', function($bot, $kg) {
    global $users;
    $u = $bot->getUser()->getUsername();
    $users[$u][USER_WEIGHT] = (int) $kg;
    $bot->reply('Massasi '.$kg." kg");
    saveUsers();
    timeToPostTheStats($bot);
});

$botman->hears('NAINEN', function($bot) {
    global $users;
    $u = $bot->getUser()->getUsername();
    $users[$u]["sex"] = FEMALE;
    $bot->reply('Sukupuolesi '.$users[$u]["sex"]);
    saveUsers();
    timeToPostTheStats($bot);
});

$botman->hears('MIES', function($bot) {
    global $users;
    $u = $bot->getUser()->getUsername();
    $users[$u]["sex"] = MALE;
    $bot->reply('Sukupuolesi '.$users[$u]["sex"]);
    saveUsers();
    timeToPostTheStats($bot);
});

$botman->hears('APUA', function($bot) {
    global $users;
    $bot->reply(HELP_TEXT);
    timeToPostTheStats($bot);
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
$botman->hears('TILASTO', function ($bot) {
  promilles($bot); 
});

$loop->run();
