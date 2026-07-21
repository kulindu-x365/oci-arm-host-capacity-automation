<?php
declare(strict_types=1);

namespace Hitrov;

class PrivateKeyResolver
{
    /**
     * Returns a filename usable as OciConfig's privateKeyFilename.
     *
     * If OCI_PRIVATE_KEY_CONTENT is set (the raw .pem contents, e.g. pasted into
     * a Railway environment variable), it's written to a temp file and that path
     * is returned - this avoids ever needing the key as a file in the repo.
     * Otherwise falls back to OCI_PRIVATE_KEY_FILENAME (a local path or URL), as before.
     */
    public static function resolveFilename(): string
    {
        $content = (string) getenv('OCI_PRIVATE_KEY_CONTENT');
        if ($content === '') {
            return (string) getenv('OCI_PRIVATE_KEY_FILENAME');
        }

        // allow pasting the key as a single line with literal \n sequences
        $content = str_replace('\\n', "\n", $content);

        $filename = rtrim(sys_get_temp_dir(), '/\\') . '/oci_api_key_' . getmypid() . '.pem';
        file_put_contents($filename, $content);
        chmod($filename, 0600);

        return $filename;
    }
}
