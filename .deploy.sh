#!/bin/bash

set -e

# clear the compiled classes
if [ -f storage/framework/compiled.php ] ; then
    rm storage/framework/compiled.php
fi


/usr/local/bin/composer.phar install --prefer-dist --no-progress
# ./artisan migrate
