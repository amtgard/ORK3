<?php

class Tools extends Ork3
{

	public function __construct()
	{
  }
  
  public function HasRole($request) {
    return (( $mundane_id = Ork3::$Lib->authorization->IsAuthorized( $request[ 'Token' ] ) ) > 0)
            && (Ork3::$Lib->unit->IsMember((array('Name'=>$request['Role'], 'MundaneId'=>$mundane_id)))['Detail']['IsMember']);
  }
  
  
  
}