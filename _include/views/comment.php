<?php

declare(strict_types = 1);

/**
 * @var callable $trans
 * @var callable $dateAndTime
 * @var string $text
 * @var int|string $time
 * @var string $nick
 * @var string $email
 * @var bool|int|string $show_email
 * @var bool|int|string $good
 * @var bool $is_author
 * @var int $id
 * @var int $i
 * @var int $depth
 * @var int $visual_depth
 * @var bool $show_addressee
 * @var array{id: int, i: int, nick: string}|null $parent
 * @var string $children
 */

$encodedNick = s2_htmlencode($nick);
$name        = !empty($show_email)
    ? \S2\Cms\Helper\StringHelper::jsMailTo($encodedNick, $email)
    : $encodedNick;
$replyQuery = http_build_query([
    'reply_to'     => $id,
    'reply_number' => $i,
    'reply_name'   => $nick,
]);

?>
<article class="comment-item depth-<?php echo $visual_depth, !empty($good) ? ' good' : '', $is_author ? ' by-author' : ''; ?>"
         id="<?php echo $i; ?>"
         data-comment-id="<?php echo $id; ?>"
         data-comment-depth="<?php echo $depth; ?>"
         role="listitem">
    <header class="comment-meta">
        <span class="comment-name"><?php echo $name; ?></span>
        <?php if ($is_author): ?>
            <span class="comment-author-mark"><?php echo $trans('Site author'); ?></span>
        <?php endif; ?>
        <a class="comment-permalink" href="#<?php echo $i; ?>" aria-label="<?php echo $trans('Comment permalink', ['%number%' => $i]); ?>">
            <time datetime="<?php echo date(DATE_ATOM, (int)$time); ?>"><?php echo $dateAndTime((int)$time); ?></time><span aria-hidden="true">, </span><span>№&nbsp;<?php echo $i; ?></span>
        </a>
    </header>
    <div class="comment-body">
        <?php if ($show_addressee && $parent !== null): ?>
            <a class="comment-addressee" href="#<?php echo $parent['i']; ?>"><?php echo s2_htmlencode($parent['nick']); ?>,</a>
        <?php endif; ?>
        <?php echo \S2\Cms\Helper\StringHelper::bbcodeToHtml(s2_htmlencode($text), $trans('Wrote')); ?>
    </div>
    <div class="comment-actions">
        <a class="comment-reply" href="?<?php echo s2_htmlencode($replyQuery); ?>#add-comment"
           data-reply-comment="<?php echo $id; ?>"
           data-reply-number="<?php echo $i; ?>"
           data-reply-name="<?php echo $encodedNick; ?>"><?php echo $trans('Reply'); ?></a>
    </div>
    <?php if ($children !== ''): ?>
        <div class="comment-children" role="list">
            <?php echo $children; ?>
        </div>
    <?php endif; ?>
</article>
