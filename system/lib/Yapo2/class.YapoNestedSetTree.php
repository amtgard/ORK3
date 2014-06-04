<?php

include_once(__DIR__ . '/class.YapoAction.php');
include_once(__DIR__ . '/class.YapoTree.php');

class YapoNestedSetTree extends YapoTree {

	var $__LEFT_FIELD;
	var $__RIGHT_FIELD;
	var $__NEXT_MODE;
	var $__MODE_STACK;
	var $__TREE_FIELD;

	const NEXT_TREE = 'NEXT_TREE';
	const NEXT_DEFAULT = 'NEXT_DEFAULT';
	
	function __construct(& $database, $table, $left_field, $right_field, $tree_field = null) {
		parent::__construct($database, $table);
		$this->__LEFT_FIELD = $left_field;
		$this->__RIGHT_FIELD = $right_field;
		$this->__NEXT_MODE = YapoTree::NEXT_DEFAULT;
		$this->__MODE_STACK = array();
		$this->__TREE_FIELD = $tree_field;
	}
	
	function tree() {
		if ($this->multitree()) {
			if (!is_null($this->mtvalue())) {
				
			}
		} else {
		
		}
		return false;
	}
	
	function clear() {
		parent::clear();
		$this->__NEXT_MODE = YapoTree::NEXT_DEFAULT;
		$this->__MODE_STACK = array();
	}
	
	function children() {
	}
	
	function root() {
	}
	
	function parent() {
	}
	
	function path() {
	}
	
	function leaves() {
	}
	
	function depthsound() {
	}
	
	function depth() {
	}
	
	function delete() {
	}
	
	function excise() {
	}
	
	function promote() {
	}
	
	function next() {
	}
}

?>