<?php

function Authorize($request)
{
    $A = new Authorization();
    return $A->Authorize($request);
}

function XSiteAuthorize($request)
{
    $A = new Authorization();
    return $A->XSiteAuthorize($request);
}

// Read-only token check for third-party verification: never rotates the token.
// The JSON gateway does not come through here (JsonServer calls the lib method
// directly); this wrapper is the SOAP path.
function WhoAmI($request)
{
    $A = new Authorization();
    return $A->WhoAmI($request);
}

function AddAuthorization($request)
{
    $A = new Authorization();
    return $A->AddAuthorization($request);
}

function RemoveAuthorization($request)
{
    $A = new Authorization();
    return $A->RemoveAuthorization($request);
}

function ResetPassword($request)
{
    $A = new Authorization();
    return $A->ResetPassword($request);
}

function GetAuthorizations($request)
{
    $A = new Authorization();
    return $A->GetAuthorizations($request);
}

function HasAuthority($request)
{
    $gate = new AuthorizationGate();
    return $gate->HasAuthority($request);
}
