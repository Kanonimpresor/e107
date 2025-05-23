<?php
/*
 * e107 website system
 *
 * Copyright (C) 2008-2009 e107 Inc (e107.org)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
 * Administration - DB Verify Class
 *
 * $URL: /cvs_backup/e107_0.8/e107_admin/db_verify.php,v $
 * $Revision: 12255 $
 * $Id: 2011-06-07 17:16:42 -0700 (Tue, 07 Jun 2011) $
 * $Author: e107coders $
 *
*/

if(!defined('e107_INIT'))
{
	exit;
}

e107::includeLan(e_LANGUAGEDIR . e_LANGUAGE . '/admin/lan_db_verify.php');


/**
 *
 */
class db_verify
{

	var    $backUrl       = "";
	public $sqlFileTables = array();
	const MOST_PREFERRED_STORAGE_ENGINE = "InnoDB";
	const MOST_PREFERRED_CHARSET        = "utf8mb4";
	public $availableStorageEngines = array(self::MOST_PREFERRED_STORAGE_ENGINE);

	var     $sqlLanguageTables = array();
	var     $results           = array();
	var     $indices           = array(); // array(0) - Issue?
	var     $fixList           = array();
	private $currentTable      = null;
	private $internalError     = false;

	/**
	 * Aliases for preferred storage engines when provided the key
	 *
	 * @var string[][]
	 */
	private $storageEnginePreferenceMap = [
		"MyISAM" => [self::MOST_PREFERRED_STORAGE_ENGINE, "Aria", "Maria", "MyISAM"],
		"Aria"   => ["Aria", "Maria", "MyISAM"],
		"InnoDB" => ["InnoDB", "XtraDB"],
		"XtraDB" => ["XtraDB", "InnoDB"],
	];

	var $fieldTypes = array('time', 'timestamp', 'datetime', 'year', 'tinyblob', 'blob',
		'mediumblob', 'longblob', 'tinytext', 'mediumtext', 'longtext', 'text', 'date', 'json');

	var $fieldTypeNum = array('bit', 'tinyint', 'smallint', 'mediumint', 'integer', 'int', 'bigint',
		'real', 'double', 'float', 'decimal', 'numeric', 'varchar', 'char', 'binary', 'varbinary', 'enum', 'set');

	const STATUS_TABLE_OK                       = 0x0;
	const STATUS_TABLE_MISSING                  = 0x1 << 1;
	const STATUS_TABLE_MISMATCH_STORAGE_ENGINE  = 0x1 << 2;
	const STATUS_TABLE_MISMATCH_DEFAULT_CHARSET = 0x1 << 3;

	var $modes = array(
		'missing_table'  => 'create',
		'mismatch'       => 'alter',
		'missing_field'  => 'insert',
		'missing_index'  => 'index',
		'mismatch_index' => '', // TODO
	);

	var $errors = array();

	const cachetag = 'Dbverify';

	/**
	 * Setup
	 */
	function __construct()
	{


		$this->backUrl = e_SELF;

		$this->init();


		return $this;

	}

	/**
	 * @return bool
	 */
	public function clearCache()
	{

		return e107::getCache()->clear(self::cachetag, true);

	}

	/**
	 * @return array
	 */
	private function load()
	{

		$mes = e107::getMessage();
		$pref = e107::getPref();

		$ret = array();

		$core_data = file_get_contents(e_CORE . 'sql/core_sql.php');
		$ret['core'] = $this->getSqlFileTables($core_data);


		if(!empty($pref['e_sql_list']))
		{
			foreach($pref['e_sql_list'] as $path => $file)
			{
				$filename = e_PLUGIN . $path . '/' . $file . '.php';
				if(is_readable($filename))
				{
					$id = str_replace('_sql', '', $file);
					$data = file_get_contents($filename);
					$this->currentTable = $id;
					$ret[$id] = $this->getSqlFileTables($data);
					unset($data);
				}
				else
				{
					$message = str_replace("[x]", $filename, DBVLAN_22);
					$mes->add($message, E_MESSAGE_WARNING);
				}
			}
		}

		return $ret;

	}


	/**
	 * Permissive field validation
	 */
	private function diffStructurePermissive($expected, $actual)
	{

		$expected['default'] = isset($expected['default']) ? $expected['default'] : '';
		$actual['default'] = isset($actual['default']) ? $actual['default'] : '';

		if($expected['type'] === 'JSON' && $actual['type'] !== 'JSON') // Fix for JSON alias MySQL 5.7+
		{
			$expected['type'] = 'LONGTEXT';
		}

		// Permit actual text types that default to null even when
		// expected does not explicitly default to null
		if(
			0 === strcasecmp($expected['type'], $actual['type']) &&
			1 === preg_match('/[A-Z]*TEXT/i', $expected['type']) &&
			0 === strcasecmp($actual['default'], "DEFAULT NULL")
		)
		{
			$expected['default'] = $actual['default'];
		}

		// Loosely typed default value for numeric types
		if(1 === preg_match('/([A-Z]*INT|NUMERIC|DEC|FIXED|FLOAT|REAL|DOUBLE)/i', $expected['type']))
		{
			$expected['default'] = preg_replace("/DEFAULT '(\d*\.?\d*)'/i", 'DEFAULT $1', $expected['default']);
			$actual['default'] = preg_replace("/DEFAULT '(\d*\.?\d*)'/i", 'DEFAULT $1', $actual['default']);
		}

		/**
		 * Display width specification for integer data types was deprecated in MySQL 8.0.17
		 *
		 * @see https://dev.mysql.com/doc/relnotes/mysql/8.0/en/news-8-0-19.html
		 */
		if(1 === preg_match('/([A-Z]*INT)/i', $expected['type']))
		{
			$expected['value'] = '';
			$actual['value'] = '';
		}

		// Correct difference on CREATE TABLE statement between MariaDB and MySQL
		if(1 === preg_match('/(DATE|DATETIME|TIMESTAMP|TIME|YEAR)/i', $expected['default']))
		{
			$expected['default'] = preg_replace("/CURRENT_TIMESTAMP\(\)/i", 'CURRENT_TIMESTAMP', $expected['default']);
			$actual['default'] = preg_replace("/CURRENT_TIMESTAMP\(\)/i", 'CURRENT_TIMESTAMP', $actual['default']);
		}

		return array_diff_assoc($expected, $actual);
	}

	/**
	 * Main Routine for checking and rendering results.
	 */
	function verify()
	{

		if(!empty($_POST['runfix']))
		{
			$this->runFix($_POST['fix']);
		}

		if(!empty($_POST['verify_table']))
		{
			$this->runComparison($_POST['verify_table']);
		}
		else
		{
			$this->renderTableSelect();
		}

	}


	/**
	 * @param $fileArray
	 * @return void
	 */
	function runComparison($fileArray)
	{

		$mes = e107::getMessage();

		foreach($fileArray as $tab)
		{
			$this->compare($tab);
			foreach($this->sqlLanguageTables as $lng => $lantab)
			{
				$this->compare($tab, $lng);
			}
		}

		if($cnt = $this->errors())
		{
			$message = str_replace("[x]", $cnt, DBVLAN_26); // Found [x] issues.
			$mes->add($message, E_MESSAGE_WARNING);
			$this->renderResults($fileArray);
		}
		else
		{
			if($this->internalError === false)
			{
				$mes->addSuccess(DBLAN_111);
				$mes->addSuccess("<a class='btn btn-primary' href='" . $this->backUrl . "'>" . LAN_BACK . "</a>");
			}


			//$debug = "<pre>".print_r($this->results,TRUE)."</pre>";
			//$mes->add($debug,E_MESSAGE_DEBUG);	
			//$text .= "<div class='buttons-bar center'>".$frm->admin_button('back', DBVLAN_17, 'back')."</div>";
			echo $mes->render();
			//	$ns->tablerender("Okay",$mes->render().$text);
		}

	}

	//	$this->sqlTables = $this->sqlTableList();

	//	print_a($this->tables);
	// $this->renderTableSelect();

	//	print_a($field);
	//	print_a($match[2]);
	// echo "<pre>".$sql_data."</pre>";

