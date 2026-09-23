<?php

function BannerSetBanner($request)
{
    $b = new Banner();
    return $b->SetBanner($request);
}

function BannerUpdateBannerConfig($request)
{
    $b = new Banner();
    return $b->UpdateBannerConfig($request);
}

function BannerRemoveBanner($request)
{
    $b = new Banner();
    return $b->RemoveBanner($request);
}

function BannerCopyBanner($request)
{
    $b = new Banner();
    return $b->CopyBanner($request);
}
