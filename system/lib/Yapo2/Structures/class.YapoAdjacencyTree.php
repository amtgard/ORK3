<?php

include_once(Yapo::$DIR_STRUCTURE . '/class.YapoGraph.php');
include_once(Yapo::$DIR_STRUCTURE . '/class.YapoTree.php');

class YapoAdjacencyTree extends YapoTree {

	var $__PARENT_FIELD;
	var $__NEXT_MODE;
	var $__TREE_CACHE;
	var $__TREE_FIELD;
	var $__TREE_TERMINATE_NODE;
	
	static $LIMIT;	

	const NEXT_TREE_DEPTH = 'NEXT_TREE_DEPTH';
	const NEXT_TREE_BREADTH = 'NEXT_TREE_BREADTH';
	const NEXT_DEFAULT = 'NEXT_DEFAULT';
	
	function __construct(& $database, $table, $parent_field, $tree_field = null) {
		parent::__construct($database, $table);
		$this->__PARENT_FIELD = $parent_field;
		$this->__NEXT_MODE = YapoTree::NEXT_DEFAULT;
		$this->__MODE_STACK = array();
		$this->__TREE_FIELD = $tree_field;
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
		return is_null($this->__TREE_FIELD)?false:$this->__TREE_FIELD;
	}
	
	protected function mtvalue($value = null) {
		$mt = $this->__TREE_FIELD;
		if (!is_null($value))
			$this->$mt = $value;
		return $this->$mt;
	}
	
	protected function parentvalue($value = null) {
		$pv = $this->__PARENT_FIELD;
		if (!is_null($value))
			$this->$pv = $value;
		return $this->$pv;
	}

	// is root node
	function isRoot() {
		return $this->activerecord() && $this->parentvalue() == 0;
	}
	
	// Return the all nodes of the tree from the current node
	function tree($order = YapoTree::NEXT_TREE_DEPTH) {
		YapoTree::$LIMIT = 500;
		$this->__NEXT_MODE = $order;
		$this->__TREE_CACHE = array();
		$this->__TREE_TERMINATE_NODE = $this->parentvalue();
	}
	
	// Clears tree methods
	function clear() {
		parent::clear();
		$this->__NEXT_MODE = YapoTree::NEXT_DEFAULT;
		$this->__TREE_CACHE = array();
	}
	
	// Return children of the current node
	function children($gt = null) {
		if ($this->activerecord()) {
			$pkid = $this->pkvalue();
			parent::clear();
			$this->parentvalue($pkid);
			if (!is_null($gt) && is_numeric($gt) && $gt > 0)
				$this->greater($this->primarykey(), $gt);
			$this->order($this->primarykey(), Yapo::ASC);
			return $this->find();
		}
		return false;
	}
	
	// Finds the root node of the current node
	function root() {
		if ($this->activerecord()) {
			if (!$this->multitree()) {
				while (($pkid = $this->parent()) > 0) 
					if ($pkid == 0)
						return $pkid;
			} else {
				$tree_id = $this->mtvalue();
				$this->clear();
				$this->parentvalue(0);
				$this->mtvalue($tree_id);
				return $this->find() == 1;
			}
		}
		return false;
	}
	
	// Finds the immediate parent of the current node
	function parent() {
		if ($this->activerecord()) {
			$pkid = $this->parentvalue();
			if ($pkid > 0) {
				parent::clear();
				$this->pkvalue($pkid);
				$this->find();
			}
			return $pkid;
		}
		return false;
	}
	
	// Returns the path from this node to the root
	function path() {
		if ($this->activerecord()) {
			$pkid = $this->pkvalue();
			do {
				$path[] = $pkid;
			} while (($pkid = $this->parent()) > 0);
			$this->pkvalue($path[0]);
			$this->find();
			return array_reverse($path);
		}
		return false;
	}
	
	// returns all the leaves of the current tree
	function leaves() {
		throw new Exception("leaves() Unimplemented.");
		if (!$this->multitree()) {
			throw new Exception("leaves() method not supported without tree keys.");
		} else if ($this->activerecord()) {
			$pkid = $this->pkvalue();
			$treeval = $this->mtvalue();
			$tree = $this->__TREE_FIELD;
			$this->clear();
			$table = $this->__Core->__table;
			$this->treeval = 1;
			$this->query("select * from `$table` where `$table`.`$tree` = :treeval"); 
		} else {
			return false;
		}
	}
	
	// Returns the depth of every subordinate node from here
	function depthsound() {
		throw new Exception("depthsound() not supported in adjacency tree.");
	}
	
	// Returns the path depth of the current node to the root
	function length() {
		if ($this->activerecord()) {
			return count($this->path()) - 1;
		}
		return false;
	}
	