	/**
	 * Check core tables and installed plugin tables
	 *
	 * @param $exclude - array of plugins to exclude.
	 */
	function compareAll($exclude = null)
	{

		if(is_array($exclude))
		{
			foreach($exclude as $val)
			{
				unset($this->sqlFileTables[$val]);
			}
		}

		$dtables = array_keys($this->sqlFileTables);

		foreach($dtables as $tb)
		{
			$this->compare($tb);
		}

		if(!empty($this->sqlLanguageTables)) // language tables. 
		{
			foreach($this->sqlLanguageTables as $lng => $lantab)
			{
				foreach($dtables as $tb)
				{
					$this->compare($tb, $lng);
				}
			}
		}

	}


	/**
	 * @param $selection
	 * @param $language
	 * @return false|void
	 */
	public function compare($selection, $language = '')
	{

		$this->currentTable = $selection;

		//	var_dump($this->sqlFileTables[$selection]);

		if(!isset($this->sqlFileTables[$selection])) // doesn't have an SQL file.
		{
			// e107::getMessage()->addDebug("No SQL File for ".$selection);
			return false;
		}


		if(empty($this->sqlFileTables[$selection]['tables']))
		{
			//$this->internalError = true;
			e107::getMessage()->addDebug("Couldn't read table data for " . $selection);

			return false;
		}

		foreach($this->sqlFileTables[$selection]['tables'] as $key => $tbl)
		{
			$rawSqlData = $this->getSqlData($tbl, $language);


			if($rawSqlData === false)
			{
				if($language)
				{
					continue;
				}


				$this->errors[$tbl]['_status'] = self::STATUS_TABLE_MISSING;
				$this->errors[$tbl]['_file'] = $selection;
				$this->results[$tbl] = [];
				// echo "missing table: $tbl";
				continue;
			}

			//	echo "<h4>RAW</h4>";
			//	print_a($rawSqlData);
			//	$this->currentTable = $tbl;v

			$sqlDataArr = $this->getSqlFileTables($rawSqlData);

			//	echo "<h4>PARSED</h4>";
			//	print_a($sqlDataArr);

			$fileData['field'] = $this->getFields($this->sqlFileTables[$selection]['data'][$key]);
			$sqlData['field'] = $this->getFields($sqlDataArr['data'][0]);

			$fileData['index'] = $this->getIndex($this->sqlFileTables[$selection]['data'][$key]);
			$sqlData['index'] = $this->getIndex($sqlDataArr['data'][0]);

			$maybeEngine = isset($sqlDataArr['engine'][0]) ? $sqlDataArr['engine'][0] : 'INTERNAL_ERROR:ENGINE';
			$fileData['engine'] = $this->getIntendedStorageEngine($this->sqlFileTables[$selection]['engine'][$key]);
			$sqlData['engine'] = $this->getCanonicalStorageEngine($maybeEngine);

			$maybeCharset = isset($sqlDataArr['charset'][0]) ? $sqlDataArr['charset'][0] : 'INTERNAL_ERROR:CHARSET';
			$fileData['charset'] = $this->getIntendedCharset($this->sqlFileTables[$selection]['charset'][$key]);
			$sqlData['charset'] = $sqlDataArr['charset'][0]; // check the actual charset. $this->getCanonicalCharset($maybeCharset);

			/*
				$debugA = print_r($fileFieldData,TRUE);	// Extracted Field Arrays
				$debugA .= "<h2>Index</h2>";
				$debugA .= print_r($fileIndexData,TRUE);
				$debugB = print_r($sqlFieldData,TRUE); // Extracted Field Arrays
				$debugB .= "<h2>Index</h2>";
				$debugB .= print_r($sqlIndexData,TRUE);
			*/

			$debugA = $this->sqlFileTables[$selection]['data'][$key];    // Extracted Raw Field Text
			//	$debugB = $rawSqlData;
			$debugB = $sqlDataArr['data'][0];    // Extracted Raw Field Text

			if(isset($debugA) && (e_PAGE === 'db.php'))
			{
				$engineA = !empty($this->sqlFileTables[$selection]['engine'][0]) ? $this->sqlFileTables[$selection]['engine'][0] : 'unknown';
				$engineB = !empty($sqlDataArr['engine'][0]) ? $sqlDataArr['engine'][0] : 'unknown';

				$charsetA = !empty($this->sqlFileTables[$selection]['charset'][0]) ? $this->sqlFileTables[$selection]['charset'][0] : 'not specified';
				$charsetB = !empty($sqlDataArr['charset'][0]) ? $sqlDataArr['charset'][0] : 'unknown';

				$debug = "<table class='table table-bordered table-condensed'>
				<tr><td style='padding:5px;font-weight:bold'>FILE: $tbl (key=$key) <span class='badge'>$engineA</span> $charsetA</td>
				<td style='padding:5px;font-weight:bold'>SQL: $tbl <span class='badge'>$engineB</span> $charsetB</td>
				</tr>
				<tr><td style='width:50%'><pre>" . $debugA . "</pre></td>
				  <td style='width:50%'><pre>" . $debugB . "</pre></td></tr></table>";

				$mes = e107::getMessage();
				$mes->add($debug, E_MESSAGE_DEBUG);
			}

			if($language)
			{
				$tbl = "lan_" . $language . "_" . $tbl;
			}

			$this->prepareResults($tbl, $selection, $sqlData, $fileData);

			unset($data);

		}


	}


	/**
	 * @param string $type fields|indices
	 * @return array
	 */
	public function getResults($type = 'fields')
	{

		if($type === 'indices')
		{
			return $this->indices;
		}

		return $this->results;

	}

	public function hasSyntaxIssue($sqlFileData): bool
	{

		return false; // TODO check syntax for errrors.
	}

	/**
	 * @param string $tbl       table name without prefix.
	 * @param string $selection 'core' OR plugin-folder name.
	 * @param array  $sqlData   ie. array('field'=>getFields($data), 'index'=>getFields($data));
	 * @param array  $fileData  ie. array('field'=>getFields($data), 'index'=>getFields($data));
	 * @todo Check for additional fields in SQL that should be removed.
	 * @todo Add support for MYSQL 5 table layout .eg. journal_id INT( 10 ) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
	 */
	public function prepareResults($tbl, $selection, $sqlData, $fileData)
	{

		if($this->hasSyntaxIssue($fileData))
		{
			return false;
		}

		// Check field and index data
		foreach(['field', 'index'] as $type)
		{
			$results = 'results';

			if($type === 'index')
			{
				$results = 'indices';
			}

			if(!isset($this->errors[$tbl]))
			{
				$this->errors[$tbl] = [];
			}
			if(!isset($this->errors[$tbl]['_status']))
			{
				$this->errors[$tbl]['_status'] = self::STATUS_TABLE_OK;
			}
			$this->errors[$tbl]['_file'] = $selection;

			foreach($fileData[$type] as $key => $value)
			{
				$this->{$results}[$tbl][$key]['_status'] = 'ok';

				if(!isset($sqlData[$type][$key]) || !is_array($sqlData[$type][$key]))
				{
					$this->{$results}[$tbl][$key]['_status'] = "missing_$type"; // type status
					$this->{$results}[$tbl][$key]['_valid'] = $value;
					$this->{$results}[$tbl][$key]['_file'] = $selection;
				}
				elseif(count($diff = $this->diffStructurePermissive($value, $sqlData[$type][$key])))
				{
					$this->{$results}[$tbl][$key]['_status'] = 'mismatch';
					$this->{$results}[$tbl][$key]['_diff'] = $diff;
					$this->{$results}[$tbl][$key]['_valid'] = $value;
					$this->{$results}[$tbl][$key]['_invalid'] = $sqlData[$type][$key];
					$this->{$results}[$tbl][$key]['_file'] = $selection;
				}


			}

			if($fileData['engine'] !== $sqlData['engine'])
			{
				$this->errors[$tbl]['_status'] |= self::STATUS_TABLE_MISMATCH_STORAGE_ENGINE;
				$this->errors[$tbl]['_valid_' . self::STATUS_TABLE_MISMATCH_STORAGE_ENGINE] = $fileData['engine'];
				$this->errors[$tbl]['_invalid_' . self::STATUS_TABLE_MISMATCH_STORAGE_ENGINE] = $sqlData['engine'];
			}
			if($fileData['charset'] !== $sqlData['charset'])
			{
				$this->errors[$tbl]['_status'] |= self::STATUS_TABLE_MISMATCH_DEFAULT_CHARSET;
				$this->errors[$tbl]['_valid_' . self::STATUS_TABLE_MISMATCH_DEFAULT_CHARSET] = $fileData['charset'];
				$this->errors[$tbl]['_invalid_' . self::STATUS_TABLE_MISMATCH_DEFAULT_CHARSET] = $sqlData['charset'];
			}

		}

		return null;

	}


