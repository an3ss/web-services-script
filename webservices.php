<?php
/**
 * Simple PHP script for web services with JSON responses. Includes a database API.
 *
 * Each service must be defined as simple PHP script in the /services directory.
 * A service URL "/<servicePath>" is processed by including the file '/services/<servicePath>.php'
 * and converting its return value to a server response.
 *
 * The <servicePath> supports one subdirectory level (see .htaccess). This subdirectory
 * can be used to implement services for different modules or apps. It is also used as
 * the default database id (see the database API).
 *
 * The supported responses from the included service file are:
 * - An array that will be returned as JSON data to the client.
 * - A string holding the path of a local image to be returned to the client.
 *
 * The global variables $Service_path and $Service_directory are available.
 */
ini_set('display_errors', 1);

// Include database configuration
include 'webservices-config.php';

// Include database API
include 'webservices-database.php';

Service_create();
Service_execute();

/**
 * This class can be used by services to throw/catch their own runtime exceptions,
 * separate from PDOException and other runtime exceptions.
 */
class ServiceException extends RuntimeException {
   public $msgid;
   public function __construct($msgid = 'SERVICE_ERROR', $message = NULL, $code = 0, Exception $previous = NULL) {
      $this->msgid = $msgid;
      parent::__construct($message, $code, $previous);
   }
}

/**
 * Service functions (not public).
 *
 */
//\\

   /**
    * Service creation function.
    */
   function Service_create() {

      global $Service_path, $Service_directory;

      // Set default timezone (required by date functions)
      date_default_timezone_set('UTC');

      // Convert all errors into exceptions (including E_NOTICE and E_STRICT)
      // Errors suppressed with @ are not handled (even if they're fatal!)
      set_error_handler(function ($errno, $errstr, $errfile, $errline) {
         if (error_reporting() !== 0) {  // not suppressed by @ operator
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
         }
      });

      // Get service path
      $Service_path = @$_GET['servicePath'];
      if (!$Service_path) {  // no service specified
         exit('<center><pre style="margin-top:3em; font-size:22px">... FJS Web Server ...</pre></center>');
      }

      // Get service directory
      $Service_directory = strstr($Service_path, '/', TRUE) ?: NULL;
   }

   /**
    * Service execution function.
    */
   function Service_execute() {

      global $Service_path, $Service_directory;
      global $DB_config;

      // Allow all origins
      header('Access-Control-Allow-Origin: *');

      // Execute the requested service
      try {
         $serviceResponse = include "services/$Service_path.php";

      } catch (ServiceException $e) {
         $serviceResponse = array(
            'error' => $e->msgid,
            'exception' => 'ServiceException',
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
         );

         if ($e->msgid != 'LOGIN_ERROR') {
            $log = date("H:i:s").' '.$Service_path.'.php('.$e->getLine().') - '.$e->msgid.': '.$e->getMessage().PHP_EOL;
            file_put_contents('logs/'.date("Y-m-d").'.log', $log, FILE_APPEND);
         }

      } catch (Exception $e) {
         $serviceResponse = array(
            'error' => 'SERVICE_ERROR',
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
         );

         if (file_exists("services/$Service_path.php")) {
            $log = date("H:i:s").' '.$Service_path.' - '.get_class($e).': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL;
         } else {
            $log = date("H:i:s").' '.$Service_path.' - Service not found ['.$_SERVER['REQUEST_METHOD'].'] '.$_SERVER['QUERY_STRING'].PHP_EOL;
         }
         file_put_contents('logs/'.date("Y-m-d").'.log', $log, FILE_APPEND);
      }

      if (is_array($serviceResponse)) {

         // If response is an array, return it as JSON
         try {
            $jsonResponse = json_encode($serviceResponse);
         } catch (Exception $e) {
            $serviceResponse = array(
               'error' => 'SERVICE_ERROR',
               'exception' => $e->getMessage(),
               'file' => $e->getFile(),
               'line' => $e->getLine()
            );
            $jsonResponse = json_encode($serviceResponse);
         }

         header('Content-Type: application/json; charset=utf-8');
         if (
            strlen($jsonResponse) > 2048 &&
            isset($_SERVER['HTTP_ACCEPT_ENCODING']) &&
            substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')
         ) {
            // Return COMPRESSED if JSON response > 2 Kb
            header('Content-Encoding: gzip');
            ob_start('ob_gzhandler');
            echo $jsonResponse;
            ob_end_flush();
         } else {
            // Return UNCOMPRESSED otherwise
            header('Content-Length: ' . strlen($jsonResponse));
            echo $jsonResponse;
         }

      } else if (is_string($serviceResponse)) {

         // If response is a string, use it as the path to a JPEG/PNG/GIF file
         $filename = $serviceResponse;
         $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
         $mimeType = $extension == 'jpg' ? 'image/jpeg' : "image/$extension";

         $protocol = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0';
         $lastCached = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : 0;

         $stat = @stat($filename);
         if ($stat === FALSE) {
            header($protocol . ' 404 Not Found');
            exit;  // file not found
         }

         $contentLength = $stat['size'];
         $lastModified = $stat['mtime'];

         if ($lastModified <= $lastCached) {
            header($protocol . ' 304 Not Modified');
            exit;  // file not modified since last cached
         }

         header("Content-Type: $mimeType");
         header("Content-Length: $contentLength");
         header('Last-Modified: ' . gmdate('D, d M Y H:i:s T', $lastModified));
         header('Cache-Control: must-revalidate, private');
         header('Expires: -1');
         readfile($filename);

      } else {
         // Otherwise, do nothing (service should write output directly)
      }
   }
