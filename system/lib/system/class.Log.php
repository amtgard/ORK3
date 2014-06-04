<?php


define('LOG_ADD', 'add');
define('LOG_REMOVE', 'remove');
define('LOG_RETIRE', 'retire');
define('LOG_RESTORE', 'restore');
define('LOG_EDIT', 'edit');

function logtrace($title, $line) {
	global $LOG;
	if (TRACE)
		$LOG->Trace($title, $line);
}

function dumplogtrace() {
	global $LOG;
	$LOG->DumpTrace();
}

class Log {
	var $log;
	var $db;
	var $trace;
	
	function __construct() {
		global $DB;
		$this->db = $DB;
		//$this->log = new yapo($this->db, DB_PREFIX . 'log');
		$this->trace = array();
	}
	
	function Write($log, $mundane_id, $type, $action) {
		return;
		$this->log->clear();
		$this->log->name = $log;
		$this->log->mundane_id = $mundane_id;
		$this->log->action_type = $type;
		$this->log->action_time = date('Y-m-d H:i:s');
		$this->log->action = json_encode($action);
		$this->log->save();
	}
	
	function GetLogs() {
		return;
        return array();
		$q = $this->db->query('select name from ' . $this->log->name() . ' group by name order by name');
		if (!$q->size()) return array();
		$logs = array();
		do {
			$logs[] = $q->name;
		} while ($q->next());
	}
	
	function Trace($title, $line) {
		if (TRACE) {
			$time = time();
			if (!isset($this->trace[$time])) $this->trace[$time] = array();
			$this->trace[$time][] = array($title, $line);
		}
	}
	
	function DumpTrace($stringize=false) {
		if ($stringize) {
			ob_start();
		}
		ksort($this->trace);
		echo "<h3>Log Trace</h3>\n\n";
		foreach ($this->trace as $time => $c) {
			echo "<h4>".date("Y-m-d H:i:s",$time)."</h4>\n";
			foreach ($c as $k => $content) {
				echo "<h5>$content[0]</h5>\n<pre>";
				print_r($content[1]);
				echo "\n</pre>\n\n";
			}
		}
		if ($stringize) {
			$s = ob_get_contents();
			ob_end_clean();
		}
	}
}

?>