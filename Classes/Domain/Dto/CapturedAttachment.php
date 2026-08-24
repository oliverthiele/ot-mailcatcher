<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Domain\Dto;

final class CapturedAttachment
{
    public function __construct(
        public readonly int $partIndex,
        public readonly string $fileName,
        public readonly string $mimeType,
        public readonly int $size,
    ) {
    }
}
