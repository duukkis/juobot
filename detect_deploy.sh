#!/bin/sh

if [ $(git rev-parse HEAD) = $(git ls-remote $(git rev-parse --abbrev-ref @{u} | \
sed 's/\// /g') | cut -f1) ]; then
  echo up to date
else
  echo not up to date
  git pull
  ps -ef | grep 'php beer' | grep -v grep | awk '{print $2}' | xargs kill
fi
