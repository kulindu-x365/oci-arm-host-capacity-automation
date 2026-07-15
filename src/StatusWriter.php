<?php
declare(strict_types=1);

namespace Hitrov;

class StatusWriter
{
    private string $filename;

    public function __construct(string $filename)
    {
        $this->filename = $filename;
    }

    public function write(array $data): void
    {
        $payload = array_merge($data, [
            'updatedAt' => date(DATE_ATOM),
        ]);

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        // write to a temp file then rename, so the status page never reads a half-written file
        $tmpFilename = $this->filename . '.tmp';
        file_put_contents($tmpFilename, $json);
        rename($tmpFilename, $this->filename);
    }
}
