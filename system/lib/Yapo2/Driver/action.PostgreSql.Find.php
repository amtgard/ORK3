<?php

class YapoPostgreSqlFind extends YapoFind {
		
	function PaginationSql() {
        list($pagination, $page) = $this->Core->GetLimit();
		$lsql = '';
        if (!is_null($pagination)) {
            $p = $page * $pagination;
            $lsql = " limit $pagination offset $p";
        }
		return $lsql;
	}
		
}

?>