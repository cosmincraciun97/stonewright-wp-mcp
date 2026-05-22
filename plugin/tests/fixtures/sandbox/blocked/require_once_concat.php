<?php
// FIXTURE: require_once with concat of user-controlled path.

$slug = $_GET['module'];
require_once __DIR__ . '/' . $slug . '.php';
