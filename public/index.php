<?php

declare(strict_types=1);

use LogiDock\Core\Application;

// Front controller: ogni richiesta che non è un file statico passa da qui.
require dirname(__DIR__) . '/vendor/autoload.php';

(new Application(dirname(__DIR__)))->run();
