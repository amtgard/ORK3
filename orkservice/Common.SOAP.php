<?php

if (!isset($server)) return;

if (is_object($server)) {

	$server->wsdl->addComplexType(
			'StatusType',
			'complexType',
			'struct',
			'all',
			'',
			array(
					'Status'=>array('name'=>'Status','type'=>'xsd:int'),
					'Error'=>array('name'=>'Error','type'=>'xsd:string'),
					'Detail'=>array('name'=>'Detail','type'=>'xsd:string')
				)
		);
		 
	$server->wsdl->addComplexType(
			'ConfigurationItemType',
			'complexType',
			'struct',
			'all',
			'',
			array(
					'ConfigurationId'=>array('name'=>'ConfigurationId','type'=>'xsd:int'),
					'Key'=>array('name'=>'Key','type'=>'xsd:string'),
					'Value'=>array('name'=>'Value','type'=>'xsd:string')
				)
		);
		

	$server->wsdl->addComplexType(
			'ConfigurationListType',
			'complexType',
			'array',
			'',
			'SOAP-ENC:Array',
			array(),
			array(
				array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:ConfigurationItemType[]')
				),
			'tns:ConfigurationItemType'
		);
		
	$server->wsdl->addComplexType(
			'ConfigurationEditItemType',
			'complexType',
			'struct',
			'all',
			'',
			array(
					'ConfigurationId'=>array('name'=>'ConfigurationId','type'=>'xsd:int'),
					'Action'=>array('name'=>'Action','type'=>'xsd:string'),
					'Key'=>array('name'=>'Key','type'=>'xsd:string'),
					'Value'=>array('name'=>'Value','type'=>'xsd:string')
				)
		);
		

	$server->wsdl->addComplexType(
			'ConfigurationEditListType',
			'complexType',
			'array',
			'',
			'SOAP-ENC:Array',
			array(),
			array(
				array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:ConfigurationEditItemType[]')
				),
			'tns:ConfigurationEditItemType'
		);
}
?>