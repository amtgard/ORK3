<?php

class Model_AmtgardIdp extends Model {
    function __construct() {
        parent::__construct();
    }

    public function exchangeAuthCodeForAccessToken($code, $codeVerifier)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, IDP_API_URL . '/oauth/token');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => IDP_CLIENT_ID,
            'client_secret' => IDP_CLIENT_SECRET,
            'redirect_uri' => UIR . 'Login/oauth_callback',
            'code' => $code,
            'code_verifier' => $codeVerifier,
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $curl_err = curl_error($ch);
        curl_close($ch);

        $token_data = json_decode($response, true);
        if (!isset($token_data['access_token'])) {
            die("OAuth Callback: Failed to get access token. Response: $response. Curl Error: $curl_err");
        }
        return $token_data;
    }

    public function fetchUserInfo($accessToken)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, IDP_API_URL . '/resources/userinfo');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $user_response = curl_exec($ch);
        curl_close($ch);

        $user_data = json_decode($user_response, true);
        if (isset($user_data['sub'])) {
            $user_data['id'] = $user_data['sub'];
        }

        if (!isset($user_data['id'])) {
            return ['error' => true, 'response' => $user_response];
        }

        return $user_data;
    }

}