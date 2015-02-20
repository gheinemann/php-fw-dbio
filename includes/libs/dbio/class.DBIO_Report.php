<?php
/**
 * Created by PhpStorm.
 * User: gheinemann
 * Date: 04/02/2015
 * Time: 16:33
 */
namespace lib\dbio {

	use DateTime;

	class DBIO_Report {
		private $start_time;
		private $end_time;
		private $raw_tuple_number;
		private $tuple_number;
		private $insert_number;
		private $errors;

		function __construct() {
			$this->start_time = microtime(true);
			$this->end_time = null;
			$this->raw_tuple_number = 0;
			$this->tuple_number = 0;
			$this->insert_number = 0;
			$this->tuples = array();
			$this->errors = array();

			return $this;
		}

		/**
		 * @return array
		 */
		public function getDatas() {
			if ($this->getEndTime() == null) {
				$this->setEndTime(microtime(true));
			}
			return array(
				"start_time" => $this->microToTimeString($this->getStartTime()),
				"end_time" => $this->microToTimeString($this->getEndTime()),
				"total_time" => $this->getTotalTime(),
				"tuple_number" => $this->getTupleNumber(),
				"insert_number" => $this->getInsertNumber(),
				"successfull_insert_number" => $this->getSuccessfullInsertNumber(),
				"errors" => $this->getErrors(),
			);
		}

		/**
		 * @return mixed
		 */
		public function getStartTime() {
			return $this->start_time;
		}

		/**
		 * @param mixed $start_time
		 */
		public function setStartTime($start_time = null) {
			if ($start_time === null) {
				$start_time = microtime(true);
			}
			if (is_float($start_time)) {
				$this->start_time = $start_time;
			}
		}

		/**
		 * @return mixed
		 */
		public function getEndTime() {
			return $this->end_time;
		}

		/**
		 * @param mixed $end_time
		 */
		public function setEndTime($end_time = null) {
			if ($end_time === null) {
				$end_time = microtime(true);
			}
			if (is_float($end_time)) {
				$this->end_time = $end_time;
			}
		}


		/**
		 * @return mixed
		 */
		public function getTupleNumber() {
			return $this->tuple_number;
		}

		/**
		 * @return int
		 */
		public function getRawTupleNumber() {
			return $this->raw_tuple_number;
		}

		/**
		 * @param int $raw_tuple_number
		 */
		public function setRawTupleNumber($raw_tuple_number) {
			if (is_int($raw_tuple_number)) {
				$this->raw_tuple_number = $raw_tuple_number;
			}
		}

		public function incrementRawTupleNumber($pNumber = 1) {
			if (is_int($pNumber) && $pNumber > 0) {
				$this->raw_tuple_number = $this->raw_tuple_number + $pNumber;
			}
		}

		/**
		 * @param mixed $tuple_number
		 */
		public function setTupleNumber($tuple_number) {
			if (is_int($tuple_number)) {
				$this->tuple_number = $tuple_number;
			}
		}

		public function incrementTupleNumber($pNumber = 1) {
			if (is_int($pNumber) && $pNumber > 0) {
				$this->tuple_number = $this->tuple_number + $pNumber;
			}
		}

		/**
		 * @return int
		 */
		public function getInsertNumber() {
			return $this->insert_number;
		}

		/**
		 * @param mixed $insert_number
		 */
		public function setInsertNumber($insert_number) {
			if (is_int($insert_number)) {
				$this->insert_number = $insert_number;
			}
		}

		public function incrementInsertNumber($pNumber = 1) {
			if (is_int($pNumber) && $pNumber > 0) {
				$this->insert_number = $this->insert_number + $pNumber;
			}
		}

		/**
		 * @return mixed
		 */
		public function getErrors() {
			return $this->errors;
		}

		/**
		 * @param mixed $errors
		 */
		public function addError($pMessage, array $pTuples, $pSql = "") {
			$this->errors[] = array(
				"msg" => $pMessage,
				"tuples" => $pTuples,
				"sql" => $pSql
			);
		}

		public function setTuples(array $ptuples){
			$this->tuples = $ptuples;
		}
		public function getTuples(){
			return $this->tuples;
		}

		/**
		 * @return bool|int
		 */
		public function getSuccessfullInsertNumber() {
			if ($this->insert_number == 0 || count($this->errors) > $this->insert_number) {
				return 0;
			}

			return $this->insert_number - count($this->errors);
		}

		/**
		 * @return \DateTime
		 */
		public function getTotalTime() {
			if ($this->end_time == null) {
				$this->setEndTime(microtime(true));
			}
			return $this->getEndTime() - $this->getStartTime();
		}

		private function microToTimeString($pMicrotime) {
			$micro = sprintf("%06d",($pMicrotime - floor($pMicrotime)) * 1000000);
			$datetime =  new DateTime(date('H:i:s.'.$micro, $pMicrotime));
			return $datetime->format("H:i:s.u");

		}
	}
}
