<?php
/**
 * Created by PhpStorm.
 * User: gheinemann
 * Date: 04/02/2015
 * Time: 16:41
 */
namespace lib\dbio {

	use core\db\Query;

	class DBIO_Mapping {
		private $source_tuples;
		private $fields;

		public function __construct(array $pField_mappings) {
			$this->source_tuples = array();

			// loop through $field_mappings
			$this->fields = $pField_mappings;
		}

		public function addSourceTuples($pSourceAlias, $pTuples) {
			$this->source_tuples[$pSourceAlias] = $pTuples;
		}

		public function getSourceValue($pSourceId, $pTupleIndex, $pSourceField) {
			return $this->source_tuples[$pSourceId][$pTupleIndex][$pSourceField];
		}


		public function getMappedField($pIndex, $pFieldId, array $pFieldMap) {
			// checks if field id given
			if (!empty($pFieldId) && array_key_exists('source_field', $pFieldMap) && !empty($pFieldMap['source_field'])) {
				if (preg_match('/%new%/', $pFieldMap['source_field']) !== 1) {
					$tmp_field_desc = explode('.', $pFieldMap['source_field']);

					$final_value = $this->getSourceValue($tmp_field_desc[0], $pIndex, $tmp_field_desc[1]);
				} else {
					$final_value = "";
				}

				if ($field = $this->fields[$pFieldId]) {
					$result = array();
					// assign default value if value is really empty, and if default value is defined
					if (empty($final_value) && 0 !== $final_value && '0' !== $final_value
						&& array_key_exists('default_value', $pFieldMap) && !empty($pFieldMap['default_value'])) {
						// if value empty and field is mandatory, exclude field (usually for primary indexes, gives an insert with incremented key)
						if (array_key_exists('mandatory_value', $pFieldMap) && $pFieldMap['mandatory_value'] === true) {
							return null;
						}

						$final_value = $pFieldMap['default_value'];
					}

					// apply modifiers to final value
					if (array_key_exists('modifiers', $pFieldMap) && !empty($pFieldMap['modifiers'])) {
						$final_value = $this->applyModifiers($pFieldMap['modifiers'], $final_value);
					}

					if ((array_key_exists('mandatory_value', $pFieldMap) && $pFieldMap['mandatory_value'] === true)
						&& (empty($final_value) || 0 === $final_value || '0' === $final_value || 'NULL' === $final_value)) {
						return null;
					}
				}
				return array($pFieldId => $final_value);
			} else {
				return null;
			}
		}

		public function getMappedTuple($pIndex) {
			$new_tuple = null;
			if (is_int($pIndex)) {
				$tmp_tuple = array();
				$flag = true;

				foreach ($this->fields as $field_id => $field_map) {
					trace($field_id);
					if ($flag && ($new_field = $this->getMappedField($pIndex, $field_id, $field_map)) && !empty($new_field)) {
						$tmp_tuple = array_merge($tmp_tuple, $new_field);
					} else {
						$flag = false;
						break;
					}
				}

				if ($flag) {
					$new_tuple = $tmp_tuple;
				}
			}

			return $new_tuple;
		}

		public function getMappedTuples($pTargetTupleNumber) {
			$mapped_tuples = array();
			for($i = 0; $i < $pTargetTupleNumber; $i++) {
				if (($result = $this->getMappedTuple($i)) && !empty($result)) {
					$mapped_tuples[] = $result;
				}
			}

			return $mapped_tuples;
		}

		public function applyModifiers(array $pModifiers, $pValue) {
			if(!is_array($pModifiers))
				return null;
			for($i = 0, $max = count($pModifiers);$i<$max;$i++) {
				$pValue = call_user_func($pModifiers[$i], $pValue);
//				$pValue = $pValue.$pModifiers[$i];
			}

			return $pValue;
		}
	}
}