	/**
	 * Compile Results into a complete list of Fixes that could be run without the need of a form selection.
	 */
	function compileResults()
	{

		foreach($this->results as $tabs => $field)
		{
			$file = varset($this->results[$tabs]['_file'], $tabs);
			$errorStatus = is_int($this->errors[$tabs]['_status']) ?
				$this->errors[$tabs]['_status'] : self::STATUS_TABLE_OK;

			if($errorStatus & self::STATUS_TABLE_MISSING) // Missing Table
			{
				$this->fixList[$file][$tabs]['all'][] = 'create';
			}
			elseif(
				$errorStatus & self::STATUS_TABLE_MISMATCH_STORAGE_ENGINE ||
				$errorStatus & self::STATUS_TABLE_MISMATCH_DEFAULT_CHARSET
			)
			{
				$this->fixList[$file][$tabs]['all'][] = 'convert';
			}
			foreach($field as $k => $f)
			{
				if($f['_status'] == 'ok')
				{
					continue;
				}
				$status = $f['_status'];
				if(!empty($this->modes[$status]))
				{
					$this->fixList[$f['_file']][$tabs][$k][] = $this->modes[$status];
				}
			}
		}

		// Index
		if(count($this->indices))
		{
			foreach($this->indices as $tabs => $field)
			{
				if($this->errors[$tabs] != 'ok')
				{
					foreach($field as $k => $f)
					{
						if($f['_status'] == 'ok')
						{
							continue;
						}
						$this->fixList[$f['_file']][$tabs][$k][] = $this->modes[$f['_status']];
					}
				}
			}
		}


	}

	/**
	 * Returns the number of errors
	 */
	public function errors()
	{

		$badTableCount = 0;
		foreach($this->errors as $tableName => $tableMetadata)
		{
			if(!empty($tableMetadata['_status']))
			{
				$badTableCount++;
				continue;
			}
			foreach($this->results[$tableName] as $fieldMetadata)
			{
				if(isset($fieldMetadata['_status']) && $fieldMetadata['_status'] != 'ok')
				{
					$badTableCount++;
					continue 2;
				}
			}
			foreach($this->indices[$tableName] as $indexMetadata)
			{
				if(isset($indexMetadata['_status']) && $indexMetadata['_status'] != 'ok')
				{
					$badTableCount++;
					continue 2;
				}
			}
		}

		return $badTableCount;
	}

	public function getErrors()
	{

		return $this->errors;
	}

	/**
	 * @param $fileArray
	 * @return void
	 */
	function renderResults($fileArray = array())
	{

		$frm = e107::getForm();
		$ns = e107::getRender();
		$mes = e107::getMessage();

		$text = "
		<form method='post' action='" . e_SELF . "?" . e_QUERY . "'>
			<fieldset id='core-db-verify-results'>
				<legend id='core-db-verify-results-legend'>" . DBVLAN_16 . "</legend>

				<table class='table adminlist'>
					<colgroup>
						<col style='width: 25%'></col>
						<col style='width: 25%'></col>
						<col style='width: 10%'></col>
						<col style='width: 30%'></col>
						<col style='width: 10%'></col>
					</colgroup>
					<thead>
						<tr>
							<th>" . DBVLAN_4 . "</th>
							<th>" . DBVLAN_5 . "</th>
							<th class='center'>" . DBVLAN_6 . "</th>
							<th>" . DBVLAN_7 . "</th>
							<th class='center last'>" . DBVLAN_19 . "</th>
						</tr>
					</thead>
					<tbody>
		";

		$info = array(
			self::STATUS_TABLE_MISSING                  => DBVLAN_13,
			self::STATUS_TABLE_MISMATCH_STORAGE_ENGINE  => DBVLAN_17,
			self::STATUS_TABLE_MISMATCH_DEFAULT_CHARSET => DBVLAN_18,
			'mismatch'                                  => DBVLAN_8,
			'missing_field'                             => DBVLAN_11,
			'ok'                                        => defset('ADMIN_TRUE_ICON', 'true'),
			'missing_index'                             => DBVLAN_25,
		);


		foreach($this->results as $tabs => $field)
		{
			$tableStatus = $this->errors[$tabs]['_status'];
			if($tableStatus != self::STATUS_TABLE_OK) // Missing Table
			{
				$errors = [];
				$parser = e107::getParser();
				foreach([
					self::STATUS_TABLE_MISSING,
					self::STATUS_TABLE_MISMATCH_STORAGE_ENGINE,
					self::STATUS_TABLE_MISMATCH_DEFAULT_CHARSET
				] as $statusFlag)
				{
					if($tableStatus & $statusFlag)
					{
						$errors[] = $parser->lanVars(
							$info[$statusFlag],
							[
								'x' => $this->errors[$tabs]['_valid_' . $statusFlag],
								'y' => $this->errors[$tabs]['_invalid_' . $statusFlag],
							]
						);
					}
				}

				$fixMode = $tableStatus & self::STATUS_TABLE_MISSING ? 'create' : 'convert';

				$text .= "
					<tr>
						<td>" . $this->renderTableName($tabs) . "</td>
						<td><em>" . DBVLAN_28 . "</em></td>
						<td class='center middle error'>" . DBVLAN_27 . "</td>
						<td>" . implode("<br />", $errors) . "</td>
						<td class='center middle autocheck e-pointer'>" . $this->fixForm($this->errors[$tabs]['_file'], $tabs, 'all', '', $fixMode) . "</td>
					</tr>
					";
			}
			foreach($field as $k => $f)
			{
				if($f['_status'] == 'ok')
				{
					continue;
				}

				$fstat = $info[$f['_status']];

				$text .= "
					<tr>
						<td>" . $this->renderTableName($tabs) . "</td>
						<td>" . $k . "&nbsp;</td>
						<td class='center middle error'>" . $fstat . "</td>
						<td>" . $this->renderNotes($f) . "&nbsp;</td>
						<td class='center middle autocheck e-pointer'>" . $this->fixForm($f['_file'], $tabs, $k, $f['_valid'], $this->modes[$f['_status']]) . "</td>
					</tr>
					";
			}
		}


		// Indices


		if(count($this->indices))
		{
			foreach($this->indices as $tabs => $field)
			{

				if($this->errors[$tabs] != 'ok')
				{
					foreach($field as $k => $f)
					{
						if($f['_status'] == 'ok')
						{
							continue;
						}

						$fstat = $info[$f['_status']];

						$text .= "
						<tr>
							<td>" . $this->renderTableName($tabs) . "</td>
							<td>" . $k . "&nbsp;</td>
							<td class='center middle error'>" . $fstat . "</td>
							<td>" . $this->renderNotes($f, 'index') . "&nbsp;</td>
							<td class='center middle autocheck e-pointer'>" . $this->fixForm($f['_file'], $tabs, $k, $f['_valid'], $this->modes[$f['_status']]) . "</td>
						</tr>
						";
					}
				}

			}
		}


		$text .= "
					</tbody>
				</table>
				<br/>
		";
		$text .= "
			<div class='buttons-bar right'>
				" . $frm->admin_button('runfix', DBVLAN_21, 'execute', '', array('id' => false)) . "
				" . $frm->admin_button('check_all', 'jstarget:fix', 'action', LAN_CHECKALL, array('id' => false)) . "
				" . $frm->admin_button('uncheck_all', 'jstarget:fix', 'action', LAN_UNCHECKALL, array('id' => false));

		foreach($fileArray as $tab)
		{
			$text .= $frm->hidden('verify_table[]', $tab);
		}

		$text .= "
			</div>
			
			</fieldset>
			</form>
		";


		$ns->tablerender(DBVLAN_23 . SEP . DBVLAN_16, $mes->render() . $text);

	}

	/**
	 * @param $tabs
	 * @return mixed|string
	 */
	function renderTableName($tabs)
	{

		if(strpos($tabs, "lan_") === 0)
		{
			list($tmp, $lang, $table) = explode("_", $tabs, 3);

			return $table . " (" . ucfirst($lang) . ")";
		}

		return $tabs;
	}


