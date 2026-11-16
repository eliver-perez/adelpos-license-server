<?php
declare(strict_types=1);
namespace AdelPos\License\Infrastructure;

use BaconQrCode\Renderer\{ImageRenderer, Image\SvgImageBackEnd, RendererStyle\RendererStyle};
use BaconQrCode\Writer;
use Dompdf\{Dompdf, Options};

final class CertificatePdf
{
    public const BRAND_FILE = 'adel-pos-tema-claro.png';
    public const BRAND_SHA256 = 'fece00410137a618703c5921d2ce2aac5ef06227836b75505201e58125e05434';

    /**
     * In-memory copy at print resolution (812 px wide, 300 dpi at the 195 pt logo width).
     * The 2048 px source would add about 300 KB to every stored PDF; the file is not modified.
     */
    private static function printSize(string $png): string
    {
        $source = imagecreatefromstring($png);
        if ($source === false) { throw new \RuntimeException('Unreadable PDF brand file.'); }
        $width = 812; $height = (int) round(imagesy($source) * $width / imagesx($source));
        $copy = imagecreatetruecolor($width, $height);
        imagealphablending($copy, false); imagesavealpha($copy, true);
        imagefill($copy, 0, 0, imagecolorallocatealpha($copy, 0, 0, 0, 127));
        imagecopyresampled($copy, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        ob_start();
        try { imagepng($copy, null, 9); return (string) ob_get_contents(); }
        finally { ob_end_clean(); }
    }

    public function render(array $document): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled',false);
        $options->set('isPhpEnabled',false);
        $options->set('isJavascriptEnabled',false);
        $options->set('allowedProtocols',['data://'=>['rules'=>[]]]);
        $options->set('chroot',dirname(__DIR__,2).'/public/assets/brand');
        $options->set('defaultFont','DejaVu Sans');
        $options->set('isFontSubsettingEnabled',true);
        $options->set('fontCache',sys_get_temp_dir());
        $options->set('tempDir',sys_get_temp_dir());
        $pdf = new Dompdf($options);
        // Owner decision: certificates use the supplied light logo unchanged. Its SHA-256 is
        // pinned, so a replaced file is noticed. Without it the template prints the name as text.
        $brand=dirname(__DIR__,2).'/public/assets/brand/'.self::BRAND_FILE;
        $logo = null;
        if (is_file($brand)) {
            $png=file_get_contents($brand);
            if (!hash_equals(self::BRAND_SHA256,hash('sha256',$png))) { throw new \RuntimeException('PDF brand file checksum mismatch.'); }
            $logo = 'data:image/png;base64,'.base64_encode(self::printSize($png));
        }
        $writer = new Writer(new ImageRenderer(new RendererStyle(150),new SvgImageBackEnd()));
        $qr = 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($document['verification_url']));
        ob_start();
        try { require dirname(__DIR__,2).'/templates/certificate-pdf.php'; $html = ob_get_contents(); }
        finally { ob_end_clean(); }
        $pdf->loadHtml($html,'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        return $pdf->output();
    }
}
