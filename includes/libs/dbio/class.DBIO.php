<?php
/**
 * Created by PhpStorm.
 * User: gheinemann
 * Date: 03/02/2015
 * Time: 09:31
 */
namespace lib\dbio {

	use core\application\rewriteurl\RewriteURLHandler;
	use core\data\SimpleJSON;
	use core\db\Query;
	use Exception;
	use lib\dbio\DBIO_Source;
	use lib\dbio\DBIO_Source_Manager;

	class DBIO {
		const STATE_INIT = "STATE_INIT";
		const STATE_SETUP = "STATE_SETUP";
		const STATE_READY = "STATE_READY";
		const STATE_PROCESSING = "STATE_PROCESSING";
		const STATE_STOPPED = "STATE_STOPPED";
		const RESULT_ERROR = "dbio_result_error";
		const RESULT_VALID = "dbio_result_valid";
		const RESULT_END = "dbio_result_end";

		private $config;
		private $current_state;

		private $config_directory;
		private $source_manager;
		public $report;
		private $jobs;
		private $jobs_pool;

		private $default_max_operations = 0;
		private $default_offset_from_start = 0;
		private $default_max_tuple_per_insert = 1000;
		private $default_lock_tables = false;
		private $default_stop_on_error = false;


		public function __construct($pName) {
			// parse config file
			$this->config_directory = 'files/dbio/';
			$full_path = $this->config_directory.$pName.'.json';

			try
			{
				$this->config = SimpleJSON::import($full_path);
			}
			catch (Exception $e)
			{
				throw new Exception("<b>DBIO</b> config : La config <b>".$pName."</b> est introuvable");
			}
			if(!$this->config)
				throw new Exception("<b>DBIO</b> config : Impossible de parser le fichier de déclaration <b>".$pName."</b>, veuillez vérifier le formatage des données (guillements, virgules, accents...).");


			// set params
//			if (!empty($this->config['params'])) {
//				foreach ($this->config['params'] as $param => $value) {
//					if (property_exists($this, $param)) {
//						$this->{$param} = $value;
//					}
//				}
//			}

			// set jobs from config
			if(!$this->config['jobs'] || empty($this->config['jobs']))
				throw new Exception("<b>DBIO</b> config : Aucuns jobs spécifiés dans le fichier de config <b>".$pName.".json</b>.");

			$this->source_manager = new DBIO_Source_Manager();

			$this->jobs = array();
			$this->jobs_pool = array();

			// loop on jobs to register them
			foreach ($this->config['jobs'] as $id_job => $raw_job) {
				$tmp_job = array();
				$params = array(
					"max_operations" => $this->default_max_operations,
					"offset_from_start" => $this->default_offset_from_start,
					"max_tuple_per_insert" => $this->default_max_tuple_per_insert,
					"lock_tables" => $this->default_lock_tables,
					"stop_on_error" => $this->default_stop_on_error,
				);

				if (array_key_exists('params', $raw_job) && count($raw_job['params']) > 0
					&& array_key_exists('max_operations', $raw_job['params']) && !empty($raw_job['params']['max_operations'])) {
					foreach ($params as $param_name => &$param_value) {
						if (array_key_exists($param_name, $raw_job['params']) && (!empty($raw_job['params'][$param_name])
								|| 0 === $raw_job['params'][$param_name])) {
							$param_value = $raw_job['params'][$param_name];
						}
					}
				} else {
					trigger_error("<b>DBIO</b> config : le job <b>$id_job</b> doit au moins avoir le paramètre <b>max_operations</b> renseigné.", E_USER_WARNING);
					continue;
				}

				// set per/job params
				$tmp_job['params'] = $params;

				// loop on sources
				if (array_key_exists('sources', $raw_job) && count($raw_job['sources']) > 0) {
					$tmp_max_tuples = 0;
					foreach ($raw_job['sources'] as $alias_source => $source_params) {
						// create non-existent source
						try {
							if ($this->source_manager->addSource($alias_source, $source_params)) {
								// set source to job
								$tmp_job['sources'][$alias_source] = $this->source_manager->getSourceById($alias_source);

								// update source $tmp_max_tuples to adjust max_operations
								$tmp_tupleNumber = $tmp_job['sources'][$alias_source]->getTupleNumber();
								if ($tmp_max_tuples < $tmp_tupleNumber) {
									$tmp_max_tuples = $tmp_tupleNumber;
								}

								// if max source tuple number < max_operations, rectify max_operations
								if ($tmp_max_tuples < $tmp_job['params']['max_operations']) {
									$tmp_job['params']['max_operations'] = $tmp_max_tuples;
								}
							} else {

							}
						} catch (Exception $e) {

						}
					}
				} else {
					throw new Exception("<b>DBIO</b> config : Aucunes sources spécifiées pour le job <b>".$id_job.
						"</b> dans le fichier de config <b>".$pName.".json</b>.");
				}

				// set target source to job (check if isValidTarget())
				if (array_key_exists('target', $raw_job) && !empty($raw_job['target'])) {
					$this->source_manager->addTargetSource($raw_job['target'], array('table' => $raw_job['target']));

					if ($this->source_manager->existsTargetSource($raw_job['target'])) {
						$tmp_job['target'] = $this->source_manager->getTargetSourceById($raw_job['target']);

						// if max_tuple_per_insert x avg tuple size > DB max_allowed_packet,
						// redefine max_tuple_per_insert to 80% of DB max_allowed_packet / avg tuple size
						if ($tmp_job['params']['max_tuple_per_insert'] > $tmp_job['target']->max_insert_size) {
							$tmp_job['params']['max_tuple_per_insert'] = floor($tmp_job['target']->max_insert_size * 0.8);
						}
					} else {
						throw new Exception("<b>DBIO</b> config : La source cible ".$raw_job['target']." n'existe pas.");
					}
				} else {
					throw new Exception("<b>DBIO</b> config : Aucune target spécifiée pour le job <b>".$id_job.
						"</b> dans le fichier de config <b>".$pName.".json</b>.");
				}


				// define jobs into jobs pool
				$this->jobs_pool = array_merge($this->jobs_pool, $this->splitJobIntoJobspool($id_job, $tmp_job['params']));


				// set fields to job
				if (array_key_exists('fields', $raw_job) && !empty($raw_job['fields'])) {
					$tmp_job['fields'] = $raw_job['fields'];
				} else {
					throw new Exception("<b>DBIO</b> config : Aucuns champs spécifiés pour le job <b>".$id_job.
						"</b> dans le fichier de config <b>".$pName.".json</b>.");
				}

				// add job to jobs
				$this->jobs[$id_job] = $tmp_job;
			}

//			trace_r($this->jobs_pool);

			// create report
			$this->report = new DBIO_Report();

			return $this;
		}

