<?php
require 'vendor/autoload.php';
use React\EventLoop\Factory;
use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\Drivers\Slack\SlackRTMDriver;

include("vars.php");

// Load driver
DriverManager::loadDriver(SlackRTMDriver::class);

const MALE = 'M';
const FEMALE = 'F';
const GRAMS = 'gr';
const KILOGRAMS = 'kg';

$loop = Factory::create();
$botman = BotManFactory::createForRTM([
    'slack' => [
        'token' => SLACK_TOKEN,
    ],
], $loop);

// array of users to gather
// sex, kg, gr, lastupdated
$users = array();
$last_tilasto = time();


/**
 * adds grams of alcohol to your blood
 */
function calculate($bot, $pros, $quantity){
  global $users;
  $u = $bot->getUser()->getUsername();
  
  if (!isset($users[$u]["lastupdated"])) { $users[$u]["lastupdated"] = time(); }
  if (!isset($users[$u][GRAMS])) { $users[$u][GRAMS] = 0; }
  
  $users[$u][GRAMS] += ($pros * $quantity);
  $bot->reply('Grammoja veressä '.$users[$u][GRAMS]);
  timeToPostTheStats($bot);
}

/**
 * checker to check is it time to post the stats
 */
function timeToPostTheStats($bot){
  global $last_tilasto;
  if(time() - $last_tilasto > STAT_RATE){
    $last_tilasto = time();
    promilles($bot);
  }
}

/**
 * https://fi.wikipedia.org/wiki/Veri 
 */
function amountOfBlood($kg){
  return $kg*0.07;
}

/**
 * updates the stats
 */
function promilles($bot){
  global $users;
  $now = time();
  $stats = array();
  if (!empty($users)) {
    foreach ($users AS $name => $u) {
      if (isset($u[KILOGRAMS])) {
        $hours_past = (($now - $u["lastupdated"])/3600);
        $users[$name]["lastupdated"] = time();
        
        $factor = ($u["sex"] == FEMALE) ? WOMAN_FACTOR : 1;
        $blood = amountOfBlood($u[KILOGRAMS]);
        // body burns 1 gram of alcohol for every 10 kilos every hour
        $users[$name][GRAMS] -= ($f*($u[KILOGRAMS]/10)*$hours_past);
        
        $promills = $u[GRAMS]/$blood/10;
        // user has no grams, dont show his/her result
        if ($users[$name][GRAMS] < 0) {
          $users[$name][GRAMS] = 0;
        } else { // append to array
          $stats[$name] = $promills;
        }
      }
    }
    
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
  $users[$u][KILOGRAMS] = $kg;
  $bot->reply('Massasi '.$kg." kg");
  timeToPostTheStats($bot);
});

$botman->hears('NAINEN', function($bot) {
  global $users;
  $u = $bot->getUser()->getUsername();
  $users[$u]["sex"] = FEMALE;
  $bot->reply('Sukupuolesi '.$users[$u]["sex"]);
  timeToPostTheStats($bot);
});

$botman->hears('MIES', function($bot) {
  global $users;
  $u = $bot->getUser()->getUsername();
  $users[$u]["sex"] = MALE;
  $bot->reply('Sukupuolesi '.$users[$u]["sex"]);
  timeToPostTheStats($bot);
});

$botman->hears('APUA', function($bot) {
  global $users;
  $bot->reply("Aseta ensin sukupuoli ja massa ja ryhdy juomaan.\nNAINEN\nMIES\n{kg}kg\n\nolut {dl}\naolut {dl}\nsiideri {dl}\nlonkero {dl}\nviini {cl}\ntiukka\njekku\nkossu\njuoma {dl} {pros}\n");
  timeToPostTheStats($bot);
});

$botman->hears('olut {dl}', function($bot, $dl) { calculate($bot, 4.5, $dl); });
$botman->hears('aolut {dl}', function($bot, $dl) { calculate($bot, 5.2, $dl); });
$botman->hears('siideri {dl}', function($bot, $dl) { calculate($bot, 4.5, $dl); });
$botman->hears('lonkero {dl}', function($bot, $dl) { calculate($bot, 5.5, $dl); });
$botman->hears('juoma {dl} {pros}', function($bot, $pros, $dl) { calculate($bot, $pros, $dl); });
$botman->hears('viini {cl}', function($bot, $cl) { calculate($bot, 12, ($cl/10)); });
$botman->hears('tiukka', function($bot) { calculate($bot, 40, 0.4); });
$botman->hears('jekku', function($bot) { calculate($bot, 35, 0.4); });
$botman->hears('kossu', function($bot) { calculate($bot, 38, 0.4); });
$botman->hears('camparisoda', function($bot) { calculate($bot, 10, 0.98); });
// also command that can post the stats before hand
$botman->hears('TILASTO', function($bot) { promilles($bot); });

$loop->run();
