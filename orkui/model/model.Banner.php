<?php

class Model_Banner extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Banner = new APIModel('Banner');
    }

    public function set_banner(array $request): array
    {
        return $this->Banner->SetBanner($request);
    }

    public function update_config(array $request): array
    {
        return $this->Banner->UpdateBannerConfig($request);
    }

    public function remove_banner(array $request): array
    {
        return $this->Banner->RemoveBanner($request);
    }

    public function handle_ajax(
        string $type,
        string $action,
        int $id,
        string $token,
        array $post,
        array $files,
        int $authDeniedStatus = 5,
    ): void {
        $base = ['Token' => $token, 'Type' => $type, 'Id' => $id];

        if ($action === 'remove') {
            $this->emit_json($this->remove_banner($base), $authDeniedStatus);
        }

        if ($action === 'config') {
            $this->emit_json($this->update_config($base + [
                'ShowLogo' => !empty($post['ShowLogo']) ? 1 : 0,
                'Vignette' => !empty($post['Vignette']) ? 1 : 0,
                'OffsetX' => (int)($post['OffsetX'] ?? 50),
                'OffsetY' => (int)($post['OffsetY'] ?? 50),
            ]), $authDeniedStatus);
        }

        if ($action === 'update') {
            if (empty($files['Banner']['tmp_name'])) {
                echo json_encode(['status' => 1, 'error' => 'No file uploaded.']);
                exit;
            }
            if (!is_uploaded_file($files['Banner']['tmp_name'])) {
                echo json_encode(['status' => 1, 'error' => 'Invalid upload.']);
                exit;
            }

            $tmp = $files['Banner']['tmp_name'];
            $detectedType = @exif_imagetype($tmp);
            $mime = ($detectedType === IMAGETYPE_PNG) ? 'image/png' : 'image/jpeg';

            $this->emit_json($this->set_banner($base + [
                'Banner' => base64_encode((string)file_get_contents($tmp)),
                'BannerMimeType' => $mime,
                'ShowLogo' => !empty($post['ShowLogo']) ? 1 : 0,
                'Vignette' => !empty($post['Vignette']) ? 1 : 0,
                'OffsetX' => (int)($post['OffsetX'] ?? 50),
                'OffsetY' => (int)($post['OffsetY'] ?? 50),
            ]), $authDeniedStatus);
        }

        echo json_encode(['status' => 1, 'error' => 'Unknown action.']);
        exit;
    }

    public function emit_json(array $response, int $authDeniedStatus = 5): void
    {
        $status = (int)($response['Status'] ?? 1);
        if ($status === ServiceErrorIds::Success) {
            echo json_encode(['status' => 0]);
            exit;
        }

        $detail = (string)($response['Detail'] ?? '');
        $error = $detail !== '' ? $detail : (string)($response['Error'] ?? 'Error');
        if ($status === ServiceErrorIds::NoAuthorization && $authDeniedStatus === 3 && $detail === '') {
            $error = 'Not authorized.';
        }
        $jsonStatus = match ($status) {
            ServiceErrorIds::NoAuthorization => $authDeniedStatus,
            ServiceErrorIds::SecureTokenFailure => 5,
            default => 1,
        };

        echo json_encode(['status' => $jsonStatus, 'error' => $error]);
        exit;
    }
}