	/**
	 * @param $file
	 * @param $table
	 * @param $field
	 * @param $newvalue
	 * @param $mode
	 * @param $after
	 * @return string
	 */
	function fixForm($file, $table, $field, $newvalue, $mode, $after = '')
	{

		$frm = e107::getForm();
		$text = $frm->checkbox("fix[$file][$table][$field][]", $mode, false, array('id' => false));

		return $text;
	}


	/**
	 * @param $data
	 * @param $mode
	 * @return string
	 */
	function renderNotes($data, $mode = 'field')
	{

		// return "<pre>".print_r($data,TRUE)."</pre>";

		$v = $data['_valid'];
		$i = !empty($data['_invalid']) ? $data['_invalid'] : array();

		$valid = $this->toMysql($v, $mode);
		$invalid = $this->toMysql($i, $mode);

		$text = "";
		if($invalid)
		{
			$text .= "<strong>" . DBVLAN_9 . "</strong>
				<div class='indent'>" . $invalid . "</div>";
		}

		$text .= "<strong>" . DBVLAN_10 . "</strong>
			<div class='indent'>" . $valid . "</div>";

		return $text;
	}


	/**
	 * @param $data
	 * @param $mode
	 * @return string|void
	 */
	public function toMysql($data, $mode = 'field')
	{

		if(!$data)
		{
			return;
		}

		if($mode === 'index')
		{
			// print_a($data);
			if($data['type'])
			{
				//return $data['type']." (".$data['field'].");";
				// Check if index keyname exists and add backticks
				$keyname = (!empty($data['keyname']) ? " `" . $data['keyname'] . "`" : "");

				return $data['type'] . $keyname . " (" . $data['field'] . ");";
			}
			else
			{
				return "INDEX `" . $data['field']  . "` (" . $data['keyname'] . ");";
			}

		}

		if(!in_array(strtolower($data['type']), $this->fieldTypes))
		{
			$ret = $data['type'] . "(" . $data['value'] . ") " . $data['attributes'] . " " . $data['null'] . " " . $data['default'];

			return trim($ret);
		}
		else
		{
			$ret = $data['type'];
			$ret .= !empty($data['attributes']) ? " " . $data['attributes'] : '';
			$ret .= !empty($data['null']) ? " " . $data['null'] : '';
			$ret .= !empty($data['default']) ? " " . $data['default'] : '';

			return $ret;
		}

	}

	// returns the previous Field

	/**
	 * @param $array
	 * @param $cur
	 * @return int|mixed|string
	 */
	function getPrevious($array, $cur)
	{

		$fkeys = array_keys($array);
		$cur_key = array_search($cur, $fkeys);

		return @$fkeys[$cur_key - 1];
	}

	/**
	 * get the key ID for the current table which is being Fixed.
	 */
	function getId($tabl, $cur)
	{

		if(empty($tabl))
		{
			return null;
		}

		$key = array_flip($tabl);

		if(strpos($cur, "lan_") === 0) // language table adjustment.
		{
			list($tmp, $lang, $cur) = explode("_", $cur, 3);
		}

		if(isset($key[$cur]))
		{
			return $key[$cur];
		}

	}


	/**
	 * @param string $mode        index|alter|insert|drop|create|indexdrop
	 * @param string $table       eg. submitnews
	 * @param string $field       eg. submitnews_id
	 * @param string $sqlFileData (after CREATE)  eg. dblog_id int(10) unsigned NOT NULL auto_increment, ..... KEY....
	 * @param string $engine      MyISAM|InnoDB
	 * @param string $charset     MySQL/MariaDB text character set
	 * @return string SQL query
	 */
	function getFixQuery(
		$mode,
		$table,
		$field,
		$sqlFileData,
		$engine = self::MOST_PREFERRED_STORAGE_ENGINE,
		$charset = self::MOST_PREFERRED_CHARSET
	)
	{

		if(strpos($mode, 'index') === 0)
		{
			$fdata = $this->getIndex($sqlFileData);
			$newval = $this->toMysql($fdata[$field], 'index');
		}
		elseif($mode == 'alter' || $mode === 'insert' || $mode === 'index')
		{
			$fdata = $this->getFields($sqlFileData);
			$newval = $this->toMysql($fdata[$field]);
		}

		$query = "";

		switch($mode)
		{
			case 'alter':
				$query = "ALTER TABLE `" . MPREFIX . $table . "` CHANGE `$field` `$field` $newval";
				break;

			case 'insert':
				$after = ($aft = $this->getPrevious($fdata, $field)) ? " AFTER {$aft}" : "";
				$query = "ALTER TABLE `" . MPREFIX . $table . "` ADD `$field` $newval{$after}";
				break;

			case 'drop':
				$query = "ALTER TABLE `" . MPREFIX . $table . "` DROP `$field`";
				break;

			case 'index':
				$newval = str_replace("PRIMARY", "PRIMARY KEY", $newval);
				$query = "ALTER TABLE `" . MPREFIX . $table . "` ADD " . $newval;
				break;

			case 'indexdrop':
				$query = "ALTER TABLE `" . MPREFIX . $table . "` DROP INDEX `$field`";
				break;

			case 'create':
				$query = "CREATE TABLE `" . MPREFIX . $table . "` (" . $sqlFileData . ")" .
					" ENGINE=" . $engine . " DEFAULT CHARACTER SET=" . $charset . ";";
				break;

			case 'convert':
				$showCreateTable = $this->getSqlData($table);
				$currentSchema = $this->getSqlFileTables($showCreateTable);
				if($engine != $currentSchema['engine'][0])
				{
					$query .= "ALTER TABLE `" . MPREFIX . $table . "` ENGINE=" . $engine . ";";
				}
				if($charset != $currentSchema['charset'][0])
				{
					$query .= "ALTER TABLE `" . MPREFIX . $table . "` CONVERT TO CHARACTER SET " . $charset . ";";
				}
				break;
		}


		return $query;
	}


	/**
	 * Fix tables
	 * FixArray eg. [core][table][field] = alter|create|index| etc.
	 */
	function runFix($fixArray = '')
	{

		$mes = e107::getMessage();
		$log = e107::getLog();

		if(!is_array($fixArray))
		{
			$fixArray = $this->fixList;    // Fix All
		}


		foreach($fixArray as $j => $file)
		{

			foreach($file as $table => $val)
			{

				$id = $this->getId($this->sqlFileTables[$j]['tables'], $table);
				$toFix = count($val);

				foreach($val as $field => $fixes)
				{
					foreach($fixes as $mode)
					{

						$query = $this->getFixQuery(
							$mode,
							$table,
							$field,
							$this->sqlFileTables[$j]['data'][$id],
							$this->getIntendedStorageEngine($this->sqlFileTables[$j]['engine'][$id]),
							$this->getIntendedCharset($this->sqlFileTables[$j]['charset'][$id])
						);


						// $mes->addDebug("Query: ".$query);		
						// continue;	


						if(e107::getDb()->gen($query) !== false)
						{
							$log->addDebug(defset('LAN_UPDATED', 'Updated') . '  [' . $query . ']');
							$toFix--;
						}
						else
						{
							$log->addWarning(defset('LAN_UPDATED_FAILED', 'Update Failed') . '  [' . $query . ']');
							$log->addWarning(e107::getDb()->getLastErrorText()); // PDO compatible.
							/*if(mysql_errno())
							{
								$log->addWarning('SQL #'.mysql_errno().': '.mysql_error());
							}*/
						}
					}
				}

				if(empty($toFix))
				{
					unset($this->errors[$table], $this->fixList[$j][$table]); // remove from error list since we are using a singleton
				}
			}    //


		}

		$log->flushMessages("Database Table(s) Modified");

	}


