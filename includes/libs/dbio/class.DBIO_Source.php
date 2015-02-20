<?php
/**
 * Created by PhpStorm.
 * User: gheinemann
 * Date: 04/02/2015
 * Time: 09:38
 */
namespace lib\dbio {

	use core\application\Configuration;
	use core\db\DBManager;
	use core\db\Query;
	use Exception;

	class DBIO_Source {
		private $select_query;
		private $db_handler;
		private $db_name;
		private $table_name;
		private $table_id;
		private $return_fields;
		private $joins;
		private $conditions;

		public $tuples_nb;
		private $is_reachable;
		private $is_writable;

		public $max_allowed_packet;
		public $innodb_table_locks;
		public $first_tuple_size;
		public $max_insert_size;
		public $max_connections;

		function __construct($pTable, $pId, $pFields = "*", $pJoins = array(), $pConditions = array()) {
			$tmpInfos = explode('.', $pTable);
			$this->db_handler = $tmpInfos[0];
			$this->table_name = $tmpInfos[1];
			$this->table_id = $pId;

			if (!isset(Configuration::$db[$this->db_handler]) || empty(Configuration::$db[$this->db_handler])) {
				throw new Exception("<b>DBIO_Source</b>: aucun handler <b>".$this->db_handler."</b> trouvé dans la config de l'application.");
			}
			if (empty($this->table_name)) {
				throw new Exception("<b>DBIO_Source</b>: aucun nom de table specifié.");
			}

			$this->db_name = Configuration::$db[$this->db_handler]['name'];
			$this->is_writable = Configuration::$db[$this->db_handler]['writable'];

			if ($result = Query::execute('SELECT version();', $this->db_handler)) {
				$this->is_reachable = count($result) > 0 && !empty($result[0]['version()']);
			}

			if ($this->is_reachable) {
				$sql_tables = Query::execute("SHOW TABLES LIKE '{$this->table_name}'", $this->db_handler);
				if (count($sql_tables) == 0 || !array_key_exists('Tables_in_'.$this->db_name.' ('.$this->table_name.')', $sql_tables[0]) || $this->table_name !== $sql_tables[0]['Tables_in_'.$this->db_name.' ('.$this->table_name.')']) {
					$this->is_reachable = false;
				}

				$max_packet = Query::execute("SHOW VARIABLES LIKE 'max_allowed_packet'", $this->db_handler);
				if (count($max_packet) == 0 || !array_key_exists('Value', $max_packet[0]) || !empty($max_packet[0]['Value'])) {
					$this->max_allowed_packet = intval($max_packet[0]['Value']);
				}
				$innodb_table_locks = Query::execute("SHOW VARIABLES LIKE 'innodb_table_locks'", $this->db_handler);
				if (count($innodb_table_locks) == 0 || !array_key_exists('Value', $innodb_table_locks[0]) || !empty($innodb_table_locks[0]['Value'])) {
					$this->innodb_table_locks = $innodb_table_locks[0]['Value'] === 'ON';
				}
				$max_connections = Query::execute("SHOW VARIABLES LIKE 'max_connections'", $this->db_handler);
				if (count($max_connections) == 0 || !array_key_exists('Value', $max_connections[0]) || !empty($max_connections[0]['Value'])) {
					$this->max_connections = $max_connections[0]['Value'] === 'ON';
				}
			}

			// create query
			$this->return_fields = !empty($pFields) ? $pFields : '*';
			$this->count_query = Query::select('count(1) as nb', $this->table_name);
			$this->select_query = Query::select($this->return_fields, $this->table_name);


			if (is_array($pJoins) && count($pJoins) > 0) {
				// TODO v2 : validate joins
				$this->joins = $pJoins;
				// TODO v2 : add joins to query
				foreach ($this->joins as $joinParameters) {
					call_user_func_array(array($this->select_query, 'join'), $joinParameters);
				}

			}
			if (is_array($pConditions) && count($pConditions) > 0) {
				// TODO v2 : validate conditions
				$this->conditions = $pConditions;
				// TODO v2 : set conditions to query

				if (count($this->conditions > 0 && !empty($this->conditions))) {
					$main_cond = Query::condition();
					// gather conditions into one...
					foreach ($this->conditions as $condition) {
						foreach($condition as $method => $parameters) {
							call_user_func_array(array($main_cond, $method), $parameters);
						}
					}

					//... and apply it to select
					$this->select_query->setCondition($main_cond);
				}
			}

			if ($this->is_reachable) {
				// set tuple number
				$result = $this->count_query->execute($this->db_handler);

				if (count($result) > 0 && isset($result[0]['nb']) && !empty($result[0]['nb'])) {
					$this->tuples_nb = intval($result[0]['nb']);
				} else {
					$this->tuples_nb = 0;
				}

				// set first tuple size
				$tmp_squery = clone $this->select_query;
				$tmp_squery->limit(0, 1);

				$start = memory_get_usage();
				$tmp_sql_results = $tmp_squery->execute($this->db_handler);
				$end = memory_get_usage();

				$this->first_tuple_size = $end - $start;

				if ($this->first_tuple_size > 0) {
					$this->max_insert_size = floor($this->max_allowed_packet / $this->first_tuple_size);
				} else {
					$this->max_insert_size = 0;
				}
			}

			return $this;
		}

