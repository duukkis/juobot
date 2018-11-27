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
    NAINEN
    MIES

- set weight
    {kg}kg

- start drinking
    olut {dl}
    aolut {dl}
    siideri {dl}
    lonkero {dl}
    viini {cl}
    tiukka (40%, 4cl)
    jekku (35%, 4cl)
    kossu (35%, 4cl)
    juoma {dl} {pros}

- post stats to channel
    TILASTO
- post promilles to you
    OMA
- help
    APUA