		public function executeJob($pJobId, $pStartOffset, $pEndOffset) {
			$result = null;
			$result_type = null;
			$result_msg = null;
			$sources = array();
			$mapped_tuples = array();
			$processed_sql = "";

			if ($this->existsJob($pJobId) && $job = $this->getJobById($pJobId)) {
				$mapping = new DBIO_Mapping($job['fields']);
				$select_tuples_number = 0;
				// TODO v2 : executeJob how to manage limit/number of tuples in return with multiple sources ?

				// REPORT : increment insert number
				$this->report->incrementInsertNumber();
				// loop on sources to get tuples in mapping pool
				foreach ($job['sources'] as $source_alias => $source) {
					// sub query trick to speed up MYSQL SELECT with big OFFSET in a large TABLE
					$sub_query = '('.Query::select($source->getTableId(), $source->getTableName())
							->limit($pStartOffset, $job['params']['max_tuple_per_insert'])
							->get(false).') as lim USING('.$source->getTableId().')';
					$select_query = $source->getSelectQuery()
						->join($sub_query, Query::JOIN_INNER);

					$tmp_tuples = $select_query->execute($source->getDbHandler());

					$select_tuples_number = count($tmp_tuples);

					$sources[$source_alias] = array(
						"sql" => $select_query->get(),
						"results" => $select_tuples_number,
						"tuples" => $tmp_tuples
					);
					// REPORT : increment queried tuples
					$this->report->incrementRawTupleNumber($select_tuples_number);

					$mapping->addSourceTuples($source_alias, $tmp_tuples);
				}
				// return mapped tuples
				$final_tuple_number = $job['params']['max_tuple_per_insert'] > $select_tuples_number ? $select_tuples_number : $job['params']['max_tuple_per_insert'];
				$mapped_tuples = $mapping->getMappedTuples($final_tuple_number);

				if ($final_tuple_number > 0 && count($mapped_tuples)) {
					// inject tuples into destination source
					$result_type = null;
					$target_source = $job['target'];
					$result_insert = $target_source->insertTuples($mapped_tuples);
					$processed_sql = $result_insert['sql'];

					if ($result_insert['return'] !== false && $result_insert['return'] !== null) {
						$result_type = self::RESULT_VALID;
						$result_msg = "L'insertion des entrées pour le job $pJobId ($pStartOffset-$pEndOffset) a été effectuée.";


					} else {
						$result_type = self::RESULT_ERROR;
						if (!empty($result_insert['error'])) {
							$result_msg = $result_insert['error'];
						} else {
							$result_msg = "L'insertion des entrées pour le job $pJobId ($pStartOffset-$pEndOffset) a échoué.";
						}

						// REPORT : increment insert errors
						$this->report->addError($result_msg, $mapped_tuples, $processed_sql);
					}
				} else {
					$result_type = self::RESULT_ERROR;
					$result_msg = "Aucunes entrées à insérer n'ont pu être récupérées pour le job $pJobId ($pStartOffset-$pEndOffset)
					\n=> SELECT ne retourne rien OU un champ 'mandatory' a une valeur vide ou NULL ou 0 OU un champ
					n'est pas disponible dans une source";
					foreach($sources as $source_alias => $source) {
						$processed_sql .= $source['sql']."\n";
					}

				}

				// REPORT : count job tuples
				$this->report->incrementTupleNumber($final_tuple_number);

			} else {
				trigger_error("<b>DBIO</b> executeJob: Aucun job nommé <b>$pJobId</b> n'a été trouvé", E_USER_WARNING);

				$result_type = self::RESULT_ERROR;
				$result_msg = "Aucun job nommé $pJobId n'a été trouvé.";
			}

			$result = array(
				"type" => $result_type,
				"msg" => $result_msg,
				"sources" => $sources,
				"sql" => $processed_sql,
				"mapped_tuples" => $mapped_tuples
			);

			return $result;
		}

