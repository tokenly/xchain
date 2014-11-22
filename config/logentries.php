<?php

return [

    'token' => getenv('LOGENTRIES_LOG_TOKEN') ?: null,
    'ssl'   => getenv('LOGENTRIES_USE_SSL')   ?: true,

];