	/**
	 * @param $sql_data
	 * @return array|false
	 */
	function getSqlFileTables($sql_data)
	{

		if(!$sql_data)
		{
			e107::getMessage()->addError("No SQL Data found in file");

			return false;
		}

		$ret = array();

		$sql_data = preg_replace("#\/\*.*?\*\/#mis", '', $sql_data);    // remove comments

		//	$regex = "/CREATE TABLE (?:IF NOT EXISTS )?`?([\w]*)`?\s*?\(([^;]*)\)\s*((?:[\w\s]+=[^\s]+)+\s*)*;/i";
		// 	$regex = "/CREATE TABLE (?:IF NOT EXISTS )?`?(\w*)`?\s*?\(([^;]*)\)\s*((?:[\w\s]+=\S+)+\s*)*;/i";
		$regex = "/CREATE TABLE (?:IF NOT EXISTS )?`?(\w*)`?\s*?\(([^;]*)\)\s*((?:[\w\s]+=[^;]+)+\s*)*;/i";

		preg_match_all($regex, $sql_data, $match);

		$tables = array();

		foreach($match[1] as $c => $k)
		{
			if(strpos($k, 'e107_') === 0) // remove prefix if found in sql dump.
			{
				$k = substr($k, 5);
			}

			$tables[$c] = $k;
		}


		$ret['tables'] = $tables;

		$data = array();

		if(!empty($match[2])) // clean/trim data.
		{
			foreach($match[2] as $dat)
			{
				$dat = str_replace("\t", '', $dat); // remove tab chars.
				$data[] = trim($dat);
			}
		}

		$ret['data'] = $data;

		$ret['engine'] = array();
		$ret['charset'] = array();

		foreach($match[3] as $rawTableOptions)
		{
			if(empty($rawTableOptions))
			{
				continue;
			}

			$engine = null;
			$charset = null;

			//	$tableOptionsRegex = "/([\w\s]+=[\w]+)+?\s*/";
			$tableOptionsRegex = "/([\w\s]+=\s?\w+)+?\s*/";
			preg_match_all($tableOptionsRegex, $rawTableOptions, $tableOptionsSplit);
			$tableOptionsSplit = current($tableOptionsSplit);
			foreach($tableOptionsSplit as $rawTableOption)
			{
				list($tableOptionName, $tableOptionValue) = explode("=", $rawTableOption, 2);
				$tableOptionName = strtoupper(trim($tableOptionName));
				$tableOptionValue = trim($tableOptionValue);
				switch($tableOptionName)
				{
					case "ENGINE":
					case "TYPE":
						$engine = $tableOptionValue;
						break;
					case "DEFAULT CHARSET":
					case "DEFAULT CHARACTER SET":
					case "CHARSET":
					case "CHARACTER SET":
						$charset = $tableOptionValue;
						break;
				}
			}

			$ret['engine'][] = str_replace('MYISAM', 'MyISAM', $engine);
			$ret['charset'][] = $charset;
		}

		if(empty($ret['tables']))
		{
			e107::getMessage()->addDebug("Unable to parse " . $this->currentTable . "_sql.php file data. Possibly missing a ';' at the end?");
			e107::getMessage()->addDebug(print_a($regex, true));
		}

		return $ret;
	}


	/**
	 * @param $data
	 * @param $print
	 * @return array
	 */
	function getFields($data, $print = false)
	{

		// Clean $data and add ` ` arond field-names - prevents issues when field == field-type. 
		$tmp = explode("\n", $data);
		$newline = array();

		foreach($tmp as $line)
		{
			$line = trim($line);

			if(strpos($line, "PRIMARY") === 0 || strpos($line, "KEY") === 0 || strpos($line, "INDEX") === 0 || strpos($line, "FULLTEXT") === 0 || strpos($line, "FOREIGN") === 0)
			{
				$newline[] = '';  // Add a placeholder to preserve the structure
				continue;
			}

			$newline[] = preg_replace('/^([^`\s][0-9a-zA-Z\$_]*)/', "`$1`", $line);
		}

		$data = implode("\n", $newline);
		// --------------------

		$mes = e107::getMessage();

		//	$regex = "/`?([\w]*)`?\s*?(".implode("|",$this->fieldTypes)."|".implode("|",$this->fieldTypeNum).")\s?(?:\([\s]?([0-9,]*)[\s]?\))?[\s]?(unsigned)?[\s]?.*?(?:(NOT NULL|NULL))?[\s]*(auto_increment|default .*)?[\s]?(?:PRIMARY KEY)?[\s]*?,?\s*?\n/im";
		$regex = "/^\s*?`?([\w]*)`?\s*?(" . implode("|", $this->fieldTypes) . "|" . implode("|", $this->fieldTypeNum) . ")\s?(?:\([\s]?([0-9,]*)[\s]?\))?[\s]?(unsigned)?[\s]?.*?(?:(NOT NULL|NULL))?[\s]*(auto_increment|default|AUTO_INCREMENT|DEFAULT [\w'\s.\(:\)-]*)?[\s]?(comment [\w\s'.-]*)?[\s]?(?:PRIMARY KEY|FULLTEXT)?[\s]*?,?\s*?\n/im";

		preg_match_all($regex, $data, $m);

		$ret = array();

		if($print)
		{
			var_dump($regex, $m);
		}

		foreach($m[1] as $k => $val)
		{
			$ret[$val] = array(
				'type'       => trim(strtoupper($m[2][$k])),
				'value'      => $m[3][$k],
				'attributes' => strtoupper($m[4][$k]),
				'null'       => strtoupper($m[5][$k]),
				'default'    => strtoupper($m[6][$k])
			);
		}

		return $ret;
	}


	/**
	 * Helper method to clean column names
	 *
	 * @param string $col The raw column name to clean
	 * @return string The cleaned column name
	 */
	private function cleanColumn($col)
	{

		$col = trim($col);
		$col = str_replace('(', ' (', $col);
		$tmp = explode(' ', $col);

		return str_replace('`', '', $tmp[0]);
	}

	/**
	 * Parse index definitions from a string
	 *
	 * @param string $data  The raw index definition string
	 * @param bool   $print Optional flag to print results (not used here)
	 * @return array Parsed index information
	 */
	public function getIndex($data, $print = false)
	{

		// Regular expression to match index definitions
		$regex = "/(?P<type>PRIMARY|UNIQUE|FULLTEXT|FOREIGN|KEY|INDEX)[\s]*?(?P<key_type>INDEX|KEY)?[\s]*(?:`?(?P<field>[\w]*)`?)?[\s]*?\((?P<columns>[^)]+)\)[\s]*,?/i";
		preg_match_all($regex, $data, $m);

		$ret = [];

		// Process each matched index
		foreach($m['type'] as $k => $val)
		{
			$type = trim(strtoupper($m['type'][$k])); // Index type (e.g., PRIMARY, UNIQUE)
			$field = trim($m['field'][$k]); // Index name before parentheses
			$columnsRaw = trim($m['columns'][$k]); // Content inside parentheses

			// Split columns and clean each one using the helper method
			$columnsArray = array_map(array($this, 'cleanColumn'), explode(',', $columnsRaw));
			$keyname = implode(',', $columnsArray); // Comma-separated column names

			// Determine the index name: use 'field' if provided, otherwise first column
			$i = $field ?: $columnsArray[0];
			if($type === 'PRIMARY')
			{
				$i = $columnsArray[0]; // Primary key uses the column name
			}

			// Normalize KEY/INDEX to empty type for regular indexes
			if($type === 'KEY' || $type === 'INDEX')
			{
				$type = '';
			}

			// Build the result array for this index
			$ret[$i] = array(
				'type'    => $type,
				'keyname' => $keyname,
				'field'   => $i,
			);
		}

		return $ret;
	}


	/**
	 * @param $tbl
	 * @param $language
	 * @return false|string
	 */
	function getSqlData($tbl, $language = '')
	{

		$mes = e107::getMessage();
		$prefix = MPREFIX;

		if($language)
		{
			if(!in_array($tbl, $this->sqlLanguageTables[$language]))
			{
				return false;
			}

			$prefix .= "lan_" . $language . "_";
			// $mes->addDebug("<h2>Retrieving Language Table Data: ".$prefix . $tbl."</h2>"); 				
		}


		$sql = e107::getDb();

		if(!$sql->isTable($tbl))
		{
			$mes->addDebug('Missing table on db-verify: ' . $tbl);

			return false;
		}


		//	mysql_query('SET SQL_QUOTE_SHOW_CREATE = 1');
		$qry = 'SHOW CREATE TABLE `' . $prefix . $tbl . "`";


		//	$z = mysql_query($qry);
		$z = $sql->gen($qry);
		if($z)
		{
			//	$row = mysql_fetch_row($z);
			$row = $sql->fetch('num');

			//return $row[1];

			return stripslashes($row[1]) . ';'; // backticks needed.
			// return str_replace("`", "", stripslashes($row[1])).';';
		}
		else
		{
			$mes->addDebug('Failed: ' . $qry);
			$this->internalError = true;

			return false;
		}

	}

	/**
	 * @return array
	 */
	function getSqlLanguages()
	{

		$sql = e107::getDb();
		$list = $sql->tables('lan');

		$array = array();

		foreach($list as $tb)
		{
			list($tmp, $lang, $table) = explode("_", $tb, 3);
			$array[$lang][] = $table;
		}

		return $array;

	}


