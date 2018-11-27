# juobot

- composer install
- get slack token
- get channel id, where to post the stats

- update these to default_vars.php and rename that to vars.php
- run command `php beer.php´
- start talking to bot

The bots calmculates the promilles and writes a local cache file to user_data.txt or what is defined as LOCAL_FILE.

Commands
- set gender with
```
    NAINEN
    MIES
```
- set weight
```
    {kg}kg
```

- start drinking
```
    olut {dl}
    aolut {dl}
    siideri {dl}
    lonkero {dl}
    viini {cl}
    tiukka
    jekku
    kossu
    juoma {dl} {pros}
```
tiukka is 4cl of 40%, jekku and kossu are 4cl of 35%, olut, siideri are 4.5%, aolut is 5.2%, lonkero is 5.5%, viini is 12%, juoma can be anything defined by {pros}

- post stats to channel
```
   TILASTO
```
- post promilles to you
```
    OMA
```
- help
```
    APUA
```
