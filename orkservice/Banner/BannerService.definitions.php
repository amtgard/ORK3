<?php

$server->wsdl->addComplexType(
    'SetBannerRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'Type' => array('name' => 'Type','type' => 'xsd:string'),
                'Id' => array('name' => 'Id','type' => 'xsd:int'),
                'Banner' => array('name' => 'Banner','type' => 'xsd:string'),
                'BannerMimeType' => array('name' => 'BannerMimeType','type' => 'xsd:string'),
                'ShowLogo' => array('name' => 'ShowLogo','type' => 'xsd:int'),
                'Vignette' => array('name' => 'Vignette','type' => 'xsd:int'),
                'OffsetX' => array('name' => 'OffsetX','type' => 'xsd:int'),
                'OffsetY' => array('name' => 'OffsetY','type' => 'xsd:int'),
            )
);

$server->wsdl->addComplexType(
    'UpdateBannerConfigRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'Type' => array('name' => 'Type','type' => 'xsd:string'),
                'Id' => array('name' => 'Id','type' => 'xsd:int'),
                'ShowLogo' => array('name' => 'ShowLogo','type' => 'xsd:int'),
                'Vignette' => array('name' => 'Vignette','type' => 'xsd:int'),
                'OffsetX' => array('name' => 'OffsetX','type' => 'xsd:int'),
                'OffsetY' => array('name' => 'OffsetY','type' => 'xsd:int'),
            )
);

$server->wsdl->addComplexType(
    'RemoveBannerRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'Type' => array('name' => 'Type','type' => 'xsd:string'),
                'Id' => array('name' => 'Id','type' => 'xsd:int'),
            )
);

$server->wsdl->addComplexType(
    'CopyBannerRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'Type' => array('name' => 'Type','type' => 'xsd:string'),
                'SourceId' => array('name' => 'SourceId','type' => 'xsd:int'),
                'TargetId' => array('name' => 'TargetId','type' => 'xsd:int'),
            )
);
