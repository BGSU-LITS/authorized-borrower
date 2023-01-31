<?php

declare(strict_types=1);

namespace Lits\Config;

use Lits\Config;

final class FormConfig extends Config
{
    public string $subject = 'Authorized Borrower Form';
    public ?string $to = null;

    /** @var string[] $cc */
    public array $cc = [];
}
