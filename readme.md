## Tokenly XChain

[![Build Status](https://travis-ci.org/tokenly/xchain.svg?branch=master)](https://travis-ci.org/tokenly/xchain)
[![Coverage Status](https://img.shields.io/coveralls/tokenly/xchain.svg)](https://coveralls.io/r/tokenly/xchain?branch=master)

A public web service for BTC and XCP transactions.

###Queue commands

```
php artisan queue:work --sleep=0 --daemon --queue btctx blockingbeanstalkd

php artisan queue:work --sleep=0 --daemon --queue btcblock blockingbeanstalkd

php artisan queue:work --sleep=0 --daemon --queue notifications_return blockingbeanstalkd

php artisan queue:work --sleep=0 --daemon --queue validate_counterpartytx blockingbeanstalkd



```
