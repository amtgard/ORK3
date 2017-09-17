<?php

include_once(__DIR__ . '/class.YapoAction.php');

class YapoTree extends Yapo {

	var $__PARENT_FIELD;
	var $__NEXT_MODE;
	var $__MODE_STACK;
	var $__TREE_FIELD;

	const NEXT_TREE = 'NEXT_TREE';
	const NEXT_DEFAULT = 'NEXT_DEFAULT';
	
	function __construct(& $database, $table, $parent_field, $tree_field = null) {
		parent::__construct($database, $table);
		$this->__PARENT_FIELD = $parent_field;
		$this->__NEXT_MODE = YapoTree::NEXT_DEFAULT;
		$this->__MODE_STACK = array();
		$this->__TREE_FIELD = $tree_field;
	}

	protected function multitree() {
		return !is_null($this->__TREE_FIELD);
	}
	
	protected function mtvalue($value = null) {
		$mt = $this->__TREE_FIELD;
		if (!is_null($value))
			$this->$mt = $value;
		return $this->$mt;
	}

	function tree() {
		$this->__NEXT_MODE = YapoTree::NEXT_TREE;
		$this->__MODE_STACK = array();
		return $this->children();
	}
	
	function clear() {
		parent::clear();
		$this->__NEXT_MODE = YapoTree::NEXT_DEFAULT;
		$this->__MODE_STACK = array();
	}
	
	function children() {
		if ($this->__Core->PrimaryKeyIsSet()) {
			$pkid = $this->pkvalue();
			$this->clear();
			$parent = $this->__PARENT_FIELD;
			$this->$parent = $pkid;
			return $this->find();
		}
		return false;
	}
	
	function root() {
		if ($this->__Core->PrimaryKeyIsSet()) {
			if (is_null($this->__TREE_FIELD)) {
				while (($pkid = $this->parent()) > 0) 
					if ($pkid == 0)
						return $pkid;
			} else {
				$tree = $this->__TREE_FIELD;
				$tree_id = $this->$tree;
				$parent = $this->__PARENT_FIELD;
				$this->clear();
				$this->$parent = 0;
				$this->$tree = $tree_id;
				return $this->find() == 1;
			}
		}
		return false;
	}
	
	function parent() {
		if ($this->__Core->PrimaryKeyIsSet()) {
			$parent = $this->__PARENT_FIELD;
			$pkid = $this->$parent;
			if ($pkid > 0) {
				$this->clear();
				$this->pkvalue($pkid);
				$this->find();
			}
			return $pkid;
		}
		return false;
	}
	
	function path() {
		if ($this->__Core->PrimaryKeyIsSet()) {
			$pk = $this->primarykey();
			$path = array( $this->$pk );
			while (($pkid = $this->parent()) > 0) 
				if ($pkid == 0)
					return array_reverse($path);
				else
					$path[] = $pkid;
		}
		return false;
	}
	
	function leaves() {
		throw new Exception("leaves() Unimplemented.");
		if (!$this->multitree()) {
			throw new Exception("leaves() method not supported without tree keys.");
		} else if ($this->__Core->PrimaryKeyIsSet()) {
			$pkid = $this->pkvalue();
			$treeval = $this->mtvalue();
			$tree = $this->__TREE_FIELD;
			$this->clear();
			$table = $this->__Core->__table;
			$this->treeval = 
			$this->query("select * from `$table` where `$table`.`$tree` = :treeval"); 
		} else {
			return false;
		}
	}
	
	function depthsound() {
		throw new Exception("depthsound() not supported in adjacency tree.");
	}
	
	function depth() {
		if ($this->__Core->PrimaryKeyIsSet()) {
			return count($this->path) - 1;
		}
		return false;
	}
	
	function delete() {
		// tree delete
		$children = array();
		if ($this->tree()) {
			do {
				$children[] = $this->pkvalue();
			} while ($this->next());
		}
		foreach ($children as $k => $pkid) {
			$this->clear();
			$this->pkvalue($pkid);
			parent::delete();
		}
	}
	
	function excise() {
		// remove this node and move it's children up one
		if ($this->__Core->PrimaryKeyIsSet()) {
			$pkid = $this->pkvalue();
			$parent = $this->__PARENT_FIELD;
			$this->clear();
			$table = $this->__Core->__table;
			$this->query("update `$table` set `$table`.`$parent` = 0 where `$table`.`$parent` = $pkid"); 
		}
	}
	
	function promote() {
		if ($this->__Core->PrimaryKeyIsSet()) {
			$pkid = $this->pkvalue();
			$this->clear();
			$this->pkvalue($pkid);
			$this->find();
			$parent = $this->__PARENT_FIELD;
			$this->$parent = 0;
			parent::save();
		}
	}
	
	function next() {
		switch ($this->__NEXT_MODE) {
			case YapoTree::NEXT_DEFAULT:
				return parent::next();
			case YapoTree::NEXT_TREE:
				if (!parent::next()) {
					if (count($this->__MODE_STACK) == 0) return false;
					$pkid = array_shift($this->__MODE_STACK);
					$stack = $this->__MODE_STACK;
					$this->clear();
					$this->__MODE_STACK = $stack;
					$this->__NEXT_MODE = YapoTree::NEXT_TREE;
					$parent = $this->__PARENT_FIELD;
					$this->$parent = $pkid;
					$size = $this->children();
				} else {
					$pk = $this->primarykey();
					$this->__MODE_STACK[] = $this->$pk;
				}
				break;
		}
	}
}

?>