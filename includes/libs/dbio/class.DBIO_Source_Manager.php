<?php
/**
 * Created by PhpStorm.
 * User: gheinemann
 * Date: 06/02/2015
 * Time: 15:11
 */
namespace lib\dbio {

	use Exception;

	class DBIO_Source_Manager {
		const SOURCE_TARGET = 'target';
		private $sources;

		public function __construct() {
			$this->sources = array();
		}

		/**
		 * @return mixed
		 */
		public function getSources() {
			return $this->sources;
		}

		public function toArray() {
			$result = array();

			foreach ($this->sources as $source_alias => $source) {
				$result[] = array(
					"alias" => $source_alias,
					"db_handler" => $source->getDbHandler(),
					"db_name" => $source->getDbName(),
					"table_name" => $source->getTableName(),
					"sql" => $source->getSelectQuery()->get(),
					"tuple_nb" => $source->tuples_nb,
					"is_reachable" => $source->isReachable(),
					"is_writable" => $source->isWritable(),
				);
			}

			return $result;
		}

		public function getSourceById($pSourceId) {
			if (!$this->existsSource($pSourceId)) {
				return null;
			}
			return $this->sources[$pSourceId];
		}

		public function addSource($pSourceId, array $pParams) {
			if (!$this->existsSource($pSourceId)) {
				if (!array_key_exists('table', $pParams)) {
					throw new Exception("<b>DBIO_Source_Manager</b> addSource : Aucune table n'a été spécifiée dans la config pour la source $pSourceId.");
				}

				if (!array_key_exists('id', $pParams)) {
					throw new Exception("<b>DBIO_Source_Manager</b> addSource : Aucun id pour la table {$pParams['table']} n'a été spécifiée dans la config pour la source $pSourceId.");
				}


				$this->sources[$pSourceId] = new DBIO_Source($pParams['table'], $pParams['id'], $pParams['fields'], $pParams['joins'], $pParams['conditions']);
			} elseif($pSourceId !== self::SOURCE_TARGET) {
				trigger_error("<b>DBIO_Source_Manager</b> addSource : Une source avec l'id <b>$pSourceId</b> existe déjà.", E_USER_NOTICE);
			}

			return true;
		}

		public function existsSource($pSourceId) {
			return array_key_exists($pSourceId, $this->sources);
		}

		public function existsTargetSource($pSourceId) {
			return array_key_exists(self::SOURCE_TARGET.'_'.$pSourceId, $this->sources);
		}

		public function addTargetSource($pSourceId, array $pParams) {
			$pParams['id'] = "";
			return $this->addSource(self::SOURCE_TARGET.'_'.$pSourceId, $pParams);
		}

		public function getTargetSourceById($pSourceId) {
			return $this->getSourceById(self::SOURCE_TARGET.'_'.$pSourceId);
		}
	}
}
