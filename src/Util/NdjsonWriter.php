<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Util;

use Symfony\Component\Console\Style\SymfonyStyle;

final class NdjsonWriter
{
    public function __construct(private readonly SymfonyStyle $io, private readonly ?\SplFileObject $file)
    {
    }

    public function write(array $row): void
    {
        $line = json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        if ($this->file) {
            $this->file->fwrite($line);
        } else {
            $this->io->write($line);
        }
    }
}
