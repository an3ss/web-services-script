<?php
/**
 * Database functions, implemented over PDO.
 *
 * Defines the $DB_config global variable, which contains the database configuration of the
 * connected database. The connection itself can be accessed in $DB_connection (also global).
 *
 * Note that the following restrictions apply when using the MySQL driver:
 *
 * - Native prepared statements don't allow a named attribute to appear more than once
 *   in a statement. For this reason, emulated prepared statements must be used (this
 *   is the detault option).
 *
 * - Numeric values are always returned as strings when using emulated prepared statements
 *   (setting PDO::ATTR_STRINGIFY_FETCHES to FALSE has no effect).
 */
//\\

   /**
    * Connects to one of the databases defined in $DB_CONFIG. By default, the database id
    * is the same as the service directory, but you can specify one explicitly with $dbId.
    *
    * @param $options Array of driver-specific options.
    * @param $dbId Optional database id. By default, the service directory is used.
    *
    * @return The created PDO database object, in case you need to call any of its methods.
    *         This is also assigned to the global variable $DB_connection.
    *
    * @throws RuntimeException If the database configuration is not defined.
    * @throws PDOException If the connection cannot be established.
    */
   function DB_connect(array $options = NULL, /*string*/ $dbId = NULL)/*: PDO */ {

      global $DB_CONFIG;
      global $Service_path, $Service_directory;
      global $DB_config, $DB_connection, $DB_queries, $DB_statements;

      // Use specified id or service directory for database id
      if (!$dbId) {
         if (!$Service_directory) {
            throw new RuntimeException("No database id specified in service <code>$Service_path</code>.");
         }
         $dbId = $Service_directory;
      }

      $DB_config = $DB_CONFIG[$dbId];
      if (!$DB_config) {
         throw new RuntimeException("A database for name <code>$dbId</code> is not defined.");
      }

      // Create database connection
      $DB_connection = @new PDO($DB_config['dsn'], $DB_config['userName'], $DB_config['password'], $options);
      $DB_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

      // Initialize query file array (indexed by query file name)
      $DB_queries = array();

      // Initialize statements array (indexed by query file name and query name)
      $DB_statements = array();

      return $DB_connection;
   }

   /**
    * Prepares and returns the statement for the specified query id. Queries are defined by
    * query name in a SQL query file, which is just a regular INI file in the /sql directory
    * (with the .sql.cfg extension).
    *
    * The query id is specified as [<file-name>/]<query-name>. If a file name is not
    * included, then the requested service's directory name will be used, unless the
    * 'sqlFileName' is defined in $DB_CONFIG.
    *
    * @param $queryId Either [<file-name>/]<query-name> or just a query name.
    * @param $replacements Array of named strings to be replaced in the statement.
    *           For any given key "myKey", the first appearance of "%myKey%" in the statement
    *           is replaced with the corresponding value.
    *
    * @return A prepared statement for the specified query name.
    *
    * @throws RuntimeException If the specified query id is not defined.
    * @throws PDOException
    */
   function DB_getStatement(/*string*/ $queryId, $replacements)/*: PDOStatement*/ {

      global $Service_directory;
      global $DB_config, $DB_connection, $DB_queries, $DB_statements;

      // Determine SQL query file name
      $fileName = strstr($queryId, '/', TRUE);
      if ($fileName) {
         // Use query file name from statement name, if specified
         $queryFileName = $fileName;
         $queryName = substr(strstr($queryId, '/'), 1);
      } else if (isset($DB_config['sqlFileName'])) {
         // Use query file name from configuration, if defined
         $queryFileName = $DB_config['sqlFileName'];
         $queryName = $queryId;
      } else {
         // Use the service directory as query file name
         $queryFileName = $Service_directory;
         $queryName = $queryId;
      }

      if (!isset($DB_queries[$queryFileName])) {
         // Load SQL query file and initialize the corresponding statements array
         $DB_queries[$queryFileName] = parse_ini_file("sql/$queryFileName.sql.cfg");
         $DB_statements[$queryFileName] = array();
      }

      if (isset($DB_statements[$queryFileName][$queryName])) {
         // Use previously prepared statement
         $stmt = $DB_statements[$queryFileName][$queryName];
      } else if (isset($DB_queries[$queryFileName][$queryName])) {
         // Prepare and save statement
         $query = $DB_queries[$queryFileName][$queryName];
         if ($replacements) {
            foreach ($replacements as $key => $value) {
               $query = str_replace("%$key%", $value, $query);
            }
            $stmt = $DB_connection->prepare($query);
         } else {
            $stmt = $DB_connection->prepare($query);
            $DB_statements[$queryFileName][$queryName] = $stmt;
         }
         $logStatements = @$DB_config['logStatements'];
         if ($logStatements == '*' || $logStatements == $queryName) {
            file_put_contents('sql_log.txt', "$query\n\n", FILE_APPEND);
         }
      } else {
         throw new RuntimeException("The statement <code>$queryId</code> is not defined.");
      }

      return $stmt;
   }

   /**
    * Executes the specified SELECT query.
    *
    * @param $queryId A query id for a SELECT (see {@link DB_getStatement}).
    * @param $params Parameters array for the SELECT statement (either named or ordered).
    *           It can be a string if there is only one (unnamed) parameter.
    * @param $expectOne Set to TRUE if the query should return only one result (or NULL).
    * @param $replacements Array of named strings to be replaced in the statement.
    *           For any given key "myKey", the first appearance of "%myKey%" in the statement
    *           is replaced with the corresponding value.
    *
    * @return Array of rows if $expectOne is FALSE. A single row if $expectOne is TRUE.
    *         NULL is returned if no matching row is found.
    *
    * @throws RuntimeException If the query is not defined or $expectOne is TRUE and more than one row is found.
    * @throws PDOException
    */
   function DB_select(/*string*/ $queryId, $params = NULL, /*bool*/ $expectOne = FALSE, $replacements = NULL) {

      // Get the prepared statement for this query
      $stmt = DB_getStatement($queryId, $replacements);

      // Convert string parameter to array
      if ($params !== NULL && !is_array($params)) {
         $params = array($params);
      }

      // Execute statement
      if ($stmt->execute($params) === FALSE) {
         throw new RuntimeException("The statement <code>$queryId</code> cannot be executed.");
      }

      // Return query result
      if ($stmt->rowCount() == 0) {
         return NULL;  // query OK but no results found
      }
      if ($expectOne) {
         if ($stmt->rowCount() == 1) {
            // Result has one row only (as expected)
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
         } else {
            throw new RuntimeException("The select statement <code>$queryId</code> returned more than one result but only one was expected.");
         }
      } else {
         // Result has one or more rows
         $result = array();
         while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[] = $row;
         }
      }
      $stmt->closeCursor();
      return $result;
   }

   /**
    * Executes the specified update query (INSERT, UPDATE, DELETE or MERGE).
    *
    * @param $queryId A query id for an update query (see {@link DB_getStatement}).
    * @param $params Parameters array for the statement (either named or ordered).
    *           It can be a string if there is only one (unnamed) parameter.
    * @param $replacements Array of named strings to be replaced in the statement.
    *           For any given key "myKey", the first appearance of "%myKey%" in the statement
    *           is replaced with the corresponding value.
    *
    * @return The number of rows affected by the statement.
    *
    * @throws RuntimeException If the query is not defined.
    * @throws PDOException
    */
   function DB_update(/*string*/ $queryId, $params = NULL, $replacements = NULL)/*: int*/ {

      global $DB_config;

      // Get the prepared statement for this query
      $stmt = DB_getStatement($queryId, $replacements);

      // Convert string parameter to array
      if ($params !== NULL && !is_array($params)) {
         $params = (array) $params;
      }

      // In test mode, don't execute update statements
      if (isset($DB_config['test']) && $DB_config['test']) {
         return 1;
      }

      // Execute statement
      if ($stmt->execute($params) === FALSE) {
         throw new RuntimeException("The statement <code>$queryId</code> cannot be executed.");
      }

      // Return update count
      $count = $stmt->rowCount();
      $stmt->closeCursor();
      return $count;
   }
