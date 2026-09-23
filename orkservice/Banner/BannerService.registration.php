<?php

$server->register(
    'BannerSetBanner',
    array('SetBannerRequest' => 'tns:SetBannerRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'BannerUpdateBannerConfig',
    array('UpdateBannerConfigRequest' => 'tns:UpdateBannerConfigRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'BannerRemoveBanner',
    array('RemoveBannerRequest' => 'tns:RemoveBannerRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);

$server->register(
    'BannerCopyBanner',
    array('CopyBannerRequest' => 'tns:CopyBannerRequest'),
    array('return' => 'tns:StatusType'),
    $namespace
);