	function delete() {
		$children = array();
		if ($this->tree()) {
			do {
				$children[] = $this->pkvalue();
			} while ($this->next());
			foreach ($children as $k => $pkid) {
				$this->clear();
				$this->pkvalue($pkid);
				parent::delete();
			}
		}
	}
	
	private function _save_child(& $yapo, $child, $primary_key_field, $primary_key_id) {
		foreach ($child as $field => $value) {
			$yapo->$field = $value;
		}
		$yapo->$primary_key_field = $primary_key_id;
		return $yapo->save();
	}
	
	private function _save_child_mt(& $yapo, $child, $primary_key_field, $primary_key_id, $multitree_field, $multitree_value) {
		$yapo->$multitree_field = $multitree_value;
		return $this->_save_child($yapo, $child, $primary_key_field, $primary_key_id);
	}
	
	// Saves the current node, or prepends a new child
	// For trees which support ordered chidlren, after_id references the existing child which new_child will be inserted after
	//		{ A1, B1, C1 }: save(X1, B1) -> { A1, B1, X1, C1 }
	//
	function save($new_child = false, $after_id = false) {
		if (is_object($new_child) && get_class($new_child) == 'YapoGraphNode') {
			if ($this->activerecord()) {
				$yapo = new Yapo($this->__Database, $this->__TableName);
				$yapo->clear();
				if ($this->multitree()) {
					return $this->_save_child_mt($yapo, $new_child, $this->__PARENT_FIELD, $this->pkvalue(), $this->multitree(), $this->mtvalue());
				} else {
					return $this->_save_child($yapo, $new_child, $this->__PARENT_FIELD, $this->pkvalue());
				}
				parent::save($new_child);
			} else {
				throw new Exception('Multiple child insert not supported');
			}
		} else {
			return parent::save($new_child);
		}
	}
	
	// Removes this node
	function excise() {
		// remove this node and move it's children up one
		if ($this->activerecord()) {
			$pkid = $this->pkvalue();
			$pv = $this->parentvalue();
			$this->parentvalue(0);
			if ($this->multitree())
				$this->mtvalue(0);
			$this->save();
			$this->clear();
			$this->parentvalue($pkid);
			$this->find();
			$this->parentvalue($pv);
			$this->save();
		}
	}
	
	// Changes this subtree into it's own tree
	function promote($mtvalue = null) {
		if ($this->activerecord()) {
			if ($this->multitree() && is_null($mtvalue))
				return false;
			$pkid = $this->pkvalue();
			$this->clear();
			$this->pkvalue($pkid);
			$this->find();
			$this->parentvalue(0);
			$this->mtvalue($mtvalue);
			parent::save();
			return true;
		}
		return false;
	}
	
	// Inserts the sub-tree referenced from root node node_id
	// into the location referenced by new_parent_id
	function insert($node_id, $insert_parent_id) {
		$this->clear();
		$this->pkvalue($node_id);
		$this->find();
		$this->parentvalue($insert_parent_id);
		$this->save();
	}
	
	// Moves to the next node
	function next() {
		switch ($this->__NEXT_MODE) {
			case YapoTree::NEXT_DEFAULT:
				return parent::next();
			case YapoTree::NEXT_TREE_DEPTH:
				// Depth-first search
					do {
						$pvid = $this->parentvalue();
						$start_sibling = isset($this->__TREE_CACHE[$this->pkvalue()])?$this->__TREE_CACHE[$this->pkvalue()]:0;
						if (($children = $this->children($start_sibling)) > 0) {
							$this->__TREE_CACHE[$this->parentvalue()] = $this->pkvalue();
							return $children;
						} else {
							if ($pvid == $this->__TREE_TERMINATE_NODE || $pvid <= 0)
								return false;
							parent::clear();
							$this->pkvalue($pvid);
							$this->find();
							$start_sibling = isset($this->__TREE_CACHE[$this->pkvalue()])?$this->__TREE_CACHE[$this->pkvalue()]:0;
							if (($children = $this->children($start_sibling)) > 0) {
								// return remaining siblings
								$this->__TREE_CACHE[$this->parentvalue()] = $this->pkvalue();
								return $children;
							} else {
								// ascend parent
								$this->parent();
							}
						}
					} while (YapoTree::$LIMIT-- > 0);
				break;
			case YapoTree::NEXT_TREE_BREADTH:				
				// Breadth-first search
					if ($this->children() > 0) do {
						array_push($this->__TREE_CACHE, $this->pkvalue());
					} while (parent::next());
					$node = array_shift($this->__TREE_CACHE);
					if (is_null($node) || false === $node)
						return false;
					parent::clear();
					$this->pkvalue($node);
					$this->find();
					return true;
				break;
		}
	}
}

?>
