<?php
/**
 * Set to TRUE to log all service-generated exceptions in the /logs directory.
 * A log file is created for each day (no automatic cleanup is done).
 */
$LOG_EXCEPTIONS = FALSE;

/**
 * Database configuration by database id.
 */
$DB_CONFIG = array(
   'sample' => array(
      'name' => 'Sample Database',
      'dsn' => 'mysql:host=localhost;dbname=sample;charset=utf8',
      'userName' => 'sample-user',
      'password' => 'sample-password',
      'test' => TRUE
   )
);
