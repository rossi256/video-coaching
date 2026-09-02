<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/spots.php';
setApiHeaders();

jsonResponse(getSpots(getDb()));