		public function generateCustomConfig(array $pJobs){
			$custom_config = array();

			// loop over jobs including only specified in param
				// report sources
				// report target
				// report fields
			if (!empty($this->config['jobs']) && !empty($pJobs)) {
				$custom_config['jobs'] = array();

				foreach ($this->config['jobs'] as $job_id => $job) {
					if (array_key_exists($job_id, $pJobs)) {
						$custom_config['jobs'][$job_id] = $job;

						// if job has no params in original config, define to defaults
						if (!array_key_exists('params', $custom_config['jobs'][$job_id])
							|| empty($custom_config['jobs'][$job_id]['params'])) {
							$custom_config['jobs'][$job_id]['params'] = array(
								"max_operations" => $this->default_max_operations,
								"offset_from_start" => $this->default_offset_from_start,
								"max_tuple_per_insert" => ceil($this->default_max_tuple_per_insert / 2),
								"lock_tables" => $this->default_lock_tables,
								"stop_on_error" => $this->default_stop_on_error,
							);
						}

						// loop over job params and override default when specified in parameters
						if (array_key_exists('params', $pJobs[$job_id]) && !empty($pJobs[$job_id]['params'])) {
							// loop on custom config params
							foreach ($pJobs[$job_id]['params'] as $param => $value) {
								$redefine_flag = false;
								$tmp_value = $value;

								// if custom config params is valid, define it as final param
								switch ($param) {
									case 'max_tuple_per_insert':
										$tmp_value = intval($tmp_value);
										if ($tmp_value > 0) {
											$tmp_value = ceil($tmp_value / 2);
											$redefine_flag = true;
										}
										break;
									case 'max_operations':
									case 'offset_from_start':
										$tmp_value = intval($tmp_value);
										$redefine_flag = true;
										break;
									case 'lock_tables':
									case 'stop_on_error':
										$tmp_value = $tmp_value === 'true';
										$redefine_flag = true;
										break;
								}

								if ($redefine_flag === true) {
									$custom_config['jobs'][$job_id]['params'][$param] = $tmp_value;
								}
							}
						}
					}
				}

				if (empty($custom_config['jobs'])) {
					throw new Exception("<b>DBIO</b> generateCustomConfig : Aucuns jobs spécifiés dans les paramètres.");
				}
			} else {
				throw new Exception("<b>DBIO</b> generateCustomConfig : Aucuns jobs trouvés.");
			}

			// return new config
			return $custom_config;
		}

