<?php
namespace lib\dbio;
{
	use core\application\Autoload;
	use core\application\BackController;
	use core\application\Go;
	use core\application\Core;
	use core\application\Header;
	use core\application\rewriteurl\RewriteURLHandler;
	use core\system\File;
	use core\tools\Menu;
	use lib\dbio\DBIO;

	class DbioController extends BackController
	{
		private $config_file_name;

		public function __construct() {
			parent::__construct();
			$this->config_file_name = 'config-sample';
		}


		public function index() {
			Go::toBack($this->className, 'board');
		}

		public function board()
		{
			$menu = new Menu(Core::$path_to_application.'/modules/back/menu.json');

			if (Core::checkRequiredGetVars('config_file_name')) {
				$this->config_file_name = $_GET['config_file_name'];
			}

			$dbio = new DBIO($this->config_file_name);

			$dbio_details = $dbio->getDetails();
			$dbio_details['config_file_name'] = $this->config_file_name;
			$dbio_details['urls'] = array(
				"process_job" => RewriteURLHandler::rewrite($this->className, 'async_process_job'),
				"generate_config" => RewriteURLHandler::rewrite($this->className, 'generate_config')
			);
			$this->addContent('dbio', $dbio_details);
//			trace_r($dbio_details);

			Autoload::addScript('DBIO');
			Autoload::addStyle('/includes/components/dbio/dbio.css', false);
			$this->setTemplate('import', 'index');
			$this->setTitle($this->titles->get('dbio_board'));
			$this->addContent("h1", $this->h1->get('dbio_board'));
		}

		public function generate_config() {
			if (!isset($_POST['config_file_name']) || !isset($_POST['target_jobs'])
				|| empty($_POST['config_file_name']) || empty($_POST['target_jobs'])) {
				Core::performResponse('Impossible de créer la config sans le nom de la config de référence
				 (config_file_name) ou les jobs ciblés (target_jobs:{"xxx":{...}, "yyy":{...}})');
			}


			$dbio = new DBIO($_POST['config_file_name']);
			$jobs = json_decode($_POST['target_jobs'], true);

			$new_config = $dbio->generateCustomConfig($jobs);

			if (array_key_exists('jobs', $new_config) && !empty($new_config['jobs'])) {
				$file_name = $_POST['config_file_name'].'-'.join('-', array_keys($jobs)).'_'.date('Y-m-d').'.json';

				File::download($file_name, json_encode($new_config));
			} else {
				Core::performResponse('Config inexistante pour le(s) job(s) demandé(s), selon les paramètres précisés.');
			}
		}

		public function async_process_job() {
			if (!Core::$request_async) {
				Go::to404();
			}

			$error_flag = false;
			$data = array();
			$async_type = DBIO::RESULT_ERROR;
			$async_msg = "";
			$http_status = "200";

			if (!isset($_POST['dbio_job_details']) || empty($_POST['dbio_job_details'])) {
				$error_flag = true;
				$async_msg = "Aucun détail de job n'a été fourni ('dbio_job_details').";
			}

			list($job_id, $offset_start, $offset_end) = explode('/', $_POST['dbio_job_details']);

			$offset_start = intval($offset_start);
			$offset_end = intval($offset_end);

			if ($error_flag && empty($job_id) || (empty($offset_end) && 0 !== $offset_end) || (empty($offset_start) && 0 !== $offset_start)) {
				$error_flag = true;
				$async_msg = "Une donnée est manquante ou erronée dans le détail du job (job_id, offset_start, offset_end).";
			}

			if (!$error_flag) {
				$dbio = new DBIO($this->config_file_name);
				$dbio->report->setStartTime();
				$data = $dbio->executeJob($job_id, $offset_start, $offset_end);
				$dbio->report->setEndTime();
				$data['report'] = $dbio->report->getDatas();
			} else {
				$http_status = "400";
				$data = array(
					"type" => $async_type,
					"msg" => $async_msg
				);
			}

			Header::status($http_status);
			Core::performResponse(json_encode($data), 'json');
		}

	}
}