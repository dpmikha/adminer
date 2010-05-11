<?php
$possible_drivers[] = "PgSQL";
$possible_drivers[] = "PDO_PgSQL";
if (extension_loaded("pgsql") || extension_loaded("pdo_pgsql")) {
	$drivers["pgsql"] = "PostgreSQL";
}

if (isset($_GET["pgsql"])) {
	define("DRIVER", "pgsql");
	if (extension_loaded("pgsql")) {
		class Min_DB {
			var $extension = "PgSQL", $_link, $_result, $_string, $_database = true, $server_info, $affected_rows, $error;
			
			function _error($errno, $error) {
				if (ini_bool("html_errors")) {
					$error = html_entity_decode(strip_tags($error));
				}
				$error = ereg_replace('^[^:]*: ', '', $error);
				$this->error = $error;
			}
			
			function connect($server, $username, $password) {
				set_error_handler(array($this, '_error'));
				$this->_string = "host='" . str_replace(":", "' port='", addcslashes($server, "'\\")) . "' user='" . addcslashes($username, "'\\") . "' password='" . addcslashes($password, "'\\") . "'";
				$this->_link = @pg_connect($this->_string . (DB != "" ? " dbname='" . addcslashes(DB, "'\\") . "'" : ""), PGSQL_CONNECT_FORCE_NEW);
				if (!$this->_link && DB != "") {
					// try to connect directly with database for performance
					$this->_database = false;
					$this->_link = @pg_connect($this->_string, PGSQL_CONNECT_FORCE_NEW);
				}
				restore_error_handler();
				if ($this->_link) {
					$version = pg_version($this->_link);
					$this->server_info = $version["server"];
					pg_set_client_encoding($this->_link, "UTF8");
				}
				return (bool) $this->_link;
			}
			
			function quote($string) {
				return "'" . pg_escape_string($this->_link, $string) . "'"; //! bytea
			}
			
			function select_db($database) {
				if ($database == DB) {
					return $this->_database;
				}
				$link = @pg_connect($this->_connection . " dbname='" . addcslashes($database, "'\\") . "'", PGSQL_CONNECT_FORCE_NEW);
				if ($link) {
					$this->_link = $link;
				}
				return $link;
			}
			
			function close() {
				$this->_link = @pg_connect($this->_string);
			}
			
			function query($query, $unbuffered = false) {
				$result = @pg_query($this->_link, $query);
				if (!$result) {
					$this->error = pg_last_error($this->_link);
					return false;
				} elseif (!pg_num_fields($result)) {
					$this->affected_rows = pg_affected_rows($result);
					return true;
				}
				return new Min_Result($result);
			}
			
			function multi_query($query) {
				return $this->_result = $this->query($query);
			}
			
			function store_result() {
				return $this->_result;
			}
			
			function next_result() {
				// PgSQL extension doesn't support multiple results
				return false;
			}
			
			function result($query, $field = 0) {
				$result = $this->query($query);
				if (!$result) {
					return false;
				}
				return pg_fetch_result($result->_result, 0, $field);
			}
		}
		
		class Min_Result {
			var $_result, $_offset = 0, $num_rows;
			
			function Min_Result($result) {
				$this->_result = $result;
				$this->num_rows = pg_num_rows($result);
			}
			
			function fetch_assoc() {
				return pg_fetch_assoc($this->_result);
			}
			
			function fetch_row() {
				return pg_fetch_row($this->_result);
			}
			
			function fetch_field() {
				$column = $this->_offset++;
				$row = new stdClass;
				if (function_exists('pg_field_table')) {
					$row->orgtable = pg_field_table($this->_result, $column);
				}
				$row->name = pg_field_name($this->_result, $column);
				$row->orgname = $row->name;
				$row->type = pg_field_type($this->_result, $column);
				$row->charsetnr = ($row->type == "bytea" ? 63 : 0); // 63 - binary
				return $row;
			}
			
			function __destruct() {
				pg_free_result($this->_result);
			}
		}
		
	} elseif (extension_loaded("pdo_pgsql")) {
		class Min_DB extends Min_PDO {
			var $extension = "PDO_PgSQL";
			
			function connect($server, $username, $password) {
				$string = "pgsql:host='" . str_replace(":", "' port='", addcslashes($server, "'\\")) . "' options='-c client_encoding=utf8'";
				$this->dsn($string . (DB != "" ? " dbname='" . addcslashes(DB, "'\\") . "'" : ""), $username, $password);
				//! connect without DB in case of an error
				return true;
			}
			
			function select_db($database) {
				return (DB == $database);
			}
			
			function close() {
			}
		}
		
	}
	
	function idf_escape($idf) {
		return '"' . str_replace('"', '""', $idf) . '"';
	}

	function table($idf) {
		return idf_escape($idf);
	}

	function connect() {
		global $adminer;
		$connection = new Min_DB;
		$credentials = $adminer->credentials();
		if ($connection->connect($credentials[0], $credentials[1], $credentials[2])) {
			return $connection;
		}
		return $connection->error;
	}
	
	function get_databases() {
		return get_vals("SELECT datname FROM pg_database");
	}
	
	function limit($query, $limit, $offset = 0, $separator = " ") {
		return " $query" . (isset($limit) ? $separator . "LIMIT $limit" . ($offset ? " OFFSET $offset" : "") : "");
	}

	function limit1($query) {
		return " $query";
	}
	
	function db_collation($db, $collations) {
		global $connection;
		return $connection->result("SHOW LC_COLLATE"); //! respect $db
	}

	function engines() {
		return array();
	}
	
	function logged_user() {
		global $connection;
		return $connection->result("SELECT user");
	}
	
	function tables_list() {
		global $connection;
		return get_key_vals("SELECT table_name, table_type FROM information_schema.tables WHERE table_schema = current_schema() ORDER BY table_name");
	}
	
	function count_tables($databases) {
		return array(); // would require reconnect
	}

	function table_status($name = "") {
		global $connection;
		$return = array();
		$result = $connection->query("SELECT relname AS \"Name\", CASE relkind WHEN 'r' THEN '' ELSE 'view' END AS \"Engine\", pg_relation_size(oid) AS \"Data_length\", pg_total_relation_size(oid) - pg_relation_size(oid) AS \"Index_length\", pg_catalog.obj_description(oid, 'pg_class') AS \"Comment\"
FROM pg_catalog.pg_class
WHERE relkind IN ('r','v')
AND relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = current_schema())"
			. ($name != "" ? " AND relname = " . $connection->quote($name) : "")
		); //! Index_length, Auto_increment
		while ($row = $result->fetch_assoc()) {
			$return[$row["Name"]] = $row;
		}
		return ($name != "" ? $return[$name] : $return);
	}
	
	function fk_support($table_status) {
		return true;
	}
	
	function fields($table) {
		global $connection;
		$return = array();
		$table_oid = $connection->result("SELECT oid FROM pg_class WHERE relname = " . $connection->quote($table));
		$result = $connection->query("SELECT *, col_description($table_oid, ordinal_position) AS comment FROM information_schema.columns WHERE table_name = " . $connection->quote($table) . " ORDER BY ordinal_position");
		if ($result) {
			while ($row = $result->fetch_assoc()) {
				$length = $row["character_maximum_length"];
				$return[$row["column_name"]] = array(
					"field" => $row["column_name"],
					"full_type" => $row["data_type"] . ($length ? "($length)" : ""),
					"type" => $row["data_type"],
					"length" => $length,
					"default" => $row["column_default"],
					"null" => ($row["is_nullable"] == "YES"),
					"auto_increment" => eregi("^nextval\\(", $row["column_default"]),
					"on_update" => "", //!
					"collation" => $row["collation_name"],
					"privileges" => array("insert" => 1, "select" => 1, "update" => 1), //! is_updatable
					"primary" => false, //!
					"comment" => $row["comment"],
				);
			}
		}
		return $return;
	}
	
	function indexes($table, $connection2 = null) {
		global $connection;
		if (!is_object($connection2)) {
			$connection2 = $connection;
		}
		$return = array();
		$table_oid = $connection2->result("SELECT oid FROM pg_class WHERE relname = " . $connection2->quote($table));
		$columns = get_key_vals("SELECT attnum, attname FROM pg_attribute WHERE attrelid = $table_oid AND attnum > 0", $connection2);
		$result = $connection2->query("SELECT relname, indisunique, indisprimary, indkey FROM pg_index i, pg_class ci WHERE i.indrelid = $table_oid AND ci.oid = i.indexrelid");
		while ($row = $result->fetch_assoc()) {
			$return[$row["relname"]]["type"] = ($row["indisprimary"] == "t" ? "PRIMARY" : ($row["indisunique"] == "t" ? "UNIQUE" : "INDEX"));
			$return[$row["relname"]]["columns"] = array();
			foreach (explode(" ", $row["indkey"]) as $indkey) {
				$return[$row["relname"]]["columns"][] = $columns[$indkey];
			}
			$return[$row["relname"]]["lengths"] = array();
		}
		return $return;
	}
	
	function foreign_keys($table) {
		global $connection;
		$return = array();
		$result = $connection->query("SELECT tc.constraint_name, kcu.column_name, rc.update_rule AS on_update, rc.delete_rule AS on_delete, ccu.table_name AS table, ccu.column_name AS ref
FROM information_schema.table_constraints tc
LEFT JOIN information_schema.key_column_usage kcu USING (constraint_catalog, constraint_schema, constraint_name)
LEFT JOIN information_schema.referential_constraints rc USING (constraint_catalog, constraint_schema, constraint_name)
LEFT JOIN information_schema.constraint_column_usage ccu ON rc.unique_constraint_catalog = ccu.constraint_catalog AND rc.unique_constraint_schema = ccu.constraint_schema AND rc.unique_constraint_name = ccu.constraint_name
WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = " . $connection->quote($table)); //! there can be more unique_constraint_name
		while ($row = $result->fetch_assoc()) {
			$foreign_key = &$return[$row["constraint_name"]];
			if (!$foreign_key) {
				$foreign_key = $row;
			}
			$foreign_key["source"][] = $row["column_name"];
			$foreign_key["target"][] = $row["ref"];
		}
		return $return;
	}
	
	function view($name) {
		global $connection;
		return array("select" => $connection->result("SELECT pg_get_viewdef(" . $connection->quote($name) . ")"));
	}
	
	function collations() {
		//! supported in CREATE DATABASE
		return array();
	}
	
	function information_schema($db) {
		return ($db == "information_schema");
	}
	
	function error() {
		global $connection;
		$return = h($connection->error);
		if (preg_match('~^(.*\\n)?([^\\n]*)\\n( *)\\^(\\n.*)?$~s', $return, $match)) {
			$return = $match[1] . preg_replace('~((?:[^&]|&[^;]*;){' . strlen($match[3]) . '})(.*)~', '\\1<b>\\2</b>', $match[2]) . $match[4];
		}
		return nl_br($return);
	}
	
	function exact_value($val) {
		global $connection;
		return $connection->quote($val);
	}
	
	function create_database($db, $collation) {
		return queries("CREATE DATABASE " . idf_escape($db) . ($collation ? " ENCODING " . idf_escape($collation) : ""));
	}
	
	function drop_databases($databases) {
		global $connection;
		$connection->close();
		foreach ($databases as $db) {
			if (!queries("DROP DATABASE " . idf_escape($db))) {
				return false;
			}
		}
		return true;
	}
	
	function rename_database($name, $collation) {
		//! current database cannot be renamed
		return queries("ALTER DATABASE " . idf_escape(DB) . " RENAME TO " . idf_escape($name));
	}
	
	function auto_increment() {
		return "";
	}
	
	function alter_table($table, $name, $fields, $foreign, $comment, $engine, $collation, $auto_increment, $partitioning) {
		global $connection;
		$alter = array();
		$queries = array();
		foreach ($fields as $field) {
			$column = idf_escape($field[0]);
			$val = $field[1];
			if (!$val) {
				$alter[] = "DROP $column";
			} else {
				$val5 = $val[5];
				unset($val[5]);
				if (isset($val[6]) && $field[0] == "") { // auto_increment
					$val[1] = ($val[1] == "bigint" ? " big" : " ") . "serial";
				}
				if ($field[0] == "") {
					$alter[] = ($table != "" ? "ADD " : "  ") . implode($val);
				} else {
					if ($column != $val[0]) {
						$queries[] = "ALTER TABLE " . table($table) . " RENAME $column TO $val[0]";
					}
					$alter[] = "ALTER $column TYPE$val[1]";
					if (!$val[6]) {
						$alter[] = "ALTER $column " . ($val[3] ? "SET$val[3]" : "DROP DEFAULT"); //! quoting
						$alter[] = "ALTER $column " . ($val[2] == " NULL" ? "DROP NOT" : "SET") . $val[2];
					}
				}
				if ($field[0] != "" || $val5 != "") {
					$queries[] = "COMMENT ON COLUMN " . table($table) . ".$val[0] IS " . ($val5 != "" ? substr($val5, 9) : "''");
				}
			}
		}
		$alter = array_merge($alter, $foreign);
		if ($table == "") {
			array_unshift($queries, "CREATE TABLE " . table($name) . " (\n" . implode(",\n", $alter) . "\n)");
		} elseif ($alter) {
			array_unshift($queries, "ALTER TABLE " . table($table) . "\n" . implode(",\n", $alter));
		}
		if ($table != "" && $table != $name) {
			$queries[] = "ALTER TABLE " . table($table) . " RENAME TO " . table($name);
		}
		if ($table != "" || $comment != "") {
			$queries[] = "COMMENT ON TABLE " . table($name) . " IS " . $connection->quote($comment);
		}
		if ($auto_increment != "") {
			//! $queries[] = "SELECT setval(pg_get_serial_sequence(" . $connection->quote($name) . ", ), $auto_increment)";
		}
		foreach ($queries as $query) {
			if (!queries($query)) {
				return false;
			}
		}
		return true;
	}
	
	function alter_indexes($table, $alter) {
		$create = array();
		$drop = array();
		foreach ($alter as $val) {
			if ($val[0] != "INDEX") {
				$create[] = ($val[2] ? "\nDROP CONSTRAINT " : "\nADD $val[0] " . ($val[0] == "PRIMARY" ? "KEY " : "")) . $val[1];
			} elseif ($val[2]) {
				$drop[] = $val[1];
			} elseif (!queries("CREATE INDEX " . idf_escape(uniqid($table . "_")) . " ON " . table($table) . " $val[1]")) {
				return false;
			}
		}
		return ((!$create || queries("ALTER TABLE " . table($table) . implode(",", $create)))
			&& (!$drop || queries("DROP INDEX " . implode(", ", $drop)))
		);
	}
	
	function truncate_tables($tables) {
		return queries("TRUNCATE " . implode(", ", array_map('table', $tables)));
		return true;
	}
	
	function drop_views($views) {
		return queries("DROP VIEW " . implode(", ", array_map('table', $views)));
	}
	
	function drop_tables($tables) {
		return queries("DROP TABLE " . implode(", ", array_map('table', $tables)));
	}
	
	function move_tables($tables, $views, $target) {
		foreach ($tables as $table) {
			if (!queries("ALTER TABLE " . table($table) . " SET SCHEMA " . idf_escape($target))) {
				return false;
			}
		}
		foreach ($views as $table) {
			if (!queries("ALTER VIEW " . table($table) . " SET SCHEMA " . idf_escape($target))) {
				return false;
			}
		}
		return true;
	}
	
	function trigger($name) {
		global $connection;
		$result = $connection->query('SELECT trigger_name AS "Trigger", condition_timing AS "Timing", event_manipulation AS "Event", \'FOR EACH \' || action_orientation AS "Type", action_statement AS "Statement" FROM information_schema.triggers WHERE event_object_table = ' . $connection->quote($_GET["trigger"]) . ' AND trigger_name = ' . $connection->quote($name));
		return $result->fetch_assoc();
	}
	
	function triggers($table) {
		global $connection;
		$return = array();
		$result = $connection->query("SELECT * FROM information_schema.triggers WHERE event_object_table = " . $connection->quote($table));
		while ($row = $result->fetch_assoc()) {
			$return[$row["trigger_name"]] = array($row["condition_timing"], $row["event_manipulation"]);
		}
		return $return;
	}
	
	function trigger_options() {
		return array(
			"Timing" => array("BEFORE", "AFTER"),
			"Type" => array("FOR EACH ROW", "FOR EACH STATEMENT"),
		);
	}
	
	function begin() {
		return queries("BEGIN");
	}
	
	function insert_into($table, $set) {
		return queries("INSERT INTO " . table($table) . ($set ? " (" . implode(", ", array_keys($set)) . ")\nVALUES (" . implode(", ", $set) . ")" : "DEFAULT VALUES"));
	}
	
	function explain($connection, $query) {
		return $connection->query("EXPLAIN $query");
	}
	
	function schemas() {
		return get_vals("SELECT nspname FROM pg_namespace");
	}
	
	function get_schema() {
		global $connection;
		return $connection->result("SELECT current_schema()");
	}
	
	function set_schema($schema) {
		global $connection;
		return $connection->query("SET search_path TO " . idf_escape($schema));
	}
	
	function use_sql($database) {
		return "\connect " . idf_escape($database);
	}
	
	function show_variables() {
		return get_key_vals("SHOW ALL");
	}
	
	function support($feature) {
		return ereg('^(comment|view|scheme|sequence|trigger|variables|drop_col)$', $feature); //! routine|
	}
	
	$jush = "pgsql";
	$types = array();
	$structured_types = array();
	foreach (array( //! arrays
		lang('Numbers') => array("smallint" => 5, "integer" => 10, "bigint" => 19, "boolean" => 1, "numeric" => 0, "real" => 7, "double precision" => 16, "money" => 20),
		lang('Date and time') => array("date" => 13, "time" => 17, "timestamp" => 20, "interval" => 0),
		lang('Strings') => array("character" => 0, "character varying" => 0, "text" => 0, "tsquery" => 0, "tsvector" => 0, "uuid" => 0, "xml" => 0),
		lang('Binary') => array("bit" => 0, "bit varying" => 0, "bytea" => 0),
		lang('Network') => array("cidr" => 43, "inet" => 43, "macaddr" => 17, "txid_snapshot" => 0),
		lang('Geometry') => array("box" => 0, "circle" => 0, "line" => 0, "lseg" => 0, "path" => 0, "point" => 0, "polygon" => 0),
	) as $key => $val) {
		$types += $val;
		$structured_types[$key] = array_keys($val);
	}
	$unsigned = array();
	$operators = array("=", "<", ">", "<=", ">=", "!=", "~", "!~", "LIKE", "LIKE %%", "IN", "IS NULL", "NOT LIKE", "NOT IN", "IS NOT NULL");
	$functions = array("char_length", "lower", "round", "to_hex", "to_timestamp", "upper");
	$grouping = array("avg", "count", "count distinct", "max", "min", "sum");
	$edit_functions = array(
		array(
			"char" => "md5",
			"date|time" => "now",
		), array(
			"int|numeric|real|money" => "+/-",
			"date|time" => "+ interval/- interval", //! escape
			"char|text" => "||",
		)
	);
}
