<?php

include_once(Yapo::$DIR_DRIVER . '/structure.Interface.YapoNestedSetTree.php');

class PostgresqlYapoNestedSetTree extends InterfaceYapoNestedSetTree {

	function __construct(& $database, $table, $left_field, $right_field, $tree_field = null) {
		parent::__construct($database, $table, $left_field, $right_field, $tree_field);
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
	
	// Return the all nodes of the tree from the current node
	function tree($order = YapoTree::NEXT_TREE_DEPTH) {
		$tree_id = $this->pkvalue();
		$Data = array( ':tree_id' => $tree_id );
		if ($this->multitree()) {
			$mtselect = "and parent.\"" . $this->multitree() . "\" = :mtvalue and node.\"" . $this->multitree() . "\" = :mtvalue ";
			$Data[':mtvalue'] = $this->mtvalue();
		}
		$this->clear();
		$sql = "SELECT node.* FROM \"" . $this->__TableName . "\" as node, \"" . $this->__TableName . "\" as parent
					where 
						node.\"" . $this->__LEFT_FIELD . "\" between parent.\"" . $this->__LEFT_FIELD . "\" and parent.\"" . $this->__RIGHT_FIELD . "\"
						and parent.\"" . $this->primarykey() . "\" = :tree_id
						$mtselect
					order by node.\"" . $this->__LEFT_FIELD . "\"";
		return $this->query($sql, $Data, true);
	}
	
	// Return children of the current node
	function children($gt = null) {
		$tree_id = $this->pkvalue();
		$Data = array( ':tree_id' => $tree_id );
		if ($this->multitree()) {
			$mtselect = "and node.\"" . $this->multitree() . "\" = :mtvalue and parent.\"" . $this->multitree() . "\" = :mtvalue";
			$submtselect = "and sub_parent.\"" . $this->multitree() . "\" = :mtvalue";
			$Data[':mtvalue'] = $this->mtvalue();
		}
		$this->clear();
		$sql = "
					SELECT node.group_graph_id, node.group_id, node.client_id, node.\"left\", node.\"right\", node.machine_control, (COUNT(parent.\"" . $this->primarykey() . "\") - (sub_tree.depth + 1)) AS depth
						FROM \"" . $this->__TableName . "\" AS node,
								\"" . $this->__TableName . "\" AS parent,
								\"" . $this->__TableName . "\" AS sub_parent,
								(
										SELECT node.\"" . $this->primarykey() . "\", (COUNT(parent.\"" . $this->primarykey() . "\") - 1) AS depth
											FROM \"" . $this->__TableName . "\" AS node,
												\"" . $this->__TableName . "\" AS parent
											WHERE node.\"" . $this->__LEFT_FIELD . "\" BETWEEN parent.\"" . $this->__LEFT_FIELD . "\" AND parent.\"" . $this->__RIGHT_FIELD . "\"
												AND node.\"" . $this->primarykey() . "\" = :tree_id
												$mtselect
											GROUP BY node.\"" . $this->primarykey() . "\"
											ORDER BY node.\"" . $this->__LEFT_FIELD . "\"
								) AS sub_tree
						WHERE node.\"" . $this->__LEFT_FIELD . "\" BETWEEN parent.\"" . $this->__LEFT_FIELD . "\" AND parent.\"" . $this->__RIGHT_FIELD . "\"
								AND node.\"" . $this->__LEFT_FIELD . "\" BETWEEN sub_parent.\"" . $this->__LEFT_FIELD . "\" AND sub_parent.\"" . $this->__RIGHT_FIELD . "\"
								AND sub_parent.\"" . $this->primarykey() . "\" = sub_tree.\"" . $this->primarykey() . "\"
								$mtselect
								$submtselect
						GROUP BY node.\"" . $this->primarykey() . "\"
						HAVING depth = 1
						ORDER BY node.\"" . $this->__LEFT_FIELD . "\"
						";
		return $this->query($sql, $Data, true);
	}
	
	// Finds the immediate parent of the current node
	function parent() {
		$tree_id = $this->pkvalue();
		$Data = array( ':left' => $this->leftvalue(), ':right' => $this->rightvalue() );
		if ($this->multitree()) {
			$mtselect = "and node.\"" . $this->multitree() . "\" = :mtvalue ";
			$Data[':mtvalue'] = $this->mtvalue();
		}
		$this->clear();
		$sql = "SELECT * FROM \"" . $this->__TableName . "\" as node
					where 
						node.\"" . $this->__RIGHT_FIELD . "\" > node.\"" . $this->__LEFT_FIELD . "\" + 1
						and node.\"" . $this->__RIGHT_FIELD . "\" > :right and node.\"" . $this->__LEFT_FIELD . "\" < :left
						$mtselect
					order by node.\"" . $this->__LEFT_FIELD . "\" DESC
					limit 1";
		return $this->query($sql, $Data, true);
	}
	
	// Returns the path from this node to the root
	function path() {
		$tree_id = $this->pkvalue();
		$Data = array( ':tree_id' => $tree_id );
		if ($this->multitree()) {
			$mtselect = "and parent.\"" . $this->multitree() . "\" = :mtvalue and node.\"" . $this->multitree() . "\" = :mtvalue";
			$Data[':mtvalue'] = $this->mtvalue();
		}
		$this->clear();
		$sql = "SELECT parent.* FROM \"" . $this->__TableName . "\" as node, \"" . $this->__TableName . "\" as parent
					where 
						node.\"" . $this->__LEFT_FIELD . "\" between parent.\"" . $this->__LEFT_FIELD . "\" and parent.\"" . $this->__RIGHT_FIELD . "\"
						and node.\"" . $this->primarykey() . "\" = :tree_id
						$mtselect
					order by node.\"" . $this->__LEFT_FIELD . "\"";
					
		return $this->query($sql, $Data, true);
	}
	
	// returns all the leaves of the current tree
	function leaves() {
		$Data = array(  );
		if ($this->multitree()) {
			$mtselect = "and node.\"" . $this->multitree() . "\" = :mtvalue ";
			$Data[':mtvalue'] = $this->mtvalue();
		}
		$this->clear();
		$sql = "SELECT * FROM \"" . $this->__TableName . "\" as node
					where 
						node.\"" . $this->__RIGHT_FIELD . "\" = node.\"" . $this->__LEFT_FIELD . "\" + 1
						$mtselect
					order by node.\"" . $this->__LEFT_FIELD . "\"";
					
		return $this->query($sql, $Data, true);
	}
	
	// Returns the depth of every subordinate node from here
	function depthsound() {
		$tree_id = $this->pkvalue();
		$Data = array( ':tree_id' => $tree_id );
		if ($this->multitree()) {
			$mtselect = "and node.\"" . $this->multitree() . "\" = :mtvalue and parent.\"" . $this->multitree() . "\" = :mtvalue";
			$submtselect = "and sub_parent.\"" . $this->multitree() . "\" = :mtvalue";
			$Data[':mtvalue'] = $this->mtvalue();
		}
		$this->clear();
		$sql = "
					SELECT node.group_graph_id, node.group_id, node.client_id, node.\"left\", node.\"right\", node.machine_control, (COUNT(parent.\"" . $this->primarykey() . "\") - (sub_tree.depth + 1)) AS depth
						FROM \"" . $this->__TableName . "\" AS node,
								\"" . $this->__TableName . "\" AS parent,
								\"" . $this->__TableName . "\" AS sub_parent,
								(
										SELECT node.\"" . $this->primarykey() . "\", (COUNT(parent.\"" . $this->primarykey() . "\") - 1) AS depth
											FROM \"" . $this->__TableName . "\" AS node,
												\"" . $this->__TableName . "\" AS parent
											WHERE node.\"" . $this->__LEFT_FIELD . "\" BETWEEN parent.\"" . $this->__LEFT_FIELD . "\" AND parent.\"" . $this->__RIGHT_FIELD . "\"
												AND node.\"" . $this->primarykey() . "\" = :tree_id
												$mtselect
											GROUP BY node.\"" . $this->primarykey() . "\"
											ORDER BY node.\"" . $this->__LEFT_FIELD . "\"
								)AS sub_tree
						WHERE node.\"" . $this->__LEFT_FIELD . "\" BETWEEN parent.\"" . $this->__LEFT_FIELD . "\" AND parent.\"" . $this->__RIGHT_FIELD . "\"
								AND node.\"" . $this->__LEFT_FIELD . "\" BETWEEN sub_parent.\"" . $this->__LEFT_FIELD . "\" AND sub_parent.\"" . $this->__RIGHT_FIELD . "\"
								AND sub_parent.\"" . $this->primarykey() . "\" = sub_tree.\"" . $this->primarykey() . "\"
								$mtselect
								$submtselect
						GROUP BY node.\"" . $this->primarykey() . "\"
						ORDER BY node.\"" . $this->__LEFT_FIELD . "\"";
		return $this->query($sql, $Data, true);
	}	
	
	function _depthsound() {
		$tree_id = $this->pkvalue();
		$Data = array( );
		if ($this->multitree()) {
			$mtselect = "and parent.\"" . $this->multitree() . "\" = :mtvalue and node.\"" . $this->multitree() . "\" = :mtvalue ";
			$Data[':mtvalue'] = $this->mtvalue();
		}
		$this->clear();
		$sql = "SELECT node.group_graph_id, node.group_id, node.client_id, node.\"left\", node.\"right\", node.machine_control, (count(parent.\"" . $this->primarykey() . "\") - 1) as depth 
					FROM \"" . $this->__TableName . "\" as node, \"" . $this->__TableName . "\" as parent
					where 
						node.\"" . $this->__LEFT_FIELD . "\" between parent.\"" . $this->__LEFT_FIELD . "\" and parent.\"" . $this->__RIGHT_FIELD . "\"
						$mtselect
					group by node.\"" . $this->primarykey() . "\"
					order by node.\"" . $this->__LEFT_FIELD . "\"";
		return $this->query($sql, $Data, true);
	}
	
	protected function _save_child(& $yapo, $child, $insert_left = false) {
		$insert_left = $insert_left ? $insert_left : $this->leftvalue();
		$mtvalue = $this->mtvalue();
		$mtdata = array();
		if ($this->multitree()) {
			$mtselect = "and \"" . $this->multitree() . "\" = :mtvalue";
			$mtdata[':mtvalue'] = $this->mtvalue();
		}
		
		foreach ($child as $field => $value) {
			$yapo->$field = $value;
		}
		$lv = $this->__LEFT_FIELD;
		$rv = $this->__RIGHT_FIELD;
		$mt = $this->__TREE_FIELD;

		$yapo->$mt = $mtvalue;
		
		$child_id = $yapo->save();
		
		if ($child_id > 0) {
			
			$Data = array( ':insert_left' => $insert_left );
			$sql = "update \"" . $this->__TableName . "\" 
						set 
							\"" . $this->__RIGHT_FIELD . "\" = \"" . $this->__RIGHT_FIELD . "\" + 2 
						where 
							\"" . $this->__RIGHT_FIELD . "\" > :insert_left
							$mtselect";

			$this->execute($sql, array_merge($mtdata, $Data));
			
			$Data = array( ':insert_left' => $insert_left );
			$sql = "update \"" . $this->__TableName . "\" 
						set 
							\"" . $this->__LEFT_FIELD . "\" = \"" . $this->__LEFT_FIELD . "\" + 2 
						where 
							\"" . $this->__LEFT_FIELD . "\" > :insert_left
							$mtselect";
			
			$this->execute($sql, array_merge($mtdata, $Data));
			
			$yapo->$lv = $insert_left + 1;
			$yapo->$rv = $insert_left + 2;

			$yapo->save();
			
			return $child_id;
		}
		
		return 0;
	}
	
	// Removes this node
	function excise() {
		$this->beginTransaction();
		
		try {
			$tree_id = $this->pkvalue();
			$Data = array( );
			if ($this->multitree()) {
				$mtselect = "and \"" . $this->multitree() . "\" = :mtvalue ";
				$Data[':mtvalue'] = $this->mtvalue();
			}
			$left = array( ':left' => $this->leftvalue() );
			$right = array( ':right' => $this->rightvalue() );
			
			parent::delete();
			
			$sql = "UPDATE \"" . $this->__TableName . "\" 
						SET 
							\"" . $this->__RIGHT_FIELD . "\" = \"" . $this->__RIGHT_FIELD . "\" - 1, 
							\"" . $this->__LEFT_FIELD . "\" = \"" . $this->__LEFT_FIELD . "\" - 1 
						WHERE 
							\"" . $this->__LEFT_FIELD . "\" BETWEEN :left AND :right
							$mtselect";
			$this->execute($sql, array_merge($Data, $left, $right));
			
			$sql = "UPDATE \"" . $this->__TableName . "\" 
						SET 
							\"" . $this->__RIGHT_FIELD . "\" = \"" . $this->__RIGHT_FIELD . "\" - 2 
						WHERE 
							\"" . $this->__RIGHT_FIELD . "\" > :right
							$mtselect";
			$this->execute($sql, array_merge($Data, $right));
						
			$sql = "UPDATE \"" . $this->__TableName . "\" 
						SET 
							\"" . $this->__LEFT_FIELD . "\" = \"" . $this->__LEFT_FIELD . "\" - 2 
						WHERE 
							\"" . $this->__LEFT_FIELD . "\" > :right
							$mtselect";
			$this->execute($sql, array_merge($Data, $right));
			
			$this->commit();
		} catch (PDOException $pdo) {
			$this->rollback();
			throw $pdo;
		}
	}
	
	function delete() {
		$this->beginTransaction();
		
		try {
			$Data = array( );
			if ($this->multitree()) {
				$mtselect = "and \"" . $this->multitree() . "\" = :mtvalue ";
				$Data[':mtvalue'] = $this->mtvalue();
			}
			$left = array( ':left' => $this->leftvalue() );
			$right = array( ':right' => $this->rightvalue() );
			$width = array( ':width' => $this->rightvalue() - $this->leftvalue() + 1 );
			
			$sql = "delete 
						from \"" . $this->__TableName . "\" 
						where 
							\"" . $this->__LEFT_FIELD . "\" between :left and :right 
							$mtselect;";
			$this->execute($sql, array_merge($Data, $right, $left));
			
			$sql = "update \"" . $this->__TableName . "\" 
						set 
							\"" . $this->__RIGHT_FIELD . "\" = \"" . $this->__RIGHT_FIELD . "\" - :width
						where
							\"" . $this->__RIGHT_FIELD . "\" > :right
							$mtselect";
			$this->execute($sql, array_merge($Data, $width, $right));
			
			$sql = "update \"" . $this->__TableName . "\" 
						set 
							\"" . $this->__LEFT_FIELD . "\" = \"" . $this->__LEFT_FIELD . "\" - :width
						where
							\"" . $this->__LEFT_FIELD . "\" > :right
							$mtselect";
			$this->execute($sql, array_merge($Data, $width, $right));
			
			$this->commit();
		} catch (PDOException $pdo) {
			$this->rollback();
			throw $pdo;
		}
	}
	
	// Changes this subtree into it's own tree
	function promote($mtvalue = null) {
		if (is_null($mtvalue))
			return false;
			
		$this->beginTransaction();
		
		try {
		
			$left = array( ':left' => $this->leftvalue() );
			$right = array( ':right' => $this->rightvalue() );
			$width = array( ':width' => $this->rightvalue() - $this->leftvalue() + 1 );
			$mtdata = array( ':mtvalue' => $this->mtvalue() );
			$Data = array( 
						':mtvalue' => $this->mtvalue(),
						':new_mtvalue' => $mtvalue,
						':position' => $this->leftvalue() - 1);
			$sql = "update \"" . $this->__TableName . "\"
						set 
							\"" . $this->multitree() . "\" = :new_mtvalue,
							\"" . $this->__LEFT_FIELD . "\" = \"" . $this->__LEFT_FIELD . "\" - :position,
							\"" . $this->__RIGHT_FIELD . "\" = \"" . $this->__RIGHT_FIELD . "\" - :position
						where
							\"" . $this->__LEFT_FIELD . "\" >= :left and
							\"" . $this->__RIGHT_FIELD . "\" <= :right and
							\"" . $this->multitree() . "\" = :mtvalue";
			$this->execute($sql, array_merge($left, $right, $mtdata, $Data));
			
			$sql = "update \"" . $this->__TableName . "\" 
						set 
							\"" . $this->__RIGHT_FIELD . "\" = \"" . $this->__RIGHT_FIELD . "\" - :width
						where
							\"" . $this->__RIGHT_FIELD . "\" > :right and
							\"" . $this->multitree() . "\" = :mtvalue";
			$this->execute($sql, array_merge($mtdata, $width, $right));
			
			$sql = "update \"" . $this->__TableName . "\" 
						set 
							\"" . $this->__LEFT_FIELD . "\" = \"" . $this->__LEFT_FIELD . "\" - :width
						where
							\"" . $this->__LEFT_FIELD . "\" > :right and
							\"" . $this->multitree() . "\" = :mtvalue";
			$this->execute($sql, array_merge($mtdata, $width, $right));
			
			$this->commit();
		} catch (PDOException $pdo) {
			$this->rollback();
			throw $pdo;
		}
		return true;
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
			$sql = "update \"" . $this->__TableName . "\" 
						set 
							\"" . $this->__LEFT_FIELD . "\" = \"" . $this->__LEFT_FIELD . "\" + :width
						where
							\"" . $this->__LEFT_FIELD . "\" >= :newpos and
							\"" . $this->multitree() . "\" = :mtvalue";
			$this->execute($sql, array_merge($width, $newpos, $mtvalue));
			//print_r(array($sql, array_merge($width, $newpos, $mtvalue)));
			$sql = "update \"" . $this->__TableName . "\" 
						set 
							\"" . $this->__RIGHT_FIELD . "\" = \"" . $this->__RIGHT_FIELD . "\" + :width
						where
							\"" . $this->__RIGHT_FIELD . "\" >= :newpos and
							\"" . $this->multitree() . "\" = :mtvalue";
			$this->execute($sql, array_merge($width, $newpos, $mtvalue));
			//print_r(array($sql, array_merge($width, $newpos, $mtvalue)));
			
			// move subtree
			$sql = "update \"" . $this->__TableName . "\" 
						set 
							\"" . $this->__LEFT_FIELD . "\" = \"" . $this->__LEFT_FIELD . "\" + :distance,
							\"" . $this->__RIGHT_FIELD . "\" = \"" . $this->__RIGHT_FIELD . "\" + :distance
						where
							\"" . $this->__LEFT_FIELD . "\" >= :tmppos and
							\"" . $this->__RIGHT_FIELD . "\" < :tmppos + :width and
							\"" . $this->multitree() . "\" = :mtvalue";
			$this->execute($sql, array_merge($distance, $width, $mtvalue, $tmppos));
			//print_r(array($sql, array_merge($distance, $width, $mtvalue, $tmppos)));
			
			// remove old space
			$sql = "update \"" . $this->__TableName . "\" 
						set 
							\"" . $this->__LEFT_FIELD . "\" = \"" . $this->__LEFT_FIELD . "\" - :width
						where
							\"" . $this->__LEFT_FIELD . "\" > :oldrpos and
							\"" . $this->multitree() . "\" = :mtvalue";
			$this->execute($sql, array_merge($width, $oldrpos, $mtvalue));
			//print_r(array($sql, array_merge($width, $oldrpos, $mtvalue)));
			$sql = "update \"" . $this->__TableName . "\" 
						set 
							\"" . $this->__RIGHT_FIELD . "\" = \"" . $this->__RIGHT_FIELD . "\" - :width
						where
							\"" . $this->__RIGHT_FIELD . "\" > :oldrpos and
							\"" . $this->multitree() . "\" = :mtvalue";
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
			$sql = "update \"" . $this->__TableName . "\" 
						set 
							\"" . $this->__RIGHT_FIELD . "\" = \"" . $this->__RIGHT_FIELD . "\" + :width
						where
							\"" . $this->__RIGHT_FIELD . "\" > :to_left and
							\"" . $this->multitree() . "\" = :to_mtvalue";
			$this->execute($sql, array_merge($to_mtvalue, $width, $to_left));
			
			$sql = "update \"" . $this->__TableName . "\" 
						set 
							\"" . $this->__LEFT_FIELD . "\" = \"" . $this->__LEFT_FIELD . "\" + :width
						where
							\"" . $this->__LEFT_FIELD . "\" > :to_left and
							\"" . $this->multitree() . "\" = :to_mtvalue";
			$this->execute($sql, array_merge($to_mtvalue, $width, $to_left));
			
			// insert sub-tree
			$sql = "update \"" . $this->__TableName . "\" 
						set 
							\"" . $this->__LEFT_FIELD . "\" = \"" . $this->__LEFT_FIELD . "\" + :to_left,
							\"" . $this->__RIGHT_FIELD . "\" = \"" . $this->__RIGHT_FIELD . "\" + :to_left,
							\"" . $this->multitree() . "\" = :to_mtvalue
						where
							\"" . $this->multitree() . "\" = :from_mtvalue";
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