	/**
	 * @return void
	 */
	function renderTableSelect()
	{

		$frm = e107::getForm();
		$ns = e107::getRender();
		$mes = e107::getMessage();


		$text = "
		<form method='post' action='" . e_SELF . (e_QUERY ? '?' . e_QUERY : '') . "' id='core-db-verify-sql-tables-form'>
			<fieldset id='core-db-verify-sql-tables'>
				<legend>" . DBVLAN_14 . "</legend>
				<table class='table table-striped' >
					<colgroup>
						<col style='width: 33%'></col>
						<col style='width: 33%'></col>
						<col style='width: 33%'></col>
					</colgroup>
					<thead>
						<tr>
							<th class='first form-inline' colspan='3'><label for='check-all-verify-jstarget-verify-table'>" . $frm->checkbox_toggle('check-all-verify', 'verify_table') . " " . LAN_CHECKALL . ' | ' . LAN_UNCHECKALL . "</label></th>
						</tr>
					</thead>
					<tbody>
		";

		$c = 0;
		$plg = e107::getPlug();

		foreach(array_keys($this->sqlFileTables) as $t => $x)
		{
			if($x !== 'core')
			{
				$plg->load($x);
				if(!$plg->getId()) // no data.
				{
					$plg->load($x . '_menu');// try menu folder.
				}

				$icon = $plg->getIcon();
				$name = $plg->getName();

			}
			else
			{
				$icon = defset('E_16_E107');
				$name = LAN_CORE;
			}
			$text .= ($c === 0) ? "<tr>\n" : '';
			$text .= "<td title='" . $x . "'>" . $frm->checkbox('verify_table[' . $t . ']', $x, false, array('label' => $icon . ' ' . $name)) . "</td>";
			$text .= ($c === 2) ? "</tr>\n" : '';

			$c++;

			if($c > 2)
			{
				$c = 0;
			}

		}

		while(($c % 3) !== 0)
		{
			$text .= "<td>&nbsp;</td>\n";
			$text .= (($c + 1) % 3 == 0) ? "</tr>" : "";
			$c++;
		}

		/*	if($c !== 2)
			{
				$add = (3 - $c) + 1;

				$text .= "<td>".$c."</td>";

				$text .= "</tr>";
			}*/

		$text .= "
					</tbody>
					</table>
						<div class='buttons-bar center'>
							" . $frm->admin_button('db_verify', DBVLAN_15) . "
							" . $frm->admin_button('db_tools_back', LAN_BACK, 'back') . "
						</div>
					</fieldset>
				</form>
		";

		$ns->tablerender(DBVLAN_23 . SEP . DBVLAN_16, $mes->render() . $text);
	}

	/**
	 * Get the available storage engines on this MySQL server
	 *
	 * This method is not memoized and should not be called repeatedly.
	 *
	 * @return string[] An unordered list of the storage engines supported by the current MySQL server
	 */
	private function getAvailableStorageEngines()
	{

		$db = e107::getDb();
		$db->gen("SHOW ENGINES;");
		$output = [];
		while($row = $db->fetch())
		{
			$output[] = $row['Engine'];
		}

		return $output;
	}

	/**
	 * Get the most compatible MySQL storage engine on this server for the provided storage engine
	 *
	 * @param string|null $maybeStorageEngine The requested storage engine
	 * @return string|false The MySQL storage engine that should actually be used. false if no match found.
	 */
	public function getIntendedStorageEngine($maybeStorageEngine = null)
	{

		if($maybeStorageEngine === null)
		{
			return $this->getIntendedStorageEngine(self::MOST_PREFERRED_STORAGE_ENGINE);
		}

		if(strtoupper($maybeStorageEngine) === 'MYISAM')
		{
			$maybeStorageEngine = 'MyISAM';
		}
		elseif(strtoupper($maybeStorageEngine) === 'INNODB')
		{
			$maybeStorageEngine = 'InnoDB';
		}

		if(!array_key_exists($maybeStorageEngine, $this->storageEnginePreferenceMap))
		{
			if(in_array($maybeStorageEngine, $this->availableStorageEngines))
			{
				return $maybeStorageEngine;
			}

			return false;
		}

		$fit = array_intersect($this->storageEnginePreferenceMap[$maybeStorageEngine], $this->availableStorageEngines);

		return current($fit);
	}

	/**
	 * Try to figure out what storage engine the provided one is referring to
	 *
	 * @param string $maybeStorageEngine The reported storage engine
	 * @return string The probable storage engine the input is referring to
	 * @throws UnexpectedValueException if the provided storage engine is not known as an available storage engine
	 */
	public function getCanonicalStorageEngine($maybeStorageEngine)
	{

		if(in_array($maybeStorageEngine, $this->availableStorageEngines))
		{
			return $maybeStorageEngine;
		}

		throw new UnexpectedValueException(
			"Unknown storage engine: " . var_export($maybeStorageEngine, true)
		);
	}

	/**
	 * Get the most compatible MySQL character set based on the input
	 *
	 * @param string|null $maybeCharset The requested character set. null to retrieve the default
	 * @return string The MySQL character set that should actually be used
	 */
	public function getIntendedCharset($maybeCharset = null)
	{

		if(empty($maybeCharset))
		{
			return self::MOST_PREFERRED_CHARSET;
		}

		return $this->getCanonicalCharset($maybeCharset);
	}

	/**
	 * Try to figure out what character set the provided one is referring to
	 *
	 * @param string $maybeCharset The reported character set
	 * @return string The probable character set
	 */
	public function getCanonicalCharset($maybeCharset)
	{

		if($maybeCharset == "utf8")
		{
			return "utf8mb4";
		}

		return $maybeCharset;
	}

	/**
	 * Inititalize the class parameters.
	 *
	 * @return void
	 */
	public function init($clearCache = false): void
	{

		$sql = e107::getDb();
		$sql->gen('SET SQL_QUOTE_SHOW_CREATE = 1');

		if(!deftrue('e_DEBUG') && ($clearCache === false) && $tmp = e107::getCache()->retrieve(self::cachetag, 15, true, true))
		{
			$cacheData = e107::unserialize($tmp);
			$this->sqlFileTables = isset($cacheData['sqlFileTables']) ? $cacheData['sqlFileTables'] : $this->load();
			$this->availableStorageEngines = isset($cacheData['availableStorageEngines']) ?
				$cacheData['availableStorageEngines'] : $this->getAvailableStorageEngines();
		}
		else
		{
			$this->sqlFileTables = $this->load();
			$this->availableStorageEngines = $this->getAvailableStorageEngines();
			$cacheData = e107::serialize([
				'sqlFileTables'           => $this->sqlFileTables,
				'availableStorageEngines' => $this->availableStorageEngines,
			], 'json');
			e107::getCache()->set(self::cachetag, $cacheData, true, true, true);
		}


		$this->sqlLanguageTables = $this->getSqlLanguages();
	}


}




