<?php

include_once(Yapo::$DIR_STRUCTURE . '/class.YapoGraph.php');

class YapoTree extends YapoGraph {

	const NEXT_TREE_DEPTH = 'NEXT_TREE_DEPTH';
	const NEXT_TREE_BREADTH = 'NEXT_TREE_BREADTH';
	const NEXT_DEFAULT = 'NEXT_DEFAULT';
	
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
		Move a sub-tree to a new location -- move()
		Insert a sub-tree into an existing location -- insert()
	**/
	
	// Returns multi-tree descriminator field or false if not a multitree
	protected function multitree() {
	}
	
	// Returns the value of the multi-tree descriminator
	protected function mtvalue($value = null) {
	}
	
	// Returns true if this is a root node
	function isRoot() {
	}
	
	// Return the all nodes of the tree from the current node
	function tree() {
	}
	
	// Return children of the current node
	function children($gt = null) {
	}
	
	// Finds the root node of the current node
	function root() {
	}
	
	// Finds the immediate parent of the current node
	function parent() {
	}
	
	// Returns the path from this node to the root
	function path() {
	}
	
	// returns all the leaves of the current tree
	function leaves() {
	}
	
	// Returns the depth of every subordinate node from here
	function depthsound() {
	}
	
	// Returns the path depth of the current node to the root
	function length() {
	}
	
	// Deletes this tree
	function destroy() {
		// tree delete
		if ($this->multitree()) {
			$mtval = $this->mtvalue();
			$this->clear();
			$this->mtvalue($mtval);
			$this->find();
			$this->delete();
		} else {
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
	}
	
	function node() {
		return new YapoGraphNode($this->primarykey());
	}
	
	// Removes this node
	function excise() {
	}
	
	// Changes this subtree into it's own tree
	function promote($mtvalue = null) {
	}
	
	function move($new_parent_id, $temp_tree_id) {
		$this->beginTransaction();
				
		$node_id = $this->pkvalue();
		if ($this->promote($temp_tree_id))
			$this->insert($node_id, $new_parent_id);
			
		$this->commit();
	}
	
	function insert($node_id, $insert_parent_id) {
	
	}
}

?>