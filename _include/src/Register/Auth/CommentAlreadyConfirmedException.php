<?php

declare(strict_types = 1);

namespace Register\Auth;

/** A saved comment can be acknowledged, but its email token cannot authenticate again. */
final class CommentAlreadyConfirmedException extends \RuntimeException
{
    public function __construct(public readonly string $returnPath)
    {
        parent::__construct('The comment has already been confirmed.');
    }
}
