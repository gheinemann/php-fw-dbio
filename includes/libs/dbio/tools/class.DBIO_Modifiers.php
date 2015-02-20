<?php
/**
 * Created by PhpStorm.
 * User: gheinemann
 * Date: 12/02/2015
 * Time: 16:33
 */
namespace lib\dbio\tools {

	class DBIO_Modifiers {
		public static function zeroToNull($pVal){
			if ($pVal === 0 || $pVal === '0') {
				$pVal = 'NULL';
			}

			return $pVal;
		}

		public static function toNull($pVal){
			return 'NULL';
		}
	}
}
