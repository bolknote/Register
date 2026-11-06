<?php

declare(strict_types = 1);

namespace Register\Core\Comment;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/** Removes request-bound forms and their cookie from an early public snapshot only. */
final readonly class CommentFormCacheSnapshot
{
    public const string ATTRIBUTE = '_register_public_comment_forms';

    /** @param list<array{form:string,placeholder:string}> $forms */
    public function __construct(private array $forms, private Cookie $visitorCookie)
    {
    }

    public function publicResponse(Response $response): Response
    {
        $public = clone $response;
        $content = $public->getContent();
        $matchingCookie = false;
        foreach ($public->headers->getCookies() as $cookie) {
            if ((string)$cookie === (string)$this->visitorCookie) {
                $matchingCookie = true;
                break;
            }
        }

        if (\is_string($content) && $matchingCookie) {
            foreach ($this->forms as $form) {
                $content = str_replace($form['form'], $form['placeholder'], $content, $count);
                if ($count !== 1) {
                    $public->headers->addCacheControlDirective('no-store');
                    return $public;
                }
            }

            $public->setContent($content);
            $public->headers->removeCookie($this->visitorCookie->getName(), $this->visitorCookie->getPath(), $this->visitorCookie->getDomain());
            return $public;
        }

        // A later processor changed the form or cookie. Keep the ordinary response,
        // but fail closed rather than publishing any of its private form state.
        $public->headers->addCacheControlDirective('no-store');
        return $public;
    }
}
