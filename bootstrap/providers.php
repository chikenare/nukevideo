<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\TypeScriptTransformerServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    TypeScriptTransformerServiceProvider::class,
];