/*




function check_tables($what)
{
	global $tablines, $table_list, $frm, $emessage;

	$cur = 0;
	$table_list = "";
	read_tables($what);

	$fix_active = FALSE;			// Flag set as soon as there's a fix - enables 'Fix it' button

	$text = "
		<form method='post' action='".e_SELF."'>
			<fieldset id='core-db-verify-{$what}'>
				<legend id='core-db-verify-{$what}-legend'>".DBVLAN_16." - $what ".DBVLAN_18."</legend>
	";
	foreach(array_keys($table_list) as $k)
	{	// $k is the DB table name (less prefix)
		$ttcount = 0;
		$ttext = "
				<table  class='table adminlist'>
					<colgroup >
						<col style='width: 25%'></col>
						<col style='width: 25%'></col>
						<col style='width: 10%'></col>
						<col style='width: 30%'></col>
						<col style='width: 10%'></col>
					</colgroup>
					<thead>
						<tr>
							<th>".DBVLAN_4.": {$k}</th>
							<th>".DBVLAN_5."</th>
							<th class='center'>".DBVLAN_6."</th>
							<th>".DBVLAN_7."</th>
							<th class='center last'>".DBVLAN_19."</th>
						</tr>
					</thead>
					<tbody>
		";

		$prefix = MPREFIX;
		$current_tab = get_current($k, $prefix);		// Get list of fields and keys from actual table
		unset($fields);
		unset($xfields);
		$xfield_errors = 0;

		if ($current_tab)
		{
			$lines = explode("\n", $current_tab);			// Actual table - create one element of $lines per field or other line of info
			$fieldnum = 0;
			foreach($tablines[$k] as $x)
			{	// $x is a line of the DB definition from the *_sql.php file
		  		$x = str_replace('  ',' ',$x);				// Remove double spaces
		  		$fieldnum++;
				$ffound = 0;
		  		list($fname, $fparams) = explode(' ', $x, 2);		// Pull out first word of definition
				if ($fname == 'UNIQUE' || $fname == 'FULLTEXT')
				{
					list($key, $key1, $keyname, $keyparms) = explode(' ', $x, 4);
					$fname = $key." ".$key1." ".$keyname;
					$fparams = $keyparms;
				}
				elseif ($fname == 'KEY')
		  		{
					list($key, $keyname, $keyparms) = explode(' ', $x, 3);
					$fname = $key." ".$keyname;
					$fparams = $keyparms;
		  		}
				elseif ($fname == 'PRIMARY')
		  		{	// Nothing to do ATM
		  		}
				else
				{		// Must be a field name
					$fname = str_replace('`','',$fname);		// Just remove back ticks if present
				}
		  		$fields[$fname] = 1;
		 		$fparams = ltrim(rtrim($fparams));
		  		$fparams = preg_replace("/\r?\n$|\r[^\n]$|,$/", '', $fparams);


		 		if(stristr($k, "lan_") !== FALSE && $cur != 1)
				{
					$cur = 1;
				}

				$head_txt = "
							<tr>
								<td>{$k}</td>
								<td>{$fname} 
				";

				if (strpos($fparams, 'KEY') !== FALSE)
				{
					$head_txt .= " {$fparams} aa";
				}

				$head_txt .= "</td>
				";

				$xfieldnum = -1;
				$body_txt = '';

				foreach($lines as $l)
				{
					$xfieldnum++;
					list($xl, $tmp) = explode("\n", $l, 2);			// $tmp should be null

					$xl = ltrim(rtrim(stripslashes($xl)));
					$xl = preg_replace('/\r?\n$|\r[^\n]$/', '', $xl);
					$xl = str_replace('  ',' ',$xl);				// Remove double spaces
					list($xfname, $xfparams) = explode(" ", $xl, 2);	// Field name and the rest
					
					if ($xfname == 'UNIQUE' || $xfname == 'FULLTEXT')
					{
						list($key, $key1, $keyname, $keyparms) = explode(" ", $xl, 4);
						$xfname = $key." ".$key1." ".$keyname;
						$xfparams = $keyparms;
					}
					elseif ($xfname == "KEY")
					{
						list($key, $keyname, $keyparms) = explode(" ", $xl, 3);
						$xfname = $key." ".$keyname;
						$xfparams = $keyparms;
					}
					
					if ($xfname != "CREATE" && $xfname != ")")
					{
						$xfields[$xfname] = 1;
					}
					$xfparams = preg_replace('/,$/', '', $xfparams);
					$fparams = preg_replace('/,$/', '', $fparams);
					if ($xfname == $fname)
					{  // Field names match - or it could be the word 'KEY' and its name which matches
						$ffound = 1;
						//echo "Field: ".$xfname."   Actuals: ".$xfparams."   Expected: ".$fparams."<br />";
						$xfsplit = explode(' ',$xfparams);
						$fsplit  = explode(' ',$fparams);
						$skip = FALSE;
						$i = 0;
						$fld_err = FALSE;
						foreach ($xfsplit as $xf)
						{
							if ($skip)
							{
								$skip = FALSE;
								// echo "  Unskip: ".$xf."<br />";
							}
							elseif (strcasecmp(trim($xf),'collate') == 0)
							{	// Strip out the collation definition
								$skip = TRUE;
							// cho "Skip = ".$xf;
							}
							else
							{
							// echo "Compare: ".$xf." - ".$fsplit[$i]."<br />";
							// Since VARCHAR and CHAR are interchangeable, convert to CHAR (strictly, VARCHAR(3) and smalller becomes CHAR() )
								if (stripos($xf,'VARCHAR') === 0) $xf = substr($xf,3);
								if (stripos($fsplit[$i],'VARCHAR') === 0) $fsplit[$i] = substr($fsplit[$i],3);
								if (strcasecmp(trim($xf),trim($fsplit[$i])) != 0)
								{
									$fld_err = TRUE;
								//echo "Mismatch: ".$xf." - ".$fsplit[$i]."<br />";
								}
							$i++;
							}
						}

						if ($fld_err)
						{
							$body_txt .= "
								<td class='center middle error'>".DBVLAN_8."</td>
								<td>
									<strong>".DBVLAN_9."</strong>
									<div class='indent'>{$xfparams}</div>
									<strong>".DBVLAN_10."</strong>
									<div class='indent'>{$fparams}</div>
								</td>
								<td class='center middle autocheck e-pointer'>".fix_form($k, $fname, $fparams, "alter")."</td>
							";
							$fix_active = TRUE;
							$xfield_errors++;
						}
						/* FIXME - can't stay if there is no way of fixing the field numbers (e.g. AFTER query)
						elseif ($fieldnum != $xfieldnum)
						{  // Field numbers different - missing field?
							$body_txt .= "
								<td class='center middle error'>".DBVLAN_5." ".DBVLAN_8."</td>
								<td>
									<strong>".DBVLAN_9.": </strong>#{$xfieldnum}
									<br />
									<strong>".DBVLAN_10.": </strong>#{$fieldnum}
								</td>
								<td class='center middle'>&nbsp;</td>

							";
						}
						
						
						// DISABLED for now (show only errors), could be page setting
						// else
						// {
							// $body_txt .= "
								// <td class='center'>OK</td>
								// <td>&nbsp;</td>
								// <td class='center middle'>&nbsp;</td>
							// ";
						// }
// 						
					}
				}	// Finished checking one field

				if ($ffound == 0)
				{
					$prev_fname = $fname; //FIXME - wrong $prev_fname!
					$body_txt .= "
								<td class='center middle error'>".DBVLAN_11."</td>
								<td>
									<strong>".DBVLAN_10."</strong>
									<div class='indent'>{$fparams}</div>
								</td>
								<td class='center middle autocheck e-pointer'>".fix_form($k, $fname, $fparams, "insert", $prev_fname)."</td>
					";
					$fix_active = TRUE;
					$xfield_errors++;
				}

				if($xfield_errors && $body_txt)
				{
					$ttext .= $head_txt.$body_txt."
							</tr>
					";
				}


			}

			foreach(array_keys($xfields) as $tf)
			{
				if (!$fields[$tf] && $k != "user_extended")
				{
					$fix_active = TRUE;
					$xfield_errors++;
					$ttext .= "
					<tr>
						<td>$k</td>
						<td>$tf</td>
						<td class='center middle'>".DBVLAN_12."</td>
						<td>&nbsp;</td>
						<td class='center middle autocheck e-pointer'>".fix_form($k, $tf, $fparams, "drop")."</td>
					</tr>
					";
				}
			}
			
			
		}
		else
		{	// Table Missing.
			$ttext .= "
					<tr>
						<td>{$k}</td>
						<td>&nbsp;</td>
						<td class='center middle error'>".DBVLAN_13."</td>
						<td>&nbsp;</td>
						<td class='center middle autocheck e-pointer'>".fix_form($k, $tf, $tablines[$k], "create") . "</td>
					</tr>
			";

			$fix_active = TRUE;
			$xfield_errors++;
		}
		
		if(!$xfield_errors)
		{
			//no errors, so no table rows yet
			$ttext .= "
					<tr>
						<td colspan='5' class='center'>Table status OK</td>
					</tr>
			";
		}
	
		$ttext .= "
					</tbody>
				</table>
				<br/>
		";
		
		//FIXME - add 'show_if_ok' switch
		if($xfield_errors || (!$xfield_errors && varsettrue($_GET['show_if_ok'])))
		{
			$text .= $ttext;
			$ttcount++;
		}
	}
	
	if(!$fix_active)
	{
		//Everything should be OK
		$emessage->add('DB successfully verified - no problems were found.', E_MESSAGE_SUCCESS);
		
		if(!$ttcount)
		{
			//very tired and sick of this page, so quick and dirty
			$text .= "
					<script>
						\$('core-db-verify-{$what}-legend').hide();
					</script>
			";
		}
	}
	
	if($fix_active)
	{
		$text .= "
			<div class='buttons-bar right'>
				".$frm->admin_button('do_fix', DBVLAN_21, 'execute', '', array('id'=>false))."
				".$frm->admin_button('check_all', 'jstarget:fix_active', 'action', LAN_CHECKALL, array('id'=>false))."
				".$frm->admin_button('uncheck_all', 'jstarget:fix_active', 'action', LAN_UNCHECKALL, array('id'=>false))."
			</div>
		";
	}
	
	foreach(array_keys($_POST) as $j) 
	{
		$match = array();
		if (preg_match('/table_(.*)/', $j, $match))
		{
			$lx = $match[1];
			$text .= "<div><input type='hidden' name='table_{$lx}' value='1' /></div>\n";
		}
	}

	$text .= "
		</fieldset>
		<div class='buttons-bar center'>
			".$frm->admin_button('back', DBVLAN_17, 'back')."
		</div>
	</form>

	";

	return $text;
}

global $table_list;

// -------------------- Table Fixing ------------------------------

if(isset($_POST['do_fix']))
{
	//$emessage->add(DBVLAN_20);
	foreach( $_POST['fix_active'] as $key=>$val)
	{

		if (MAGIC_QUOTES_GPC == TRUE)
		{
			$table = stripslashes($_POST['fix_table'][$key][0]);
			$newval = stripslashes($_POST['fix_newval'][$key][0]);
			$mode = stripslashes($_POST['fix_mode'][$key][0]);
			$after = stripslashes($_POST['fix_after'][$key][0]);
		}
		else
		{
			$table = $_POST['fix_table'][$key][0];
			$newval = $_POST['fix_newval'][$key][0];
			$mode = $_POST['fix_mode'][$key][0];
			$after = $_POST['fix_after'][$key][0];
		}


		$field= $key;
		
		switch($mode)
		{
			case 'alter':
				$query = "ALTER TABLE `".MPREFIX.$table."` CHANGE `$field` `$field` $newval";
			break;

			case 'insert':
				if($after) $after = " AFTER {$after}";
				$query = "ALTER TABLE `".MPREFIX.$table."` ADD `$field` $newval{$after}";
			break;
			
			case 'drop':
				$query = "ALTER TABLE `".MPREFIX.$table."` DROP `$field` ";
			break;
			
			case 'index':
				$query = "ALTER TABLE `".MPREFIX.$table."` ADD INDEX `$field` ($newval)";
			break;
			
			case 'indexalt':
				$query = "ALTER TABLE `".MPREFIX.$table."` ADD $field ($newval)";
			break;
			
			case 'indexdrop':
				$query = "ALTER TABLE `".MPREFIX.$table."` DROP INDEX `$field`";
			break;
			
			case 'create':
			$query = "CREATE TABLE `".MPREFIX.$table."` ({$newval}";
			if (!preg_match('#.*?\s+?(?:TYPE|ENGINE)\s*\=\s*(.*?);#is', $newval))
			{
				$query .= ') TYPE=MyISAM;';
			}
			break;
		}

		return $query;
		//FIXME - db handler!!!
		if(mysql_query($query)) $emessage->add(LAN_UPDATED.' [&nbsp;'.$query.'&nbsp;]', E_MESSAGE_SUCCESS);
		else 
		{
			$emessage->add(LAN_UPDATED_FAILED.' [&nbsp;'.$query.'&nbsp;]', E_MESSAGE_WARNING);
			if(mysql_errno())
			{
				$emessage->add('&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;SQL #'.mysql_errno().': '.mysql_error(), E_MESSAGE_WARNING);
			}
		}
	}
}




$text = "
	<form method='post' action='".e_SELF.(e_QUERY ? '?'.e_QUERY : '')."' id='core-db-verify-sql-tables-form'>
		<fieldset id='core-db-verify-sql-tables'>
			<legend>".DBVLAN_14."</legend>
			<table class='table adminlist'>
				<colgroup>
					<col style='width: 100%'></col>
				</colgroup>
				<thead>
					<tr>
						<th class='last'>".$frm->checkbox_toggle('check-all-verify', 'table_').LAN_CHECKALL.' | '.LAN_UNCHECKALL."</th>
					</tr>
				</thead>
				<tbody>
";

foreach(array_keys($tables) as $x) {
	$text .= "
						<tr>
							<td>
								".$frm->checkbox('table_'.$x, $x).$frm->label($x, 'table_'.$x, $x)."
							</td>
						</tr>
	";
}

$text .= "
					</tbody>
				</table>
				<div class='buttons-bar center'>
					".$frm->admin_button('db_verify', DBVLAN_15)."
					".$frm->admin_button('db_tools_back', DBVLAN_17, 'back')."
				</div>
			</fieldset>
		</form>
";

$e107->ns->tablerender(DBVLAN_23.' - '.DBVLAN_16, $emessage->render().$text);
require_once(e_ADMIN."footer.php");
exit;

// --------------------------------------------------------------
function fix_form($table,$field, $newvalue,$mode,$after ='')
{
	global $frm;
	
	if($mode == 'create')
	{
		$newvalue = implode("\n",$newvalue);
		$field = $table;		// Value for $field may be rubbish!
	}
	else
	{
		if(stristr($field, 'KEY ') !== FALSE)
		{
			$field = chop(str_replace('KEY ','',$field));
			$mode = ($mode == 'drop') ? 'indexdrop' : 'index';
			$search = array('(', ')');
			$newvalue = str_replace($search,'',$newvalue);
			$after = '';
		}
		
		if($mode == 'index' && (stristr($field, 'FULLTEXT ') !== FALSE || stristr($field, 'UNIQUE ') !== FALSE))
		{
			$mode = 'indexalt';
		}
		elseif($mode == 'indexdrop' && (stristr($field, 'FULLTEXT ') !== FALSE || stristr($field, 'UNIQUE ') !== FALSE))
		{
			$field = trim(str_replace(array('FULLTEXT ', 'UNIQUE '), '', $field));
		}
		$field = trim($field, '`');
	}

	$text .= "\n\n";
	$text .= $frm->checkbox("fix_active[$field][]", 1, false, array('id'=>false));
	$text .= "<input type='hidden' name=\"fix_newval[$field][]\" value=\"$newvalue\" />\n";
	$text .= "<input type='hidden'  name=\"fix_table[$field][]\" value=\"$table\" />\n";
	$text .= "<input type='hidden'  name=\"fix_mode[$field][]\" value=\"$mode\" />\n";
	$text .= ($after) ? "<input type='hidden'  name=\"fix_after[$field][]\" value=\"$after\" />\n" : "";
	$text .= "\n\n";

	return $text;
}

function table_list()
{
	// grab default language lists.
	global $mySQLdefaultdb;

	$exclude[] = "banlist";		$exclude[] = "banner";
	$exclude[] = "cache";		$exclude[] = "core";
	$exclude[] = "online";		$exclude[] = "parser";
	$exclude[] = "plugin";		$exclude[] = "user";
	$exclude[] = "upload";		$exclude[] = "userclass_classes";
	$exclude[] = "rbinary";		$exclude[] = "session";
	$exclude[] = "tmp";	 		$exclude[] = "flood";
	$exclude[] = "stat_info";	$exclude[] = "stat_last";
	$exclude[] = "submit_news";	$exclude[] = "rate";
	$exclude[] = "stat_counter";$exclude[] = "user_extended";
	$exclude[] = "user_extended_struct";
	$exclude[] = "pm_messages";
	$exclude[] = "pm_blocks";
	
	$replace = array();
	
	$lanlist = explode(",",e_LANLIST);
	foreach($lanlist as $lang)
	{
		if($lang != $pref['sitelanguage'])
		{
			$replace[] = "lan_".strtolower($lang)."_";
		}
	}

	$tables = mysql_list_tables($mySQLdefaultdb);
	while (list($temp) = mysql_fetch_array($tables))
	{
		$prefix = MPREFIX."lan_";
		$match = array();
		if(strpos($temp,$prefix)!==FALSE)
		{
			$e107tab = str_replace(MPREFIX, "", $temp);	
			$core = str_replace($replace,"",$e107tab);
			if (str_replace($exclude, "", $e107tab))
			{
				$tabs[$core] = $e107tab;
			}		
		}
	}

	return $tabs;
}
*/