		public function existsJob($pJobId) {
			return array_key_exists($pJobId, $this->jobs);
		}
		public function getJobById($pJobId) {
			return $this->jobs[$pJobId];
		}

		public function executeAllJobs() {
			$returns = array();

			// REPORT: set start time
			$this->report->setStartTime();

			if (count($this->jobs_pool) > 0) {
				foreach ($this->jobs_pool as $i => $jobParams) {
					$returns[$jobParams["job_id"].'/'.$jobParams["offset_from_start"].'/'.$jobParams["offset_end"]] =
						$this->executeJob($jobParams["job_id"], $jobParams["offset_from_start"], $jobParams["offset_end"]);
				}

			} else {
				// no jobs
			}

			// REPORT: set end time
			$this->report->setEndTime();

			return $returns;
		}

		private function splitJobIntoJobspool($pJobId, array $pParams){
			$job_pool = array();

			$tuples_number = $pParams['max_operations'] - 1;
			$offset_from_start = $pParams['offset_from_start'];
			$offset_end = $offset_from_start + $pParams['max_tuple_per_insert'] - 1;
			$end_target = $offset_from_start + $tuples_number;

			// if $offset_end goes beyond tuple number, $offset_end becomes $end_target
			if ($offset_end > $end_target) {
				$offset_end = $end_target;
			}

			// add job to pool as long as we do not reach $end_target
			do {
//				trace("offset_from_start: $offset_from_start");
//				trace("offset_end: $offset_end");
//				trace("calculated offset_from_start: ".($offset_from_start + $pParams['max_tuple_per_insert'] - 1));
//				trace("end_target: $end_target");
//				trace("-----------------------");
				$job_pool[$pJobId.'/'.$offset_from_start.'/'.$offset_end] = array(
					"job_id" => $pJobId,
					"offset_from_start" => $offset_from_start,
					"offset_end" => $offset_end,
					"max_tuple_per_insert" => $pParams['max_tuple_per_insert'],
					"max_operations" => $pParams['max_operations'],
					"executed" => false
				);


				// if next $offset_from_start is greater than $end_target, define it at $end_target
				if ($offset_from_start + $pParams['max_tuple_per_insert'] > $end_target) {
					$offset_from_start = $end_target;
				} else {
					$offset_from_start = $offset_from_start + $pParams['max_tuple_per_insert'];
					$offset_end = $offset_from_start + $pParams['max_tuple_per_insert'] - 1;

					if ($offset_end > $end_target) {
						$offset_end = $end_target;
					}
				}

			} while ($offset_from_start !== $end_target);
			return $job_pool;
		}

		public function getDetails() {
			if ($this->current_state === self::STATE_INIT || $this->current_state === self::STATE_SETUP) {
				trigger_error("<b>DBIO</b> getDetails: cette méthode ne peut être invoquée que lorsque le statut est différent de <b>STATE_INIT</b> ou <b>STATE_SETUP</b>", E_USER_ERROR);
			}
			return array(
				"jobs" => $this->jobs,
				"jobs_pool" => $this->jobs_pool
//				"params" => array(
//					"max_operations" => $this->max_operations,
//					"offset_from_start" => $this->offset_from_start,
//					"max_tuple_per_insert" => $this->max_tuple_per_insert,
//					"lock_tables" => $this->lock_tables,
//					"stop_on_error" => $this->stop_on_error,
//				)
			);
		}

		public function getReport() {
//			if ($this->current_state !== self::STATE_STOPPED) {
//				trigger_error("<b>DBIO</b> getReport: cette méthode ne peut être invoquée que lorsque le statut est <b>STATE_STOPPED</b>", E_USER_ERROR);
//			}

			return $this->report->getDatas();
		}
	}


}
