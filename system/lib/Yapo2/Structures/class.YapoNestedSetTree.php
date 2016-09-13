<?php

include_once(Yapo::$DIR_STRUCTURE . '/class.YapoTree.php');

class YapoNestedSetTree extends YapoTree {

	function __construct(& $database, $table, $left_field, $right_field, $tree_field = null) {
		parent::__construct($database, $table);
		$this->__load_driver('YapoNestedSetTree', function($classname, & $database, $table, $left_field, $right_field, $tree_field) {
			return new $classname($database, $table, $left_field, $right_field, $tree_field);
		}, array(& $database, $table, $left_field, $right_field, $tree_field));
	}
	
	/**
		Retrieve a Full Tree -- root(); tree()
		Find all leaf nodes -- leaves()
		Retrieve a single path -- path()
		Finding depth of a subtree -- depthsound()
		Find immediate subordinates -- children()
		Find the root of the current node -- root()
		Find the parent of the current node -- parent()
		delete an entire tree -- destroy()
		delete this node and children -- delete()
		remove a node and move it's children up to it's parent -- excise()
		Convert a sub-tree into it's own tree -- promote()
	**/
	
	protected function multitree() {
		return $this->__Driver->multitree;
	}
	
	protected function mtvalue($value = null) {
		return $this->__Driver->mtvalue($value);
	}
	
	protected function leftvalue($value = null) {
		return $this->__Driver->leftvalue($value);
	}

	protected function rightvalue($value = null) {
		return $this->__Driver->rightvalue($value);
	}

	protected function parentvalue($value = null) {
		return $this->__Driver->parentvalue($value);
	}

	function isRoot() {
		return $this->__Driver->isRoot();
	}
	
	// Return the all nodes of the tree from the current node
	function tree($order = YapoTree::NEXT_TREE_DEPTH) {
		return $this->__Driver->tree($order);
	}
	
	// Return children of the current node
	function children($gt = null) {
		return $this->__Driver->children($gt);
	}
	
	// Finds the root node of the current node
	function root() {
		return $this->__Driver->root();
	}
	
	// Finds the immediate parent of the current node
	function parent() {
		return $this->__Driver->parent();
	}
	
	// Returns the path from this node to the root
	function path() {
		return $this->__Driver->path();
	}
	
	// returns all the leaves of the current tree
	function leaves() {
		return $this->__Driver->leaves();
	}
	
	// Returns the depth of every subordinate node from here
	function depthsound() {
		return $this->__Driver->depthsound();
	}

	// Returns the path depth of the current node to the root
	function length() {
		return $this->__Driver->length();
	}
	
	function save($new_child = false, $after_id = false) {
		return $this->__Driver->save($new_child, $after_id);
	}
	
	// Removes this node
	function excise() {
		return $this->__Driver->excise();
	}
	
	function delete() {
		return $this->__Driver->delete();
	}
	
	// Changes this subtree into it's own tree
	function promote($mtvalue = null) {
		return $this->__Driver->promote($mtvalue);
	}
	
	function move($node_id, $parent_node_id) {
		return $this->__Driver->move($move_id, $parent_node_id);
	}
	
	function insert($node_id, $insert_parent_id) {
		return $this->__Driver->insert($node_id, $insert_parent_id);
	}

}

?>