		public function getTupleNumber() {
			return $this->tuples_nb;
		}

		/**
		 * @return string
		 */
		public function getTableId() {
			return $this->table_id;
		}



		public function insertTuples(array $pTuples) {
			$insert_query = Query::insertMultiple($pTuples)->into($this->table_name);
			$sql = "";
			$result = true;
			$error_msg = "";
			$error = "";
			$error_nb = "";

			if (empty($pTuples)) {
				trigger_error("<b>DBIO_Source</b> insertTuples : <b>pTuples</b> est vide", E_USER_WARNING);
				$sql = $this->select_query->get();
				$error_nb = "insertTuples";
				$error = "<b>pTuples</b> est vide";
				$result = false;
			} elseif(!$this->isValidTarget()) {
				trigger_error("<b>DBIO_Source</b> insertTuples : la source n'est pas une cible valide", E_USER_WARNING);
				$error_nb = "insertTuples";
				$error = "la source n'est pas une cible valide";
				$result = false;
			} else {
				$sql = $insert_query->get();
				$result	= $insert_query->execute($this->db_handler);

				$error = DBManager::get($this->db_handler)->getError();
				$error_nb = DBManager::get($this->db_handler)->getErrorNumber();
			}

			if (!empty($error) && !empty($error_nb)) {
				$error_msg = $error_nb.' - '.$error;
			}

			return array(
				"sql" => $sql,
				"error" => $error_msg,
				"return" => $result
			);
		}

		public function isWritable() {
			return $this->is_writable;
		}
		public function isReachable() {
			return $this->is_reachable;
		}

		private function isValid() {
			return $this->is_reachable;
		}

		public function isValidTarget() {
			return $this->isValid() && $this->isWritable();
		}

		public function toArray() {
			return array(
				"db_handler" => $this->db_handler,
				"db_name" => $this->db_name,
				"table_name" => $this->table_name,
				"max_allowed_packet" => $this->max_allowed_packet,
				"innodb_table_locks" => $this->innodb_table_locks,
				"select_query" => $this->select_query->get(),
				"count_query" => $this->count_query->get(),
				"is_reachable" => $this->is_reachable,
				"is_writable" => $this->is_writable,
				"is_valid_source" => $this->isValid(),
				"is_valid_destination" => $this->isValidTarget()
			);
		}

		public function getDbHandler() {
			return $this->db_handler;
		}
		public function getDbName() {
			return $this->db_name;
		}
		public function getTableName() {
			return $this->table_name;
		}
		public function getSelectQuery() {
			return $this->select_query;
		}
	}
}
