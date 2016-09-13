<?php

include_once(Yapo::$DIR_DRIVER . '/structure.Interface.Yapo.php');

class InterfaceYapoNestedSetTree extends InterfaceYapo {

	protected $__LEFT_FIELD;
	protected $__RIGHT_FIELD;
	protected $__NEXT_MODE;
	protected $__TREE_CACHE; 
	protected $__TREE_FIELD;

	function __construct(& $database, $table, $left_field, $right_field, $tree_field = null) {
		parent::__construct($database, $table);
		$this->__LEFT_FIELD = $left_field;
		$this->__RIGHT_FIELD = $right_field;
		$this->__NEXT_MODE = YapoTree::NEXT_DEFAULT;
		$this->__TREE_CACHE = array();
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
	
	protected function leftvalue($value = null) {
		$lv = $this->__LEFT_FIELD;
		if (!is_null($value))
			$this->$lv = $value;
		return $this->$lv;
	}

	protected function rightvalue($value = null) {
		$rv = $this->__RIGHT_FIELD;
		if (!is_null($value))
			$this->$rv = $value;
		return $this->$rv;
	}

	protected function parentvalue($value = null) {
		$pv = $this->__PARENT_FIELD;
		if (!is_null($value))
			$this->$pv = $value;
		return $this->$pv;
	}

	function isRoot() {
		return $this->activerecord() && $this->leftvalue() == 1;
	}
	
	// Return the all nodes of the tree from the current node
	function tree($order = YapoTree::NEXT_TREE_DEPTH) {
		$tree_id = $this->pkvalue();
		$Data = array( ':tree_id' => $tree_id );
		if ($this->multitree()) {
			$mtselect = "and parent.`" . $this->multitree() . "` = :mtvalue and node.`" . $this->multitree() . "` = :mtvalue ";
			$Data[':mtvalue'] = $this->mtvalue();
		}
		$this->clear();
		$sql = "SELECT node.* FROM `" . $this->__TableName . "` as node, `" . $this->__TableName . "` as parent
					where 
						node.`" . $this->__LEFT_FIELD . "` between parent.`" . $this->__LEFT_FIELD . "` and parent.`" . $this->__RIGHT_FIELD . "`
						and parent.`" . $this->primarykey() . "` = :tree_id
						$mtselect
					order by node.`" . $this->__LEFT_FIELD . "`";
		return $this->query($sql, $Data, true);
	}
	
	// Return children of the current node
	function children($gt = null) {
		$tree_id = $this->pkvalue();
		$Data = array( ':tree_id' => $tree_id );
		if ($this->multitree()) {
			$mtselect = "and node.`" . $this->multitree() . "` = :mtvalue and parent.`" . $this->multitree() . "` = :mtvalue";
			$submtselect = "and sub_parent.`" . $this->multitree() . "` = :mtvalue";
			$Data[':mtvalue'] = $this->mtvalue();
		}
		$this->clear();
		$sql = "
					SELECT node.*, (COUNT(parent.`" . $this->primarykey() . "`) - (sub_tree.depth + 1)) AS depth
						FROM `" . $this->__TableName . "` AS node,
								`" . $this->__TableName . "` AS parent,
								`" . $this->__TableName . "` AS sub_parent,
								(
										SELECT node.`" . $this->primarykey() . "`, (COUNT(parent.`" . $this->primarykey() . "`) - 1) AS depth
											FROM `" . $this->__TableName . "` AS node,
												`" . $this->__TableName . "` AS parent
											WHERE node.`" . $this->__LEFT_FIELD . "` BETWEEN parent.`" . $this->__LEFT_FIELD . "` AND parent.`" . $this->__RIGHT_FIELD . "`
												AND node.`" . $this->primarykey() . "` = :tree_id
												$mtselect
											GROUP BY node.`" . $this->primarykey() . "`
											ORDER BY node.`" . $this->__LEFT_FIELD . "`
								)AS sub_tree
						WHERE node.`" . $this->__LEFT_FIELD . "` BETWEEN parent.`" . $this->__LEFT_FIELD . "` AND parent.`" . $this->__RIGHT_FIELD . "`
								AND node.`" . $this->__LEFT_FIELD . "` BETWEEN sub_parent.`" . $this->__LEFT_FIELD . "` AND sub_parent.`" . $this->__RIGHT_FIELD . "`
								AND sub_parent.`" . $this->primarykey() . "` = sub_tree.`" . $this->primarykey() . "`
								$mtselect
								$submtselect
						GROUP BY node.`" . $this->primarykey() . "`
						HAVING depth = 1
						ORDER BY node.`" . $this->__LEFT_FIELD . "`
						";
		return $this->query($sql, $Data, true);
	}
	
	// Finds the root node of the current node
	function root() {
		if ($this->multitree()) {
			$mtvalue = $this->mtvalue();
		}
		$this->clear();
		$this->left = 1;
		if ($this->multitree()) $this->mtvalue($mtvalue);
		return $this->find();
	}
	
	// Finds the immediate parent of the current node
	function parent() {
		$tree_id = $this->pkvalue();
		$Data = array( ':left' => $this->leftvalue(), ':right' => $this->rightvalue() );
		if ($this->multitree()) {
			$mtselect = "and node.`" . $this->multitree() . "` = :mtvalue ";
			$Data[':mtvalue'] = $this->mtvalue();
		}
		$this->clear();
		$sql = "SELECT * FROM `" . $this->__TableName . "` as node
					where 
						node.`" . $this->__RIGHT_FIELD . "` > node.`" . $this->__LEFT_FIELD . "` + 1
						and node.`" . $this->__RIGHT_FIELD . "` > :right and node.`" . $this->__LEFT_FIELD . "` < :left
						$mtselect
					order by node.`" . $this->__LEFT_FIELD . "` DESC
					limit 1";
		return $this->query($sql, $Data, true);
	}
	
	// Returns the path from this node to the root
	function path() {
		$tree_id = $this->pkvalue();
		$Data = array( ':tree_id' => $tree_id );
		if ($this->multitree()) {
			$mtselect = "and parent.`" . $this->multitree() . "` = :mtvalue and node.`" . $this->multitree() . "` = :mtvalue";
			$Data[':mtvalue'] = $this->mtvalue();
		}
		$this->clear();
		$sql = "SELECT parent.* FROM `" . $this->__TableName . "` as node, `" . $this->__TableName . "` as parent
					where 
						node.`" . $this->__LEFT_FIELD . "` between parent.`" . $this->__LEFT_FIELD . "` and parent.`" . $this->__RIGHT_FIELD . "`
						and node.`" . $this->primarykey() . "` = :tree_id
						$mtselect
					order by node.`" . $this->__LEFT_FIELD . "`";
					
		return $this->query($sql, $Data, true);
	}
	
	// returns all the leaves of the current tree
	function leaves() {
		$Data = array(  );
		if ($this->multitree()) {
			$mtselect = "and node.`" . $this->multitree() . "` = :mtvalue ";
			$Data[':mtvalue'] = $this->mtvalue();
		}
		$this->clear();
		$sql = "SELECT * FROM `" . $this->__TableName . "` as node
					where 
						node.`" . $this->__RIGHT_FIELD . "` = node.`" . $this->__LEFT_FIELD . "` + 1
						$mtselect
					order by node.`" . $this->__LEFT_FIELD . "`";
					
		return $this->query($sql, $Data, true);
	}
	
	// Returns the depth of every subordinate node from here
	function depthsound() {
		$tree_id = $this->pkvalue();
		$Data = array( ':tree_id' => $tree_id );
		if ($this->multitree()) {
			$mtselect = "and node.`" . $this->multitree() . "` = :mtvalue and parent.`" . $this->multitree() . "` = :mtvalue";
			$submtselect = "and sub_parent.`" . $this->multitree() . "` = :mtvalue";
			$Data[':mtvalue'] = $this->mtvalue();
		}
		$this->clear();
		$sql = "
					SELECT node.*, (COUNT(parent.`" . $this->primarykey() . "`) - (sub_tree.depth + 1)) AS depth
						FROM `" . $this->__TableName . "` AS node,
								`" . $this->__TableName . "` AS parent,
								`" . $this->__TableName . "` AS sub_parent,
								(
										SELECT node.`" . $this->primarykey() . "`, (COUNT(parent.`" . $this->primarykey() . "`) - 1) AS depth
											FROM `" . $this->__TableName . "` AS node,
												`" . $this->__TableName . "` AS parent
											WHERE node.`" . $this->__LEFT_FIELD . "` BETWEEN parent.`" . $this->__LEFT_FIELD . "` AND parent.`" . $this->__RIGHT_FIELD . "`
												AND node.`" . $this->primarykey() . "` = :tree_id
												$mtselect
											GROUP BY node.`" . $this->primarykey() . "`
											ORDER BY node.`" . $this->__LEFT_FIELD . "`
								)AS sub_tree
						WHERE node.`" . $this->__LEFT_FIELD . "` BETWEEN parent.`" . $this->__LEFT_FIELD . "` AND parent.`" . $this->__RIGHT_FIELD . "`
								AND node.`" . $this->__LEFT_FIELD . "` BETWEEN sub_parent.`" . $this->__LEFT_FIELD . "` AND sub_parent.`" . $this->__RIGHT_FIELD . "`
								AND sub_parent.`" . $this->primarykey() . "` = sub_tree.`" . $this->primarykey() . "`
								$mtselect
								$submtselect
						GROUP BY node.`" . $this->primarykey() . "`
						ORDER BY node.`" . $this->__LEFT_FIELD . "`";
		return $this->query($sql, $Data, true);
	}	
	
	function _depthsound() {
		$tree_id = $this->pkvalue();
		$Data = array( );
		if ($this->multitree()) {
			$mtselect = "and parent.`" . $this->multitree() . "` = :mtvalue and node.`" . $this->multitree() . "` = :mtvalue ";
			$Data[':mtvalue'] = $this->mtvalue();
		}
		$this->clear();
		$sql = "SELECT node.*, (count(parent.`" . $this->primarykey() . "`) - 1) as depth 
					FROM `" . $this->__TableName . "` as node, `" . $this->__TableName . "` as parent
					where 
						node.`" . $this->__LEFT_FIELD . "` between parent.`" . $this->__LEFT_FIELD . "` and parent.`" . $this->__RIGHT_FIELD . "`
						$mtselect
					group by node.`" . $this->primarykey() . "`
					order by node.`" . $this->__LEFT_FIELD . "`";
		return $this->query($sql, $Data, true);
	}

	// Returns the path depth of the current node to the root
	function length() {
		return $this->path();
	}
	
	protected function _save_child(& $yapo, $child, $insert_left = false) {

	}
	
	function save($new_child = false, $after_id = false) {
		$this->beginTransaction();
		try {
			if (is_object($new_child) && get_class($new_child) == 'YapoGraphNode') {
				if ($this->activerecord()) {
					$yapo = new Yapo($this->__Database, $this->__TableName);
					$yapo->clear();
					if ($this->multitree()) {
						$this->_save_child($yapo, $new_child, $after_id);
					}
					$this->commit();
					parent::save($new_child);
				} else {
					throw new Exception('Multiple child insert not supported');
				}
			} else {
				// Creates a new tree
				if (!$this->activerecord()) {
					$yapo = new Yapo($this->__Database, $this->__TableName);
					$yapo->clear();
					$mtfield = $this->multitree();
					$yapo->$mtfield = $this->mtvalue();
					if ($yapo->find() > 0)
						return 0;
					$this->leftvalue(1);
					$this->rightvalue(2);
				}
				$this->commit();
				return parent::save();
			}
		}  catch (PDOException $pdo) {
			$this->rollback();
			throw $pdo;
		}
		return 0;
	}
	
	// Removes this node
	function excise() {

	}
	
	function delete() {
		parent::delete();
	}
	
	// Changes this subtree into it's own tree
	function promote($mtvalue = null) {

	}
	
	function move($node_id, $parent_node_id) {
		$this->beginTransaction();
		
		try {
			$this->clear();
			$this->pkvalue($parent_node_id);
			$this->find();
			
			$newpos = $this->leftvalue() + 1;
			
			$this->clear();
			$this->pkvalue($node_id);
			$this->find();
			
			$width = $this->rightvalue() - $this->leftvalue() + 1;
			$distance = $newpos - $this->leftvalue();
			$tmppos = $this->leftvalue();
			
			if ($distance < 0) {
				$distance -= $width;
				$tmppos += $width;
			}
			
			$width = array( ":width" => $width );
			$distance = array( ":distance" => $distance );
			$tmppos = array( ":tmppos" => $tmppos );
			$newpos = array( ":newpos" => $newpos );
			$mtvalue = array( ':mtvalue' => $this->mtvalue() );
			$oldrpos = array( ':oldrpos' => $this->rightvalue() );
			
			// make space for sub-tree
			$sql = "update `" . $this->__TableName . "` 
						set 
							`" . $this->__LEFT_FIELD . "` = `" . $this->__LEFT_FIELD . "` + :width
						where
							`" . $this->__LEFT_FIELD . "` >= :newpos and
							`" . $this->multitree() . "` = :mtvalue";
			$this->execute($sql, array_merge($width, $newpos, $mtvalue));
			//print_r(array($sql, array_merge($width, $newpos, $mtvalue)));
			$sql = "update `" . $this->__TableName . "` 
						set 
							`" . $this->__RIGHT_FIELD . "` = `" . $this->__RIGHT_FIELD . "` + :width
						where
							`" . $this->__RIGHT_FIELD . "` >= :newpos and
							`" . $this->multitree() . "` = :mtvalue";
			$this->execute($sql, array_merge($width, $newpos, $mtvalue));
			//print_r(array($sql, array_merge($width, $newpos, $mtvalue)));
			
			// move subtree
			$sql = "update `" . $this->__TableName . "` 
						set 
							`" . $this->__LEFT_FIELD . "` = `" . $this->__LEFT_FIELD . "` + :distance,
							`" . $this->__RIGHT_FIELD . "` = `" . $this->__RIGHT_FIELD . "` + :distance
						where
							`" . $this->__LEFT_FIELD . "` >= :tmppos and
							`" . $this->__RIGHT_FIELD . "` < :tmppos + :width and
							`" . $this->multitree() . "` = :mtvalue";
			$this->execute($sql, array_merge($distance, $width, $mtvalue, $tmppos));
			//print_r(array($sql, array_merge($distance, $width, $mtvalue, $tmppos)));
			
			// remove old space
			$sql = "update `" . $this->__TableName . "` 
						set 
							`" . $this->__LEFT_FIELD . "` = `" . $this->__LEFT_FIELD . "` - :width
						where
							`" . $this->__LEFT_FIELD . "` > :oldrpos and
							`" . $this->multitree() . "` = :mtvalue";
			$this->execute($sql, array_merge($width, $oldrpos, $mtvalue));
			//print_r(array($sql, array_merge($width, $oldrpos, $mtvalue)));
			$sql = "update `" . $this->__TableName . "` 
						set 
							`" . $this->__RIGHT_FIELD . "` = `" . $this->__RIGHT_FIELD . "` - :width
						where
							`" . $this->__RIGHT_FIELD . "` > :oldrpos and
							`" . $this->multitree() . "` = :mtvalue";
			$this->execute($sql, array_merge($width, $oldrpos, $mtvalue));
			//print_r(array($sql, array_merge($width, $oldrpos, $mtvalue)));
			

		} catch (PDOException $pdo) {
			$this->rollback();
			throw $pdo;
		}
	}
	
	function insert($node_id, $insert_parent_id) {
		$this->beginTransaction();
		
		try {
			$this->clear();
			$this->pkvalue($node_id);

			$this->find();
			$from_mtvalue = array( ':from_mtvalue' => $this->mtvalue() );
			$width = array( ':width' => $this->rightvalue() - $this->leftvalue() + 1 );
			
			$this->clear();
			$this->pkvalue($insert_parent_id);
			$this->find();

			$to_mtvalue = array( ':to_mtvalue' => $this->mtvalue() );
			$to_left = array( ':to_left' => $this->leftvalue() );
			
			// make space for sub-tree
			$sql = "update `" . $this->__TableName . "` 
						set 
							`" . $this->__RIGHT_FIELD . "` = `" . $this->__RIGHT_FIELD . "` + :width
						where
							`" . $this->__RIGHT_FIELD . "` > :to_left and
							`" . $this->multitree() . "` = :to_mtvalue";
			$this->execute($sql, array_merge($to_mtvalue, $width, $to_left));
			
			$sql = "update `" . $this->__TableName . "` 
						set 
							`" . $this->__LEFT_FIELD . "` = `" . $this->__LEFT_FIELD . "` + :width
						where
							`" . $this->__LEFT_FIELD . "` > :to_left and
							`" . $this->multitree() . "` = :to_mtvalue";
			$this->execute($sql, array_merge($to_mtvalue, $width, $to_left));
			
			// insert sub-tree
			$sql = "update `" . $this->__TableName . "` 
						set 
							`" . $this->__LEFT_FIELD . "` = `" . $this->__LEFT_FIELD . "` + :to_left,
							`" . $this->__RIGHT_FIELD . "` = `" . $this->__RIGHT_FIELD . "` + :to_left,
							`" . $this->multitree() . "` = :to_mtvalue
						where
							`" . $this->multitree() . "` = :from_mtvalue";
			$this->execute($sql, array_merge($from_mtvalue, $to_left, $to_mtvalue));
			
			$this->commit();
		} catch (PDOException $pdo) {
			$this->rollback();
			throw $pdo;
		}
		return true;
		//print_r(array($sql, array_merge($from_mtvalue, $to_left, $to_mtvalue)));
	}
	
}