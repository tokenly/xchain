#!/bin/bash

set -e

/usr/local/bin/composer.phar install --prefer-dist --no-progress
# ./artisan migrate
